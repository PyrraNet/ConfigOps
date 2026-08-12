<?php
/**
 * Extension point for adapter-aware mutation classification.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Noise;

interface MutationClassifier
{
	/**
	 * @return array{classification: string, reason: string}
	 */
	public function classify(string $optionName): array;
}
