<?php
/**
 * Optional adapter contract for known non-configuration database writes.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Adapter;

interface DatabaseWriteAwareAdapter
{
	/**
	 * @param array{type: string, component: string, file: string, line: int} $source Source attribution without SQL or values.
	 */
	public function isKnownNonConfigurationWrite(string $table, array $source): bool;
}
