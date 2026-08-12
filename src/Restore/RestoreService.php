<?php
/**
 * Conflict-aware compensating restore operations.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Restore;

use ConfigOps\Capture\ValueCodec;
use ConfigOps\Database\CaptureRepository;
use ConfigOps\Database\MutationRepository;
use ConfigOps\Database\OptionMetadataRepository;
use ConfigOps\Execution\OperationLock;
use RuntimeException;
use Throwable;

final class RestoreService
{
	private const MAX_DISTINCT_OPTIONS = 1000;
	private const MAX_RETAINED_BYTES = 67108864;

	public function __construct(
		private readonly CaptureRepository $captures,
		private readonly MutationRepository $mutations,
		private readonly ValueCodec $codec,
		private readonly OptionMetadataRepository $optionMetadata,
		private readonly OperationLock $operationLock
	) {
	}

	public function restoreMutation(int $mutationId): void
	{
		$this->operationLock->run(
			'restore',
			function () use ($mutationId): void {
				$this->assertNoActiveCapture();

				$mutation = $this->mutations->find($mutationId);
				if (! $mutation) {
					throw new RuntimeException('The mutation no longer exists.');
				}

				$this->assertRestorable($mutation);
				$this->assertCurrentState(
					(string) $mutation->option_name,
					(string) $mutation->new_value,
					isset($mutation->new_autoload) ? (string) $mutation->new_autoload : null
				);
				$this->applyState(
					(string) $mutation->option_name,
					(string) $mutation->old_value,
					isset($mutation->old_autoload) ? (string) $mutation->old_autoload : null
				);
			}
		);
	}

	public function restoreSession(int $sessionId): int
	{
		return $this->operationLock->run('restore', fn (): int => $this->restoreSessionUnlocked($sessionId));
	}

	private function restoreSessionUnlocked(int $sessionId): int
	{
		$this->assertNoActiveCapture();

		$session = $this->captures->find($sessionId);
		if (! $session) {
			throw new RuntimeException('The capture session no longer exists.');
		}

		/** @var array<string, array{first: object, last: object}> $states */
		$states = array();
		$retainedBytes = 0;
		foreach ($this->mutations->iterateRestoreForSession($sessionId) as $mutation) {
			$this->assertRestorable($mutation);
			$name = (string) $mutation->option_name;
			if (! isset($states[$name])) {
				if (count($states) >= self::MAX_DISTINCT_OPTIONS) {
					throw new RuntimeException('This session exceeds the 1,000-option restore safety limit. Restore smaller reviewed groups instead.');
				}

				$states[$name] = array('first' => $mutation, 'last' => $mutation);
				$retainedBytes += $this->mutationFootprint($mutation);
			} else {
				if ((int) $states[$name]['first']->id !== (int) $states[$name]['last']->id) {
					$retainedBytes -= $this->mutationFootprint($states[$name]['last']);
				}
				$states[$name]['last'] = $mutation;
				$retainedBytes += $this->mutationFootprint($mutation);
			}

			if ($retainedBytes > self::MAX_RETAINED_BYTES) {
				throw new RuntimeException('This session exceeds the 64 MiB restore-planning safety budget. Restore smaller reviewed groups instead.');
			}
		}

		if (empty($states)) {
			return 0;
		}

		uasort(
			$states,
			static fn (array $left, array $right): int => (int) $right['last']->id <=> (int) $left['last']->id
		);

		// Validate the complete plan before changing the first option.
		foreach ($states as $name => $state) {
			$this->assertCurrentState(
				$name,
				(string) $state['last']->new_value,
				isset($state['last']->new_autoload) ? (string) $state['last']->new_autoload : null
			);
		}

		$applied = array();
		try {
			foreach ($states as $name => $state) {
				// Close the window between whole-plan validation and the individual write.
				$this->assertCurrentState(
					$name,
					(string) $state['last']->new_value,
					isset($state['last']->new_autoload) ? (string) $state['last']->new_autoload : null
				);

				$first = $state['first'];
				$this->applyState(
					$name,
					(string) $first->old_value,
					isset($first->old_autoload) ? (string) $first->old_autoload : null
				);
				$applied[$name] = $state;
			}
		} catch (Throwable $error) {
			$this->compensateAppliedStates($applied, $error);
		}

		return count($states);
	}

	private function mutationFootprint(object $mutation): int
	{
		return strlen((string) $mutation->old_value) + strlen((string) $mutation->new_value) + 256;
	}

	/**
	 * @param array<string, array{first: object, last: object}> $applied States already restored.
	 */
	private function compensateAppliedStates(array $applied, Throwable $cause): never
	{
		$failedCompensations = array();
		foreach (array_reverse($applied, true) as $name => $state) {
			try {
				$this->applyState(
					$name,
					(string) $state['last']->new_value,
					isset($state['last']->new_autoload) ? (string) $state['last']->new_autoload : null
				);
			} catch (Throwable) {
				$failedCompensations[] = $name;
			}
		}

		if (! empty($failedCompensations)) {
			throw new RuntimeException(
				$cause->getMessage() . ' Compensation also failed for: ' . implode(', ', $failedCompensations) . '.',
				0,
				$cause
			);
		}

		throw new RuntimeException(
			$cause->getMessage() . ' Earlier restore steps were compensated.',
			0,
			$cause
		);
	}

	private function assertNoActiveCapture(): void
	{
		if (null !== $this->captures->activeId()) {
			throw new RuntimeException('Stop the active capture before restoring values.');
		}
	}

	private function assertRestorable(object $mutation): void
	{
		if (1 !== (int) $mutation->restorable) {
			throw new RuntimeException(
				'This mutation contains a redacted, oversized, or unsupported value and cannot be restored safely.'
			);
		}
	}

	private function assertCurrentState(string $optionName, string $expectedPayload, ?string $expectedAutoload): void
	{
		$sentinel = new \stdClass();
		$current  = get_option($optionName, $sentinel);

		if ($this->codec->isMissing($expectedPayload)) {
			if ($current !== $sentinel) {
				throw new RuntimeException("Conflict: {$optionName} exists but the captured state expects it to be absent.");
			}

			return;
		}

		if ($current === $sentinel || ! $this->codec->matches($current, $expectedPayload, $optionName)) {
			throw new RuntimeException("Conflict: {$optionName} changed after this capture. Nothing was restored.");
		}

		$currentAutoload = $this->optionMetadata->autoloadFor($optionName);
		if (
			null !== $expectedAutoload
			&& $this->autoloadMode($expectedAutoload) !== $this->autoloadMode($currentAutoload)
		) {
			throw new RuntimeException("Conflict: {$optionName} has a different autoload state. Nothing was restored.");
		}
	}

	private function applyState(string $optionName, string $payload, ?string $autoload): void
	{
		$sentinel = new \stdClass();
		$current  = get_option($optionName, $sentinel);

		if ($this->codec->isMissing($payload)) {
			if ($current !== $sentinel && ! delete_option($optionName)) {
				throw new RuntimeException("WordPress could not delete {$optionName} during restore.");
			}

			return;
		}

		$value        = $this->codec->decode($payload);
		$autoloadFlag = $this->autoloadFlag($autoload);

		if ($current === $sentinel) {
			if (! add_option($optionName, $value, '', $autoloadFlag)) {
				throw new RuntimeException("WordPress could not recreate {$optionName} during restore.");
			}

			return;
		}

		if (! update_option($optionName, $value, $autoloadFlag)) {
			$stored = get_option($optionName, $sentinel);
			if ($stored === $sentinel || ! $this->codec->matches($stored, $payload, $optionName)) {
				throw new RuntimeException("WordPress could not restore {$optionName}.");
			}
		}
	}

	private function autoloadFlag(?string $autoload): ?bool
	{
		return match ($autoload) {
			'on', 'yes', 'auto-on' => true,
			'off', 'no', 'auto-off' => false,
			default => null,
		};
	}

	private function autoloadMode(?string $autoload): string
	{
		return match ($autoload) {
			'on', 'yes', 'auto-on' => 'on',
			'off', 'no', 'auto-off' => 'off',
			'auto' => 'auto',
			default => 'unknown',
		};
	}
}
