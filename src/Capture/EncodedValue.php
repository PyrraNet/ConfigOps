<?php
/**
 * A storage-safe representation of an option value.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Capture;

final readonly class EncodedValue
{
	public function __construct(
		public string $payload,
		public mixed $display,
		public bool $restorable,
		public bool $redacted
	) {
	}
}
