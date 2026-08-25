<?php
/**
 * Database schema management.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Database;

use RuntimeException;
use wpdb;

final class Schema
{
	private const VERSION = 11;

	public function __construct(private readonly wpdb $database)
	{
	}

	public function maybeUpgrade(): void
	{
		if (self::VERSION !== (int) get_option('configops_schema_version', 0)) {
			$this->install();

			return;
		}

		if (false !== get_option(CaptureRepository::INTEGRITY_FALLBACK_OPTION, false)) {
			try {
				$this->assertInstalledShape();
			} catch (RuntimeException) {
				// A value-free emergency marker means a host setting may have saved
				// while ConfigOps storage failed. Repair and verify before booting.
				$this->install();
			}
		}
	}

	public function install(): void
	{
		(new SharedStorageLock($this->database))->run(function (): void {
			$this->installUnlocked();
		});
	}

	private function installUnlocked(): void
	{
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$storage        = new StorageContext($this->database);
		$charsetCollate = $this->database->get_charset_collate();
		$sessions       = $storage->table('configops_capture_sessions');
		$mutations      = $storage->table('configops_mutations');
		$writeSignals   = $storage->table('configops_write_signals');
		$restoreRuns    = $storage->table('configops_restore_runs');

		$sql = "CREATE TABLE {$sessions} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			network_id bigint(20) unsigned NOT NULL DEFAULT 0,
			blog_id bigint(20) unsigned NOT NULL DEFAULT 0,
			legacy_id bigint(20) unsigned NULL,
			name varchar(191) NOT NULL,
			capture_mode varchar(16) NOT NULL DEFAULT 'manual',
			status varchar(20) NOT NULL DEFAULT 'active',
			actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			initial_url text NULL,
			mutation_count bigint(20) unsigned NOT NULL DEFAULT 0,
			review_change_count bigint(20) unsigned NOT NULL DEFAULT 0,
			technical_change_count bigint(20) unsigned NOT NULL DEFAULT 0,
			write_signal_count bigint(20) unsigned NOT NULL DEFAULT 0,
			capture_error_count bigint(20) unsigned NOT NULL DEFAULT 0,
			last_error_code varchar(64) NULL,
			last_error_at datetime NULL,
			started_at datetime NOT NULL,
			ended_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY site_legacy (network_id, blog_id, legacy_id),
			KEY site_status_started (network_id, blog_id, status, started_at)
		) {$charsetCollate};

		CREATE TABLE {$mutations} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			network_id bigint(20) unsigned NOT NULL DEFAULT 0,
			blog_id bigint(20) unsigned NOT NULL DEFAULT 0,
			legacy_id bigint(20) unsigned NULL,
			session_id bigint(20) unsigned NOT NULL,
			request_id char(36) NOT NULL,
			mutation_type varchar(16) NOT NULL,
			option_name varchar(191) NOT NULL,
			old_value longtext NOT NULL,
			new_value longtext NOT NULL,
			diff longtext NOT NULL,
			old_autoload varchar(20) NULL,
			new_autoload varchar(20) NULL,
			restorable tinyint(1) unsigned NOT NULL DEFAULT 1,
			restore_mode varchar(12) NOT NULL DEFAULT 'full',
			is_redacted tinyint(1) unsigned NOT NULL DEFAULT 0,
			review_change_count int(10) unsigned NOT NULL DEFAULT 0,
			technical_change_count int(10) unsigned NOT NULL DEFAULT 0,
			secret_change_count int(10) unsigned NOT NULL DEFAULT 0,
			safe_restore_change_count int(10) unsigned NOT NULL DEFAULT 0,
			classification varchar(20) NOT NULL DEFAULT 'unknown',
			classification_reason varchar(255) NULL,
			adapter_id varchar(191) NULL,
			adapter_schema_version int(10) unsigned NULL,
			component_version varchar(64) NULL,
			source_type varchar(20) NOT NULL DEFAULT 'unknown',
			source_component varchar(191) NULL,
			source_basis varchar(24) NOT NULL DEFAULT 'caller',
			source_file text NULL,
			source_line int(10) unsigned NULL,
			request_method varchar(12) NULL,
			request_uri text NULL,
			admin_screen varchar(191) NULL,
			actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			occurred_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY site_legacy (network_id, blog_id, legacy_id),
			KEY site_session_order (network_id, blog_id, session_id, id),
			KEY site_session_request (network_id, blog_id, session_id, request_id),
			KEY site_option_occurred (network_id, blog_id, option_name, occurred_at),
			KEY site_occurred_at (network_id, blog_id, occurred_at)
		) {$charsetCollate};

		CREATE TABLE {$writeSignals} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			network_id bigint(20) unsigned NOT NULL DEFAULT 0,
			blog_id bigint(20) unsigned NOT NULL DEFAULT 0,
			legacy_id bigint(20) unsigned NULL,
			session_id bigint(20) unsigned NOT NULL,
			request_id char(36) NOT NULL,
			operation varchar(16) NOT NULL,
			table_name varchar(191) NOT NULL,
			occurrence_count bigint(20) unsigned NOT NULL DEFAULT 1,
			source_type varchar(20) NOT NULL DEFAULT 'unknown',
			source_component varchar(191) NULL,
			source_basis varchar(24) NOT NULL DEFAULT 'caller',
			source_file text NULL,
			source_line int(10) unsigned NULL,
			request_method varchar(12) NULL,
			request_uri text NULL,
			admin_screen varchar(191) NULL,
			actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			occurred_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY site_legacy (network_id, blog_id, legacy_id),
			KEY site_session_order (network_id, blog_id, session_id, id),
			KEY site_session_request (network_id, blog_id, session_id, request_id),
			KEY site_table_occurred (network_id, blog_id, table_name, occurred_at)
		) {$charsetCollate};

		CREATE TABLE {$restoreRuns} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			network_id bigint(20) unsigned NOT NULL DEFAULT 0,
			blog_id bigint(20) unsigned NOT NULL DEFAULT 0,
			legacy_id bigint(20) unsigned NULL,
			scope_type varchar(16) NOT NULL,
			scope_id bigint(20) unsigned NOT NULL,
			session_id bigint(20) unsigned NOT NULL,
			actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(24) NOT NULL DEFAULT 'running',
			restored_option_count int(10) unsigned NOT NULL DEFAULT 0,
			failure_code varchar(64) NULL,
			started_at datetime NOT NULL,
			finished_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY site_legacy (network_id, blog_id, legacy_id),
			KEY site_session_started (network_id, blog_id, session_id, started_at),
			KEY site_actor_started (network_id, blog_id, actor_id, started_at),
			KEY site_status_started (network_id, blog_id, status, started_at)
		) {$charsetCollate};";

		dbDelta($sql);
		$this->assertInstalledShape();
		$this->backfillLegacySharedRows($storage);
		(new LegacySiteTableMigrator($this->database, $storage))->migrateCurrentSite();

		update_option('configops_schema_version', self::VERSION, false);
		if (self::VERSION !== (int) get_option('configops_schema_version', 0)) {
			throw new RuntimeException('ConfigOps created its tables but could not commit the schema version.');
		}
	}

	private function assertInstalledShape(): void
	{
		$storage      = new StorageContext($this->database);
		$sessions     = $storage->table('configops_capture_sessions');
		$mutations    = $storage->table('configops_mutations');
		$writeSignals = $storage->table('configops_write_signals');
		$restoreRuns  = $storage->table('configops_restore_runs');
		$this->assertTableShape(
			$sessions,
			array(
				'id',
				'network_id',
				'blog_id',
				'legacy_id',
				'name',
				'capture_mode',
				'status',
				'actor_id',
				'initial_url',
				'mutation_count',
				'review_change_count',
				'technical_change_count',
				'write_signal_count',
				'capture_error_count',
				'last_error_code',
				'last_error_at',
				'started_at',
				'ended_at',
			)
		);
		$this->assertTableShape(
			$mutations,
			array(
				'id',
				'network_id',
				'blog_id',
				'legacy_id',
				'session_id',
				'request_id',
				'mutation_type',
				'option_name',
				'old_value',
				'new_value',
				'diff',
				'old_autoload',
				'new_autoload',
				'restorable',
				'restore_mode',
				'is_redacted',
				'review_change_count',
				'technical_change_count',
				'secret_change_count',
				'safe_restore_change_count',
				'classification',
				'classification_reason',
				'adapter_id',
				'adapter_schema_version',
				'component_version',
				'source_type',
				'source_component',
				'source_basis',
				'source_file',
				'source_line',
				'request_method',
				'request_uri',
				'admin_screen',
				'actor_id',
				'occurred_at',
			)
		);
		$this->assertTableShape(
			$writeSignals,
			array(
				'id',
				'network_id',
				'blog_id',
				'legacy_id',
				'session_id',
				'request_id',
				'operation',
				'table_name',
				'occurrence_count',
				'source_type',
				'source_component',
				'source_basis',
				'source_file',
				'source_line',
				'request_method',
				'request_uri',
				'admin_screen',
				'actor_id',
				'occurred_at',
			)
		);
		$this->assertTableShape(
			$restoreRuns,
			array('id', 'network_id', 'blog_id', 'legacy_id', 'scope_type', 'scope_id', 'session_id', 'actor_id', 'status', 'restored_option_count', 'failure_code', 'started_at', 'finished_at')
		);

		$this->assertIndexShape($sessions, 'site_legacy', array('network_id', 'blog_id', 'legacy_id'), true);
		$this->assertIndexShape($sessions, 'site_status_started', array('network_id', 'blog_id', 'status', 'started_at'));
		$this->assertIndexShape($mutations, 'site_legacy', array('network_id', 'blog_id', 'legacy_id'), true);
		$this->assertIndexShape($mutations, 'site_session_order', array('network_id', 'blog_id', 'session_id', 'id'));
		$this->assertIndexShape($writeSignals, 'site_legacy', array('network_id', 'blog_id', 'legacy_id'), true);
		$this->assertIndexShape($writeSignals, 'site_session_order', array('network_id', 'blog_id', 'session_id', 'id'));
		$this->assertIndexShape($restoreRuns, 'site_legacy', array('network_id', 'blog_id', 'legacy_id'), true);
		$this->assertIndexShape($restoreRuns, 'site_session_started', array('network_id', 'blog_id', 'session_id', 'started_at'));
	}

	private function backfillLegacySharedRows(StorageContext $storage): void
	{
		$networkId = $storage->networkId();
		$blogId    = $storage->blogId();
		if (is_multisite() && function_exists('get_site')) {
			$mainSite = get_site(1);
			if (is_object($mainSite)) {
				$networkId = max(0, (int) ($mainSite->site_id ?? 0));
				$blogId    = max(1, (int) ($mainSite->blog_id ?? 1));
			}
		}

		foreach (
			array(
				'configops_capture_sessions',
				'configops_mutations',
				'configops_write_signals',
				'configops_restore_runs',
			) as $suffix
		) {
			$table   = $storage->table($suffix);
			$updated = $this->database->query(
				$this->database->prepare(
					"UPDATE {$table} SET network_id = %d, blog_id = %d WHERE network_id = 0 AND blog_id = 0",
					$networkId,
					$blogId
				)
			);
			if (false === $updated) {
				throw new RuntimeException('ConfigOps could not assign legacy evidence to its original site.');
			}
		}
	}

	/**
	 * @param list<string> $requiredColumns Columns required before boot can continue.
	 */
	private function assertTableShape(string $table, array $requiredColumns): void
	{
		$exists = $this->database->get_var(
			$this->database->prepare('SHOW TABLES LIKE %s', $this->database->esc_like($table))
		);
		if ($table !== $exists) {
			throw new RuntimeException('ConfigOps storage is unavailable after the schema upgrade.');
		}

		$quotedTable = '`' . str_replace('`', '``', $table) . '`';
		$columns = $this->database->get_col("SHOW COLUMNS FROM {$quotedTable}", 0);
		$missing = array_diff($requiredColumns, is_array($columns) ? $columns : array());
		if (! empty($missing)) {
			throw new RuntimeException('ConfigOps storage is incomplete after the schema upgrade.');
		}
	}

	/**
	 * @param list<string> $requiredColumns Ordered index columns.
	 */
	private function assertIndexShape(
		string $table,
		string $index,
		array $requiredColumns,
		bool $unique = false
	): void {
		$quotedTable = '`' . str_replace('`', '``', $table) . '`';
		$rows        = $this->database->get_results("SHOW INDEX FROM {$quotedTable}", ARRAY_A);
		$columns     = array();
		$nonUnique = null;
		foreach (is_array($rows) ? $rows : array() as $row) {
			$keyName = (string) ($row['Key_name'] ?? $row['key_name'] ?? '');
			if ($index !== $keyName) {
				continue;
			}
			$sequence = (int) ($row['Seq_in_index'] ?? $row['seq_in_index'] ?? 0);
			$column   = (string) ($row['Column_name'] ?? $row['column_name'] ?? '');
			if ($sequence > 0 && '' !== $column) {
				$columns[$sequence] = $column;
			}
			$nonUnique = (int) ($row['Non_unique'] ?? $row['non_unique'] ?? 1);
		}
		ksort($columns);
		if (array_values($columns) !== $requiredColumns || ($unique && 0 !== $nonUnique)) {
			throw new RuntimeException('ConfigOps storage indexes are incomplete after the schema upgrade.');
		}
	}
}
