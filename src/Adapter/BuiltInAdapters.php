<?php
/**
 * Canonical adapter set shipped with ConfigOps.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Adapter;

final class BuiltInAdapters
{
	/**
	 * @return list<ConfigAdapter>
	 */
	public static function create(): array
	{
		return array(
			new WordPressCoreAdapter(),
			new WpMailSmtpAdapter(),
			new YoastSeoAdapter(),
		);
	}
}
