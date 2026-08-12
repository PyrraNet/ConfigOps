<?php
/**
 * Capture-time decision returned by a configuration adapter.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Adapter;

final readonly class AdapterAnalysis
{
	public function __construct(
		public string $classification,
		public string $reason,
		public bool $allowsGenericRestore = true
	) {
	}
}
