<?php
/**
 * Resolve bounded identity evidence for one website-local reference type.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Reference;

interface ReferenceResolver
{
	public function type(): string;

	/**
	 * @return array<string, bool|int|string>|null
	 */
	public function snapshot(mixed $value): ?array;

	/**
	 * Add current, non-persisted presentation state to stored identity evidence.
	 *
	 * @param array<string, mixed> $snapshot
	 * @return array<string, mixed>
	 */
	public function present(array $snapshot): array;

	/**
	 * A local undo may only restore references that still resolve on this site.
	 *
	 * @param array<string, mixed> $snapshot
	 */
	public function isAvailable(array $snapshot): bool;
}
