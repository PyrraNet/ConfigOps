<?php
/**
 * Fail-closed boundary for request-local evidence and commands.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Multisite;

use ConfigOps\Database\CaptureRepository;
use RuntimeException;
use Throwable;

final class SiteBoundaryGuard
{
	/** @var array<int, true> */
	private array $markedSessions = array();

	/** @var array<string, true> */
	private array $reportedTransitions = array();

	private bool $reporting = false;

	public function __construct(
		private readonly SiteScope $scope,
		private readonly CaptureRepository $captures
	) {
	}

	public function scope(): SiteScope
	{
		return $this->scope;
	}

	public function isCurrentSite(): bool
	{
		return $this->scope->isCurrent();
	}

	/**
	 * Return false outside the request's owning site and make any affected
	 * capture visibly incomplete without leaking the failure into the host write.
	 */
	public function acceptsCurrentSite(?int $sessionId = null): bool
	{
		if ($this->scope->isCurrent()) {
			return true;
		}

		$this->reject($sessionId);

		return false;
	}

	public function assertCurrentSite(): void
	{
		if (! $this->scope->isCurrent()) {
			throw new RuntimeException(
				'ConfigOps refused this operation because WordPress is currently switched to another site.'
			);
		}
	}

	public function key(string $value): string
	{
		return $this->scope->key($value);
	}

	/**
	 * @template T
	 * @param callable(): T $operation Internal recovery operation.
	 * @return T
	 */
	public function runInOwningSite(callable $operation): mixed
	{
		return $this->scope->run($operation);
	}

	public function recordCaptureError(int $sessionId, string $code): void
	{
		$this->scope->run(fn (): null => $this->recordCaptureErrorInOwningSite($sessionId, $code));
	}

	private function reject(?int $sessionId): void
	{
		if ($this->reporting) {
			return;
		}

		$this->reporting = true;
		$current = SiteScope::current();
		try {
			$sessionId = $sessionId ?? $this->scope->run(fn (): ?int => $this->captures->activeId());
			if (null !== $sessionId && $sessionId > 0 && ! isset($this->markedSessions[$sessionId])) {
				$this->scope->run(
					fn (): null => $this->recordCaptureErrorInOwningSite($sessionId, 'cross_site_write_ignored')
				);
				$this->markedSessions[$sessionId] = true;
			}
		} catch (Throwable $error) {
			$this->report($current, $sessionId, $error);
			$this->reporting = false;

			return;
		}

		$this->report($current, $sessionId, null);
		$this->reporting = false;
	}

	private function recordCaptureErrorInOwningSite(int $sessionId, string $code): null
	{
		$this->captures->recordCaptureError($sessionId, $code);

		return null;
	}

	private function report(SiteScope $current, ?int $sessionId, ?Throwable $error): void
	{
		$key = $current->key((string) ($sessionId ?? 0));
		if (isset($this->reportedTransitions[$key])) {
			return;
		}
		$this->reportedTransitions[$key] = true;

		try {
			do_action(
				'configops_site_boundary_violation',
				$this->scope->toArray(),
				$current->toArray(),
				$sessionId ?? 0,
				$error
			);
		} catch (Throwable) {
			// Boundary diagnostics must never break the host settings request.
		}
	}
}
