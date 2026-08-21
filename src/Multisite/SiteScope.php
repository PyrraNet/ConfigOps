<?php
/**
 * Immutable identity of the WordPress site that owns one ConfigOps request.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Multisite;

use RuntimeException;

final readonly class SiteScope implements EvidenceScope
{
	public function __construct(
		private int $networkId,
		private int $siteId
	) {
		if ($this->networkId < 0 || $this->siteId <= 0) {
			throw new RuntimeException('A ConfigOps site scope requires a valid WordPress site identity.');
		}
	}

	public static function current(): self
	{
		$siteId    = function_exists('get_current_blog_id') ? max(1, (int) get_current_blog_id()) : 1;
		$networkId = self::networkIdForSite($siteId);

		return new self($networkId, $siteId);
	}

	public function networkId(): int
	{
		return $this->networkId;
	}

	public function siteId(): int
	{
		return $this->siteId;
	}

	public function isNetwork(): bool
	{
		return false;
	}

	public function equals(self $other): bool
	{
		return $this->networkId === $other->networkId
			&& $this->siteId === $other->siteId;
	}

	public function isCurrent(): bool
	{
		return $this->equals(self::current());
	}

	public function key(string $value): string
	{
		return $this->networkId . ':' . $this->siteId . ':' . $value;
	}

	/**
	 * Execute an internal recovery operation against the request's owning site,
	 * then restore the caller's previous switch_to_blog() stack position.
	 *
	 * @template T
	 * @param callable(): T $operation Operation that must run in the owning site.
	 * @return T
	 */
	public function run(callable $operation): mixed
	{
		if ($this->isCurrent()) {
			return $operation();
		}

		if (! function_exists('switch_to_blog') || ! function_exists('restore_current_blog')) {
			throw new RuntimeException('ConfigOps cannot safely return to the site that owns this request.');
		}
		if (! switch_to_blog($this->siteId)) {
			throw new RuntimeException('ConfigOps could not enter the site that owns this request.');
		}

		try {
			if (! $this->isCurrent()) {
				throw new RuntimeException(
					'ConfigOps entered a site that no longer belongs to the expected WordPress network.'
				);
			}

			return $operation();
		} finally {
			if (! restore_current_blog()) {
				throw new RuntimeException('ConfigOps could not restore the previous WordPress site context.');
			}
		}
	}

	/**
	 * @return array{network_id: int, site_id: int}
	 */
	public function toArray(): array
	{
		return array(
			'network_id' => $this->networkId,
			'site_id'    => $this->siteId,
		);
	}

	private static function networkIdForSite(int $siteId): int
	{
		if (
			function_exists('is_multisite')
			&& is_multisite()
			&& function_exists('get_site')
		) {
			$site = get_site($siteId);
			if (is_object($site)) {
				$networkId = (int) ($site->site_id ?? 0);
				if ($networkId > 0) {
					return $networkId;
				}
			}

			return 0;
		}

		return function_exists('get_current_network_id') ? max(0, (int) get_current_network_id()) : 0;
	}
}
