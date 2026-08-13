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
	 *   option: string,
	 *   owner: string,
	 *   before: EncodedValue,
	 *   old_autoload: ?string,
	 *   review_count: int,
	 *   technical_count: int
	 * }|null
	 */
	private ?array $aggregate = null;

	public function __construct(
		private readonly CaptureRepository $captures,
		private readonly MutationRepository $mutations,
		private readonly OptionMetadataRepository $optionMetadata,
		private readonly InternalOptionPolicy $internalOptions,
		private readonly ValueCodec $codec,
		private readonly NestedDiff $diff,
		private readonly AdapterRegistry $adapters,
		private readonly SourceAttributor $source,
		private readonly RequestContext $request
	) {
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

		if ($this->internalOptions->isInternal($option)) {
			unset($this->pendingAdds[$option]);
			return;
		}

		$sessionId = $this->captures->activeId();
		if (null === $sessionId) {
			unset($this->pendingAdds[$option]);
			return;
		}

		// Pin ownership before WordPress writes. The corresponding after-hook may
		// run after another request has already moved the capture to `stopping`.
		$this->pendingAdds[$option] = $sessionId;
	}

	public function beforeUpdate(mixed $value, string $option, mixed $oldValue): mixed
	{
		unset($oldValue);

		if ($this->internalOptions->isInternal($option)) {
			unset($this->pendingUpdates[$option]);

			return $value;
		}

		$sessionId = $this->captures->activeId();
		if (null === $sessionId) {
			unset($this->pendingUpdates[$option]);

			return $value;
		}

		$this->pendingUpdates[$option] = array('session_id' => $sessionId, 'autoload' => null);
		try {
			$this->pendingUpdates[$option]['autoload'] = $this->optionMetadata->autoloadFor($option);
		} catch (Throwable $error) {
			$this->reportCaptureError($error, $option, $sessionId);
		}

		return $value;
	}

	public function onAdded(string $option, mixed $value): void
	{
		$sessionId = $this->pendingAdds[$option] ?? null;
		unset($this->pendingAdds[$option]);
		if ($this->internalOptions->isInternal($option) || null === $sessionId) {
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
		$pending = $this->pendingUpdates[$option] ?? null;
		unset($this->pendingUpdates[$option]);
		if ($this->internalOptions->isInternal($option) || null === $pending) {
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
		if ($this->internalOptions->isInternal($option)) {
			unset($this->pendingDeletes[$option]);

			return;
		}

		$sessionId = $this->captures->activeId();
		if (null === $sessionId) {
			unset($this->pendingDeletes[$option]);

			return;
		}

		try {
			// Whole-option credentials can be classified from their name alone. Do
			// not fetch those values into ConfigOps memory merely to redact them.
			$before = $this->codec->isEntireOptionSensitive($option)
				? $this->codec->redacted()
				: $this->codec->encode(get_option($option), $option);
			$this->pendingDeletes[$option] = array(
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
		$pending = $this->pendingDeletes[$option] ?? null;
		unset($this->pendingDeletes[$option]);
		if ($this->internalOptions->isInternal($option) || null === $pending) {
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
		$requestId = $this->request->id();
		$source    = $this->source->capture();
		$owner     = $this->coalescingOwner($source);
		$aggregate = $this->aggregate;
		$canCoalesce = null !== $aggregate
			&& null !== $owner
			&& $aggregate['session_id'] === $sessionId
			&& $aggregate['request_id'] === $requestId
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
		$changes        = $classification['changes'];
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
		$mutation = array(
			'session_id'           => $sessionId,
			'request_id'            => $requestId,
			'mutation_type'         => $this->mutationType($baseline, $after),
			'option_name'           => $option,
			'old_value'             => $baseline->payload,
			'new_value'             => $after->payload,
			'diff'                  => is_string($diffJson) ? $diffJson : '[]',
			'old_autoload'          => $baselineAutoload,
			'new_autoload'          => $newAutoload,
			'restorable'            => 'none' === $restoreMode ? 0 : 1,
			'restore_mode'           => $restoreMode,
			'is_redacted'           => $baseline->redacted || $after->redacted ? 1 : 0,
			'review_change_count'    => $classification['review_change_count'],
			'technical_change_count' => $classification['technical_change_count'],
			'secret_change_count'    => $classification['secret_change_count'],
			'safe_restore_change_count' => $classification['safe_restore_change_count'],
			'classification'        => $classification['classification'],
			'classification_reason' => $classification['reason'],
			'adapter_id'             => $classification['adapter_id'],
			'adapter_schema_version' => $classification['adapter_schema_version'],
			'component_version'      => $classification['component_version'],
			'source_type'           => $source['type'],
			'source_component'      => $source['component'],
			'source_file'           => $source['file'],
			'source_line'           => $source['line'],
			'request_method'        => $this->request->method(),
			'request_uri'           => $this->request->uri(),
			'admin_screen'          => $this->request->adminScreen(),
			'actor_id'              => $this->request->actorId(),
			'occurred_at'           => current_time('mysql', true),
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
				$sessionId = $this->captures->activeId() ?? 0;
			} catch (Throwable) {
				$sessionId = 0;
			}
		}

		try {
			$this->captures->recordCaptureError($sessionId, 'option_capture_failed');
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
}
