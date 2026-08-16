<?php
/**
 * Short-lived pointers to automatic evidence awaiting operator feedback.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Admin;

final class EvidenceNoticeStore
{
	private const OPTION_PREFIX = 'configops_pending_evidence_';
	private const MAX_ITEMS = 5;
	private const MAX_AGE = 600;

	public function push(int $actorId, int $sessionId): void
	{
		if ($actorId <= 0 || $sessionId <= 0) {
			return;
		}

		$items = $this->read($actorId);
		$items = array_values(
			array_filter(
				$items,
				static fn (array $item): bool => $sessionId !== $item['session_id']
			)
		);
		$items[] = array('session_id' => $sessionId, 'recorded_at' => time());
		$items = array_slice($items, -self::MAX_ITEMS);

		update_option($this->optionName($actorId), $items, false);
	}

	/**
	 * @return list<int>
	 */
	public function pull(int $actorId): array
	{
		if ($actorId <= 0) {
			return array();
		}

		$option = $this->optionName($actorId);
		$items  = $this->read($actorId);
		delete_option($option);

		return array_values(array_map(static fn (array $item): int => $item['session_id'], $items));
	}

	/**
	 * @return list<array{session_id: int, recorded_at: int}>
	 */
	private function read(int $actorId): array
	{
		$stored = get_option($this->optionName($actorId), array());
		if (! is_array($stored)) {
			return array();
		}

		$minimum = time() - self::MAX_AGE;
		$items   = array();
		foreach (array_slice($stored, -self::MAX_ITEMS) as $item) {
			if (! is_array($item)) {
				continue;
			}
			$sessionId = absint($item['session_id'] ?? 0);
			$recordedAt = (int) ($item['recorded_at'] ?? 0);
			if ($sessionId <= 0 || $recordedAt < $minimum || $recordedAt > time() + 30) {
				continue;
			}
			$items[] = array('session_id' => $sessionId, 'recorded_at' => $recordedAt);
		}

		return $items;
	}

	private function optionName(int $actorId): string
	{
		return self::OPTION_PREFIX . max(0, $actorId);
	}
}
