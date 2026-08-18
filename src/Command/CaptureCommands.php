<?php
/**
 * Shared application commands for capture and restore transports.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Command;

use ConfigOps\Capture\AutomaticRecorder;
use ConfigOps\Database\CaptureRepository;
use ConfigOps\Multisite\SiteBoundaryGuard;
use ConfigOps\Multisite\SiteScope;
use ConfigOps\Restore\RestoreService;

final class CaptureCommands
{
	private readonly SiteBoundaryGuard $siteBoundary;

	public function __construct(
		private readonly CaptureRepository $captures,
		private readonly RestoreService $restore,
		private readonly ?AutomaticRecorder $automatic = null,
		?SiteBoundaryGuard $siteBoundary = null
	) {
		$this->siteBoundary = $siteBoundary ?? new SiteBoundaryGuard(SiteScope::current(), $captures);
	}

	public function start(string $name): int
	{
		$this->siteBoundary->assertCurrentSite();
		$this->automatic?->suppress();
		if ('' === trim($name)) {
			$name = sprintf(
				/* translators: %s: UTC date and time. */
				__('Capture %s', 'configops'),
				gmdate('Y-m-d H:i')
			);
		}

		return $this->captures->start($name, get_current_user_id(), wp_get_referer() ?: admin_url());
	}

	public function stop(): ?int
	{
		$this->siteBoundary->assertCurrentSite();
		$this->automatic?->suppress();

		return $this->captures->stop();
	}

	public function restoreMutation(int $mutationId): void
	{
		$this->siteBoundary->assertCurrentSite();
		$this->automatic?->suppress();
		$this->restore->restoreMutation($mutationId);
	}

	public function restoreSession(int $sessionId): int
	{
		$this->siteBoundary->assertCurrentSite();
		$this->automatic?->suppress();

		return $this->restore->restoreSession($sessionId);
	}
}
