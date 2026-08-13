<?php
/**
 * Reusable path semantics for Options API adapters.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Adapter;

abstract class AbstractOptionAdapter implements ConfigAdapter
{
	/** @var array<string, array<string, FieldDefinition>> */
	private array $fields = array();

	final protected function define(
		string $optionName,
		string $path,
		string $label,
		string $group,
		string $kind,
		string $explanation,
		?string $referenceType = null
	): void
	{
		$this->fields[$optionName][$path] = new FieldDefinition($label, $group, $kind, $explanation, $referenceType);
	}

	/**
	 * Register a declarative group of exact option fields.
	 *
	 * @param array<string, array{0: string, 1: string, 2: string, 3: string, 4?: string}> $fields
	 */
	final protected function defineFields(string $optionName, array $fields): void
	{
		foreach ($fields as $path => $definition) {
			$this->define(
				$optionName,
				$path,
				$definition[0],
				$definition[1],
				$definition[2],
				$definition[3],
				$definition[4] ?? null
			);
		}
	}

	public function field(string $optionName, string $jsonPointer): ?FieldDefinition
	{
		if (isset($this->fields[$optionName][$jsonPointer])) {
			return $this->fields[$optionName][$jsonPointer];
		}

		return $this->fallbackField($jsonPointer);
	}

	/**
	 * @param list<array<string, mixed>> $changes Nested diff entries.
	 */
	final protected function analyzeFields(string $optionName, array $changes): AdapterAnalysis
	{
		$kinds = array();
		foreach ($changes as $change) {
			if (is_string($change['kind'] ?? null)) {
				$kinds[] = $change['kind'];
				continue;
			}
			if (true === ($change['redacted'] ?? false)) {
				$kinds[] = 'secret';
				continue;
			}
			$path  = is_string($change['path'] ?? null) ? $change['path'] : '/';
			$field = $this->field($optionName, $path);
			$kinds[] = $field?->kind ?? 'unknown';
		}

		$kinds = array_values(array_unique($kinds));
		if (in_array('secret', $kinds, true)) {
			return new AdapterAnalysis('secret', 'A credential changed. Its value was removed before ConfigOps stored the capture.', false);
		}
		if (in_array('unsupported', $kinds, true)) {
			return new AdapterAnalysis('unsupported', 'This data belongs to content or an unsupported plugin area, not portable configuration.', false);
		}
		if (in_array('reference', $kinds, true)) {
			return new AdapterAnalysis('reference', 'This setting points to an object on this website and must be resolved again on another site.');
		}
		if (in_array('environment', $kinds, true)) {
			return new AdapterAnalysis('environment', 'At least one value may need to differ between staging and production.');
		}
		if (! empty($kinds) && array() === array_diff($kinds, array('runtime'))) {
			return new AdapterAnalysis('derived', 'The plugin generated this runtime or maintenance value. It is hidden from normal review.', false);
		}
		if (! empty($kinds) && ! in_array('unknown', $kinds, true)) {
			return new AdapterAnalysis('portable', 'The adapter recognizes these settings as reusable configuration.');
		}

		return new AdapterAnalysis('unknown', 'The plugin owns this value, but this path is not yet described by the tested adapter schema.');
	}

	protected function fallbackField(string $jsonPointer): ?FieldDefinition
	{
		$parts = $this->pointerParts($jsonPointer);
		if (empty($parts)) {
			return null;
		}

		$label = $this->humanize((string) $parts[array_key_last($parts)]);

		return new FieldDefinition($label, 'Other settings', 'unknown', 'Recognized as plugin data, but not yet assigned a portable meaning.');
	}

	/**
	 * @return list<string>
	 */
	final protected function pointerParts(string $jsonPointer): array
	{
		if ('/' === $jsonPointer || '' === $jsonPointer) {
			return array();
		}

		return array_map(
			static fn (string $part): string => str_replace(array('~1', '~0'), array('/', '~'), $part),
			explode('/', ltrim($jsonPointer, '/'))
		);
	}

	final protected function humanize(string $key): string
	{
		$withSpaces = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $key) ?? $key;
		$withSpaces = preg_replace('/[_-]+/', ' ', $withSpaces) ?? $withSpaces;

		return ucwords(trim($withSpaces));
	}

	/**
	 * @param list<int|string> $path Value path.
	 */
	final protected function pathMatchesSecret(array $path): bool
	{
		$key = strtolower((string) ($path[array_key_last($path)] ?? ''));

		return 1 === preg_match('/(^|_)(pass|password|secret|token|api_key|private_key|client_secret|consumer_secret|license_key)($|_)/', $key);
	}
}
