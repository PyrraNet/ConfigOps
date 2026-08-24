<?php
/**
 * Request-local automatic capture lifecycle.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Capture;

use ConfigOps\Api\RestRoutes;
use ConfigOps\Admin\EvidenceNoticeStore;
use ConfigOps\Database\CaptureRepository;
use ConfigOps\Multisite\SiteBoundaryGuard;
use ConfigOps\Multisite\SiteScope;
use Throwable;

final class AutomaticRecorder
{
	private ?int $automaticSessionId = null;
	private bool $startAttempted = false;
	private bool $finalized = false;
	private bool $suppressed = false;
	private readonly SiteBoundaryGuard $siteBoundary;

	public function __construct(
		private readonly CaptureRepository $captures,
		private readonly EvidenceNoticeStore $notices,
		private readonly RequestContext $request,
		?SiteBoundaryGuard $siteBoundary = null
	) {
		$this->siteBoundary = $siteBoundary ?? new SiteBoundaryGuard(SiteScope::current(), $captures);
	}

	public function register(): void
	{
		add_action('shutdown', array($this, 'finalize'), PHP_INT_MAX);
	}

	/**
	 * Close any observation opened during early request boot, then prevent
	 * ConfigOps command handlers from observing their own writes.
	 */
	public function suppress(): void
	{
		$this->suppressed = true;
		if (null !== $this->automaticSessionId && ! $this->finalized) {
			$this->finalize();
		}

		// A completed or interrupted request-local session must never receive the
		// command's later writes through sessionId().
		$this->automaticSessionId = null;
	}

	/**
	 * Resolve a named session first, otherwise lazily create one automatic
	 * request-local observation for the first configuration mutation.
	 */
	public function sessionId(bool $createAutomatic = true): ?int
	{
		if (! $this->siteBoundary->acceptsCurrentSite($this->automaticSessionId)) {
			return null;
		}

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
		if (! $this->siteBoundary->acceptsCurrentSite($this->automaticSessionId)) {
			$this->siteBoundary->runInOwningSite(fn (): null => $this->finalizeInOwningSite());

			return;
		}

		$this->finalizeInOwningSite();
	}

	private function finalizeInOwningSite(): null
	{
		if ($this->finalized || null === $this->automaticSessionId) {
			return null;
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

		return null;
	}

	private function isEligible(): bool
	{
		$wpCli = defined('WP_CLI') && WP_CLI;
		if ($this->suppressed || (! $wpCli && ! current_user_can('configops_capture'))) {
			return false;
		}

		$action = '';
		if (isset($_REQUEST['action']) && is_string($_REQUEST['action'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing check only; no state is changed here.
			$action = sanitize_key(wp_unslash($_REQUEST['action'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if (str_starts_with($action, 'configops_')) {
			return false;
		}
		if ($this->isConfigOpsRestRequest()) {
			return false;
		}

		$context = array(
			'actor_id' => $this->request->actorId(),
			'method'   => $this->request->method(),
			'uri'      => $this->request->uri(),
		);
		$administrative = is_admin()
			|| (defined('REST_REQUEST') && REST_REQUEST)
			|| $wpCli;
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

	private function isConfigOpsRestRequest(): bool
	{
		if (RestRoutes::owns($this->request->uri())) {
			return true;
		}

		$queryRoute = '';
		if (isset($_GET['rest_route']) && is_string($_GET['rest_route'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing check only; no state is changed here.
			$queryRoute = sanitize_text_field(wp_unslash($_GET['rest_route'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		return RestRoutes::ownsQueryRoute($queryRoute);
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
