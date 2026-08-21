<?php
/**
 * Fail-closed boundary for network-owned evidence.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Multisite;

use ConfigOps\Database\CaptureRepository;
use Throwable;

final class NetworkBoundaryGuard
{
	/** @var array<int, true> */
	private array $markedSessions = array();

	private bool $reported = false;
	private bool $reporting = false;

	public function __construct(
		private readonly NetworkScope $scope,
		private readonly CaptureRepository $captures
	) {
	}

	/**
	 * Return false when a network-option write does not belong to the network
	 * that booted this request. Any open capture is marked incomplete without
	 * leaking the boundary failure into the host write.
	 */
	public function accepts(int $targetNetworkId, ?int $sessionId = null): bool
	{
		if (
			$targetNetworkId > 0
			&& $targetNetworkId === $this->scope->networkId()
			&& $this->scope->isCurrent()
		) {
			return true;
		}

		$this->reject($targetNetworkId, $sessionId);

		return false;
	}

	private function reject(int $targetNetworkId, ?int $sessionId): void
	{
		if ($this->reporting) {
			return;
		}

		$this->reporting = true;
		$error = null;
		try {
			$sessionId ??= $this->captures->activeId();
			if (null !== $sessionId && $sessionId > 0 && ! isset($this->markedSessions[$sessionId])) {
				$this->captures->recordCaptureError($sessionId, 'cross_network_write_ignored');
				$this->markedSessions[$sessionId] = true;
			}
		} catch (Throwable $caught) {
			$error = $caught;
		}

		$this->report($targetNetworkId, $sessionId, $error);
		$this->reporting = false;
	}

	private function report(int $targetNetworkId, ?int $sessionId, ?Throwable $error): void
	{
		if ($this->reported) {
			return;
		}
		$this->reported = true;

		try {
			do_action(
				'configops_network_boundary_violation',
				$this->scope->toArray(),
				array('network_id' => max(0, $targetNetworkId), 'site_id' => 0),
				function_exists('get_current_network_id') ? max(0, (int) get_current_network_id()) : 0,
				$sessionId ?? 0,
				$error
			);
		} catch (Throwable) {
			// Boundary diagnostics must never break the host settings request.
		}
	}
}
