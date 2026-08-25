<?php
/**
 * Observe mutations made through the WordPress Options API.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Capture;

use ConfigOps\Adapter\AdapterRegistry;
use ConfigOps\Database\CaptureRepository;
use ConfigOps\Database\MutationRepository;
use ConfigOps\Database\OptionMetadataRepository;
use ConfigOps\Diff\NestedDiff;
use ConfigOps\Multisite\SiteBoundaryGuard;
use ConfigOps\Multisite\SiteScope;
use Throwable;

final class MutationObserver
{
	/** @var array<string, array{before: EncodedValue, autoload: ?string, session_id: int}> */
	private array $pendingDeletes = array();

	/** @var array<string, int> */
	private array $pendingAdds = array();

	/** @var array<string, array{session_id: int, autoload: ?string}> */
	private array $pendingUpdates = array();

	private readonly SiteBoundaryGuard $siteBoundary;
	private readonly MutationRecorder $recorder;

	public function __construct(
		private readonly CaptureRepository $captures,
		MutationRepository $mutations,
		private readonly OptionMetadataRepository $optionMetadata,
		private readonly InternalOptionPolicy $internalOptions,
		private readonly ValueCodec $codec,
		NestedDiff $diff,
		AdapterRegistry $adapters,
		SourceAttributor $source,
		RequestContext $request,
		?IntentContext $intent = null,
		private readonly ?AutomaticRecorder $automatic = null,
		?SiteBoundaryGuard $siteBoundary = null,
		?RegisteredSettingAttributor $registeredSettings = null
	) {
		$this->siteBoundary = $siteBoundary ?? new SiteBoundaryGuard(SiteScope::current(), $captures);
		$this->recorder = new MutationRecorder(
			$captures,
			$mutations,
			$codec,
			$diff,
			$adapters,
			$source,
			$request,
			$this->siteBoundary->scope(),
			$intent ?? new IntentContext(),
			$registeredSettings
		);
	}

	public function register(): void
	{
		add_action('add_option', array($this, 'beforeAdd'), 1, 2);
		add_filter('pre_update_option', array($this, 'beforeUpdate'), 1, 3);
		add_action('added_option', array($this, 'onAdded'), 10, 2);
		add_action('updated_option', array($this, 'onUpdated'), 10, 3);
		add_action('delete_option', array($this, 'beforeDelete'), 10, 1);
		add_action('deleted_option', array($this, 'onDeleted'), 10, 1);
	}

	public function beforeAdd(string $option, mixed $value): void
	{
		unset($value);
		$key = $this->pendingKey($option);

		if ($this->internalOptions->isInternal($option)) {
			unset($this->pendingAdds[$key]);
			return;
		}

		$sessionId = $this->sessionId();
		if (null === $sessionId) {
			unset($this->pendingAdds[$key]);
			return;
		}

		// Pin ownership before WordPress writes. The corresponding after-hook may
		// run after another request has already moved the capture to `stopping`.
		$this->pendingAdds[$key] = $sessionId;
	}

	public function beforeUpdate(mixed $value, string $option, mixed $oldValue): mixed
	{
		unset($oldValue);
		$key = $this->pendingKey($option);

		if ($this->internalOptions->isInternal($option)) {
			unset($this->pendingUpdates[$key]);

			return $value;
		}
		$sessionId = $this->sessionId();
		if (null === $sessionId) {
			unset($this->pendingUpdates[$key]);

			return $value;
		}

		$this->pendingUpdates[$key] = array('session_id' => $sessionId, 'autoload' => null);
		try {
			$this->pendingUpdates[$key]['autoload'] = $this->optionMetadata->autoloadFor($option);
		} catch (Throwable $error) {
			$this->reportCaptureError($error, $option, $sessionId);
		}

		return $value;
	}

	public function onAdded(string $option, mixed $value): void
	{
		$key = $this->pendingKey($option);
		$sessionId = $this->pendingAdds[$key] ?? null;
		unset($this->pendingAdds[$key]);
		if ($this->internalOptions->isInternal($option) || null === $sessionId) {
			return;
		}
		if (! $this->siteBoundary->acceptsCurrentSite($sessionId)) {
			return;
		}

		try {
			$this->record(
				$sessionId,
				$option,
				$this->codec->missing(),
				$this->codec->encode($value, $option),
				null,
				$this->optionMetadata->autoloadFor($option)
			);
		} catch (Throwable $error) {
			$this->reportCaptureError($error, $option, $sessionId);
		}
	}

	public function onUpdated(string $option, mixed $oldValue, mixed $value): void
	{
		$key = $this->pendingKey($option);
		$pending = $this->pendingUpdates[$key] ?? null;
		unset($this->pendingUpdates[$key]);
		if ($this->internalOptions->isInternal($option) || null === $pending) {
			return;
		}
		if (! $this->siteBoundary->acceptsCurrentSite($pending['session_id'])) {
			return;
		}

		try {
			$this->record(
				$pending['session_id'],
				$option,
				$this->codec->encode($oldValue, $option),
				$this->codec->encode($value, $option),
				$pending['autoload'],
				$this->optionMetadata->autoloadFor($option)
			);
		} catch (Throwable $error) {
			$this->reportCaptureError($error, $option, $pending['session_id']);
		}
	}

	public function beforeDelete(string $option): void
	{
		$key = $this->pendingKey($option);
		if ($this->internalOptions->isInternal($option)) {
			unset($this->pendingDeletes[$key]);

			return;
		}
		$sessionId = $this->sessionId();
		if (null === $sessionId) {
			unset($this->pendingDeletes[$key]);

			return;
		}

		try {
			// Whole-option credentials can be classified from their name alone. Do
			// not fetch those values into ConfigOps memory merely to redact them.
			$before = $this->codec->isEntireOptionSensitive($option)
				? $this->codec->redacted()
				: $this->codec->encode(get_option($option), $option);
			$this->pendingDeletes[$key] = array(
				'before'   => $before,
				'autoload' => $this->optionMetadata->autoloadFor($option),
				'session_id' => $sessionId,
			);
		} catch (Throwable $error) {
			$this->reportCaptureError($error, $option, $sessionId);
		}
	}

	public function onDeleted(string $option): void
	{
		$key = $this->pendingKey($option);
		$pending = $this->pendingDeletes[$key] ?? null;
		unset($this->pendingDeletes[$key]);
		if ($this->internalOptions->isInternal($option) || null === $pending) {
			return;
		}
		if (! $this->siteBoundary->acceptsCurrentSite($pending['session_id'])) {
			return;
		}

		try {
			$this->record(
				$pending['session_id'],
				$option,
				$pending['before'],
				$this->codec->missing(),
				$pending['autoload'],
				null
			);
		} catch (Throwable $error) {
			$this->reportCaptureError($error, $option, $pending['session_id']);
		}
	}

	private function record(
		int $sessionId,
		string $option,
		EncodedValue $before,
		EncodedValue $after,
		?string $oldAutoload,
		?string $newAutoload
	): void {
		if (! $this->siteBoundary->acceptsCurrentSite($sessionId)) {
			return;
		}

		$this->recorder->record($sessionId, $option, $before, $after, $oldAutoload, $newAutoload);
	}

	private function reportCaptureError(Throwable $error, string $option, ?int $pinnedSessionId = null): void
	{
		$sessionId = $pinnedSessionId ?? 0;
		if ($sessionId <= 0) {
			try {
				$sessionId = $this->sessionId(false) ?? 0;
			} catch (Throwable) {
				$sessionId = 0;
			}
		}

		try {
			$this->siteBoundary->recordCaptureError($sessionId, 'option_capture_failed');
		} catch (Throwable $integrityError) {
			try {
				do_action('configops_capture_integrity_error', $integrityError, $option, $sessionId);
			} catch (Throwable) {
				// Observer diagnostics cannot break the host settings request.
			}
		}

		try {
			do_action('configops_capture_error', $error, $option, $sessionId);
		} catch (Throwable) {
			// Observer diagnostics cannot break the host settings request.
		}

		// Observers must never throw into the host settings request.
	}

	private function sessionId(bool $createAutomatic = true): ?int
	{
		if (null !== $this->automatic) {
			return $this->automatic->sessionId($createAutomatic);
		}
		if (! $this->siteBoundary->acceptsCurrentSite()) {
			return null;
		}

		return $this->captures->activeId();
	}

	private function pendingKey(string $option): string
	{
		return $this->siteBoundary->key($option);
	}
}
