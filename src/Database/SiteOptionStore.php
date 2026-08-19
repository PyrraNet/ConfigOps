<?php
/**
 * Site-local ConfigOps state stored through the normal Options API.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Database;

final readonly class SiteOptionStore implements ScopedOptionStore
{
	public function get(string $name, mixed $default = false): mixed
	{
		return get_option($name, $default);
	}

	public function add(string $name, mixed $value): bool
	{
		return add_option($name, $value, '', false);
	}

	public function update(string $name, mixed $value): bool
	{
		return update_option($name, $value, false);
	}

	public function delete(string $name): bool
	{
		return delete_option($name);
	}
}
