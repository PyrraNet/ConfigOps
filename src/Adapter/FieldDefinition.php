<?php
/**
 * Human and machine meaning for one adapter-owned configuration path.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Adapter;

final readonly class FieldDefinition
{
	public function __construct(
		public string $label,
		public string $group,
		public string $kind,
		public string $explanation,
		public ?string $referenceType = null
	) {
	}

	/**
	 * Add field meaning to a nested diff entry while preserving complete
	 * historical descriptions already stored with the capture.
	 *
	 * @param array<string, mixed> $change
	 * @return array<string, mixed>
	 */
	public function applyTo(array $change): array
	{
		$described = is_string($change['label'] ?? null)
			&& is_string($change['group'] ?? null)
			&& is_string($change['kind'] ?? null)
			&& is_string($change['explanation'] ?? null);
		if (! $described) {
			$change['label']       = $this->label;
			$change['group']       = $this->group;
			$change['kind']        = $this->kind;
			$change['explanation'] = $this->explanation;
		}
		if (! isset($change['reference_type']) && null !== $this->referenceType) {
			$change['reference_type'] = $this->referenceType;
		}

		return $change;
	}
}
