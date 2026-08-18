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
use ConfigOps\Restore\RestoreService;

final class CaptureCommands
{
	public function __construct(
		private readonly CaptureRepository $captures,
		private readonly RestoreService $restore,
		private readonly ?AutomaticRecorder $automatic = null
	) {
	}

	public function start(string $name): int
	{
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
		$this->automatic?->suppress();

		return $this->captures->stop();
	}

	public function restoreMutation(int $mutationId): void
	{
		$this->automatic?->suppress();
		$this->restore->restoreMutation($mutationId);
	}

	public function restoreSession(int $sessionId): int
	{
		$this->automatic?->suppress();

		return $this->restore->restoreSession($sessionId);
	}
}
