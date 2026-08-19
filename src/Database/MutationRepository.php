<?php
/**
 * Captured mutation persistence.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Database;

use ConfigOps\Multisite\EvidenceScope;
use Generator;
use RuntimeException;
use wpdb;

final class MutationRepository
{
	private string $table;
	private readonly StorageContext $storage;

	public function __construct(private readonly wpdb $database, ?EvidenceScope $evidenceScope = null)
	{
		$this->storage = new StorageContext($this->database, $evidenceScope);
		$this->table   = $this->storage->table('configops_mutations');
	}

	/**
	 * @param array<string, int|string|null> $mutation Mutation data.
	 */
	public function insert(array $mutation): int
	{
		$inserted = $this->database->insert($this->table, $this->storage->row($mutation));
		if (false === $inserted) {
			throw new RuntimeException('The configuration mutation could not be stored.');
		}

		return (int) $this->database->insert_id;
	}

	/**
	 * Replace one request-local aggregate with its newer final state.
	 *
	 * @param array<string, int|string|null> $mutation Mutation data.
	 */
	public function update(int $id, array $mutation): void
	{
		$updated = $this->database->update(
			$this->table,
			$this->storage->row($mutation),
			$this->storage->where(array('id' => $id))
		);
		if (false === $updated || (0 === $updated && null === $this->find($id))) {
			throw new RuntimeException('The aggregated configuration mutation could not be updated.');
		}
	}

	public function delete(int $id): void
	{
		$deleted = $this->database->delete(
			$this->table,
			$this->storage->where(array('id' => $id)),
			$this->storage->whereFormats(array('%d'))
		);
		if (1 !== $deleted) {
			throw new RuntimeException('The reverted configuration mutation could not be removed.');
		}
	}

	public function find(int $id): ?object
	{
		$row = $this->database->get_row(
			$this->storage->prepare(
				"SELECT * FROM {$this->table} WHERE {$this->storage->clause()} AND id = %d",
				$id
			)
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
		$rows   = $this->database->get_results(
			$this->storage->prepare(
				"SELECT * FROM {$this->table} WHERE {$this->storage->clause()} AND session_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
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
			$this->storage->prepare(
				"SELECT * FROM {$this->table}
				WHERE {$this->storage->clause()} AND session_id = %d AND id > %d
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
		yield from $this->iterateBatches(
			$batchSize,
			fn (int $lastId, int $limit): ?array => $this->database->get_results(
				$this->storage->prepare(
					"SELECT * FROM {$this->table} WHERE {$this->storage->clause()} AND session_id = %d AND id > %d ORDER BY id ASC LIMIT %d",
					$sessionId,
					$lastId,
					$limit
				)
			)
		);
	}

	/**
	 * Iterate only the fields required for restore planning.
	 *
	 * @return Generator<int, object>
	 */
	public function iterateRestoreForSession(int $sessionId, int $batchSize = 500): Generator
	{
		yield from $this->iterateBatches(
			$batchSize,
			fn (int $lastId, int $limit): ?array => $this->database->get_results(
				$this->storage->prepare(
					"SELECT id, option_name, old_value, new_value, diff, old_autoload, new_autoload, restorable, restore_mode, classification, adapter_id, adapter_schema_version
					FROM {$this->table}
					WHERE {$this->storage->clause()} AND session_id = %d AND id > %d AND classification <> 'derived'
					ORDER BY id ASC
					LIMIT %d",
					$sessionId,
					$lastId,
					$limit
				)
			)
		);
	}

	/**
	 * @param callable(int, int): array<int, object>|null $loadBatch Keyset page loader.
	 * @return Generator<int, object>
	 */
	private function iterateBatches(int $batchSize, callable $loadBatch): Generator
	{
		$batchSize = max(1, min(1000, $batchSize));
		$lastId    = 0;

		do {
			$rows = $loadBatch($lastId, $batchSize);
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
			$this->storage->prepare(
				"SELECT
					COUNT(*) AS mutation_total,
					COALESCE(SUM(review_change_count), 0) AS total,
					COALESCE(SUM(technical_change_count), 0) AS derived,
					COALESCE(SUM(secret_change_count), 0) AS redacted,
					COALESCE(SUM(CASE WHEN classification <> 'derived' AND (restorable <> 1 OR restore_mode <> 'full') THEN 1 ELSE 0 END), 0) AS not_restorable
				FROM {$this->table}
				WHERE {$this->storage->clause()} AND session_id = %d",
				$sessionId
			)
		);

		$reviewCount    = is_object($row) ? (int) $row->total : 0;
		$technicalCount = is_object($row) ? (int) $row->derived : 0;
		$mutationTotal  = is_object($row) ? (int) $row->mutation_total : 0;
		// Schema v4 rows did not persist field counts. Preserve a usable summary
		// after upgrade instead of presenting old captures as empty.
		if ($mutationTotal > 0 && 0 === $reviewCount + $technicalCount) {
			$technicalCount = (int) $this->database->get_var(
				$this->storage->prepare(
					"SELECT COUNT(*) FROM {$this->table} WHERE {$this->storage->clause()} AND session_id = %d AND classification = 'derived'",
					$sessionId
				)
			);
			$reviewCount = $mutationTotal - $technicalCount;
		}

		return array(
			'total'          => $reviewCount + $technicalCount,
			'derived'        => $technicalCount,
			'redacted'       => is_object($row) ? (int) $row->redacted : 0,
			'not_restorable' => is_object($row) ? (int) $row->not_restorable : 0,
		);
	}
}
