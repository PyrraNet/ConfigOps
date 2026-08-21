<?php
/**
 * Observe settings persisted in the current WordPress network's sitemeta scope.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Capture;

use ConfigOps\Database\CaptureRepository;
use ConfigOps\Multisite\NetworkBoundaryGuard;
use ConfigOps\Multisite\NetworkScope;
use Throwable;

final readonly class NetworkMutationObserver
{
	private NetworkBoundaryGuard $boundary;

	public function __construct(
		private CaptureRepository $captures,
		private InternalOptionPolicy $internalOptions,
		private ValueCodec $codec,
		private MutationRecorder $recorder,
		private NetworkAutomaticRecorder $automatic,
		private NetworkScope $scope,
		?NetworkBoundaryGuard $boundary = null
	) {
		$this->boundary = $boundary ?? new NetworkBoundaryGuard($this->scope, $this->captures);
	}

	public function register(): void
	{
		add_action('add_site_option', array($this, 'onAdded'), 10, 3);
		add_action('update_site_option', array($this, 'onUpdated'), 10, 4);
		add_action('delete_site_option', array($this, 'onDeleted'), 10, 2);
	}

	public function onAdded(string $option, mixed $value, int $networkId): void
	{
		$this->observe(
			$option,
			$networkId,
			fn (): EncodedValue => $this->codec->missing(),
			fn (): EncodedValue => $this->codec->encode($value, $option)
		);
	}

	public function onUpdated(string $option, mixed $value, mixed $oldValue, int $networkId): void
	{
		$this->observe(
			$option,
			$networkId,
			fn (): EncodedValue => $this->codec->encode($oldValue, $option),
			fn (): EncodedValue => $this->codec->encode($value, $option)
		);
	}

	public function onDeleted(string $option, int $networkId): void
	{
		$this->observe(
			$option,
			$networkId,
			fn (): EncodedValue => $this->codec->unavailable('previous network value unavailable'),
			fn (): EncodedValue => $this->codec->missing()
		);
	}

	/**
	 * @param callable(): EncodedValue $before
	 * @param callable(): EncodedValue $after
	 */
	private function observe(string $option, int $networkId, callable $before, callable $after): void
	{
		if ($this->internalOptions->isInternal($option)) {
			return;
		}

		$openSessionId = $this->automatic->sessionId($this->scope->networkId(), false);
		if (! $this->boundary->accepts($networkId, $openSessionId)) {
			return;
		}

		$sessionId = $this->automatic->sessionId($networkId);
		if (null === $sessionId) {
			return;
		}

		try {
			$this->recorder->record($sessionId, $option, $before(), $after(), null, null);
		} catch (Throwable $error) {
			$this->reportCaptureError($error, $option, $sessionId);
		}
	}

	private function reportCaptureError(Throwable $error, string $option, int $sessionId): void
	{
		try {
			$this->captures->recordCaptureError($sessionId, 'network_option_capture_failed');
		} catch (Throwable $integrityError) {
			try {
				do_action('configops_network_capture_integrity_error', $integrityError, $option, $sessionId);
			} catch (Throwable) {
				// Observer diagnostics cannot break the host settings request.
			}
		}

		try {
			do_action('configops_network_capture_error', $error, 'network_option_capture_failed', $sessionId);
		} catch (Throwable) {
			// Observer diagnostics cannot break the host settings request.
		}
	}
}
