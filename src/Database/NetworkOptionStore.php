<?php
/**
 * Network-owned ConfigOps state stored separately from every site's options.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Database;

use RuntimeException;
use wpdb;

final readonly class NetworkOptionStore implements ScopedOptionStore
{
	private wpdb $database;
	private string $table;

	public function __construct(private int $networkId, ?wpdb $database = null)
	{
		if ($this->networkId <= 0) {
			throw new RuntimeException('ConfigOps network state requires a valid network ID.');
		}
		if (! $database instanceof wpdb) {
			global $wpdb;
			$database = $wpdb;
		}
		if (! $database instanceof wpdb) {
			throw new RuntimeException('ConfigOps network state requires a WordPress database connection.');
		}
		$this->database = $database;
		$this->table = (string) $this->database->sitemeta;
		if ('' === $this->table) {
			throw new RuntimeException('ConfigOps network state requires the WordPress network metadata table.');
		}
	}

	public function get(string $name, mixed $default = false): mixed
	{
		return get_network_option($this->networkId, $name, $default);
	}

	public function add(string $name, mixed $value): bool
	{
		return add_network_option($this->networkId, $name, $value);
	}

	public function update(string $name, mixed $value): bool
	{
		return update_network_option($this->networkId, $name, $value);
	}

	public function delete(string $name): bool
	{
		return delete_network_option($this->networkId, $name);
	}

	public function deleteIfValue(string $name, mixed $expected): bool
	{
		$table = '`' . str_replace('`', '``', $this->table) . '`';
		$deleted = $this->database->query(
			$this->database->prepare(
				"DELETE FROM {$table} WHERE site_id = %d AND meta_key = %s AND meta_value = %s",
				$this->networkId,
				$name,
				maybe_serialize($expected)
			)
		);
		if (1 === $deleted) {
			wp_cache_delete($this->networkId . ':' . $name, 'site-options');
			wp_cache_delete($this->networkId . ':notoptions', 'site-options');
		}

		return 1 === $deleted;
	}
}
