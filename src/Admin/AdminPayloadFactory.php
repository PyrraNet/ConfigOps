<?php
/**
 * Stable, transport-neutral payloads for the wp-admin shell and Agent API.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Admin;

use ConfigOps\Adapter\AdapterRegistry;
use ConfigOps\Database\CaptureRepository;
use ConfigOps\Database\DatabaseWriteSignalRepository;
use ConfigOps\Database\MutationRepository;
use ConfigOps\Database\RestoreAuditRepository;
use ConfigOps\Maintenance\HistoryRetention;

final class AdminPayloadFactory
{
	private const PAGE_SIZE = 25;
	private const MAX_PAGE_BYTES = 524288;

	public function __construct(
		private readonly CaptureRepository $captures,
		private readonly MutationRepository $mutations,
		private readonly DatabaseWriteSignalRepository $writeSignals,
		private readonly ReviewPresenter $presenter,
		private readonly AdapterRegistry $adapters,
		private readonly RestoreAuditRepository $restoreAudits
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function support(): array
	{
		return array(
			'adapters' => $this->adapters->supportPayload(),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function state(
		?int $requestedSessionId = null,
		string $noticeCode = '',
		string $noticeMessage = '',
		bool $includeReview = true
	): array {
		$active   = $this->captures->activeSession();
		$sessions = $this->captures->recent();
		$selected = null !== $requestedSessionId && $requestedSessionId > 0
			? $this->captures->find($requestedSessionId)
			: null;
		$selected ??= $active;
		$selected ??= $sessions[0] ?? null;

		$review = $selected
			? (
				$includeReview
					? $this->reviewPayload((int) $selected->id, 0, self::PAGE_SIZE, $selected)
					: $this->emptyReview(true)
			)
			: $this->emptyReview();

		return array(
			'active'       => $active ? $this->sessionPayload($active) : null,
			'sessions'     => array_map($this->sessionPayload(...), $sessions),
			'selected'     => $selected ? $this->sessionPayload($selected, true) : null,
			'review'       => $review,
			'capabilities' => array(
				'capture'  => current_user_can('configops_capture'),
				'rollback' => current_user_can('configops_rollback'),
			),
			'notice'       => array(
				'code' => sanitize_key($noticeCode),
				'kind' => 'error' === $noticeCode ? 'error' : 'success',
				'text' => $this->presenter->noticeText($noticeCode, $noticeMessage),
			),
			'retentionDays' => HistoryRetention::retentionDays(),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function mutationPage(int $sessionId, int $afterId = 0, int $limit = self::PAGE_SIZE): array
	{
		$session = $this->captures->find($sessionId);
		if (! $session) {
			return $this->emptyReview();
		}

		return $this->reviewPayload($sessionId, $afterId, $limit, $session);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function reviewPayload(int $sessionId, int $afterId, int $limit, object $session): array
	{
		$summary = $this->mutations->summaryForSession($sessionId);
		$rows    = $this->mutations->forSessionAfter($sessionId, $afterId, $limit);
		$hasMore = count($rows) > $limit;
		if ($hasMore) {
			array_pop($rows);
		}

		$view          = $this->presenter->present($rows, $summary, '', '');
		$groups        = array_map($this->groupPayload(...), $view->groups);
		$mutationAuditMap = $this->restoreAudits->latestMutationRuns(array_map(static fn (object $row): int => (int) $row->id, $rows));
		foreach ($groups as &$group) {
			foreach ($group['mutations'] as &$mutation) {
				$run = $mutationAuditMap[(int) $mutation['id']] ?? null;
				$mutation['lastRestore'] = $run ? $this->restoreAuditPayload($run) : null;
			}
			unset($mutation);
		}
		unset($group);
		$lastAvailable = empty($rows) ? $afterId : (int) $rows[array_key_last($rows)]->id;
		[$groups, $last] = $this->fitGroupsToResponseBudget($groups, $afterId);
		$hasMore = $hasMore || $last < $lastAvailable;
		$signalCount = $this->writeSignals->occurrenceCountForSession($sessionId);
		if (0 === $afterId) {
			$groups = $this->attachWriteSignals($groups, $this->writeSignals->forSession($sessionId));
		}

		$captureErrorCount = $this->captureErrorCount($session);
		$lastSessionRestore = $this->restoreAudits->latestSessionRun($sessionId);
		$blockingMutationRestores = $this->restoreAudits->blockingMutationCountForSession($sessionId);
		$sessionRestoreBlocksRetry = $lastSessionRestore && in_array(
			(string) $lastSessionRestore->status,
			array('succeeded', 'running', 'compensation_failed'),
			true
		);

		return array(
			'summary'  => array(
				'total'           => $view->totalCount,
				'needsReview'     => $view->needsReviewCount,
				'derived'         => $view->derivedCount,
				'redacted'        => $view->redactedCount,
				'unmanagedWrites' => $signalCount,
				'captureErrors'    => $captureErrorCount,
				'individuallyUndone' => $blockingMutationRestores,
				'lastSessionRestore' => $lastSessionRestore ? $this->restoreAuditPayload($lastSessionRestore) : null,
				'allRestorable'   => $view->allRestorable
					&& 0 === $signalCount
					&& 0 === $captureErrorCount
					&& 0 === $blockingMutationRestores
					&& ! $sessionRestoreBlocksRetry,
			),
			'groups'   => $groups,
			'pageInfo' => array(
				'hasNext'    => $hasMore,
				'nextCursor' => $hasMore ? $last : null,
			),
			'deferred' => false,
		);
	}

	/**
	 * @param list<array<string, mixed>> $groups Prepared request groups.
	 * @return array{0: list<array<string, mixed>>, 1: int}
	 */
	private function fitGroupsToResponseBudget(array $groups, int $afterId): array
	{
		$fitted = array();
		$bytes  = 0;
		$lastId = $afterId;
		$stop   = false;

		foreach ($groups as $group) {
			$fittedMutations = array();
			foreach ($group['mutations'] as $mutation) {
				$encoded = wp_json_encode($mutation, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
				$cost    = is_string($encoded) ? strlen($encoded) : self::MAX_PAGE_BYTES;
				if ($bytes + $cost > self::MAX_PAGE_BYTES && $lastId !== $afterId) {
					$stop = true;
					break;
				}

				$fittedMutations[] = $mutation;
				$bytes             += $cost;
				$lastId            = (int) $mutation['id'];
			}

			if (! empty($fittedMutations)) {
				$group['mutations'] = $fittedMutations;
				$fitted[]           = $group;
			}
			if ($stop) {
				break;
			}
		}

		return array($fitted, $lastId);
	}

	/**
	 * @param array{index: string, request_id: string, head: object, mutations: list<array{mutation: object, diff: list<array<string, mixed>>, classification_label: string}>} $group Group.
	 * @return array<string, mixed>
	 */
	private function groupPayload(array $group): array
	{
		return array(
			'index'      => $group['index'],
			'requestId'  => $group['request_id'],
			'title'      => (string) ($group['title'] ?? ''),
			'head'       => $this->requestHead($group['head']),
			'mutations'  => array_map(
				function (array $prepared): array {
					$mutation = $prepared['mutation'];
					$diff = $prepared['diff'];
					$technicalCount = count(
						array_filter(
							$diff,
							static fn (array $change): bool => 'derived' === (string) $mutation->classification
								|| 'runtime' === (string) ($change['kind'] ?? '')
						)
					);
					$secretCount = (int) ($mutation->secret_change_count ?? 0);
					$restoreMode = (string) ($mutation->restore_mode ?? (1 === (int) $mutation->restorable ? 'full' : 'none'));

					return array(
						'id'                   => (int) $mutation->id,
						'type'                 => (string) $mutation->mutation_type,
						'optionName'           => (string) $mutation->option_name,
						'classification'       => (string) $mutation->classification,
						'classificationLabel'  => $prepared['classification_label'],
						'classificationReason' => (string) $mutation->classification_reason,
						'restorable'           => 1 === (int) $mutation->restorable && 'derived' !== (string) $mutation->classification,
						'redacted'              => $secretCount > 0,
						'containsProtectedData' => 1 === (int) $mutation->is_redacted,
						'restoreMode'           => in_array($restoreMode, array('full', 'patch'), true) ? $restoreMode : 'none',
						'changeCounts'          => array(
							'settings'  => count($diff) - $technicalCount,
							'technical' => $technicalCount,
							'secrets'   => $secretCount,
							'safeUndo'  => (int) ($mutation->safe_restore_change_count ?? 0),
						),
						'diff'                  => $diff,
						'adapter'               => $prepared['adapter'],
						'displayName'           => (string) ($prepared['diff'][0]['label'] ?? ''),
						'source'                => $this->sourcePayload($mutation),
					);
				},
				$group['mutations']
			),
			'writeSignals' => array(),
		);
	}

	/**
	 * @param list<array<string, mixed>> $groups Mutation request groups.
	 * @param list<object> $signals Value-free database write signals.
	 * @return list<array<string, mixed>>
	 */
	private function attachWriteSignals(array $groups, array $signals): array
	{
		$groupIndexes = array();
		foreach ($groups as $index => $group) {
			$groupIndexes[(string) $group['requestId']] = $index;
		}

		foreach ($signals as $signal) {
			$requestId = (string) $signal->request_id;
			if (! isset($groupIndexes[$requestId])) {
				$groupIndexes[$requestId] = count($groups);
				$groups[] = array(
					'index'        => '',
					'requestId'    => $requestId,
					'head'         => $this->requestHead($signal),
					'mutations'    => array(),
					'writeSignals' => array(),
				);
			}

			$groups[$groupIndexes[$requestId]]['writeSignals'][] = array(
				'id'              => (int) $signal->id,
				'operation'       => strtoupper((string) $signal->operation),
				'table'           => (string) $signal->table_name,
				'occurrenceCount' => (int) $signal->occurrence_count,
				'source'          => $this->sourcePayload($signal),
			);
		}

		foreach ($groups as $index => &$group) {
			$group['index'] = str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
		}
		unset($group);

		return $groups;
	}

	/**
	 * @return array<string, string>
	 */
	private function requestHead(object $row): array
	{
		return array(
			'adminScreen' => (string) $row->admin_screen,
			'requestUri'  => (string) $row->request_uri,
			'method'      => (string) $row->request_method,
			'occurredAt'  => $this->isoDate((string) $row->occurred_at),
			'timeLabel'   => get_date_from_gmt((string) $row->occurred_at, 'H:i:s'),
		);
	}

	/**
	 * @return array{type: string, component: string, file: string, line: int}
	 */
	private function sourcePayload(object $row): array
	{
		return array(
			'type'      => (string) $row->source_type,
			'component' => (string) $row->source_component,
			'file'      => (string) $row->source_file,
			'line'      => (int) $row->source_line,
		);
	}

	/**
	 * @return array<string, int|string|null>
	 */
	private function sessionPayload(object $session, bool $withActor = false): array
	{
		$actor = $withActor ? get_userdata((int) $session->actor_id) : null;
		$mutationCount = (int) $session->mutation_count;
		$reviewChangeCount = (int) ($session->review_change_count ?? 0);
		$technicalChangeCount = (int) ($session->technical_change_count ?? 0);
		if ($mutationCount > 0 && 0 === $reviewChangeCount + $technicalChangeCount) {
			$reviewChangeCount = $mutationCount;
		}

		return array(
			'id'               => (int) $session->id,
			'name'             => (string) $session->name,
			'status'           => (string) $session->status,
			'mutationCount'    => $mutationCount,
			'reviewChangeCount' => $reviewChangeCount,
			'technicalChangeCount' => $technicalChangeCount,
			'writeSignalCount' => (int) ($session->write_signal_count ?? 0),
			'captureErrorCount' => $this->captureErrorCount($session),
			'startedAt'        => $this->isoDate((string) $session->started_at),
			'startedAtLabel'   => human_time_diff(strtotime((string) $session->started_at . ' UTC'), time()),
			'startedDisplay'   => $withActor ? get_date_from_gmt((string) $session->started_at, 'Y-m-d H:i:s') : null,
			'actorName'        => $withActor ? ($actor ? (string) $actor->display_name : __('System', 'configops')) : null,
		);
	}

	private function captureErrorCount(object $session): int
	{
		$count = (int) ($session->capture_error_count ?? 0);

		return 'stopping' === (string) ($session->status ?? '') ? max(1, $count) : $count;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function emptyReview(bool $deferred = false): array
	{
		return array(
			'summary'  => array(
				'total'           => 0,
				'needsReview'     => 0,
				'derived'         => 0,
				'redacted'        => 0,
				'unmanagedWrites' => 0,
				'captureErrors'    => 0,
				'individuallyUndone' => 0,
				'lastSessionRestore' => null,
				'allRestorable'   => true,
			),
			'groups'   => array(),
			'pageInfo' => array('hasNext' => false, 'nextCursor' => null),
			'deferred' => $deferred,
		);
	}

	private function isoDate(string $mysqlUtc): string
	{
		$timestamp = strtotime($mysqlUtc . ' UTC');

		return false === $timestamp ? '' : gmdate(DATE_ATOM, $timestamp);
	}

	/**
	 * @return array<string, int|string|null>
	 */
	private function restoreAuditPayload(object $run): array
	{
		$actor = get_userdata((int) $run->actor_id);
		$finishedAt = (string) ($run->finished_at ?? '');

		return array(
			'id'                  => (int) $run->id,
			'status'              => (string) $run->status,
			'restoredOptionCount' => (int) $run->restored_option_count,
			'failureCode'         => (string) ($run->failure_code ?? ''),
			'actorName'           => $actor ? (string) $actor->display_name : __('System', 'configops'),
			'finishedAt'          => '' !== $finishedAt ? $this->isoDate($finishedAt) : null,
			'finishedAtLabel'     => '' !== $finishedAt ? get_date_from_gmt($finishedAt, 'Y-m-d H:i:s') : null,
		);
	}
}
