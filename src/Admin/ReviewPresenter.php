<?php
/**
 * Convert persistence records into stable review-template data.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Admin;

use ConfigOps\Adapter\AdapterRegistry;
use ConfigOps\Adapter\FieldDefinition;

final class ReviewPresenter
{
	public function __construct(private readonly AdapterRegistry $adapters)
	{
	}

	/**
	 * @param list<object> $mutations Stored mutations for the current page.
	 * @param array{total: int, derived: int, redacted: int, not_restorable: int} $summary Whole-session summary.
	 */
	public function present(array $mutations, array $summary, string $noticeCode, string $noticeMessage): ReviewViewModel
	{
		$grouped = array();

		foreach ($mutations as $mutation) {
			$requestId = (string) $mutation->request_id;
			$grouped[$requestId] ??= array();
			$grouped[$requestId][] = $mutation;
		}

		$groups = array();
		foreach (array_values($grouped) as $offset => $requestMutations) {
			$preparedMutations = array();
			$adapterNames      = array();
			$sourceNames       = array();
			foreach ($requestMutations as $mutation) {
				$diff = json_decode((string) $mutation->diff, true);
				$adapterId = (string) ($mutation->adapter_id ?? '');
				$manifest  = '' !== $adapterId ? $this->adapters->manifest($adapterId) : null;
				if (null !== $manifest) {
					$adapterNames[$manifest->id] = $manifest->name;
				} else {
					$sourceType = (string) ($mutation->source_type ?? 'unknown');
					$sourceComponent = (string) ($mutation->source_component ?? '');
					$sourceNames[$sourceType . ':' . $sourceComponent] = SourcePresentation::displayName(
						$sourceType,
						$sourceComponent
					);
				}
				$preparedMutations[] = array(
					'mutation'             => $mutation,
					'diff'                 => $this->prepareDiff(
						is_array($diff) ? $diff : array(),
						$adapterId,
						(int) ($mutation->adapter_schema_version ?? 0),
						(string) $mutation->option_name,
						(string) ($mutation->source_type ?? 'unknown'),
						(string) ($mutation->source_component ?? ''),
						(string) ($mutation->source_basis ?? 'caller')
					),
					'classification_label' => $this->classificationLabel((string) $mutation->classification),
					'adapter'               => null === $manifest ? null : array(
						'id'              => $manifest->id,
						'name'            => $manifest->name,
						'schemaVersion'   => (int) ($mutation->adapter_schema_version ?? 0),
						'componentVersion' => (string) ($mutation->component_version ?? ''),
					),
				);
			}
			$intent = $this->intentSummary($preparedMutations);

			$groups[] = array(
				'index'      => str_pad((string) ($offset + 1), 2, '0', STR_PAD_LEFT),
				'request_id' => (string) $requestMutations[0]->request_id,
				'head'       => $requestMutations[0],
				'title'      => 1 === count($adapterNames)
					? reset($adapterNames) . ' settings'
					: (empty($adapterNames) && 1 === count($sourceNames)
						? reset($sourceNames) . ' settings'
						: (string) ($intent['screen'] ?? '')),
				'intent'     => $intent,
				'mutations'  => $preparedMutations,
			);
		}

		$totalCount = $summary['total'];

		return new ReviewViewModel(
			$groups,
			$totalCount,
			$totalCount - $summary['derived'],
			$summary['derived'],
			$summary['redacted'],
			$totalCount > 0 && 0 === $summary['not_restorable'],
			$noticeCode,
			$this->noticeText($noticeCode, $noticeMessage)
		);
	}

	/**
	 * @param list<array{diff: list<array<string, mixed>>}> $preparedMutations
	 * @return array<string, int|string|list<string>>|null
	 */
	private function intentSummary(array $preparedMutations): ?array
	{
		$fields = array();
		$labels = array();
		$screens = array();
		$actions = array();
		$observedFields = 0;
		$allHighConfidence = true;

		foreach ($preparedMutations as $prepared) {
			foreach ($prepared['diff'] as $change) {
				$intent = is_array($change['intent'] ?? null) ? $change['intent'] : null;
				if (null === $intent) {
					continue;
				}

				$fieldName = is_string($intent['field_name'] ?? null) ? $intent['field_name'] : '';
				if ('' !== $fieldName) {
					$fields[$fieldName] = true;
				}
				$label = is_string($intent['label'] ?? null) ? $intent['label'] : '';
				if ('' !== $label) {
					$labels[$label] = true;
				}
				$screen = is_string($intent['screen'] ?? null) ? $intent['screen'] : '';
				if ('' !== $screen) {
					$screens[$screen] = true;
				}
				$action = is_string($intent['action'] ?? null) ? $intent['action'] : '';
				if ('' !== $action) {
					$actions[$action] = true;
				}
				$observedFields = max($observedFields, (int) ($intent['observed_fields'] ?? 0));
				$allHighConfidence = $allHighConfidence && 'high' === ($intent['confidence'] ?? '');
			}
		}

		$matchedFields = count($fields);
		if (0 === $matchedFields) {
			return null;
		}

		return array(
			'matchedFields'   => $matchedFields,
			'observedFields'  => max($matchedFields, $observedFields),
			'confidence'      => $allHighConfidence ? 'high' : 'medium',
			'screen'          => 1 === count($screens) ? (string) array_key_first($screens) : '',
			'action'          => 1 === count($actions) ? (string) array_key_first($actions) : '',
			'labels'          => array_slice(array_keys($labels), 0, 3),
		);
	}

	public function noticeText(string $code, string $message): string
	{
		return match ($code) {
			'started' => __('Named session started. Save the intended settings, then return to stop and review the session.', 'configops'),
			'stopped' => __('Named session stopped. Its recorded writes are ready for review.', 'configops'),
			'nothing-to-stop' => __('No named session is currently recording.', 'configops'),
			'mutation-restored' => __('The current value matched the recording, so ConfigOps restored the previous value.', 'configops'),
			'session-restored' => sprintf(
				/* translators: %d: number of restored options. */
				_n('%d option was restored.', '%d options were restored.', (int) $message, 'configops'),
				(int) $message
			),
			'error' => $message,
			default => '',
		};
	}

	private function classificationLabel(string $classification): string
	{
		return match ($classification) {
			'derived' => __('Technical side effect', 'configops'),
			'portable' => __('Reusable setting', 'configops'),
			'environment' => __('Check per website', 'configops'),
			'secret' => __('Secret hidden', 'configops'),
			'reference' => __('Website-specific link', 'configops'),
			'unsupported' => __('Outside config scope', 'configops'),
			'destructive' => __('Potentially destructive', 'configops'),
			'unknown' => __('Needs review', 'configops'),
			default => ucwords(str_replace(array('-', '_'), ' ', $classification)),
		};
	}

	/**
	 * @param list<array<string, mixed>> $diff
	 * @return list<array<string, mixed>>
	 */
	private function prepareDiff(
		array $diff,
		string $adapterId,
		int $schemaVersion,
		string $optionName,
		string $sourceType,
		string $sourceComponent,
		string $sourceBasis
	): array
	{
		foreach ($diff as &$change) {
			$path  = is_string($change['path'] ?? null) ? $change['path'] : '/';
			$field = $this->adapters->field($adapterId, $schemaVersion, $optionName, $path);
			if (null === $field) {
				$field = $this->genericField($optionName, $path, $sourceType, $sourceComponent, $sourceBasis);
			}
			if (null !== $field) {
				$change = $field->applyTo($change);
			}
		}
		unset($change);

		return $this->adapters->presentReferences($diff);
	}

	private function genericField(
		string $optionName,
		string $path,
		string $sourceType,
		string $sourceComponent,
		string $sourceBasis
	): FieldDefinition
	{
		$labels = array(
			'blogdescription'    => __('Site tagline', 'configops'),
			'blogname'           => __('Site title', 'configops'),
			'blog_public'        => __('Search engine visibility', 'configops'),
			'default_role'       => __('New user default role', 'configops'),
			'permalink_structure' => __('Permalink structure', 'configops'),
			'start_of_week'      => __('Week starts on', 'configops'),
			'timezone_string'    => __('Time zone', 'configops'),
			'users_can_register' => __('Anyone can register', 'configops'),
		);
		$label = '/' === $path && isset($labels[$optionName])
			? $labels[$optionName]
			: SourcePresentation::fieldLabel($optionName, $path);

		return new FieldDefinition(
			$label,
			SourcePresentation::settingsGroup($sourceType),
			'unknown',
			SourcePresentation::unmappedExplanation($sourceType, $sourceComponent, $sourceBasis)
		);
	}
}
