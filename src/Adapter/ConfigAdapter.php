<?php
/**
 * Capture semantics shared by all ConfigOps plugin adapters.
 *
 * Apply and verification are deliberately separate future capabilities. A
 * recorder adapter must not imply that remote deployment is already safe.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Adapter;

interface ConfigAdapter
{
	public function manifest(): AdapterManifest;

	public function ownsOption(string $optionName): bool;

	/**
	 * @param list<array<string, mixed>> $changes Nested diff entries.
	 */
	public function analyze(string $optionName, array $changes): AdapterAnalysis;

	public function field(string $optionName, string $jsonPointer): ?FieldDefinition;

	/**
	 * @param list<int|string> $path Full path from the option root.
	 */
	public function isSensitive(string $optionName, array $path): bool;
}
