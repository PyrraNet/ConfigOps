<?php
/**
 * Complete local data cleanup when WordPress uninstalls ConfigOps.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps;

use ConfigOps\Access\CapabilityManager;
use ConfigOps\Experiment\ExperimentalFeatures;
use ConfigOps\Maintenance\HistoryRetention;
use ConfigOps\Multisite\SiteContextRunner;
use Throwable;
use wpdb;

final class Uninstall
{
	public static function run(): void
	{
		global $wpdb;

		if (! $wpdb instanceof wpdb) {
			return;
		}

		$sites = new SiteContextRunner();
		foreach ($sites->siteIds() as $siteId) {
			try {
				$sites->run(
					$siteId,
					static function () use ($wpdb): void {
						wp_clear_scheduled_hook(HistoryRetention::HOOK);
						self::removeCapabilities();
						self::removeOptions($wpdb);
						self::dropLegacyTables($wpdb);
					}
				);
			} catch (Throwable $error) {
				self::reportCleanupError($error, $siteId);
			}
		}

		self::removeNetworkOptions($wpdb);
		self::removeSharedOptions($wpdb);
		self::dropSharedTables($wpdb);
	}

	private static function removeNetworkOptions(wpdb $database): void
	{
		if (! is_multisite()) {
			return;
		}

		$lockPrefix = 'configops_operation_lock_';
		$siteMeta = '`' . str_replace('`', '``', (string) $database->sitemeta) . '`';
		$networkIds = get_networks(array('fields' => 'ids', 'number' => 0));
		foreach (array_map('absint', is_array($networkIds) ? $networkIds : array()) as $networkId) {
			if ($networkId <= 0) {
				continue;
			}
			delete_network_option($networkId, 'configops_active_capture_id');
			delete_network_option($networkId, 'configops_capture_integrity_fallback');
			$locks = $database->get_col(
				$database->prepare(
					"SELECT meta_key FROM {$siteMeta}
					WHERE site_id = %d AND SUBSTR(meta_key, 1, %d) = %s",
					$networkId,
					strlen($lockPrefix),
					$lockPrefix
				)
			);
			foreach (is_array($locks) ? $locks : array() as $lock) {
				delete_network_option($networkId, (string) $lock);
			}
		}
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
		self::removeFlashTransients();

		foreach (
			array(
				'configops_schema_version',
				'configops_capabilities_version',
				'configops_active_capture_id',
				'configops_capture_integrity_fallback',
				ExperimentalFeatures::GENERIC_ARRAY_UNDO_OPTION,
			) as $option
		) {
			delete_option($option);
		}

		$prefixes = array(
			'configops_operation_lock_',
			'configops_pending_evidence_',
			'_transient_configops_',
			'_transient_timeout_configops_',
		);
		$quotedOptions  = '`' . str_replace('`', '``', $database->options) . '`';
		$dynamicOptions = $database->get_col(
			$database->prepare(
				"SELECT option_name FROM {$quotedOptions}
				WHERE SUBSTR(option_name, 1, %d) = %s
					OR SUBSTR(option_name, 1, %d) = %s
					OR SUBSTR(option_name, 1, %d) = %s
					OR SUBSTR(option_name, 1, %d) = %s",
				strlen($prefixes[0]),
				$prefixes[0],
				strlen($prefixes[1]),
				$prefixes[1],
				strlen($prefixes[2]),
				$prefixes[2],
				strlen($prefixes[3]),
				$prefixes[3]
			)
		);
		foreach (is_array($dynamicOptions) ? $dynamicOptions : array() as $option) {
			$option = (string) $option;
			if (str_starts_with($option, '_transient_configops_')) {
				delete_transient(substr($option, strlen('_transient_')));
				continue;
			}
			if (str_starts_with($option, '_transient_timeout_configops_')) {
				delete_transient(substr($option, strlen('_transient_timeout_')));
				continue;
			}
			delete_option((string) $option);
		}
		wp_cache_delete('alloptions', 'options');
		wp_cache_delete('notoptions', 'options');
	}

	private static function removeFlashTransients(): void
	{
		delete_transient('configops_flash_0');
		$offset = 0;
		do {
			$userIds = get_users(
				array(
					'fields'  => 'ids',
					'number'  => 100,
					'offset'  => $offset,
					'orderby' => 'ID',
					'order'   => 'ASC',
				)
			);
			$userIds = array_values(array_filter(array_map('absint', is_array($userIds) ? $userIds : array())));
			foreach ($userIds as $userId) {
				delete_transient('configops_flash_' . $userId);
			}
			$offset += count($userIds);
		} while (100 === count($userIds));
	}

	private static function removeSharedOptions(wpdb $database): void
	{
		$baseOptions = '`' . str_replace('`', '``', (string) ($database->base_prefix ?: $database->prefix) . 'options') . '`';
		$database->query(
			$database->prepare(
				"DELETE FROM {$baseOptions} WHERE option_name = %s",
				'configops_shared_schema_lock'
			)
		);
	}

	private static function dropLegacyTables(wpdb $database): void
	{
		$basePrefix = (string) ($database->base_prefix ?: $database->prefix);
		if ((string) $database->prefix === $basePrefix) {
			return;
		}

		self::dropTablesWithPrefix($database, (string) $database->prefix);
	}

	private static function dropSharedTables(wpdb $database): void
	{
		self::dropTablesWithPrefix($database, (string) ($database->base_prefix ?: $database->prefix));
	}

	private static function dropTablesWithPrefix(wpdb $database, string $prefix): void
	{
		foreach (
			array(
				'configops_restore_runs',
				'configops_write_signals',
				'configops_mutations',
				'configops_capture_sessions',
			) as $suffix
		) {
			$table       = $prefix . $suffix;
			$quotedTable = '`' . str_replace('`', '``', $table) . '`';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifier is composed only from wpdb::prefix and a fixed suffix, then quoted.
			$database->query("DROP TABLE IF EXISTS {$quotedTable}");
		}
	}

	private static function reportCleanupError(Throwable $error, int $siteId): void
	{
		try {
			do_action('configops_uninstall_error', $error, $siteId);
		} catch (Throwable) {
			// Uninstall should continue cleaning the remaining sites.
		}
	}
}
