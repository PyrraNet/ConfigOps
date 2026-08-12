<?php
/**
 * Database schema management.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Database;

use ConfigOps\Execution\OperationLock;
use RuntimeException;
use wpdb;

final class Schema
{
	private const VERSION = 8;

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
		(new OperationLock($this->database))->run('schema-upgrade', function (): void {
			$this->installUnlocked();
		});
	}

	private function installUnlocked(): void
	{
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charsetCollate = $this->database->get_charset_collate();
		$sessions       = $this->database->prefix . 'configops_capture_sessions';
		$mutations      = $this->database->prefix . 'configops_mutations';
		$writeSignals   = $this->database->prefix . 'configops_write_signals';
		$restoreRuns    = $this->database->prefix . 'configops_restore_runs';

		$sql = "CREATE TABLE {$sessions} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL,
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
			KEY status_started (status, started_at)
		) {$charsetCollate};

		CREATE TABLE {$mutations} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
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
			source_file text NULL,
			source_line int(10) unsigned NULL,
			request_method varchar(12) NULL,
			request_uri text NULL,
			admin_screen varchar(191) NULL,
			actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			occurred_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY session_order (session_id, id),
			KEY session_request (session_id, request_id),
			KEY option_occurred (option_name, occurred_at),
			KEY occurred_at (occurred_at)
		) {$charsetCollate};

		CREATE TABLE {$writeSignals} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id bigint(20) unsigned NOT NULL,
			request_id char(36) NOT NULL,
			operation varchar(16) NOT NULL,
			table_name varchar(191) NOT NULL,
			occurrence_count bigint(20) unsigned NOT NULL DEFAULT 1,
			source_type varchar(20) NOT NULL DEFAULT 'unknown',
			source_component varchar(191) NULL,
			source_file text NULL,
			source_line int(10) unsigned NULL,
			request_method varchar(12) NULL,
			request_uri text NULL,
			admin_screen varchar(191) NULL,
			actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			occurred_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY session_order (session_id, id),
			KEY session_request (session_id, request_id),
			KEY table_occurred (table_name, occurred_at)
		) {$charsetCollate};

		CREATE TABLE {$restoreRuns} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
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
			KEY session_started (session_id, started_at),
			KEY actor_started (actor_id, started_at),
			KEY status_started (status, started_at)
		) {$charsetCollate};";

		dbDelta($sql);
		$this->assertInstalledShape();

		update_option('configops_schema_version', self::VERSION, false);
		if (self::VERSION !== (int) get_option('configops_schema_version', 0)) {
			throw new RuntimeException('ConfigOps created its tables but could not commit the schema version.');
		}
	}

	private function assertInstalledShape(): void
	{
		$this->assertTableShape(
			$this->database->prefix . 'configops_capture_sessions',
			array(
				'id',
				'name',
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
			$this->database->prefix . 'configops_mutations',
			array(
				'id',
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
			$this->database->prefix . 'configops_write_signals',
			array(
				'id',
				'session_id',
				'request_id',
				'operation',
				'table_name',
				'occurrence_count',
				'source_type',
				'source_component',
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
			$this->database->prefix . 'configops_restore_runs',
			array('id', 'scope_type', 'scope_id', 'session_id', 'actor_id', 'status', 'restored_option_count', 'failure_code', 'started_at', 'finished_at')
		);
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
}
