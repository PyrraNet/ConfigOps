<?php
/**
 * Identify ConfigOps-owned options that must never observe themselves.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Capture;

final class InternalOptionPolicy
{
	/** @var list<string> */
	private const PREFIXES = array(
		'configops_',
		'_configops_',
		'_transient_configops_',
		'_transient_timeout_configops_',
		'_site_transient_configops_',
		'_site_transient_timeout_configops_',
	);

	public function isInternal(string $optionName): bool
	{
		foreach (self::PREFIXES as $prefix) {
			if (str_starts_with($optionName, $prefix)) {
				return true;
			}
		}

		return false;
	}
}
