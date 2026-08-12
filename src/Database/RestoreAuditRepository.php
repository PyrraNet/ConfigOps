<?php
/**
 * Append-first audit records for every attempted restore operation.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Database;

use RuntimeException;
use wpdb;

final class RestoreAuditRepository
{
	private string $table;

	public function __construct(private readonly wpdb $database)
	{
		$this->table = $this->database->prefix . 'configops_restore_runs';
	}

	public function start(string $scopeType, int $scopeId, int $sessionId, int $actorId): int
	{
		if (! in_array($scopeType, array('mutation', 'session'), true) || $scopeId <= 0 || $sessionId <= 0) {
			throw new RuntimeException('The restore audit scope is invalid. Nothing was changed.');
		}

		$inserted = $this->database->insert(
			$this->table,
			array(
				'scope_type' => $scopeType,
				'scope_id'   => $scopeId,
				'session_id' => $sessionId,
				'actor_id'   => max(0, $actorId),
				'status'     => 'running',
				'started_at' => current_time('mysql', true),
			),
			array('%s', '%d', '%d', '%d', '%s', '%s')
		);
		if (false === $inserted) {
			throw new RuntimeException('ConfigOps could not create the restore audit record. Nothing was changed.');
		}

		return (int) $this->database->insert_id;
	}

	public function succeed(int $id, int $restoredOptionCount): void
	{
		$this->finish($id, 'succeeded', $restoredOptionCount, null);
	}

	public function fail(int $id, string $status, string $failureCode): void
	{
		$status = in_array($status, array('failed', 'compensated', 'compensation_failed'), true) ? $status : 'failed';
		$failureCode = substr(sanitize_key($failureCode), 0, 64);
		$this->finish($id, $status, 0, '' === $failureCode ? 'restore_failed' : $failureCode);
	}

	public function find(int $id): ?object
	{
		$row = $this->database->get_row(
			$this->database->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id)
		);

		return is_object($row) ? $row : null;
	}

	/**
	 * @return list<object>
	 */
	public function forSession(int $sessionId, int $limit = 20): array
	{
		$limit = max(1, min(100, $limit));
		$rows = $this->database->get_results(
			$this->database->prepare(
				"SELECT * FROM {$this->table} WHERE session_id = %d ORDER BY id DESC LIMIT %d",
				$sessionId,
				$limit
			)
		);

		return is_array($rows) ? $rows : array();
	}

	public function latestSessionRun(int $sessionId): ?object
	{
		$row = $this->database->get_row(
			$this->database->prepare(
				"SELECT * FROM {$this->table}
				WHERE session_id = %d
					AND scope_type = 'session'
					AND status IN ('succeeded', 'running', 'compensation_failed')
				ORDER BY id DESC LIMIT 1",
				$sessionId
			)
		);

		return is_object($row) ? $row : null;
	}

	/**
	 * @param list<int> $mutationIds Mutation IDs on the current response page.
	 * @return array<int, object> Latest run keyed by mutation ID.
	 */
	public function latestMutationRuns(array $mutationIds): array
	{
		$mutationIds = array_values(array_unique(array_filter(array_map('absint', $mutationIds))));
		if (empty($mutationIds)) {
			return array();
		}

		$placeholders = implode(', ', array_fill(0, count($mutationIds), '%d'));
		$rows = $this->database->get_results(
			$this->database->prepare(
				"SELECT * FROM {$this->table}
				WHERE scope_type = 'mutation'
					AND status IN ('succeeded', 'running', 'compensation_failed')
					AND scope_id IN ({$placeholders})
				ORDER BY id DESC",
				...$mutationIds
			)
		);

		$latest = array();
		foreach (is_array($rows) ? $rows : array() as $row) {
			$scopeId = (int) $row->scope_id;
			$latest[$scopeId] ??= $row;
		}

		return $latest;
	}

	public function blockingMutationCountForSession(int $sessionId): int
	{
		return (int) $this->database->get_var(
			$this->database->prepare(
				"SELECT COUNT(DISTINCT scope_id) FROM {$this->table}
				WHERE session_id = %d
					AND scope_type = 'mutation'
					AND status IN ('succeeded', 'running', 'compensation_failed')",
				$sessionId
			)
		);
	}

	private function finish(int $id, string $status, int $restoredOptionCount, ?string $failureCode): void
	{
		$updated = $this->database->update(
			$this->table,
			array(
				'status'                => $status,
				'restored_option_count' => max(0, $restoredOptionCount),
				'failure_code'          => $failureCode,
				'finished_at'           => current_time('mysql', true),
			),
			array('id' => $id, 'status' => 'running'),
			array('%s', '%d', '%s', '%s'),
			array('%d', '%s')
		);
		if (1 !== $updated) {
			throw new RuntimeException('ConfigOps could not finalize the restore audit record.');
		}
	}
}
