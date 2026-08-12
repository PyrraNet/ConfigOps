<?php
/**
 * Captured mutation persistence.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Database;

use Generator;
use RuntimeException;
use wpdb;

final class MutationRepository
{
	private string $table;

	public function __construct(private readonly wpdb $database)
	{
		$this->table = $this->database->prefix . 'configops_mutations';
	}

	/**
	 * @param array<string, int|string|null> $mutation Mutation data.
	 */
	public function insert(array $mutation): int
	{
		$inserted = $this->database->insert($this->table, $mutation);
		if (false === $inserted) {
			throw new RuntimeException('The configuration mutation could not be stored.');
		}

		return (int) $this->database->insert_id;
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
	public function forSession(int $sessionId, int $limit = 100, int $offset = 0): array
	{
		$limit  = max(1, min(1000, $limit));
		$offset = max(0, $offset);
		$rows      = $this->database->get_results(
			$this->database->prepare(
				"SELECT * FROM {$this->table} WHERE session_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
				$sessionId,
				$limit,
				$offset
			)
		);

		return is_array($rows) ? $rows : array();
	}

	/**
	 * Read a stable page without offset scans. One extra row is returned so callers
	 * can expose an honest continuation cursor.
	 *
	 * @return list<object>
	 */
	public function forSessionAfter(int $sessionId, int $afterId = 0, int $limit = 100): array
	{
		$afterId = max(0, $afterId);
		$limit   = max(1, min(100, $limit));
		$rows    = $this->database->get_results(
			$this->database->prepare(
				"SELECT * FROM {$this->table}
				WHERE session_id = %d AND id > %d
				ORDER BY id ASC
				LIMIT %d",
				$sessionId,
				$afterId,
				$limit + 1
			)
		);

		return is_array($rows) ? $rows : array();
	}

	/**
	 * Iterate without retaining every mutation payload in memory.
	 *
	 * @return Generator<int, object>
	 */
	public function iterateForSession(int $sessionId, int $batchSize = 500): Generator
	{
		$batchSize = max(1, min(1000, $batchSize));
		$lastId    = 0;

		do {
			$rows = $this->database->get_results(
				$this->database->prepare(
					"SELECT * FROM {$this->table} WHERE session_id = %d AND id > %d ORDER BY id ASC LIMIT %d",
					$sessionId,
					$lastId,
					$batchSize
				)
			);
			$rows = is_array($rows) ? $rows : array();
			foreach ($rows as $row) {
				$lastId = (int) $row->id;
				yield $row;
			}
		} while (count($rows) === $batchSize);
	}

	/**
	 * Iterate only the fields required for restore planning.
	 *
	 * @return Generator<int, object>
	 */
	public function iterateRestoreForSession(int $sessionId, int $batchSize = 500): Generator
	{
		$batchSize = max(1, min(1000, $batchSize));
		$lastId    = 0;

		do {
			$rows = $this->database->get_results(
				$this->database->prepare(
					"SELECT id, option_name, old_value, new_value, old_autoload, new_autoload, restorable
					FROM {$this->table}
					WHERE session_id = %d AND id > %d AND classification <> 'derived'
					ORDER BY id ASC
					LIMIT %d",
					$sessionId,
					$lastId,
					$batchSize
				)
			);
			$rows = is_array($rows) ? $rows : array();
			foreach ($rows as $row) {
				$lastId = (int) $row->id;
				yield $row;
			}
		} while (count($rows) === $batchSize);
	}

	/**
	 * @return array{total: int, derived: int, redacted: int, not_restorable: int}
	 */
	public function summaryForSession(int $sessionId): array
	{
		$row = $this->database->get_row(
			$this->database->prepare(
				"SELECT
					COUNT(*) AS total,
					COALESCE(SUM(CASE WHEN classification = 'derived' THEN 1 ELSE 0 END), 0) AS derived,
					COALESCE(SUM(CASE WHEN is_redacted = 1 THEN 1 ELSE 0 END), 0) AS redacted,
					COALESCE(SUM(CASE WHEN classification <> 'derived' AND restorable <> 1 THEN 1 ELSE 0 END), 0) AS not_restorable
				FROM {$this->table}
				WHERE session_id = %d",
				$sessionId
			)
		);

		return array(
			'total'          => is_object($row) ? (int) $row->total : 0,
			'derived'        => is_object($row) ? (int) $row->derived : 0,
			'redacted'       => is_object($row) ? (int) $row->redacted : 0,
			'not_restorable' => is_object($row) ? (int) $row->not_restorable : 0,
		);
	}
}
