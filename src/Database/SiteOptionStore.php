<?php
/**
 * Site-local ConfigOps state stored through the normal Options API.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Database;

use RuntimeException;
use wpdb;

final readonly class SiteOptionStore implements ScopedOptionStore
{
	private wpdb $database;

	public function __construct(?wpdb $database = null)
	{
		if (! $database instanceof wpdb) {
			global $wpdb;
			$database = $wpdb;
		}
		if (! $database instanceof wpdb) {
			throw new RuntimeException('ConfigOps site state requires a WordPress database connection.');
		}
		$this->database = $database;
	}

	public function get(string $name, mixed $default = false): mixed
	{
		return get_option($name, $default);
	}

	public function add(string $name, mixed $value): bool
	{
		return add_option($name, $value, '', false);
	}

	public function update(string $name, mixed $value): bool
	{
		return update_option($name, $value, false);
	}

	public function delete(string $name): bool
	{
		return delete_option($name);
	}

	public function deleteIfValue(string $name, mixed $expected): bool
	{
		$table = '`' . str_replace('`', '``', (string) $this->database->options) . '`';
		$deleted = $this->database->query(
			$this->database->prepare(
				"DELETE FROM {$table} WHERE option_name = %s AND option_value = %s",
				$name,
				maybe_serialize($expected)
			)
		);
		if (1 === $deleted) {
			wp_cache_delete($name, 'options');
			wp_cache_delete('alloptions', 'options');
			wp_cache_delete('notoptions', 'options');
		}

		return 1 === $deleted;
	}
}
