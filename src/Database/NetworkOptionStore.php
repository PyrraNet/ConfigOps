<?php
/**
 * Network-owned ConfigOps state stored separately from every site's options.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Database;

use RuntimeException;

final readonly class NetworkOptionStore implements ScopedOptionStore
{
	public function __construct(private int $networkId)
	{
		if ($this->networkId <= 0) {
			throw new RuntimeException('ConfigOps network state requires a valid network ID.');
		}
	}

	public function get(string $name, mixed $default = false): mixed
	{
		return get_network_option($this->networkId, $name, $default);
	}

	public function add(string $name, mixed $value): bool
	{
		return add_network_option($this->networkId, $name, $value);
	}

	public function update(string $name, mixed $value): bool
	{
		return update_network_option($this->networkId, $name, $value);
	}

	public function delete(string $name): bool
	{
		return delete_network_option($this->networkId, $name);
	}
}
