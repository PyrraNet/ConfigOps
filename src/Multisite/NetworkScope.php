<?php
/**
 * Immutable identity for evidence owned by one WordPress network.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Multisite;

use RuntimeException;

final readonly class NetworkScope implements EvidenceScope
{
	public function __construct(private int $networkId)
	{
		if ($this->networkId <= 0) {
			throw new RuntimeException('A ConfigOps network scope requires a valid WordPress network identity.');
		}
	}

	public static function current(): self
	{
		if (! is_multisite()) {
			throw new RuntimeException('ConfigOps cannot create a network scope outside WordPress Multisite.');
		}

		return new self((int) get_current_network_id());
	}

	public function networkId(): int
	{
		return $this->networkId;
	}

	public function siteId(): int
	{
		return 0;
	}

	public function isNetwork(): bool
	{
		return true;
	}

	public function isCurrent(): bool
	{
		return is_multisite() && $this->networkId === (int) get_current_network_id();
	}

	public function key(string $value): string
	{
		return $this->networkId . ':network:' . $value;
	}

	/**
	 * @return array{network_id: int, site_id: int}
	 */
	public function toArray(): array
	{
		return array(
			'network_id' => $this->networkId,
			'site_id'    => 0,
		);
	}
}
