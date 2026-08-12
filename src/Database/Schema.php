<?php
/**
 * Database schema management.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Database;

use wpdb;

final class Schema
{
	private const VERSION = 6;

	public function __construct(private readonly wpdb $database)
	{
	}

	public function maybeUpgrade(): void
	{
		if (self::VERSION !== (int) get_option('configops_schema_version', 0)) {
			$this->install();
		}
	}

	public function install(): void
	{
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charsetCollate = $this->database->get_charset_collate();
		$sessions       = $this->database->prefix . 'configops_capture_sessions';
		$mutations      = $this->database->prefix . 'configops_mutations';
		$writeSignals   = $this->database->prefix . 'configops_write_signals';

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
		) {$charsetCollate};";

		dbDelta($sql);
		update_option('configops_schema_version', self::VERSION, false);
	}
}
