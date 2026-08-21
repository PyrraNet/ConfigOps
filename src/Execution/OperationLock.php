<?php
/**
 * Short-lived, atomic locks for state-changing ConfigOps operations.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Execution;

use ConfigOps\Multisite\SiteScope;
use RuntimeException;
use wpdb;

final class OperationLock implements OperationMutex
{
	private const LIFETIME = 900;
	private const OPTION_PREFIX = 'configops_operation_lock_';
	private readonly SiteScope $siteScope;
	private readonly string $optionsTable;

	public function __construct(private readonly wpdb $database, ?SiteScope $siteScope = null)
	{
		$this->siteScope    = $siteScope ?? SiteScope::current();
		$this->optionsTable = $database->options;
	}

	/**
	 * @template T
	 * @param callable(): T $operation Operation guarded by the lock.
	 * @return T
	 */
	public function run(string $scope, callable $operation): mixed
	{
		if (! $this->siteScope->isCurrent()) {
			throw new RuntimeException('ConfigOps refused to acquire a lock while WordPress is switched to another site.');
		}

		$option = $this->optionName($scope);
		$token  = wp_generate_uuid4();

		if (! $this->acquire($option, $token)) {
			throw new RuntimeException('Another ConfigOps operation is already changing this configuration.');
		}

		try {
			if (! $this->siteScope->isCurrent()) {
				throw new RuntimeException('ConfigOps refused to continue after the lock changed WordPress site context.');
			}

			return $operation();
		} finally {
			$this->siteScope->run(fn (): null => $this->releaseInOwningSite($option, $token));
		}
	}

	private function acquire(string $option, string $token): bool
	{
		$lock = array(
			'token'      => $token,
			'expires_at' => time() + self::LIFETIME,
		);

		if (add_option($option, $lock, '', false)) {
			return true;
		}

		$raw = $this->rawValue($option);
		if (null === $raw) {
			return add_option($option, $lock, '', false);
		}

		$existing = maybe_unserialize($raw);
		if (is_array($existing) && isset($existing['expires_at']) && (int) $existing['expires_at'] >= time()) {
			return false;
		}

		$replaced = $this->database->query(
			$this->database->prepare(
				"UPDATE {$this->optionsTable} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				maybe_serialize($lock),
				$option,
				$raw
			)
		);

		if (1 === $replaced) {
			$this->invalidateCache($option);

			return true;
		}

		return false;
	}

	private function release(string $option, string $token): void
	{
		$raw = $this->rawValue($option);
		if (null === $raw) {
			return;
		}

		$existing = maybe_unserialize($raw);
		if (! is_array($existing) || ! hash_equals((string) ($existing['token'] ?? ''), $token)) {
			return;
		}

		$deleted = $this->database->query(
			$this->database->prepare(
				"DELETE FROM {$this->optionsTable} WHERE option_name = %s AND option_value = %s",
				$option,
				$raw
			)
		);
		if (1 === $deleted) {
			$this->invalidateCache($option);
		}
	}

	private function releaseInOwningSite(string $option, string $token): null
	{
		$this->release($option, $token);

		return null;
	}

	private function rawValue(string $option): ?string
	{
		$value = $this->database->get_var(
			$this->database->prepare(
				"SELECT option_value FROM {$this->optionsTable} WHERE option_name = %s",
				$option
			)
		);

		return is_string($value) ? $value : null;
	}

	private function invalidateCache(string $option): void
	{
		wp_cache_delete($option, 'options');
		wp_cache_delete('alloptions', 'options');
		wp_cache_delete('notoptions', 'options');
	}

	private function optionName(string $scope): string
	{
		return self::OPTION_PREFIX . hash('sha256', $scope);
	}
}
