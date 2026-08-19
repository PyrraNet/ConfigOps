<?php
/**
 * Scope-aware persistence of one already-observed configuration transition.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Capture;

use ConfigOps\Adapter\AdapterRegistry;
use ConfigOps\Database\CaptureRepository;
use ConfigOps\Database\MutationRepository;
use ConfigOps\Diff\NestedDiff;
use ConfigOps\Multisite\EvidenceScope;

final class MutationRecorder
{
	private const MAX_DIFF_BYTES = 262144;

	/**
	 * @var array{
	 *   id: int,
	 *   session_id: int,
	 *   request_id: string,
	 *   scope_key: string,
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
		private readonly ValueCodec $codec,
		private readonly NestedDiff $diff,
		private readonly AdapterRegistry $adapters,
		private readonly SourceAttributor $source,
		private readonly RequestContext $request,
		private readonly EvidenceScope $scope,
		private readonly IntentContext $intent
	) {
	}

	public function record(
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
			&& $aggregate['scope_key'] === $this->scope->key($option)
			&& $aggregate['option'] === $option
			&& $aggregate['owner'] === $owner;
		$baseline         = $canCoalesce ? $aggregate['before'] : $before;
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
				'session_id'               => $sessionId,
				'mutation_type'             => $this->mutationType($baseline, $after),
				'option_name'               => $option,
				'old_value'                 => $baseline->payload,
				'new_value'                 => $after->payload,
				'diff'                      => is_string($diffJson) ? $diffJson : '[]',
				'old_autoload'              => $baselineAutoload,
				'new_autoload'              => $newAutoload,
				'restorable'                => 'none' === $restoreMode ? 0 : 1,
				'restore_mode'              => $restoreMode,
				'is_redacted'               => $baseline->redacted || $after->redacted ? 1 : 0,
				'review_change_count'        => $classification['review_change_count'],
				'technical_change_count'     => $classification['technical_change_count'],
				'secret_change_count'        => $classification['secret_change_count'],
				'safe_restore_change_count'  => $classification['safe_restore_change_count'],
				'classification'            => $classification['classification'],
				'classification_reason'     => $classification['reason'],
				'adapter_id'                => $classification['adapter_id'],
				'adapter_schema_version'    => $classification['adapter_schema_version'],
				'component_version'         => $classification['component_version'],
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
			'scope_key'       => $this->scope->key($option),
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
}
