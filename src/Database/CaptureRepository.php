<?php
/**
 * Capture session persistence.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Database;

use RuntimeException;
use wpdb;

final class CaptureRepository
{
	private const ACTIVE_OPTION = 'configops_active_capture_id';

	private string $table;
	private bool $activeResolved = false;
	private ?object $activeSession = null;

	public function __construct(private readonly wpdb $database)
	{
		$this->table = $this->database->prefix . 'configops_capture_sessions';
	}

	public function start(string $name, int $actorId, string $initialUrl): int
	{
		if (null !== $this->activeSession()) {
			throw new RuntimeException('A capture session is already active.');
		}

		$inserted = $this->database->insert(
			$this->table,
			array(
				'name'        => $name,
				'status'      => 'starting',
				'actor_id'    => $actorId,
				'initial_url' => $initialUrl,
				'started_at'  => current_time('mysql', true),
			),
			array('%s', '%s', '%d', '%s', '%s')
		);

		if (false === $inserted) {
			throw new RuntimeException('The capture session could not be stored.');
		}

		$id = (int) $this->database->insert_id;
		if (! add_option(self::ACTIVE_OPTION, $id, '', false)) {
			$this->markDiscarded($id);
			$this->invalidateActiveSession();

			throw new RuntimeException('Another request started a capture session first.');
		}

		$activated = $this->database->update(
			$this->table,
			array('status' => 'active'),
			array('id' => $id),
			array('%s'),
			array('%d')
		);
		if (false === $activated) {
			delete_option(self::ACTIVE_OPTION);
			$this->markDiscarded($id);
			$this->invalidateActiveSession();

			throw new RuntimeException('The capture session lock was created, but the session could not be activated.');
		}

		$this->invalidateActiveSession();
		$this->activeSession();

		return $id;
	}

	public function stop(): ?int
	{
		$id = $this->activeId();
		if (null === $id) {
			return null;
		}

		$mutationTable = $this->database->prefix . 'configops_mutations';
		$mutationSummary = $this->database->get_row(
			$this->database->prepare(
				"SELECT
					COUNT(*) AS mutation_count,
					COALESCE(SUM(review_change_count), 0) AS review_change_count,
					COALESCE(SUM(technical_change_count), 0) AS technical_change_count
				FROM {$mutationTable} WHERE session_id = %d",
				$id
			)
		);
		$mutationCount = is_object($mutationSummary) ? (int) $mutationSummary->mutation_count : 0;
		$reviewChangeCount = is_object($mutationSummary) ? (int) $mutationSummary->review_change_count : 0;
		$technicalChangeCount = is_object($mutationSummary) ? (int) $mutationSummary->technical_change_count : 0;
		// Captures started before schema v6 have option counts but no field counts.
		// Keep those histories useful instead of turning them into an empty review.
		if ($mutationCount > 0 && 0 === $reviewChangeCount + $technicalChangeCount) {
			$technicalChangeCount = (int) $this->database->get_var(
				$this->database->prepare(
					"SELECT COUNT(*) FROM {$mutationTable} WHERE session_id = %d AND classification = 'derived'",
					$id
				)
			);
			$reviewChangeCount = $mutationCount - $technicalChangeCount;
		}
		$signalTable = $this->database->prefix . 'configops_write_signals';
		$writeSignalCount = (int) $this->database->get_var(
			$this->database->prepare("SELECT COALESCE(SUM(occurrence_count), 0) FROM {$signalTable} WHERE session_id = %d", $id)
		);

		$updated = $this->database->update(
			$this->table,
			array(
				'status'             => 'completed',
				'mutation_count'     => $mutationCount,
				'review_change_count' => $reviewChangeCount,
				'technical_change_count' => $technicalChangeCount,
				'write_signal_count' => $writeSignalCount,
				'ended_at'           => current_time('mysql', true),
			),
			array('id' => $id),
			array('%s', '%d', '%d', '%d', '%d', '%s'),
			array('%d')
		);
		if (false === $updated) {
			throw new RuntimeException('The capture session could not be stopped safely.');
		}

		delete_option(self::ACTIVE_OPTION);
		$this->invalidateActiveSession();

		return $id;
	}

	public function activeId(): ?int
	{
		$session = $this->activeSession();

		return $session ? (int) $session->id : null;
	}

	public function activeSession(): ?object
	{
		$id = (int) get_option(self::ACTIVE_OPTION, 0);
		if (
			$this->activeResolved
			&& $this->activeSession
			&& $id === (int) $this->activeSession->id
		) {
			return $this->activeSession;
		}

		$this->invalidateActiveSession();
		if ($id <= 0) {
			// Do not memoize absence: another integration may start a capture later in this request.
			return null;
		}

		$this->activeResolved = true;

		$session = $this->find($id);
		if (! $session || ! in_array((string) $session->status, array('active', 'starting'), true)) {
			delete_option(self::ACTIVE_OPTION);

			return null;
		}

		if ('starting' === (string) $session->status) {
			$recovered = $this->database->update(
				$this->table,
				array('status' => 'active'),
				array('id' => $id),
				array('%s'),
				array('%d')
			);
			if (false === $recovered) {
				delete_option(self::ACTIVE_OPTION);
				$this->markDiscarded($id);

				return null;
			}
			$session->status = 'active';
		}

		$this->activeSession = $session;

		return $this->activeSession;
	}

	public function incrementMutationCount(int $sessionId, int $reviewChanges = 1, int $technicalChanges = 0): void
	{
		$reviewChanges    = max(0, $reviewChanges);
		$technicalChanges = max(0, $technicalChanges);
		$updated = $this->database->query(
			$this->database->prepare(
				"UPDATE {$this->table}
				SET mutation_count = mutation_count + 1,
					review_change_count = review_change_count + %d,
					technical_change_count = technical_change_count + %d
				WHERE id = %d",
				$reviewChanges,
				$technicalChanges,
				$sessionId
			)
		);

		if (false === $updated) {
			throw new RuntimeException('The capture mutation counter could not be updated.');
		}

		if ($this->activeSession && (int) $this->activeSession->id === $sessionId) {
			++$this->activeSession->mutation_count;
			$this->activeSession->review_change_count += $reviewChanges;
			$this->activeSession->technical_change_count += $technicalChanges;
		}
	}

	public function incrementWriteSignalCount(int $sessionId): void
	{
		$updated = $this->database->query(
			$this->database->prepare(
				"UPDATE {$this->table} SET write_signal_count = write_signal_count + 1 WHERE id = %d",
				$sessionId
			)
		);
		if (false === $updated) {
			throw new RuntimeException('The database write signal counter could not be updated.');
		}

		if ($this->activeSession && (int) $this->activeSession->id === $sessionId) {
			++$this->activeSession->write_signal_count;
		}
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
	public function recent(int $limit = 20): array
	{
		$limit = max(1, min(100, $limit));
		$rows  = $this->database->get_results(
			$this->database->prepare(
				"SELECT * FROM {$this->table} WHERE status <> 'discarded' ORDER BY started_at DESC, id DESC LIMIT %d",
				$limit
			)
		);

		return is_array($rows) ? $rows : array();
	}

	private function markDiscarded(int $id): void
	{
		$this->database->update(
			$this->table,
			array(
				'status'   => 'discarded',
				'ended_at' => current_time('mysql', true),
			),
			array('id' => $id),
			array('%s', '%s'),
			array('%d')
		);
	}

	private function invalidateActiveSession(): void
	{
		$this->activeResolved = false;
		$this->activeSession  = null;
	}
}
