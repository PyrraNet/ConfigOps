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
		public string $explanation
	) {
	}
}
