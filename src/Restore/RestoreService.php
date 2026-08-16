<?php
/**
 * Conflict-aware compensating restore operations.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Restore;

use ConfigOps\Adapter\AdapterRegistry;
use ConfigOps\Capture\ValueCodec;
use ConfigOps\Database\CaptureRepository;
use ConfigOps\Database\MutationRepository;
use ConfigOps\Database\OptionMetadataRepository;
use ConfigOps\Database\RestoreAuditRepository;
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
		private readonly OperationLock $operationLock,
		private readonly AdapterRegistry $adapters,
		private readonly RestoreAuditRepository $audit
	) {
	}

	public function restoreMutation(int $mutationId): void
	{
		$mutation = $this->mutations->find($mutationId);
		if (! $mutation) {
			throw new RuntimeException('The mutation no longer exists.');
		}

		$this->audited(
			'mutation',
			$mutationId,
			(int) $mutation->session_id,
			fn (): int => $this->operationLock->run('restore', fn (): int => $this->restoreMutationUnlocked($mutationId))
		);
	}

	public function restoreSession(int $sessionId): int
	{
		if (! $this->captures->find($sessionId)) {
			throw new RuntimeException('The capture session no longer exists.');
		}

		return $this->audited(
			'session',
			$sessionId,
			$sessionId,
			fn (): int => $this->operationLock->run('restore', fn (): int => $this->restoreSessionUnlocked($sessionId))
		);
	}

	private function restoreMutationUnlocked(int $mutationId): int
	{
		$this->assertNoActiveNamedCapture();

		$mutation = $this->mutations->find($mutationId);
		if (! $mutation) {
			throw new RuntimeException('The mutation no longer exists.');
		}

		$this->assertRestorable($mutation);
		if ('patch' === $this->restoreMode($mutation)) {
			$this->restoreSafeFields($mutation);

			return 1;
		}

		$this->adapters->assertRestorableReferences($this->storedDiff($mutation));
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

		return 1;
	}

	/**
	 * @param callable(): int $operation
	 */
	private function audited(string $scope, int $targetId, int $sessionId, callable $operation): int
	{
		$auditId = $this->audit->start($scope, $targetId, $sessionId, get_current_user_id());
		try {
			$count = $operation();
		} catch (Throwable $error) {
			$this->recordAuditFailure($auditId, $error);
			throw $error;
		}

		$this->finalizeSuccessfulAudit($auditId, $count);

		return $count;
	}

	private function restoreSessionUnlocked(int $sessionId): int
	{
		$this->assertNoActiveNamedCapture();

		$session = $this->captures->find($sessionId);
		if (! $session) {
			throw new RuntimeException('The capture session no longer exists.');
		}
		if ('completed' !== (string) $session->status) {
			throw new RuntimeException('This capture did not complete cleanly. Review and undo supported settings individually.');
		}
		if ((int) ($session->capture_error_count ?? 0) > 0) {
			throw new RuntimeException(
				'This capture is incomplete because some changes could not be recorded. Review and undo supported settings individually.'
			);
		}
		if ((int) ($session->write_signal_count ?? 0) > 0) {
			throw new RuntimeException(
				'This capture contains unmanaged database writes. Restore reviewed Options API mutations individually or add an adapter.'
			);
		}

		/** @var array<string, array{first: object, last: object}> $states */
		$states = array();
		$retainedBytes = 0;
		foreach ($this->mutations->iterateRestoreForSession($sessionId) as $mutation) {
			$this->assertRestorable($mutation);
			if ('full' !== $this->restoreMode($mutation)) {
				throw new RuntimeException(
					'This capture contains a setting with protected fields. Undo its safe fields individually so unchanged secrets remain untouched.'
				);
			}
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
			$this->adapters->assertRestorableReferences($this->storedDiff($state['first']));
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
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- compensationFailure() escapes the message; the previous throwable is metadata.
			throw $this->compensationFailure(
				$cause->getMessage() . ' Compensation also failed for: ' . implode(', ', $failedCompensations) . '.',
				true,
				$cause
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- compensationFailure() escapes the message; the previous throwable is metadata.
		throw $this->compensationFailure(
			$cause->getMessage() . ' Earlier restore steps were compensated.',
			false,
			$cause
		);
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	private function finalizeSuccessfulAudit(int $auditId, int $restoredOptionCount): void
	{
		try {
			$this->audit->succeed($auditId, $restoredOptionCount);
		} catch (Throwable $error) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- runtimeFailure() escapes the message; the previous throwable is metadata.
			throw $this->runtimeFailure(
				'The settings were undone, but ConfigOps could not finalize the audit record. Do not retry this undo until the running audit entry has been inspected.',
				$error
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	private function recordAuditFailure(int $auditId, Throwable $error): void
	{
		$status = 'failed';
		$code = 'restore_failed';
		if ($error instanceof RestoreCompensationException) {
			$status = $error->compensationFailed ? 'compensation_failed' : 'compensated';
			$code = $error->compensationFailed ? 'apply_and_compensation_failed' : 'apply_failed_compensated';
		} elseif (str_starts_with($error->getMessage(), 'Conflict:')) {
			$code = 'target_conflict';
		} elseif (str_contains($error->getMessage(), 'incomplete')) {
			$code = 'capture_incomplete';
		} elseif (str_contains($error->getMessage(), 'unmanaged database writes')) {
			$code = 'unmanaged_writes';
		} elseif (str_contains($error->getMessage(), 'active capture')) {
			$code = 'capture_active';
		} elseif (str_starts_with($error->getMessage(), 'Reference missing:')) {
			$code = 'reference_missing';
		}

		try {
			$this->audit->fail($auditId, $status, $code);
		} catch (Throwable $auditError) {
			try {
				do_action('configops_restore_audit_error', $auditError, $auditId, $error);
			} catch (Throwable) {
				// Extension diagnostics cannot replace the original restore failure.
			}
		}
	}

	private function assertNoActiveNamedCapture(): void
	{
		if (null !== $this->captures->activeId()) {
			throw new RuntimeException('Stop the active change session before restoring values.');
		}
	}

	private function assertRestorable(object $mutation): void
	{
		if (1 !== (int) $mutation->restorable || 'derived' === (string) ($mutation->classification ?? '')) {
			throw new RuntimeException(
				'This mutation is technical, redacted, oversized, or unsupported and cannot be restored safely.'
			);
		}
	}

	private function restoreMode(object $mutation): string
	{
		$mode = (string) ($mutation->restore_mode ?? 'full');

		return in_array($mode, array('full', 'patch'), true) ? $mode : 'none';
	}

	private function restoreSafeFields(object $mutation): void
	{
		$diff = json_decode((string) ($mutation->diff ?? ''), true);
		if (! is_array($diff)) {
			throw new RuntimeException('The stored field comparison is malformed. Nothing was changed.');
		}

		$changes = $this->adapters->safeRestoreChanges(
			(string) ($mutation->adapter_id ?? ''),
			(int) ($mutation->adapter_schema_version ?? 0),
			(string) $mutation->option_name,
			$diff
		);
		if (empty($changes)) {
			throw new RuntimeException('No adapter-backed field in this setting can still be undone safely. Nothing was changed.');
		}
		$this->adapters->assertRestorableReferences($changes);

		$optionName = (string) $mutation->option_name;
		$sentinel   = new \stdClass();
		$current    = get_option($optionName, $sentinel);
		if ($current === $sentinel || ! is_array($current)) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- runtimeFailure() escapes the message.
			throw $this->runtimeFailure("Conflict: {$optionName} no longer contains the captured settings array. Nothing was restored.");
		}

		$currentAutoload = $this->optionMetadata->autoloadFor($optionName);
		$expectedAutoload = isset($mutation->new_autoload) ? (string) $mutation->new_autoload : null;
		if (
			null !== $expectedAutoload
			&& $this->autoloadMode($expectedAutoload) !== $this->autoloadMode($currentAutoload)
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- runtimeFailure() escapes the message.
			throw $this->runtimeFailure("Conflict: {$optionName} has a different autoload state. Nothing was restored.");
		}

		foreach ($changes as $change) {
			$this->assertPatchAfterState($current, $change, $optionName);
		}

		$patched = $current;
		foreach (array_reverse($changes) as $change) {
			$parts = $this->pointerParts((string) $change['path']);
			$patched = match ((string) $change['op']) {
				'add' => $this->removeAtPath($patched, $parts),
				'remove' => $this->setAtPath($patched, $parts, $change['before'] ?? null, true),
				'replace' => $this->setAtPath($patched, $parts, $change['before'] ?? null, false),
				default => $patched,
			};
		}

		$autoloadFlag = $this->autoloadFlag($currentAutoload);
		try {
			$updated = update_option($optionName, $patched, $autoloadFlag);
			$stored  = get_option($optionName, $sentinel);
			if ($stored === $sentinel || ! $this->codec->semanticallyEqual($stored, $patched)) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- runtimeFailure() escapes the message.
				throw $this->runtimeFailure("WordPress could not undo the safe fields in {$optionName}.");
			}
			unset($updated);
		} catch (Throwable $error) {
			try {
				update_option($optionName, $current, $autoloadFlag);
				$compensatedValue = get_option($optionName, $sentinel);
				$compensatedAutoload = $this->optionMetadata->autoloadFor($optionName);
				if (
					$compensatedValue === $sentinel
					|| ! $this->codec->semanticallyEqual($compensatedValue, $current)
					|| $this->autoloadMode($compensatedAutoload) !== $this->autoloadMode($currentAutoload)
				) {
					throw new RuntimeException('The original current value could not be verified after compensation.');
				}
			} catch (Throwable) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- compensationFailure() escapes the message; the previous throwable is metadata.
				throw $this->compensationFailure(
					$error->getMessage() . ' The original current value could not be restored completely.',
					true,
					$error
				);
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}

			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- compensationFailure() escapes the message; the previous throwable is metadata.
			throw $this->compensationFailure(
				$error->getMessage() . ' The original current value was reapplied and verified.',
				false,
				$error
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function storedDiff(object $mutation): array
	{
		$diff = json_decode((string) ($mutation->diff ?? ''), true);

		return is_array($diff) ? array_values(array_filter($diff, 'is_array')) : array();
	}

	/**
	 * @param array<string, mixed> $change Nested diff entry.
	 */
	private function assertPatchAfterState(array $current, array $change, string $optionName): void
	{
		$parts = $this->pointerParts((string) ($change['path'] ?? '/'));
		[$exists, $value] = $this->valueAtPath($current, $parts);
		$operation = (string) ($change['op'] ?? '');
		if ('remove' === $operation) {
			if ($exists) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- runtimeFailure() escapes the message.
				throw $this->runtimeFailure("Conflict: {$optionName} changed after this capture. Nothing was restored.");
			}

			return;
		}

		if (! $exists || ! $this->codec->semanticallyEqual($value, $change['after'] ?? null)) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- runtimeFailure() escapes the message.
			throw $this->runtimeFailure("Conflict: {$optionName} changed after this capture. Nothing was restored.");
		}
	}

	/**
	 * @param list<string> $parts JSON Pointer parts.
	 * @return array{0: bool, 1: mixed}
	 */
	private function valueAtPath(array $value, array $parts): array
	{
		$current = $value;
		foreach ($parts as $part) {
			if (! is_array($current)) {
				return array(false, null);
			}
			$key = $this->arrayKey($current, $part);
			if (! array_key_exists($key, $current)) {
				return array(false, null);
			}
			$current = $current[$key];
		}

		return array(true, $current);
	}

	/**
	 * @param list<string> $parts JSON Pointer parts.
	 */
	private function setAtPath(array $value, array $parts, mixed $replacement, bool $insert): array
	{
		$part = array_shift($parts);
		if (null === $part) {
			throw new RuntimeException('A root-level protected value cannot be partially restored.');
		}
		$key = $this->arrayKey($value, $part);
		if (empty($parts)) {
			if ($insert && array_is_list($value) && is_int($key)) {
				array_splice($value, $key, 0, array($replacement));
			} else {
				$value[$key] = $replacement;
			}

			return $value;
		}

		if (! isset($value[$key]) || ! is_array($value[$key])) {
			throw new RuntimeException('The protected setting structure changed. Nothing was restored.');
		}
		$value[$key] = $this->setAtPath($value[$key], $parts, $replacement, $insert);

		return $value;
	}

	/**
	 * @param list<string> $parts JSON Pointer parts.
	 */
	private function removeAtPath(array $value, array $parts): array
	{
		$part = array_shift($parts);
		if (null === $part) {
			throw new RuntimeException('A root-level protected value cannot be partially restored.');
		}
		$key = $this->arrayKey($value, $part);
		if (empty($parts)) {
			if (array_is_list($value) && is_int($key)) {
				array_splice($value, $key, 1);
			} else {
				unset($value[$key]);
			}

			return $value;
		}

		if (! isset($value[$key]) || ! is_array($value[$key])) {
			throw new RuntimeException('The protected setting structure changed. Nothing was restored.');
		}
		$value[$key] = $this->removeAtPath($value[$key], $parts);

		return $value;
	}

	/**
	 * @return list<string>
	 */
	private function pointerParts(string $pointer): array
	{
		if ('' === $pointer || '/' === $pointer) {
			return array();
		}

		return array_map(
			static fn (string $part): string => str_replace(array('~1', '~0'), array('/', '~'), $part),
			explode('/', ltrim($pointer, '/'))
		);
	}

	private function arrayKey(array $value, string $part): int|string
	{
		if (array_key_exists($part, $value)) {
			return $part;
		}
		if (ctype_digit($part) && array_key_exists((int) $part, $value)) {
			return (int) $part;
		}
		if (array_is_list($value) && ctype_digit($part)) {
			return (int) $part;
		}

		return $part;
	}

	private function assertCurrentState(string $optionName, string $expectedPayload, ?string $expectedAutoload): void
	{
		$sentinel = new \stdClass();
		$current  = get_option($optionName, $sentinel);

		if ($this->codec->isMissing($expectedPayload)) {
			if ($current !== $sentinel) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- runtimeFailure() escapes the message.
				throw $this->runtimeFailure("Conflict: {$optionName} exists but the captured state expects it to be absent.");
			}

			return;
		}

		if ($current === $sentinel || ! $this->codec->matches($current, $expectedPayload, $optionName)) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- runtimeFailure() escapes the message.
			throw $this->runtimeFailure("Conflict: {$optionName} changed after this capture. Nothing was restored.");
		}

		$currentAutoload = $this->optionMetadata->autoloadFor($optionName);
		if (
			null !== $expectedAutoload
			&& $this->autoloadMode($expectedAutoload) !== $this->autoloadMode($currentAutoload)
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- runtimeFailure() escapes the message.
			throw $this->runtimeFailure("Conflict: {$optionName} has a different autoload state. Nothing was restored.");
		}
	}

	private function applyState(string $optionName, string $payload, ?string $autoload): void
	{
		$sentinel        = new \stdClass();
		$current         = get_option($optionName, $sentinel);
		$currentAutoload = $this->optionMetadata->autoloadFor($optionName);
		$currentEncoded  = $current === $sentinel
			? $this->codec->missing()
			: $this->codec->encode($current, $optionName);
		if (! $currentEncoded->restorable) {
			throw new RuntimeException('The current value cannot be retained safely for restore compensation. Nothing was changed.');
		}
		$currentPayload  = $currentEncoded->payload;

		try {
			$this->writeState($optionName, $payload, $autoload);
			$this->assertAppliedState($optionName, $payload, $autoload);
		} catch (Throwable $error) {
			try {
				// The failed write may have returned successfully before another plugin's
				// synchronous option hook changed the value again. Re-read first so an
				// unchanged original state does not trigger an unnecessary second write.
				try {
					$this->assertAppliedState($optionName, $currentPayload, $currentAutoload);
				} catch (Throwable) {
					$this->writeState($optionName, $currentPayload, $currentAutoload);
					$this->assertAppliedState($optionName, $currentPayload, $currentAutoload);
				}
			} catch (Throwable) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- compensationFailure() escapes the message; the previous throwable is metadata.
				throw $this->compensationFailure(
					$error->getMessage() . ' The original current value could not be restored completely.',
					true,
					$error
				);
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}

			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- compensationFailure() escapes the message; the previous throwable is metadata.
			throw $this->compensationFailure(
				$error->getMessage() . ' The original current value was reapplied and verified.',
				false,
				$error
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	private function writeState(string $optionName, string $payload, ?string $autoload): void
	{
		$sentinel = new \stdClass();
		$current  = get_option($optionName, $sentinel);

		if ($this->codec->isMissing($payload)) {
			if ($current !== $sentinel) {
				delete_option($optionName);
			}

			return;
		}

		$value        = $this->codec->decode($payload);
		$autoloadFlag = $this->autoloadFlag($autoload);

		if ($current === $sentinel) {
			add_option($optionName, $value, '', $autoloadFlag);

			return;
		}

		update_option($optionName, $value, $autoloadFlag);
	}

	private function assertAppliedState(string $optionName, string $payload, ?string $expectedAutoload): void
	{
		$storedAutoload = $this->optionMetadata->autoloadFor($optionName);
		if ($this->codec->isMissing($payload)) {
			if (null !== $storedAutoload) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- runtimeFailure() escapes the message.
				throw $this->runtimeFailure("WordPress did not preserve the restored absence of {$optionName}.");
			}

			return;
		}

		$sentinel = new \stdClass();
		$stored   = get_option($optionName, $sentinel);
		if (
			null === $storedAutoload
			|| $stored === $sentinel
			|| ! $this->codec->matches($stored, $payload, $optionName)
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- runtimeFailure() escapes the message.
			throw $this->runtimeFailure("WordPress did not preserve the restored value of {$optionName}.");
		}

		if (
			null !== $expectedAutoload
			&& $this->autoloadMode($expectedAutoload) !== $this->autoloadMode($storedAutoload)
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- runtimeFailure() escapes the message.
			throw $this->runtimeFailure("WordPress did not preserve the restored autoload state of {$optionName}.");
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

	private function runtimeFailure(string $message, ?Throwable $previous = null): RuntimeException
	{
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The human-facing message is escaped; the previous throwable is exception metadata.
		return new RuntimeException(esc_html($message), 0, $previous);
	}

	private function compensationFailure(string $message, bool $compensationFailed, Throwable $previous): RestoreCompensationException
	{
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The human-facing message is escaped; the previous throwable is exception metadata.
		return new RestoreCompensationException(esc_html($message), $compensationFailed, $previous);
	}
}
