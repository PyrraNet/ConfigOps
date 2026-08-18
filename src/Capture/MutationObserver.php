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
	private const MAX_DIFF_BYTES = 262144;

	/** @var array<string, array{before: EncodedValue, autoload: ?string, session_id: int}> */
	private array $pendingDeletes = array();

	/** @var array<string, int> */
	private array $pendingAdds = array();

	/** @var array<string, array{session_id: int, autoload: ?string}> */
	private array $pendingUpdates = array();

	/**
	 * The latest consecutive mutation can be rewritten as one request-local
	 * baseline-to-final transition while its causal owner remains unchanged.
	 *
	 * @var array{
	 *   id: int,
	 *   session_id: int,
	 *   request_id: string,
	 *   site_id: int,
	 *   option: string,
	 *   owner: string,
	 *   before: EncodedValue,
	 *   old_autoload: ?string,
	 *   review_count: int,
	 *   technical_count: int
	 * }|null
	 */
	private ?array $aggregate = null;
	private readonly IntentContext $intent;
	private readonly SiteBoundaryGuard $siteBoundary;

	public function __construct(
		private readonly CaptureRepository $captures,
		private readonly MutationRepository $mutations,
		private readonly OptionMetadataRepository $optionMetadata,
		private readonly InternalOptionPolicy $internalOptions,
		private readonly ValueCodec $codec,
		private readonly NestedDiff $diff,
		private readonly AdapterRegistry $adapters,
		private readonly SourceAttributor $source,
		private readonly RequestContext $request,
		?IntentContext $intent = null,
		private readonly ?AutomaticRecorder $automatic = null,
		?SiteBoundaryGuard $siteBoundary = null
	) {
		$this->intent = $intent ?? new IntentContext();
		$this->siteBoundary = $siteBoundary ?? new SiteBoundaryGuard(SiteScope::current(), $captures);
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

		$requestId = $this->request->id();
		$source    = $this->source->capture();
		$owner     = $this->coalescingOwner($source);
		$aggregate = $this->aggregate;
		$canCoalesce = null !== $aggregate
			&& null !== $owner
			&& $aggregate['session_id'] === $sessionId
			&& $aggregate['request_id'] === $requestId
			&& $aggregate['site_id'] === $this->siteBoundary->scope()->siteId()
			&& $aggregate['option'] === $option
			&& $aggregate['owner'] === $owner;
		$baseline       = $canCoalesce ? $aggregate['before'] : $before;
		$baselineAutoload = $canCoalesce ? $aggregate['old_autoload'] : $oldAutoload;

		$changes = $this->diff->compare($baseline->display, $after->display);
		if (empty($changes) && ! $baseline->redacted && ! $after->redacted && $baselineAutoload === $newAutoload) {
			if ($canCoalesce) {
				$this->mutations->delete($aggregate['id']);
				$this->aggregate = null;
				$this->captures->adjustMutationCounts(
					$sessionId,
					-1,
					-$aggregate['review_count'],
					-$aggregate['technical_count']
				);
			} else {
				$this->aggregate = null;
			}

			return;
		}

		if (empty($changes) && ($baseline->redacted || $after->redacted)) {
			$changes[] = array(
				'op'       => 'replace',
				'path'     => '/',
				'before'   => '••••••••',
				'after'    => '••••••••',
				'redacted' => true,
			);
		}

		$classification = $this->adapters->analyze($option, $changes);
		$changes        = $this->intent->enrich($sessionId, $option, $classification['changes']);
		$diffJson       = wp_json_encode($changes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (! is_string($diffJson) || strlen($diffJson) > self::MAX_DIFF_BYTES) {
			$changes = array(
				array(
					'op'    => 'truncated',
					'path'  => '/',
					'after' => '[Diff omitted because it exceeds the 256 KiB display budget]',
				),
			);
			$diffJson = wp_json_encode($changes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		}

		$adapterMixedWithRuntime = null !== $classification['adapter_id']
			&& $classification['technical_change_count'] > 0;
		$fullRestore = $baseline->restorable
			&& $after->restorable
			&& $classification['allows_restore']
			&& ! $adapterMixedWithRuntime;
		$patchRestore = ! $fullRestore
			&& 'derived' !== $classification['classification']
			&& $classification['safe_restore_change_count'] > 0;
		$restoreMode = $fullRestore ? 'full' : ($patchRestore ? 'patch' : 'none');
		$mutation = array_merge(
			array(
				'session_id'                => $sessionId,
				'mutation_type'              => $this->mutationType($baseline, $after),
				'option_name'                => $option,
				'old_value'                  => $baseline->payload,
				'new_value'                  => $after->payload,
				'diff'                       => is_string($diffJson) ? $diffJson : '[]',
				'old_autoload'               => $baselineAutoload,
				'new_autoload'               => $newAutoload,
				'restorable'                 => 'none' === $restoreMode ? 0 : 1,
				'restore_mode'                => $restoreMode,
				'is_redacted'                => $baseline->redacted || $after->redacted ? 1 : 0,
				'review_change_count'         => $classification['review_change_count'],
				'technical_change_count'      => $classification['technical_change_count'],
				'secret_change_count'         => $classification['secret_change_count'],
				'safe_restore_change_count'   => $classification['safe_restore_change_count'],
				'classification'             => $classification['classification'],
				'classification_reason'      => $classification['reason'],
				'adapter_id'                 => $classification['adapter_id'],
				'adapter_schema_version'     => $classification['adapter_schema_version'],
				'component_version'          => $classification['component_version'],
			),
			$this->request->evidenceMetadata($source)
		);

		if ($canCoalesce) {
			$this->mutations->update($aggregate['id'], $mutation);
			$this->captures->adjustMutationCounts(
				$sessionId,
				0,
				$classification['review_change_count'] - $aggregate['review_count'],
				$classification['technical_change_count'] - $aggregate['technical_count']
			);
			$mutationId = $aggregate['id'];
		} else {
			$mutationId = $this->mutations->insert($mutation);
			$this->captures->incrementMutationCount(
				$sessionId,
				$classification['review_change_count'],
				$classification['technical_change_count']
			);
		}

		$this->aggregate = null === $owner ? null : array(
			'id'              => $mutationId,
			'session_id'      => $sessionId,
			'request_id'      => $requestId,
			'site_id'         => $this->siteBoundary->scope()->siteId(),
			'option'          => $option,
			'owner'           => $owner,
			'before'          => $baseline,
			'old_autoload'    => $baselineAutoload,
			'review_count'    => $classification['review_change_count'],
			'technical_count' => $classification['technical_change_count'],
		);
	}

	/**
	 * @param array{type: string, component: string, file: string, line: int} $source
	 */
	private function coalescingOwner(array $source): ?string
	{
		$type      = $source['type'];
		$component = $source['component'];
		if (! in_array($type, array('core', 'plugin', 'mu-plugin', 'theme'), true) || '' === $component) {
			return null;
		}

		return $type . ':' . $component;
	}

	private function mutationType(EncodedValue $before, EncodedValue $after): string
	{
		$beforeMissing = $this->codec->isMissing($before->payload);
		$afterMissing  = $this->codec->isMissing($after->payload);

		return $beforeMissing ? 'add' : ($afterMissing ? 'delete' : 'update');
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
