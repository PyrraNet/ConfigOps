<?php
/**
 * Versioned installation of ConfigOps capabilities.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Access;

final class CapabilityManager
{
	private const VERSION = 1;
	private const VERSION_OPTION = 'configops_capabilities_version';

	public function maybeInstall(): void
	{
		if ((int) get_option(self::VERSION_OPTION, 0) < self::VERSION) {
			$this->install();
		}
	}

	public function install(): void
	{
		$administrator = get_role('administrator');
		if (! $administrator) {
			return;
		}

		foreach (self::capabilities() as $capability) {
			$administrator->add_cap($capability);
		}

		update_option(self::VERSION_OPTION, self::VERSION, false);
	}

	/**
	 * @return list<string>
	 */
	public static function capabilities(): array
	{
		return array(
			'configops_view',
			'configops_capture',
			'configops_rollback',
			'configops_create_release',
			'configops_plan',
			'configops_apply',
			'configops_manage_policies',
			'configops_manage_secrets',
		);
	}
}
