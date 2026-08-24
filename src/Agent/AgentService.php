<?php
/**
 * Stable, value-bounded operations for automation transports.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Agent;

use ConfigOps\Admin\AdminPayloadFactory;
use ConfigOps\Command\CaptureCommands;
use ConfigOps\Database\CaptureRepository;
use ConfigOps\Database\MutationRepository;
use ConfigOps\Multisite\SiteBoundaryGuard;
use ConfigOps\Restore\RestoreService;

final readonly class AgentService
{
	public const SCHEMA_VERSION = '1';

	public function __construct(
		private CaptureRepository $captures,
		private MutationRepository $mutations,
		private CaptureCommands $commands,
		private AdminPayloadFactory $payloads,
		private RestoreService $restore,
		private SiteBoundaryGuard $siteBoundary
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function state(): array
	{
		$this->siteBoundary->assertCurrentSite();
		$active = $this->captures->activeSession();

		return $this->envelope(
			array(
				'pluginVersion' => defined('CONFIGOPS_VERSION') ? CONFIGOPS_VERSION : '',
				'activeCapture' => $active ? $this->capturePayload($active) : null,
				'capabilities'  => array(
					'view'         => current_user_can('configops_view'),
					'capture'      => current_user_can('configops_capture'),
					'planRestore'  => current_user_can('configops_plan'),
					'applyRestore' => false,
				),
				'limits'        => array(
					'capturePageSize'  => 50,
					'mutationPageSize' => AdminPayloadFactory::PAGE_SIZE,
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input Validated transport input.
	 * @return array<string, mixed>
	 */
	public function captures(array $input = array()): array
	{
		$this->siteBoundary->assertCurrentSite();
		$limit = max(1, min(50, (int) ($input['limit'] ?? 20)));
		$rows = $this->captures->recent($limit + 1);
		$hasMore = count($rows) > $limit;
		if ($hasMore) {
			array_pop($rows);
		}

		return $this->envelope(
			array(
				'items'   => array_map($this->capturePayload(...), $rows),
				'hasMore' => $hasMore,
				'limit'   => $limit,
			)
		);
	}

	/**
	 * @param array<string, mixed> $input Validated transport input.
	 * @return array<string, mixed>
	 */
	public function capture(array $input): array
	{
		$this->siteBoundary->assertCurrentSite();
		$id = max(0, (int) ($input['id'] ?? 0));
		$capture = $this->captures->find($id);
		if (! $capture) {
			throw new AgentException('capture_not_found', 'The capture session no longer exists.', 404);
		}

		return $this->envelope(
			array(
				'capture' => $this->capturePayload($capture),
				'summary' => $this->mutations->summaryForSession($id),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input Validated transport input.
	 * @return array<string, mixed>
	 */
	public function mutations(array $input): array
	{
		$this->siteBoundary->assertCurrentSite();
		$captureId = max(0, (int) ($input['captureId'] ?? 0));
		if (! $this->captures->find($captureId)) {
			throw new AgentException('capture_not_found', 'The capture session no longer exists.', 404);
		}
		$after = max(0, (int) ($input['after'] ?? 0));
		$limit = max(1, min(AdminPayloadFactory::PAGE_SIZE, (int) ($input['limit'] ?? AdminPayloadFactory::PAGE_SIZE)));

		return $this->envelope(
			array(
				'captureId' => $captureId,
				'review'    => $this->payloads->mutationPage($captureId, $after, $limit),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input Validated transport input.
	 * @return array<string, mixed>
	 */
	public function mutation(array $input): array
	{
		$this->siteBoundary->assertCurrentSite();
		$id = max(0, (int) ($input['id'] ?? 0));
		$mutation = $this->mutations->find($id);
		if (! $mutation) {
			throw new AgentException('mutation_not_found', 'The mutation no longer exists.', 404);
		}
		$page = $this->payloads->mutationPage((int) $mutation->session_id, max(0, $id - 1), 1);
		$items = array();
		foreach ((array) ($page['groups'] ?? array()) as $group) {
			foreach ((array) ($group['mutations'] ?? array()) as $item) {
				if ($id === (int) ($item['id'] ?? 0)) {
					$items[] = $item;
				}
			}
		}
		if (empty($items)) {
			throw new AgentException('mutation_unavailable', 'The mutation evidence could not be presented safely.', 409);
		}

		return $this->envelope(
			array(
				'captureId' => (int) $mutation->session_id,
				'mutation'  => $items[0],
			)
		);
	}

	/**
	 * @param array<string, mixed> $input Validated transport input.
	 * @return array<string, mixed>
	 */
	public function planRestore(array $input): array
	{
		$this->siteBoundary->assertCurrentSite();
		$id = max(0, (int) ($input['mutationId'] ?? 0));

		return $this->envelope(
			array(
				'plan'     => $this->restore->planMutation($id),
				'warnings' => array(
					'This plan performs no write and must be validated again immediately before a future apply operation.',
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input Validated transport input.
	 * @return array<string, mixed>
	 */
	public function startCapture(array $input = array()): array
	{
		$this->siteBoundary->assertCurrentSite();
		$name = sanitize_text_field((string) ($input['name'] ?? ''));
		$id = $this->commands->start($name);

		return $this->envelope(
			array(
				'status'  => 'started',
				'capture' => $this->capturePayload($this->captures->find($id)),
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function stopCapture(): array
	{
		$this->siteBoundary->assertCurrentSite();
		$id = $this->commands->stop();

		return $this->envelope(
			array(
				'status'    => null === $id ? 'nothing-to-stop' : 'stopped',
				'captureId' => $id,
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function envelope(array $data): array
	{
		$scope = $this->siteBoundary->scope();

		return array(
			'schemaVersion' => self::SCHEMA_VERSION,
			'ok'            => true,
			'requestId'     => wp_generate_uuid4(),
			'scope'         => array(
				'type'      => 'site',
				'siteId'    => $scope->siteId(),
				'networkId' => $scope->networkId(),
			),
			'data'          => $data,
		);
	}

	/**
	 * @return array<string, int|string|null>
	 */
	private function capturePayload(?object $capture): array
	{
		if (! $capture) {
			throw new AgentException('capture_not_found', 'The capture session no longer exists.', 404);
		}

		return array(
			'id'                   => (int) $capture->id,
			'name'                 => (string) $capture->name,
			'mode'                 => (string) ($capture->capture_mode ?? 'manual'),
			'status'               => (string) $capture->status,
			'actorId'              => (int) $capture->actor_id,
			'mutationCount'        => (int) $capture->mutation_count,
			'reviewChangeCount'    => (int) ($capture->review_change_count ?? 0),
			'technicalChangeCount' => (int) ($capture->technical_change_count ?? 0),
			'writeSignalCount'     => (int) ($capture->write_signal_count ?? 0),
			'captureErrorCount'    => (int) ($capture->capture_error_count ?? 0),
			'startedAt'            => $this->isoDate((string) $capture->started_at),
			'endedAt'              => empty($capture->ended_at) ? null : $this->isoDate((string) $capture->ended_at),
		);
	}

	private function isoDate(string $mysqlUtc): string
	{
		$timestamp = strtotime($mysqlUtc . ' UTC');

		return false === $timestamp ? '' : gmdate(DATE_ATOM, $timestamp);
	}
}
