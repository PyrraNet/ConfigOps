<?php
/**
 * Installation-wide lock for shared ConfigOps schema changes.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Database;

use RuntimeException;
use wpdb;

final class SharedStorageLock
{
	private const LIFETIME = 900;
	private const OPTION = 'configops_shared_schema_lock';
	private readonly string $optionsTable;

	public function __construct(private readonly wpdb $database)
	{
		$prefix = (string) ($database->base_prefix ?: $database->prefix);
		$this->optionsTable = $prefix . 'options';
	}

	/**
	 * @template T
	 * @param callable(): T $operation Shared schema operation.
	 * @return T
	 */
	public function run(callable $operation): mixed
	{
		$token = wp_generate_uuid4();
		$value = maybe_serialize(
			array(
				'token'      => $token,
				'expires_at' => time() + self::LIFETIME,
			)
		);

		if (! $this->acquire($value)) {
			throw new RuntimeException('Another ConfigOps request is upgrading the shared evidence schema.');
		}

		try {
			return $operation();
		} finally {
			$this->release($token);
		}
	}

	private function acquire(string $value): bool
	{
		$table = $this->quotedOptionsTable();
		$inserted = $this->database->query(
			$this->database->prepare(
				"INSERT IGNORE INTO {$table} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				self::OPTION,
				$value
			)
		);
		if (1 === $inserted) {
			return true;
		}

		$raw = $this->rawValue();
		if (null === $raw) {
			return false;
		}
		$existing = maybe_unserialize($raw);
		if (is_array($existing) && (int) ($existing['expires_at'] ?? 0) >= time()) {
			return false;
		}

		$replaced = $this->database->query(
			$this->database->prepare(
				"UPDATE {$table} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$value,
				self::OPTION,
				$raw
			)
		);

		return 1 === $replaced;
	}

	private function release(string $token): void
	{
		$raw = $this->rawValue();
		if (null === $raw) {
			return;
		}
		$existing = maybe_unserialize($raw);
		if (! is_array($existing) || ! hash_equals((string) ($existing['token'] ?? ''), $token)) {
			return;
		}

		$table = $this->quotedOptionsTable();
		$this->database->query(
			$this->database->prepare(
				"DELETE FROM {$table} WHERE option_name = %s AND option_value = %s",
				self::OPTION,
				$raw
			)
		);
	}

	private function rawValue(): ?string
	{
		$table = $this->quotedOptionsTable();
		$value = $this->database->get_var(
			$this->database->prepare(
				"SELECT option_value FROM {$table} WHERE option_name = %s",
				self::OPTION
			)
		);

		return is_string($value) ? $value : null;
	}

	private function quotedOptionsTable(): string
	{
		return '`' . str_replace('`', '``', $this->optionsTable) . '`';
	}
}
