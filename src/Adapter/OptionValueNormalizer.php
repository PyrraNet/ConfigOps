<?php
/**
 * Adapter-owned normalization for scalar values with a stable component storage type.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Adapter;

interface OptionValueNormalizer
{
	/** Normalize only a scalar option value; arrays and objects never pass through this extension point. */
	public function normalizeOptionValue(string $optionName, mixed $value): mixed;
}
