<?php
/**
 * Atomic, expiring lock stored in the current network's sitemeta scope.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Execution;

use ConfigOps\Database\NetworkOptionStore;
use ConfigOps\Multisite\NetworkScope;
use RuntimeException;
use wpdb;

final class NetworkOperationLock implements OperationMutex
{
	private const LIFETIME = 900;
	private const OPTION_PREFIX = 'configops_operation_lock_';
	private readonly string $table;
	private readonly NetworkOptionStore $options;

	public function __construct(
		private readonly wpdb $database,
		private readonly NetworkScope $scope
	) {
		$this->table = (string) $database->sitemeta;
		if ('' === $this->table) {
			throw new RuntimeException('ConfigOps cannot lock network settings without the WordPress network metadata table.');
		}
		$this->options = new NetworkOptionStore($scope->networkId(), $database);
	}

	/**
	 * @template T
	 * @param callable(): T $operation Operation guarded by the lock.
	 * @return T
	 */
	public function run(string $operationName, callable $operation): mixed
	{
		$this->assertCurrent();
		$option = $this->optionName($operationName);
		$token = wp_generate_uuid4();
		if (! $this->acquire($option, $token)) {
			throw new RuntimeException('Another ConfigOps operation is already changing this network configuration.');
		}

		try {
			$this->assertCurrent();

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
		if ($this->options->add($option, $lock)) {
			return true;
		}

		$raw = $this->rawValue($option);
		if (null === $raw) {
			return $this->options->add($option, $lock);
		}
		$existing = maybe_unserialize($raw);
		if (is_array($existing) && isset($existing['expires_at']) && (int) $existing['expires_at'] >= time()) {
			return false;
		}

		$replaced = $this->database->query(
			$this->database->prepare(
				"UPDATE {$this->table} SET meta_value = %s
				WHERE site_id = %d AND meta_key = %s AND meta_value = %s",
				maybe_serialize($lock),
				$this->scope->networkId(),
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
				"DELETE FROM {$this->table}
				WHERE site_id = %d AND meta_key = %s AND meta_value = %s",
				$this->scope->networkId(),
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
				"SELECT meta_value FROM {$this->table} WHERE site_id = %d AND meta_key = %s",
				$this->scope->networkId(),
				$option
			)
		);

		return is_string($value) ? $value : null;
	}

	private function invalidateCache(string $option): void
	{
		wp_cache_delete($this->scope->networkId() . ':' . $option, 'site-options');
		wp_cache_delete($this->scope->networkId() . ':notoptions', 'site-options');
	}

	private function assertCurrent(): void
	{
		if (! $this->scope->isCurrent()) {
			throw new RuntimeException('ConfigOps refused to lock settings for a different WordPress network.');
		}
	}

	private function optionName(string $operationName): string
	{
		return self::OPTION_PREFIX . hash('sha256', 'network:' . $operationName);
	}
}
