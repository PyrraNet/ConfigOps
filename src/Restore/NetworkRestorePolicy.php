<?php
/**
 * Fail-closed boundary for network state that requires a domain-specific command.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Restore;

final class NetworkRestorePolicy
{
	/** @var list<string> */
	private const REVIEW_ONLY_OPTIONS = array(
		'active_sitewide_plugins',
		'blog_count',
		'initial_db_version',
		'registration_log',
		'site_admins',
		'user_count',
	);

	public function allows(string $optionName): bool
	{
		return ! in_array($optionName, self::REVIEW_ONLY_OPTIONS, true);
	}
}
