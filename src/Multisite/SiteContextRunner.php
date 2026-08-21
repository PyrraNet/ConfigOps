<?php
/**
 * Bounded iteration and context switching for WordPress sites.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Multisite;

use RuntimeException;

final class SiteContextRunner
{
	private const BATCH_SIZE = 100;

	/**
	 * @return iterable<int>
	 */
	public function siteIds(?int $networkId = null): iterable
	{
		if (! is_multisite() || ! function_exists('get_sites')) {
			yield max(1, (int) get_current_blog_id());

			return;
		}

		$offset = 0;
		do {
			$args = array(
				'fields'  => 'ids',
				'number'  => self::BATCH_SIZE,
				'offset'  => $offset,
				'orderby' => 'id',
				'order'   => 'ASC',
			);
			if (null !== $networkId && $networkId > 0) {
				$args['network_id'] = $networkId;
			}

			$ids = get_sites($args);
			$ids = array_values(array_filter(array_map('absint', is_array($ids) ? $ids : array())));
			foreach ($ids as $siteId) {
				yield $siteId;
			}
			$offset += count($ids);
		} while (count($ids) === self::BATCH_SIZE);
	}

	/**
	 * @template T
	 * @param callable(): T $operation Site-local operation.
	 * @return T
	 */
	public function run(int $siteId, callable $operation, ?int $expectedNetworkId = null): mixed
	{
		$siteId = absint($siteId);
		if ($siteId <= 0) {
			throw new RuntimeException('ConfigOps cannot enter an invalid WordPress site.');
		}
		if ($siteId === (int) get_current_blog_id()) {
			$this->assertExpectedNetwork($expectedNetworkId);

			return $operation();
		}
		if (! switch_to_blog($siteId)) {
			throw new RuntimeException('ConfigOps could not enter a WordPress site during lifecycle maintenance.');
		}

		try {
			$this->assertExpectedNetwork($expectedNetworkId);

			return $operation();
		} finally {
			if (! restore_current_blog()) {
				throw new RuntimeException('ConfigOps could not restore the WordPress site after lifecycle maintenance.');
			}
		}
	}

	private function assertExpectedNetwork(?int $expectedNetworkId): void
	{
		if (null === $expectedNetworkId) {
			return;
		}
		if (
			$expectedNetworkId <= 0
			|| $expectedNetworkId !== SiteScope::current()->networkId()
		) {
			throw new RuntimeException(
				'ConfigOps entered a site outside the expected WordPress network during lifecycle maintenance.'
			);
		}
	}
}
