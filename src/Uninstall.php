<?php
/**
 * Complete local data cleanup when WordPress uninstalls ConfigOps.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps;

use ConfigOps\Access\CapabilityManager;
use ConfigOps\Maintenance\HistoryRetention;
use wpdb;

final class Uninstall
{
	public static function run(): void
	{
		global $wpdb;

		if (! $wpdb instanceof wpdb) {
			return;
		}

		wp_clear_scheduled_hook(HistoryRetention::HOOK);
		self::removeCapabilities();
		self::removeOptions($wpdb);
		self::dropTables($wpdb);
	}

	private static function removeCapabilities(): void
	{
		$roles = wp_roles();
		foreach (array_keys($roles->roles) as $roleName) {
			$role = get_role((string) $roleName);
			if (! $role) {
				continue;
			}

			foreach (CapabilityManager::capabilities() as $capability) {
				$role->remove_cap($capability);
			}
		}
	}

	private static function removeOptions(wpdb $database): void
	{
		foreach (
			array(
				'configops_schema_version',
				'configops_capabilities_version',
				'configops_active_capture_id',
				'configops_capture_integrity_fallback',
			) as $option
		) {
			delete_option($option);
		}

		$prefixes = array(
			'configops_operation_lock_',
			'_transient_configops_flash_',
			'_transient_timeout_configops_flash_',
		);
		$quotedOptions  = '`' . str_replace('`', '``', $database->options) . '`';
		$dynamicOptions = $database->get_col(
			$database->prepare(
				"SELECT option_name FROM {$quotedOptions}
				WHERE SUBSTR(option_name, 1, %d) = %s
					OR SUBSTR(option_name, 1, %d) = %s
					OR SUBSTR(option_name, 1, %d) = %s",
				strlen($prefixes[0]),
				$prefixes[0],
				strlen($prefixes[1]),
				$prefixes[1],
				strlen($prefixes[2]),
				$prefixes[2]
			)
		);
		$database->query(
			$database->prepare(
				"DELETE FROM {$quotedOptions}
				WHERE SUBSTR(option_name, 1, %d) = %s
					OR SUBSTR(option_name, 1, %d) = %s
					OR SUBSTR(option_name, 1, %d) = %s",
				strlen($prefixes[0]),
				$prefixes[0],
				strlen($prefixes[1]),
				$prefixes[1],
				strlen($prefixes[2]),
				$prefixes[2]
			)
		);
		foreach (is_array($dynamicOptions) ? $dynamicOptions : array() as $option) {
			wp_cache_delete((string) $option, 'options');
		}
		wp_cache_delete('alloptions', 'options');
		wp_cache_delete('notoptions', 'options');
	}

	private static function dropTables(wpdb $database): void
	{
		foreach (
			array(
				'configops_restore_runs',
				'configops_write_signals',
				'configops_mutations',
				'configops_capture_sessions',
			) as $suffix
		) {
			$table       = $database->prefix . $suffix;
			$quotedTable = '`' . str_replace('`', '``', $table) . '`';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifier is composed only from wpdb::prefix and a fixed suffix, then quoted.
			$database->query("DROP TABLE IF EXISTS {$quotedTable}");
		}
	}
}
