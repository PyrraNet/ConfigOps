<?php
/**
 * Storage identity for site-local or network-wide ConfigOps evidence.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Multisite;

interface EvidenceScope
{
	public function networkId(): int;

	/**
	 * Site-local evidence uses its blog ID; network evidence reserves zero.
	 */
	public function siteId(): int;

	public function isNetwork(): bool;

	public function key(string $value): string;

	/**
	 * @return array{network_id: int, site_id: int}
	 */
	public function toArray(): array;
}
