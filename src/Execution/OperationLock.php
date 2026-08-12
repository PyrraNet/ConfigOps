<?php
/**
 * Short-lived, atomic locks for state-changing ConfigOps operations.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Execution;

use RuntimeException;
use wpdb;

final class OperationLock
{
	private const LIFETIME = 900;
	private const OPTION_PREFIX = 'configops_operation_lock_';

	public function __construct(private readonly wpdb $database)
	{
	}

	/**
	 * @template T
	 * @param callable(): T $operation Operation guarded by the lock.
	 * @return T
	 */
	public function run(string $scope, callable $operation): mixed
	{
		$option = $this->optionName($scope);
		$token  = wp_generate_uuid4();

		if (! $this->acquire($option, $token)) {
			throw new RuntimeException('Another ConfigOps operation is already changing this configuration.');
		}

		try {
			return $operation();
		} finally {
			$this->release($option, $token);
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
				"UPDATE {$this->database->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
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
				"DELETE FROM {$this->database->options} WHERE option_name = %s AND option_value = %s",
				$option,
				$raw
			)
		);
		if (1 === $deleted) {
			$this->invalidateCache($option);
		}
	}

	private function rawValue(string $option): ?string
	{
		$value = $this->database->get_var(
			$this->database->prepare(
				"SELECT option_value FROM {$this->database->options} WHERE option_name = %s",
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
