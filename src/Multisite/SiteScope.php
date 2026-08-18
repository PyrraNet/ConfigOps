<?php
/**
 * Immutable identity of the WordPress site that owns one ConfigOps request.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Multisite;

use RuntimeException;

final readonly class SiteScope
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
		$networkId = function_exists('get_current_network_id') ? (int) get_current_network_id() : 0;
		$siteId    = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1;

		return new self(max(0, $networkId), max(1, $siteId));
	}

	public function networkId(): int
	{
		return $this->networkId;
	}

	public function siteId(): int
	{
		return $this->siteId;
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
}
