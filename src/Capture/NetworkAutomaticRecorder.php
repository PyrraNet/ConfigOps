<?php
/**
 * One request-local automatic observation for network-owned settings.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Capture;

use ConfigOps\Api\RestRoutes;
use ConfigOps\Database\CaptureRepository;
use ConfigOps\Multisite\NetworkScope;
use Throwable;

final class NetworkAutomaticRecorder
{
	private ?int $sessionId = null;
	private bool $startAttempted = false;
	private bool $finalized = false;
	private bool $suppressed = false;

	public function __construct(
		private readonly CaptureRepository $captures,
		private readonly RequestContext $request,
		private readonly NetworkScope $scope
	) {
	}

	public function register(): void
	{
		add_action('shutdown', array($this, 'finalize'), PHP_INT_MAX);
	}

	/**
	 * Finish a request-local observation before a ConfigOps command writes its
	 * own state, then prevent another automatic session in the same request.
	 */
	public function suppress(): void
	{
		$this->suppressed = true;
		if (null !== $this->sessionId && ! $this->finalized) {
			$this->finalize();
		}

		$this->sessionId = null;
	}

	public function sessionId(int $networkId, bool $create = true): ?int
	{
		if (! $this->accepts($networkId)) {
			return null;
		}

		$activeId = $this->captures->activeId();
		if (null !== $activeId) {
			return $activeId;
		}
		if (null !== $this->sessionId) {
			return $this->sessionId;
		}
		if ($this->finalized || $this->suppressed || ! $create || $this->startAttempted || ! $this->isEligible()) {
			return null;
		}

		$this->startAttempted = true;
		try {
			$this->sessionId = $this->captures->startAutomatic(
				$this->automaticName(),
				$this->request->actorId(),
				$this->request->uri()
			);
		} catch (Throwable $error) {
			$this->report($error, 'network_capture_start_failed');
		}

		return $this->sessionId;
	}

	public function finalize(): void
	{
		if ($this->finalized || null === $this->sessionId) {
			return;
		}
		$this->finalized = true;

		try {
			$this->captures->completeAutomatic($this->sessionId);
		} catch (Throwable $error) {
			try {
				$this->captures->interruptAutomatic($this->sessionId, 'network_finalize_failed');
			} catch (Throwable) {
				// The original failure remains the useful diagnostic.
			}
			$this->report($error, 'network_capture_finalize_failed');
		}
	}

	private function accepts(int $networkId): bool
	{
		return $networkId > 0
			&& $networkId === $this->scope->networkId()
			&& $this->scope->isCurrent();
	}

	private function isEligible(): bool
	{
		if (! current_user_can('manage_network_options')) {
			return false;
		}

		$administrative = is_network_admin()
			|| (defined('REST_REQUEST') && REST_REQUEST)
			|| (defined('WP_CLI') && WP_CLI);
		$administrative = true === apply_filters(
			'configops_network_recording_context_allowed',
			$administrative,
			array(
				'actor_id'  => $this->request->actorId(),
				'network_id' => $this->scope->networkId(),
				'method'    => $this->request->method(),
				'uri'       => $this->request->uri(),
			)
		);
		if (! $administrative || RestRoutes::owns($this->request->uri())) {
			return false;
		}

		return true === apply_filters(
			'configops_network_recording_enabled',
			true,
			array(
				'actor_id'  => $this->request->actorId(),
				'network_id' => $this->scope->networkId(),
			)
		);
	}

	private function automaticName(): string
	{
		$screen = $this->request->adminScreen();
		if ('' !== $screen) {
			return sprintf(
				/* translators: %s: WordPress network-admin screen ID. */
				__('Automatic network change · %s', 'configops'),
				$screen
			);
		}

		$uri = trim($this->request->uri(), '/');
		$leaf = '' === $uri ? __('WordPress network', 'configops') : basename($uri);

		return sprintf(
			/* translators: %s: safe request-path filename. */
			__('Automatic network change · %s', 'configops'),
			$leaf
		);
	}

	private function report(Throwable $error, string $code): void
	{
		try {
			do_action('configops_network_capture_error', $error, $code, $this->sessionId ?? 0);
		} catch (Throwable) {
			// Network observation must never break the host settings request.
		}
	}
}
