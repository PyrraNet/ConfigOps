<?php
/**
 * Read metadata that the public Options API hooks do not expose.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Database;

use wpdb;

final class OptionMetadataRepository
{
	public function __construct(private readonly wpdb $database)
	{
	}

	public function autoloadFor(string $optionName): ?string
	{
		$value = $this->database->get_var(
			$this->database->prepare(
				"SELECT autoload FROM {$this->database->options} WHERE option_name = %s",
				$optionName
			)
		);

		return is_string($value) ? $value : null;
	}
}
