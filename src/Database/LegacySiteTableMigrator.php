<?php
/**
 * Idempotent migration from legacy per-site tables into shared evidence tables.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Database;

use RuntimeException;
use wpdb;

final class LegacySiteTableMigrator
{
	private const BATCH_SIZE = 500;

	/** @var array<string, list<string>> */
	private const COLUMNS = array(
		'configops_capture_sessions' => array(
			'name', 'capture_mode', 'origin_type', 'origin_id', 'origin_version', 'status', 'actor_id', 'initial_url', 'mutation_count',
			'review_change_count', 'technical_change_count', 'write_signal_count',
			'capture_error_count', 'last_error_code', 'last_error_at', 'started_at', 'ended_at',
		),
		'configops_mutations' => array(
			'session_id', 'request_id', 'mutation_type', 'option_name', 'old_value', 'new_value',
			'diff', 'old_autoload', 'new_autoload', 'restorable', 'restore_mode', 'is_redacted',
			'review_change_count', 'technical_change_count', 'secret_change_count',
			'safe_restore_change_count', 'classification', 'classification_reason', 'adapter_id',
			'adapter_schema_version', 'component_version', 'source_type', 'source_component',
			'source_basis', 'source_file', 'source_line', 'request_method', 'request_uri', 'admin_screen',
			'actor_id', 'occurred_at',
		),
		'configops_write_signals' => array(
			'session_id', 'request_id', 'operation', 'table_name', 'occurrence_count',
			'source_type', 'source_component', 'source_basis', 'source_file', 'source_line', 'request_method',
			'request_uri', 'admin_screen', 'actor_id', 'occurred_at',
		),
		'configops_restore_runs' => array(
			'scope_type', 'scope_id', 'session_id', 'actor_id', 'status',
			'restored_option_count', 'failure_code', 'started_at', 'finished_at',
		),
	);

	public function __construct(
		private readonly wpdb $database,
		private readonly StorageContext $storage
	) {
	}

	public function migrateCurrentSite(): void
	{
		$legacyPrefix = (string) $this->database->prefix;
		$sharedPrefix = (string) ($this->database->base_prefix ?: $legacyPrefix);
		if ($legacyPrefix === $sharedPrefix) {
			return;
		}

		$sessionTable = $legacyPrefix . 'configops_capture_sessions';
		if (! $this->tableExists($sessionTable)) {
			return;
		}

		foreach (array_keys(self::COLUMNS) as $suffix) {
			if (! $this->tableExists($legacyPrefix . $suffix)) {
				throw new RuntimeException('ConfigOps found incomplete legacy site storage and refused a partial migration.');
			}
		}

		$this->migrateRows(
			'configops_capture_sessions',
			static fn (array $row): array => $row
		);
		$this->migrateRows(
			'configops_mutations',
			function (array $row): array {
				$row['session_id'] = $this->requireMappedId(
					'configops_capture_sessions',
					(int) ($row['session_id'] ?? 0)
				);

				return $row;
			}
		);
		$this->migrateRows(
			'configops_write_signals',
			function (array $row): array {
				$row['session_id'] = $this->requireMappedId(
					'configops_capture_sessions',
					(int) ($row['session_id'] ?? 0)
				);

				return $row;
			}
		);
		$this->migrateRows(
			'configops_restore_runs',
			function (array $row): array {
				$row['session_id'] = $this->requireMappedId(
					'configops_capture_sessions',
					(int) ($row['session_id'] ?? 0)
				);
				$scopeTable = 'mutation' === (string) ($row['scope_type'] ?? '')
					? 'configops_mutations'
					: 'configops_capture_sessions';
				$row['scope_id'] = $this->requireMappedId($scopeTable, (int) ($row['scope_id'] ?? 0));

				return $row;
			}
		);

		$this->remapSiteOptions();
	}

	/**
	 * @param callable(array<string, mixed>): array<string, mixed> $transform Row foreign-key mapper.
	 */
	private function migrateRows(string $suffix, callable $transform): void
	{
		$legacyTable = $this->database->prefix . $suffix;
		$sharedTable = $this->storage->table($suffix);
		$quotedLegacy = $this->quoteIdentifier($legacyTable);
		$lastId = 0;

		do {
			$rows = $this->database->get_results(
				$this->database->prepare(
					"SELECT * FROM {$quotedLegacy} WHERE id > %d ORDER BY id ASC LIMIT %d",
					$lastId,
					self::BATCH_SIZE
				),
				ARRAY_A
			);
			$rows = is_array($rows) ? $rows : array();
			foreach ($rows as $source) {
				$legacyId = (int) ($source['id'] ?? 0);
				if ($legacyId <= 0) {
					throw new RuntimeException('ConfigOps found an invalid legacy evidence identifier.');
				}
				$lastId = $legacyId;
				if (null !== $this->mappedId($suffix, $legacyId)) {
					continue;
				}

				$row = array_intersect_key($source, array_flip(self::COLUMNS[$suffix]));
				$row = $transform($row);
				$inserted = $this->database->insert($sharedTable, $this->storage->legacyRow($row, $legacyId));
				if (false === $inserted && null === $this->mappedId($suffix, $legacyId)) {
					throw new RuntimeException('ConfigOps could not migrate legacy site evidence into shared storage.');
				}
			}
		} while (count($rows) === self::BATCH_SIZE);
	}

	private function requireMappedId(string $suffix, int $legacyId): int
	{
		$mapped = $this->mappedId($suffix, $legacyId);
		if (null === $mapped) {
			throw new RuntimeException('ConfigOps found orphaned legacy evidence and refused a lossy migration.');
		}

		return $mapped;
	}

	private function mappedId(string $suffix, int $legacyId): ?int
	{
		if ($legacyId <= 0) {
			return null;
		}

		$table = $this->storage->table($suffix);
		$id = $this->database->get_var(
			$this->storage->prepare(
				"SELECT id FROM {$table} WHERE {$this->storage->clause()} AND legacy_id = %d",
				$legacyId
			)
		);

		return null === $id ? null : (int) $id;
	}

	private function remapSiteOptions(): void
	{
		$activeId = (int) get_option('configops_active_capture_id', 0);
		if ($activeId > 0) {
			$mapped = $this->mappedId('configops_capture_sessions', $activeId);
			if (null !== $mapped) {
				update_option('configops_active_capture_id', $mapped, false);
			}
		}

		$fallback = get_option(CaptureRepository::INTEGRITY_FALLBACK_OPTION, false);
		if (! is_array($fallback) || ! isset($fallback['events']) || ! is_array($fallback['events'])) {
			return;
		}

		$mappedEvents = array();
		$overflow = true === ($fallback['overflow'] ?? false);
		foreach ($fallback['events'] as $legacyId => $event) {
			$eventId = (int) $legacyId;
			$mapped = $this->mappedId('configops_capture_sessions', $eventId);
			if (null === $mapped && $this->canonicalIdExists('configops_capture_sessions', $eventId)) {
				$mapped = $eventId;
			}
			if (null === $mapped) {
				$overflow = true;
				continue;
			}
			$mappedEvents[(string) $mapped] = $event;
		}

		update_option(
			CaptureRepository::INTEGRITY_FALLBACK_OPTION,
			array('events' => $mappedEvents, 'overflow' => $overflow),
			false
		);
	}

	private function canonicalIdExists(string $suffix, int $id): bool
	{
		if ($id <= 0) {
			return false;
		}

		$table = $this->storage->table($suffix);
		$storedId = $this->database->get_var(
			$this->storage->prepare(
				"SELECT id FROM {$table} WHERE {$this->storage->clause()} AND id = %d",
				$id
			)
		);

		return null !== $storedId;
	}

	private function tableExists(string $table): bool
	{
		$exists = $this->database->get_var(
			$this->database->prepare('SHOW TABLES LIKE %s', $this->database->esc_like($table))
		);

		return $table === $exists;
	}

	private function quoteIdentifier(string $identifier): string
	{
		return '`' . str_replace('`', '``', $identifier) . '`';
	}
}
