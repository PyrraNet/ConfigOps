<?php
/**
 * Registry and compatibility boundary for configuration adapters.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Adapter;

use ConfigOps\Capture\HeuristicSensitiveValueDetector;
use ConfigOps\Capture\SensitiveValueDetector;
use ConfigOps\Noise\MutationClassifier;
use Throwable;

final class AdapterRegistry implements SensitiveValueDetector
{
	private const MAX_ADAPTERS = 100;

	/** @var array<string, ConfigAdapter> */
	private array $adapters = array();
	/** @var array<string, AdapterManifest> */
	private array $manifests = array();
	/** @var array<int, string> */
	private array $adapterIds = array();

	/** @var array<string, string> */
	private array $versions = array();

	/**
	 * @param list<mixed> $adapters Filterable adapter candidates.
	 */
	public function __construct(
		array $adapters,
		private readonly MutationClassifier $fallbackClassifier,
		private readonly ?SensitiveValueDetector $fallbackSecrets = null
	) {
		foreach ($adapters as $adapter) {
			if (count($this->adapters) >= self::MAX_ADAPTERS) {
				$this->reportRejectedAdapter($adapter, 'registry_full');
				continue;
			}
			if (! $adapter instanceof ConfigAdapter) {
				$this->reportRejectedAdapter($adapter, 'not_an_adapter');
				continue;
			}
			try {
				$manifest = $adapter->manifest();
			} catch (Throwable) {
				$this->reportRejectedAdapter($adapter, 'manifest_failed');
				continue;
			}
			if (! $this->isValidManifest($manifest)) {
				$this->reportRejectedAdapter($adapter, 'invalid_manifest');
				continue;
			}
			if (isset($this->adapters[$manifest->id])) {
				$this->reportRejectedAdapter($adapter, 'duplicate_id');
				continue;
			}
			$this->adapters[$manifest->id]  = $adapter;
			$this->manifests[$manifest->id] = $manifest;
			$this->adapterIds[spl_object_id($adapter)] = $manifest->id;
		}
	}

	/**
	 * @param list<array<string, mixed>> $changes Nested diff entries.
	 * @return array{classification: string, reason: string, allows_restore: bool, adapter_id: ?string, adapter_schema_version: ?int, component_version: ?string}
	 */
	public function analyze(string $optionName, array $changes): array
	{
		$owners = $this->forOption($optionName);
		if (count($owners) > 1) {
			return array(
				'classification'         => 'unknown',
				'reason'                 => 'More than one adapter claims this option. ConfigOps kept the evidence but disabled automatic interpretation and restore.',
				'allows_restore'         => false,
				'adapter_id'              => null,
				'adapter_schema_version'  => null,
				'component_version'       => null,
			);
		}
		$adapter = $owners[0] ?? null;
		if (null === $adapter) {
			$fallback = $this->fallbackClassifier->classify($optionName);

			return array(
				'classification'         => $fallback['classification'],
				'reason'                 => $fallback['reason'],
				'allows_restore'         => 'derived' !== $fallback['classification'],
				'adapter_id'              => null,
				'adapter_schema_version'  => null,
				'component_version'       => null,
			);
		}

		try {
			$manifest = $this->manifests[$this->adapterId($adapter)] ?? $adapter->manifest();
			$analysis = $adapter->analyze($optionName, $changes);
		} catch (Throwable) {
			return array(
				'classification'         => 'unknown',
				'reason'                 => 'The owning adapter failed while interpreting this change. ConfigOps kept the evidence and disabled restore.',
				'allows_restore'         => false,
				'adapter_id'              => null,
				'adapter_schema_version'  => null,
				'component_version'       => null,
			);
		}
		$version       = $this->installedVersion($manifest);
		$reason        = $analysis->reason;
		$allowsRestore = $analysis->allowsGenericRestore;
		if (null === $version) {
			$reason = 'The owning plugin version is unavailable. ' . $reason;
			$allowsRestore = false;
		} elseif (! $this->versionMatches($version, $manifest->testedVersion)) {
			$reason = sprintf('Version %s is outside the adapter’s tested range %s. %s', $version, $manifest->testedVersion, $reason);
			$allowsRestore = false;
		}

		return array(
			'classification'         => $analysis->classification,
			'reason'                 => $reason,
			'allows_restore'         => $allowsRestore,
			'adapter_id'              => $manifest->id,
			'adapter_schema_version'  => $manifest->schemaVersion,
			'component_version'       => $version,
		);
	}

	public function isSensitive(string $optionName, array $path): bool
	{
		foreach ($this->forOption($optionName) as $adapter) {
			try {
				if ($adapter->isSensitive($optionName, $path)) {
					return true;
				}
			} catch (Throwable) {
				// A broken secret contract must fail closed for the complete option.
				return true;
			}
		}

		$fallback = $this->fallbackSecrets ?? new HeuristicSensitiveValueDetector();

		return $fallback->isSensitive($optionName, $path);
	}

	public function field(string $adapterId, int $schemaVersion, string $optionName, string $jsonPointer): ?FieldDefinition
	{
		$adapter = $this->adapters[$adapterId] ?? null;
		if (null === $adapter) {
			return null;
		}

		try {
			if ($this->manifests[$adapterId]->schemaVersion !== $schemaVersion) {
				return null;
			}

			return $adapter->field($optionName, $jsonPointer);
		} catch (Throwable) {
			return null;
		}
	}

	public function manifest(string $adapterId): ?AdapterManifest
	{
		$adapter = $this->adapters[$adapterId] ?? null;

		if (null === $adapter) {
			return null;
		}

		return $this->manifests[$adapterId] ?? null;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function supportPayload(): array
	{
		$result = array();
		foreach ($this->manifests as $adapterId => $manifest) {
			$version    = $this->installedVersion($manifest);
			$active     = $this->isActive($manifest->pluginFile);
			$compatible = null !== $version && $this->versionMatches($version, $manifest->testedVersion);

			$result[] = array(
				'id'            => $manifest->id,
				'name'          => $manifest->name,
				'installed'     => null !== $version,
				'active'        => $active,
				'version'       => $version,
				'testedVersion' => $manifest->testedVersion,
				'compatible'    => $compatible,
				'schemaVersion' => $manifest->schemaVersion,
				'capabilities'  => $manifest->capabilities,
				'coverage'      => $manifest->coverage,
				'limitations'   => $manifest->limitations,
				'sourceUrl'     => $this->safeSourceUrl($manifest->sourceUrl),
			);
		}

		return $result;
	}

	/**
	 * @return list<ConfigAdapter>
	 */
	private function forOption(string $optionName): array
	{
		$owners = array();
		foreach ($this->adapters as $adapter) {
			try {
				if ($adapter->ownsOption($optionName)) {
					$owners[] = $adapter;
				}
			} catch (Throwable) {
				$this->reportRejectedAdapter($adapter, 'ownership_failed');
			}
		}

		return $owners;
	}

	private function reportRejectedAdapter(mixed $adapter, string $reason): void
	{
		if (function_exists('do_action')) {
			try {
				do_action('configops_adapter_rejected', $adapter, $reason);
			} catch (Throwable) {
				// Extension diagnostics cannot break the host request.
			}
		}
	}

	private function adapterId(ConfigAdapter $adapter): string
	{
		return $this->adapterIds[spl_object_id($adapter)] ?? '';
	}

	private function isValidManifest(AdapterManifest $manifest): bool
	{
		return 1 === preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/', $manifest->id)
			&& '' !== trim($manifest->name)
			&& strlen($manifest->name) <= 100
			&& '' !== trim($manifest->pluginFile)
			&& strlen($manifest->pluginFile) <= 191
			&& ! str_contains($manifest->pluginFile, '..')
			&& ! str_starts_with($manifest->pluginFile, '/')
			&& '' !== trim($manifest->testedVersion)
			&& strlen($manifest->testedVersion) <= 80
			&& $manifest->schemaVersion > 0
			&& count($manifest->capabilities) <= 20
			&& count($manifest->coverage) <= 50
			&& count($manifest->limitations) <= 50
			&& $this->areCapabilitiesValid($manifest->capabilities)
			&& $this->areNotesValid($manifest->coverage)
			&& $this->areNotesValid($manifest->limitations);
	}

	/**
	 * @param list<mixed> $capabilities Manifest capabilities.
	 */
	private function areCapabilitiesValid(array $capabilities): bool
	{
		foreach ($capabilities as $capability) {
			if (
				! is_array($capability)
				|| ! is_string($capability['id'] ?? null)
				|| ! is_string($capability['label'] ?? null)
				|| ! is_string($capability['level'] ?? null)
				|| ! is_string($capability['note'] ?? null)
				|| 1 !== preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $capability['id'])
				|| '' === trim($capability['label'])
				|| strlen($capability['label']) > 100
				|| ! in_array($capability['level'], array('full', 'partial', 'planned'), true)
				|| strlen($capability['note']) > 500
			) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param list<mixed> $notes Coverage or limitation notes.
	 */
	private function areNotesValid(array $notes): bool
	{
		foreach ($notes as $note) {
			if (! is_string($note) || '' === trim($note) || strlen($note) > 500) {
				return false;
			}
		}

		return true;
	}

	private function installedVersion(AdapterManifest $manifest): ?string
	{
		if (array_key_exists($manifest->id, $this->versions)) {
			return '' === $this->versions[$manifest->id] ? null : $this->versions[$manifest->id];
		}

		if (! defined('WP_PLUGIN_DIR')) {
			$this->versions[$manifest->id] = '';

			return null;
		}

		if (str_contains($manifest->pluginFile, '..') || str_starts_with($manifest->pluginFile, '/')) {
			$this->versions[$manifest->id] = '';

			return null;
		}

		$file = WP_PLUGIN_DIR . '/' . $manifest->pluginFile;
		if (! is_readable($file) || ! function_exists('get_file_data')) {
			$this->versions[$manifest->id] = '';

			return null;
		}

		$data    = get_file_data($file, array('Version' => 'Version'), false);
		$version = trim((string) ($data['Version'] ?? ''));
		$this->versions[$manifest->id] = $version;

		return '' === $version ? null : $version;
	}

	private function isActive(string $pluginFile): bool
	{
		if (! function_exists('get_option')) {
			return false;
		}

		$active = get_option('active_plugins', array());

		return is_array($active) && in_array($pluginFile, $active, true);
	}

	private function versionMatches(string $version, string $constraint): bool
	{
		foreach (preg_split('/\s+/', trim($constraint)) ?: array() as $rule) {
			if (! preg_match('/^(>=|<=|>|<|=)?(.+)$/', $rule, $matches)) {
				return false;
			}
			$operator = '' === ($matches[1] ?? '') ? '=' : $matches[1];
			if (! version_compare($version, $matches[2], $operator)) {
				return false;
			}
		}

		return true;
	}

	private function safeSourceUrl(string $sourceUrl): string
	{
		$scheme = parse_url($sourceUrl, PHP_URL_SCHEME);

		return 'https' === $scheme ? $sourceUrl : '';
	}
}
