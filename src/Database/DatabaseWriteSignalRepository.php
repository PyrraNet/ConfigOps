<?php
/**
 * Persistence for bounded, value-free database write signals.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Database;

use ConfigOps\Multisite\EvidenceScope;
use RuntimeException;
use wpdb;

final class DatabaseWriteSignalRepository
{
	private string $table;
	private readonly StorageContext $storage;

	public function __construct(private readonly wpdb $database, ?EvidenceScope $evidenceScope = null)
	{
		$this->storage = new StorageContext($this->database, $evidenceScope);
		$this->table   = $this->storage->table('configops_write_signals');
	}

	/**
	 * @param array<string, int|string|null> $signal Value-free signal data.
	 */
	public function insert(array $signal): int
	{
		$inserted = $this->database->insert($this->table, $this->storage->row($signal));
		if (false === $inserted) {
			throw new RuntimeException('The database write signal could not be stored.');
		}

		return (int) $this->database->insert_id;
	}

	public function incrementOccurrence(int $id): void
	{
		$updated = $this->database->query(
			$this->storage->prepare(
				"UPDATE {$this->table} SET occurrence_count = occurrence_count + 1 WHERE {$this->storage->clause()} AND id = %d",
				$id
			)
		);
		if (false === $updated) {
			throw new RuntimeException('The database write signal occurrence could not be updated.');
		}
	}

	/**
	 * @return list<object>
	 */
	public function forSession(int $sessionId, int $limit = 100): array
	{
		$limit = max(1, min(100, $limit));
		$rows  = $this->database->get_results(
			$this->storage->prepare(
				"SELECT * FROM {$this->table} WHERE {$this->storage->clause()} AND session_id = %d ORDER BY id ASC LIMIT %d",
				$sessionId,
				$limit
			)
		);

		return is_array($rows) ? $rows : array();
	}

	public function occurrenceCountForSession(int $sessionId): int
	{
		return (int) $this->database->get_var(
			$this->storage->prepare(
				"SELECT COALESCE(SUM(occurrence_count), 0) FROM {$this->table} WHERE {$this->storage->clause()} AND session_id = %d",
				$sessionId
			)
		);
	}
}
