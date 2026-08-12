<?php
/**
 * Stable, transport-neutral payloads for the wp-admin shell and Agent API.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Admin;

use ConfigOps\Database\CaptureRepository;
use ConfigOps\Database\MutationRepository;

final class AdminPayloadFactory
{
	private const PAGE_SIZE = 25;
	private const MAX_PAGE_BYTES = 524288;

	public function __construct(
		private readonly CaptureRepository $captures,
		private readonly MutationRepository $mutations,
		private readonly ReviewPresenter $presenter
	) {
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
		$selected = null;

		if (null !== $requestedSessionId && $requestedSessionId > 0) {
			$selected = $this->captures->find($requestedSessionId);
		}
		if (! $selected && $active) {
			$selected = $active;
		}
		if (! $selected && ! empty($sessions)) {
			$selected = $sessions[0];
		}

		$review = $selected
			? (
				$includeReview
					? $this->reviewPayload((int) $selected->id, 0, self::PAGE_SIZE)
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

		return $this->reviewPayload($sessionId, $afterId, $limit);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function reviewPayload(int $sessionId, int $afterId, int $limit): array
	{
		$summary = $this->mutations->summaryForSession($sessionId);
		$rows    = $this->mutations->forSessionAfter($sessionId, $afterId, $limit);
		$hasMore = count($rows) > $limit;
		if ($hasMore) {
			array_pop($rows);
		}

		$view   = $this->presenter->present($rows, $summary, '', '');
		$groups = array_map($this->groupPayload(...), $view->groups);
		$lastAvailable = empty($rows) ? $afterId : (int) $rows[array_key_last($rows)]->id;
		[$groups, $last] = $this->fitGroupsToResponseBudget($groups, $afterId);
		$hasMore = $hasMore || $last < $lastAvailable;

		return array(
			'summary'  => array(
				'total'         => $view->totalCount,
				'needsReview'   => $view->needsReviewCount,
				'derived'       => $view->derivedCount,
				'redacted'      => $view->redactedCount,
				'allRestorable' => $view->allRestorable,
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
		$head = $group['head'];

		return array(
			'index'      => $group['index'],
			'requestId'  => $group['request_id'],
			'head'       => array(
				'adminScreen' => (string) $head->admin_screen,
				'requestUri'  => (string) $head->request_uri,
				'method'      => (string) $head->request_method,
				'occurredAt'  => $this->isoDate((string) $head->occurred_at),
				'timeLabel'   => get_date_from_gmt((string) $head->occurred_at, 'H:i:s'),
			),
			'mutations'  => array_map(
				function (array $prepared): array {
					$mutation = $prepared['mutation'];

					return array(
						'id'                   => (int) $mutation->id,
						'type'                 => (string) $mutation->mutation_type,
						'optionName'           => (string) $mutation->option_name,
						'classification'       => (string) $mutation->classification,
						'classificationLabel'  => $prepared['classification_label'],
						'classificationReason' => (string) $mutation->classification_reason,
						'restorable'           => 1 === (int) $mutation->restorable,
						'redacted'              => 1 === (int) $mutation->is_redacted,
						'diff'                  => $prepared['diff'],
						'source'                => array(
							'type'      => (string) $mutation->source_type,
							'component' => (string) $mutation->source_component,
							'file'      => (string) $mutation->source_file,
							'line'      => (int) $mutation->source_line,
						),
					);
				},
				$group['mutations']
			),
		);
	}

	/**
	 * @return array<string, int|string|null>
	 */
	private function sessionPayload(object $session, bool $withActor = false): array
	{
		$actor = $withActor ? get_userdata((int) $session->actor_id) : null;

		return array(
			'id'             => (int) $session->id,
			'name'           => (string) $session->name,
			'status'         => (string) $session->status,
			'mutationCount'  => (int) $session->mutation_count,
			'startedAt'      => $this->isoDate((string) $session->started_at),
			'startedAtLabel' => human_time_diff(strtotime((string) $session->started_at . ' UTC'), time()),
			'startedDisplay' => $withActor ? get_date_from_gmt((string) $session->started_at, 'Y-m-d H:i:s') : null,
			'actorName'      => $withActor ? ($actor ? (string) $actor->display_name : __('System', 'configops')) : null,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function emptyReview(bool $deferred = false): array
	{
		return array(
			'summary'  => array(
				'total'         => 0,
				'needsReview'   => 0,
				'derived'       => 0,
				'redacted'      => 0,
				'allRestorable' => true,
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
}
