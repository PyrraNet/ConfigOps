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
			foreach ($requestMutations as $mutation) {
				$diff = json_decode((string) $mutation->diff, true);
				$adapterId = (string) ($mutation->adapter_id ?? '');
				$manifest  = '' !== $adapterId ? $this->adapters->manifest($adapterId) : null;
				if (null !== $manifest) {
					$adapterNames[$manifest->id] = $manifest->name;
				}
				$preparedMutations[] = array(
					'mutation'             => $mutation,
					'diff'                 => $this->prepareDiff(
						is_array($diff) ? $diff : array(),
						$adapterId,
						(int) ($mutation->adapter_schema_version ?? 0),
						(string) $mutation->option_name
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

			$groups[] = array(
				'index'      => str_pad((string) ($offset + 1), 2, '0', STR_PAD_LEFT),
				'request_id' => (string) $requestMutations[0]->request_id,
				'head'       => $requestMutations[0],
				'title'      => 1 === count($adapterNames) ? reset($adapterNames) . ' settings' : '',
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

	public function noticeText(string $code, string $message): string
	{
		return match ($code) {
			'started' => __('Capture started. Make the configuration change in WordPress, then return here to review it.', 'configops'),
			'stopped' => __('Capture stopped. The recorded request groups are ready for review.', 'configops'),
			'nothing-to-stop' => __('There was no active capture to stop.', 'configops'),
			'mutation-restored' => __('The supported setting values were undone after a conflict check.', 'configops'),
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
	private function prepareDiff(array $diff, string $adapterId, int $schemaVersion, string $optionName): array
	{
		foreach ($diff as &$change) {
			$path  = is_string($change['path'] ?? null) ? $change['path'] : '/';
			$field = $this->adapters->field($adapterId, $schemaVersion, $optionName, $path);
			if (
				is_string($change['label'] ?? null)
				&& is_string($change['group'] ?? null)
				&& is_string($change['kind'] ?? null)
				&& is_string($change['explanation'] ?? null)
			) {
				if (! isset($change['reference_type']) && null !== $field?->referenceType) {
					$change['reference_type'] = $field->referenceType;
				}
				continue;
			}
			if (null === $field && '/' === $path) {
				$field = $this->genericRootField($optionName);
			}
			if (null === $field) {
				continue;
			}
			$change['label']       = $field->label;
			$change['group']       = $field->group;
			$change['kind']        = $field->kind;
			$change['explanation'] = $field->explanation;
			if (null !== $field->referenceType) {
				$change['reference_type'] = $field->referenceType;
			}
		}
		unset($change);

		return $this->adapters->presentReferences($diff);
	}

	private function genericRootField(string $optionName): FieldDefinition
	{
		$labels = array(
			'blogdescription'    => __('Site tagline', 'configops'),
			'blogname'           => __('Site title', 'configops'),
			'blog_public'        => __('Search engine visibility', 'configops'),
			'default_role'       => __('New user default role', 'configops'),
			'permalink_structure' => __('Permalink structure', 'configops'),
			'site_icon'          => __('Site icon', 'configops'),
			'start_of_week'      => __('Week starts on', 'configops'),
			'timezone_string'    => __('Time zone', 'configops'),
			'users_can_register' => __('Anyone can register', 'configops'),
		);
		$label = $labels[$optionName] ?? ucwords(str_replace(array('_', '-'), ' ', $optionName));

		return new FieldDefinition(
			$label,
			__('WordPress setting', 'configops'),
			'unknown',
			__('No trusted plugin adapter describes this value yet. The exact option name remains visible for review.', 'configops')
		);
	}
}
