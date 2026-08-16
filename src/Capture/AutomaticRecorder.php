<?php
/**
 * Request-local automatic capture lifecycle.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Capture;

use ConfigOps\Admin\EvidenceNoticeStore;
use ConfigOps\Database\CaptureRepository;
use Throwable;

final class AutomaticRecorder
{
	private ?int $automaticSessionId = null;
	private bool $startAttempted = false;
	private bool $finalized = false;

	public function __construct(
		private readonly CaptureRepository $captures,
		private readonly EvidenceNoticeStore $notices,
		private readonly RequestContext $request
	) {
	}

	public function register(): void
	{
		add_action('shutdown', array($this, 'finalize'), PHP_INT_MAX);
	}

	/**
	 * Resolve a named session first, otherwise lazily create one automatic
	 * request-local observation for the first configuration mutation.
	 */
	public function sessionId(bool $createAutomatic = true): ?int
	{
		$activeId = $this->captures->activeId();
		if (null !== $activeId) {
			return $activeId;
		}
		if (null !== $this->automaticSessionId) {
			return $this->automaticSessionId;
		}
		if (! $createAutomatic || $this->startAttempted || ! $this->isEligible()) {
			return null;
		}

		$this->startAttempted = true;
		try {
			$this->automaticSessionId = $this->captures->startAutomatic(
				$this->automaticName(),
				$this->request->actorId(),
				$this->request->uri()
			);
		} catch (Throwable $error) {
			$this->report($error, 'automatic_capture_start_failed');
		}

		return $this->automaticSessionId;
	}

	public function finalize(): void
	{
		if ($this->finalized || null === $this->automaticSessionId) {
			return;
		}
		$this->finalized = true;

		try {
			$session = $this->captures->completeAutomatic($this->automaticSessionId);
			if (
				'discarded' !== (string) $session->status
				&& (
					(int) ($session->review_change_count ?? 0) > 0
					|| (int) ($session->capture_error_count ?? 0) > 0
				)
			) {
				$this->notices->push((int) $session->actor_id, (int) $session->id);
			}
		} catch (Throwable $error) {
			try {
				$this->captures->interruptAutomatic($this->automaticSessionId, 'automatic_finalize_failed');
			} catch (Throwable) {
				// The original failure remains the most useful diagnostic.
			}
			$this->notices->push($this->request->actorId(), $this->automaticSessionId);
			$this->report($error, 'automatic_capture_finalize_failed');
		}
	}

	private function isEligible(): bool
	{
		if (! current_user_can('configops_capture')) {
			return false;
		}

		$action = '';
		if (isset($_REQUEST['action']) && is_string($_REQUEST['action'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing check only; no state is changed here.
			$action = sanitize_key(wp_unslash($_REQUEST['action'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if (str_starts_with($action, 'configops_')) {
			return false;
		}
		if (str_contains($this->request->uri(), '/configops/v1/')) {
			return false;
		}

		$context = array(
			'actor_id' => $this->request->actorId(),
			'method'   => $this->request->method(),
			'uri'      => $this->request->uri(),
		);
		$administrative = is_admin()
			|| (defined('REST_REQUEST') && REST_REQUEST)
			|| (defined('WP_CLI') && WP_CLI);
		$administrative = true === apply_filters(
			'configops_automatic_recording_context_allowed',
			$administrative,
			$context
		);
		if (! $administrative) {
			return false;
		}

		return true === apply_filters(
			'configops_automatic_recording_enabled',
			true,
			$context
		);
	}

	private function automaticName(): string
	{
		$screen = $this->request->adminScreen();
		if ('' !== $screen) {
			return sprintf(
				/* translators: %s: WordPress admin screen ID. */
				__('Automatic change · %s', 'configops'),
				$screen
			);
		}

		$uri = trim($this->request->uri(), '/');
		$leaf = '' === $uri ? __('WordPress', 'configops') : basename($uri);

		return sprintf(
			/* translators: %s: safe request-path filename. */
			__('Automatic change · %s', 'configops'),
			$leaf
		);
	}

	private function report(Throwable $error, string $code): void
	{
		try {
			do_action('configops_automatic_capture_error', $error, $code, $this->automaticSessionId ?? 0);
		} catch (Throwable) {
			// Automatic recording must never break the host settings request.
		}
	}
}
