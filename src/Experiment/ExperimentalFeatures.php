<?php
/**
 * Site-local, explicitly opt-in experimental behavior.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Experiment;

use RuntimeException;

final class ExperimentalFeatures
{
	public const GENERIC_ARRAY_UNDO_OPTION = 'configops_experimental_generic_array_undo';

	public function genericArrayUndoEnabled(): bool
	{
		return 1 === (int) get_option(self::GENERIC_ARRAY_UNDO_OPTION, 0);
	}

	public function setGenericArrayUndo(bool $enabled): void
	{
		update_option(self::GENERIC_ARRAY_UNDO_OPTION, $enabled ? 1 : 0, false);
		if ($enabled !== $this->genericArrayUndoEnabled()) {
			throw new RuntimeException('ConfigOps could not persist the experimental generic array undo setting.');
		}
	}

	/**
	 * @return array{genericArrayUndo: array{enabled: bool, canManage: bool}}
	 */
	public function payload(): array
	{
		return array(
			'genericArrayUndo' => array(
				'enabled'   => $this->genericArrayUndoEnabled(),
				'canManage' => current_user_can('manage_options'),
			),
		);
	}
}
