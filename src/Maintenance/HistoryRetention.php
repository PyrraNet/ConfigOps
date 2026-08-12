<?php
/**
 * Bounded retention for completed local capture evidence.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Maintenance;

use ConfigOps\Execution\OperationLock;
use RuntimeException;
use wpdb;

final class HistoryRetention
{
	public const HOOK = 'configops_history_retention';
	private const DEFAULT_DAYS = 30;
	private const BATCH_SIZE = 100;
	private const MAX_BATCHES = 10;

	public function __construct(
		private readonly wpdb $database,
		private readonly OperationLock $operationLock
	) {
	}

	public function register(): void
	{
		add_action(self::HOOK, array($this, 'run'));
		self::schedule();
	}

	public function run(): int
	{
		return $this->operationLock->run('history-retention', function (): int {
			$deleted = 0;
			for ($batch = 0; $batch < self::MAX_BATCHES; ++$batch) {
				$batchDeleted = $this->deleteBatch();
				$deleted += $batchDeleted;
				if ($batchDeleted < self::BATCH_SIZE) {
					break;
				}
			}

			return $deleted;
		});
	}

	public static function schedule(): void
	{
		if (! wp_next_scheduled(self::HOOK)) {
			wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::HOOK);
		}
	}

	public static function unschedule(): void
	{
		wp_clear_scheduled_hook(self::HOOK);
	}

	public static function retentionDays(): int
	{
		$days = (int) apply_filters('configops_retention_days', self::DEFAULT_DAYS);

		return max(7, min(3650, $days));
	}

	private function deleteBatch(): int
	{
		$sessions = $this->database->prefix . 'configops_capture_sessions';
		$cutoff = gmdate('Y-m-d H:i:s', time() - self::retentionDays() * DAY_IN_SECONDS);
		$ids = $this->database->get_col(
			$this->database->prepare(
				"SELECT id FROM {$sessions}
				WHERE status = 'deleting'
					OR (
						status IN ('completed', 'interrupted', 'discarded')
						AND ended_at IS NOT NULL
						AND ended_at < %s
					)
				ORDER BY id ASC
				LIMIT %d",
				$cutoff,
				self::BATCH_SIZE
			)
		);
		$ids = array_values(array_filter(array_map('absint', is_array($ids) ? $ids : array())));
		if (empty($ids)) {
			return 0;
		}

		$placeholders = implode(', ', array_fill(0, count($ids), '%d'));
		$marked = $this->database->query(
			$this->database->prepare(
				"UPDATE {$sessions} SET status = 'deleting'
				WHERE id IN ({$placeholders})
					AND status IN ('completed', 'interrupted', 'discarded', 'deleting')",
				...$ids
			)
		);
		if (false === $marked) {
			throw new RuntimeException('ConfigOps retention could not mark expired capture history for bounded removal.');
		}

		foreach (array('configops_restore_runs', 'configops_write_signals', 'configops_mutations') as $suffix) {
			$table = $this->database->prefix . $suffix;
			$deleted = $this->database->query(
				$this->database->prepare("DELETE FROM {$table} WHERE session_id IN ({$placeholders})", ...$ids)
			);
			if (false === $deleted) {
				throw new RuntimeException('ConfigOps retention could not remove dependent evidence. Capture history was preserved.');
			}
		}

		$deletedSessions = $this->database->query(
			$this->database->prepare(
				"DELETE FROM {$sessions} WHERE id IN ({$placeholders}) AND status = 'deleting'",
				...$ids
			)
		);
		if (false === $deletedSessions) {
			throw new RuntimeException('ConfigOps retention could not remove expired capture sessions.');
		}

		return (int) $deletedSessions;
	}
}
