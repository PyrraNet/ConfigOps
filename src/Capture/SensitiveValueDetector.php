<?php
/**
 * Extension point for adapter-aware secret detection.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Capture;

interface SensitiveValueDetector
{
	/**
	 * @param list<int|string> $path Full path from the option root.
	 */
	public function isSensitive(string $optionName, array $path): bool;
}
