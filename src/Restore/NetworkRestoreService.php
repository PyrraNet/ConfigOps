<?php
/**
 * Conflict-checked restore for one full Network Options API mutation.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Restore;

use ConfigOps\Capture\ValueCodec;
use ConfigOps\Database\CaptureRepository;
use ConfigOps\Database\MutationRepository;
use ConfigOps\Database\NetworkOptionStore;
use ConfigOps\Database\RestoreAuditRepository;
use ConfigOps\Execution\NetworkOperationLock;
use ConfigOps\Multisite\NetworkScope;
use RuntimeException;
use Throwable;

final class NetworkRestoreService
{
	private readonly NetworkOptionStore $options;

	public function __construct(
		private readonly CaptureRepository $captures,
		private readonly MutationRepository $mutations,
		private readonly ValueCodec $codec,
		private readonly NetworkOperationLock $operationLock,
		private readonly RestoreAuditRepository $audit,
		private readonly NetworkScope $scope,
		private readonly NetworkRestorePolicy $restorePolicy
	) {
		$this->options = new NetworkOptionStore($scope->networkId());
	}

	public function restoreMutation(int $mutationId): void
	{
		$this->assertCurrentNetwork();
		$mutation = $this->mutations->find($mutationId);
		if (! $mutation) {
			throw new RuntimeException('The network mutation no longer exists.');
		}

		$auditId = $this->audit->start(
			'mutation',
			$mutationId,
			(int) $mutation->session_id,
			get_current_user_id()
		);
		try {
			$count = $this->operationLock->run(
				'restore',
				fn (): int => $this->restoreUnlocked($mutationId)
			);
		} catch (Throwable $error) {
			$this->recordAuditFailure($auditId, $error);
			throw $error;
		}

		try {
			$this->audit->succeed($auditId, $count);
		} catch (Throwable $error) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- runtimeFailure() escapes the message; the previous throwable is metadata.
			throw $this->runtimeFailure(
				'The network setting was undone, but ConfigOps could not finalize the audit record. Do not retry until the running audit entry has been inspected.',
				$error
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	private function restoreUnlocked(int $mutationId): int
	{
		$this->assertCurrentNetwork();
		if (null !== $this->captures->activeId()) {
			throw new RuntimeException('Stop the active network change session before restoring values.');
		}

		$mutation = $this->mutations->find($mutationId);
		if (! $mutation) {
			throw new RuntimeException('The network mutation no longer exists.');
		}
		$this->assertRestorable($mutation);
		$optionName = (string) $mutation->option_name;
		$this->assertCurrentState($optionName, (string) $mutation->new_value);
		$this->applyState($optionName, (string) $mutation->old_value);

		return 1;
	}

	private function assertRestorable(object $mutation): void
	{
		$type = (string) ($mutation->mutation_type ?? '');
		$mode = (string) ($mutation->restore_mode ?? 'none');
		if (
			1 !== (int) ($mutation->restorable ?? 0)
			|| ! in_array($type, array('add', 'update'), true)
			|| 'full' !== $mode
			|| 'derived' === (string) ($mutation->classification ?? '')
			|| ! $this->restorePolicy->allows((string) ($mutation->option_name ?? ''))
		) {
			throw new RuntimeException(
				'Only complete, non-redacted network settings additions and updates can be restored safely. Deletes, authority, lifecycle, and derived network state remain review-only.'
			);
		}
	}

	private function assertCurrentState(string $optionName, string $expectedPayload): void
	{
		$this->assertCurrentNetwork();
		[$exists, $current] = $this->read($optionName);
		if ($this->codec->isMissing($expectedPayload)) {
			if ($exists) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- runtimeFailure() escapes the message.
				throw $this->runtimeFailure("Conflict: {$optionName} exists but the captured network state expects it to be absent.");
			}

			return;
		}
		if (! $exists || ! $this->codec->matches($current, $expectedPayload, $optionName)) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- runtimeFailure() escapes the message.
			throw $this->runtimeFailure("Conflict: {$optionName} changed after this network capture. Nothing was restored.");
		}
	}

	private function applyState(string $optionName, string $payload): void
	{
		[$currentExists, $current] = $this->read($optionName);
		$currentEncoded = $currentExists
			? $this->codec->encode($current, $optionName)
			: $this->codec->missing();
		if (! $currentEncoded->restorable) {
			throw new RuntimeException('The current network value cannot be retained safely for compensation. Nothing was changed.');
		}

		try {
			$this->writeState($optionName, $payload);
			$this->assertAppliedState($optionName, $payload);
		} catch (Throwable $error) {
			try {
				try {
					$this->assertAppliedState($optionName, $currentEncoded->payload);
				} catch (Throwable) {
					$this->writeState($optionName, $currentEncoded->payload);
					$this->assertAppliedState($optionName, $currentEncoded->payload);
				}
			} catch (Throwable) {
				// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- compensationFailure() escapes the message; the previous throwable is metadata.
				throw $this->compensationFailure(
					$error->getMessage() . ' The original current network value could not be restored completely.',
					true,
					$error
				);
				// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}

			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- compensationFailure() escapes the message; the previous throwable is metadata.
			throw $this->compensationFailure(
				$error->getMessage() . ' The original current network value was reapplied and verified.',
				false,
				$error
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	private function writeState(string $optionName, string $payload): void
	{
		$this->assertCurrentNetwork();
		[$exists] = $this->read($optionName);
		if ($this->codec->isMissing($payload)) {
			if ($exists) {
				$this->options->delete($optionName);
			}

			return;
		}

		$value = $this->codec->decode($payload);
		if ($exists) {
			$this->options->update($optionName, $value);
		} else {
			$this->options->add($optionName, $value);
		}
	}

	private function assertAppliedState(string $optionName, string $payload): void
	{
		[$exists, $stored] = $this->read($optionName);
		if ($this->codec->isMissing($payload)) {
			if ($exists) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- runtimeFailure() escapes the message.
				throw $this->runtimeFailure("WordPress did not preserve the restored absence of network setting {$optionName}.");
			}

			return;
		}
		if (! $exists || ! $this->codec->matches($stored, $payload, $optionName)) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- runtimeFailure() escapes the message.
			throw $this->runtimeFailure("WordPress did not preserve the restored network value of {$optionName}.");
		}
	}

	/**
	 * @return array{0: bool, 1: mixed}
	 */
	private function read(string $optionName): array
	{
		$this->assertCurrentNetwork();
		$sentinel = new \stdClass();
		$value = $this->options->get($optionName, $sentinel);

		return array($value !== $sentinel, $value);
	}

	private function recordAuditFailure(int $auditId, Throwable $error): void
	{
		$status = 'failed';
		$code = 'network_restore_failed';
		if ($error instanceof RestoreCompensationException) {
			$status = $error->compensationFailed ? 'compensation_failed' : 'compensated';
			$code = $error->compensationFailed ? 'apply_and_compensation_failed' : 'apply_failed_compensated';
		} elseif (str_starts_with($error->getMessage(), 'Conflict:')) {
			$code = 'target_conflict';
		} elseif (str_contains($error->getMessage(), 'active network change session')) {
			$code = 'capture_active';
		} elseif (str_contains($error->getMessage(), 'review-only')) {
			$code = 'network_restore_unsupported';
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

	private function assertCurrentNetwork(): void
	{
		if (! $this->scope->isCurrent()) {
			throw new RuntimeException('ConfigOps refused to restore a setting for a different WordPress network.');
		}
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
