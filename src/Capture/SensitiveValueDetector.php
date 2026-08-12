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
	public function isSensitiveKey(string $key): bool;
}
