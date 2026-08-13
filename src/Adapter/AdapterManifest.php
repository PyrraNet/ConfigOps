<?php
/**
 * Stable identity and honest support contract for an adapter.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Adapter;

final readonly class AdapterManifest
{
	/**
	 * @param list<array{id: string, label: string, level: string, note: string}> $capabilities
	 * @param list<string> $coverage
	 * @param list<string> $limitations
	 */
	public function __construct(
		public string $id,
		public string $name,
		public string $pluginFile,
		public string $testedVersion,
		public int $schemaVersion,
		public array $capabilities,
		public array $coverage,
		public array $limitations,
		public string $sourceUrl,
		public string $componentType = 'plugin'
	) {
	}
}
