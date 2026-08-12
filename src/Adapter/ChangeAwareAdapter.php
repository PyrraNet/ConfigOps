<?php
/**
 * Optional adapter contract for interpreting a field with its captured values.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Adapter;

interface ChangeAwareAdapter
{
	/**
	 * @param array<string, mixed>       $change One nested diff entry.
	 * @param list<array<string, mixed>> $changes Every diff entry for the option mutation.
	 */
	public function fieldForChange(
		string $optionName,
		string $jsonPointer,
		array $change,
		array $changes
	): ?FieldDefinition;
}
