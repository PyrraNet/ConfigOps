<?php
/**
 * Named capture commands for one WordPress network.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Command;

use ConfigOps\Capture\NetworkAutomaticRecorder;
use ConfigOps\Database\CaptureRepository;
use ConfigOps\Multisite\NetworkScope;
use RuntimeException;

final readonly class NetworkCaptureCommands
{
	public function __construct(
		private CaptureRepository $captures,
		private NetworkAutomaticRecorder $automatic,
		private NetworkScope $scope
	) {
	}

	public function start(string $name): int
	{
		$this->assertCurrentNetwork();
		$this->automatic->suppress();
		if ('' === trim($name)) {
			$name = sprintf(
				/* translators: %s: UTC date and time. */
				__('Network capture %s', 'configops'),
				gmdate('Y-m-d H:i')
			);
		}

		return $this->captures->start(
			$name,
			get_current_user_id(),
			wp_get_referer() ?: network_admin_url()
		);
	}

	public function stop(): ?int
	{
		$this->assertCurrentNetwork();
		$this->automatic->suppress();

		return $this->captures->stop();
	}

	private function assertCurrentNetwork(): void
	{
		if (! $this->scope->isCurrent()) {
			throw new RuntimeException(
				'ConfigOps refused this operation because WordPress is currently serving another network.'
			);
		}
	}
}
