<?php
/**
 * Small state channel scoped to the same owner as persisted evidence.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Database;

interface ScopedOptionStore
{
	public function get(string $name, mixed $default = false): mixed;

	public function add(string $name, mixed $value): bool;

	public function update(string $name, mixed $value): bool;

	public function delete(string $name): bool;
}
