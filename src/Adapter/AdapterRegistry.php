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
use ConfigOps\Reference\ReferenceRegistry;
use ConfigOps\Reference\WordPressReferenceFields;
use Throwable;

final class AdapterRegistry implements SensitiveValueDetector, OptionValueNormalizer
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
	private readonly ReferenceRegistry $references;
	private readonly WordPressReferenceFields $wordpressReferences;

	/**
	 * @param list<mixed> $adapters Filterable adapter candidates.
	 */
	public function __construct(
		array $adapters,
		private readonly MutationClassifier $fallbackClassifier,
		private readonly ?SensitiveValueDetector $fallbackSecrets = null,
		?ReferenceRegistry $references = null,
		?WordPressReferenceFields $wordpressReferences = null
	) {
		$this->references = $references ?? new ReferenceRegistry();
		$this->wordpressReferences = $wordpressReferences ?? new WordPressReferenceFields();
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
	 * @return array{
	 *   classification: string,
	 *   reason: string,
	 *   allows_restore: bool,
	 *   adapter_id: ?string,
	 *   adapter_schema_version: ?int,
	 *   component_version: ?string,
	 *   changes: list<array<string, mixed>>,
	 *   review_change_count: int,
	 *   technical_change_count: int,
	 *   secret_change_count: int,
	 *   safe_restore_change_count: int
	 * }
	 */
	public function analyze(string $optionName, array $changes): array
	{
		$owners = $this->forOption($optionName);
		if (count($owners) > 1) {
			return $this->analysisPayload(
				$changes,
				'unknown',
				'More than one adapter claims this option. ConfigOps kept the evidence but disabled automatic interpretation and restore.',
				false,
				null,
				null,
				null,
				0
			);
		}
		$adapter = $owners[0] ?? null;
		if (null === $adapter) {
			$changes = $this->describeWordPressChanges($optionName, $changes);
			$changes = $this->references->capture($changes);
			$referenceOnly = ! empty($changes) && array() === array_filter(
				$changes,
				static fn (array $change): bool => 'reference' !== ($change['kind'] ?? null)
			);
			if ($referenceOnly) {
				return $this->analysisPayload(
					$changes,
					'reference',
					'This WordPress setting points to media on this website. ConfigOps keeps its local identity for review and conflict-checked undo.',
					true,
					null,
					null,
					null,
					0
				);
			}
			$fallback = $this->fallbackClassifier->classify($optionName);

			return $this->analysisPayload(
				$changes,
				$fallback['classification'],
				$fallback['reason'],
				'derived' !== $fallback['classification'],
				null,
				null,
				null,
				0
			);
		}

		try {
			$manifest = $this->manifests[$this->adapterId($adapter)] ?? $adapter->manifest();
			$changes  = $this->describeChanges($adapter, $optionName, $changes);
			$changes  = $this->references->capture($changes);
			$analysis = $adapter->analyze($optionName, $changes);
		} catch (Throwable) {
			return $this->analysisPayload(
				$changes,
				'unknown',
				'The owning adapter failed while interpreting this change. ConfigOps kept the evidence and disabled restore.',
				false,
				null,
				null,
				null,
				0
			);
		}
		$version       = $this->installedVersion($manifest);
		$reason        = $analysis->reason;
		$allowsRestore = $analysis->allowsGenericRestore;
		$compatible    = false;
		if (null === $version) {
			$reason = 'The owning plugin version is unavailable. ' . $reason;
			$allowsRestore = false;
		} elseif (! $this->versionMatches($version, $manifest->testedVersion)) {
			$reason = sprintf('Version %s is outside the adapter’s tested range %s. %s', $version, $manifest->testedVersion, $reason);
			$allowsRestore = false;
		} else {
			$compatible = true;
		}

		$safeRestoreCount = $compatible && $this->manifestSupportsRestore($manifest)
			? count(array_filter($changes, $this->isSafePatchChange(...)))
			: 0;

		return $this->analysisPayload(
			$changes,
			$analysis->classification,
			$reason,
			$allowsRestore,
			$manifest->id,
			$manifest->schemaVersion,
			$version,
			$safeRestoreCount
		);
	}

	/**
	 * Return only fields that can be restored without reconstructing a secret,
	 * technical side effect, unsupported value, or unknown adapter path.
	 *
	 * @param list<array<string, mixed>> $changes Stored nested diff entries.
	 * @return list<array<string, mixed>>
	 */
	public function safeRestoreChanges(
		string $adapterId,
		int $schemaVersion,
		string $optionName,
		array $changes
	): array {
		$adapter  = $this->adapters[$adapterId] ?? null;
		$manifest = $this->manifests[$adapterId] ?? null;
		if (null === $adapter || null === $manifest || $manifest->schemaVersion !== $schemaVersion) {
			return array();
		}

		$version = $this->installedVersion($manifest);
		if (
			null === $version
			|| ! $this->versionMatches($version, $manifest->testedVersion)
			|| ! $this->manifestSupportsRestore($manifest)
		) {
			return array();
		}

		try {
			$changes = $this->describeChanges($adapter, $optionName, $changes);
		} catch (Throwable) {
			return array();
		}

		return array_values(array_filter($changes, $this->isSafePatchChange(...)));
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

	public function normalizeOptionValue(string $optionName, mixed $value): mixed
	{
		$owners = $this->forOption($optionName);
		if (1 !== count($owners) || ! $owners[0] instanceof OptionValueNormalizer) {
			return $value;
		}

		$adapter = $owners[0];
		$manifest = $this->manifests[$this->adapterId($adapter)] ?? null;
		if (null === $manifest) {
			return $value;
		}
		$version = $this->installedVersion($manifest);
		if (null === $version || ! $this->versionMatches($version, $manifest->testedVersion)) {
			return $value;
		}

		try {
			return $adapter->normalizeOptionValue($optionName, $value);
		} catch (Throwable) {
			return $value;
		}
	}

	/**
	 * @param array{type: string, component: string, file: string, line: int} $source Source attribution without SQL or values.
	 */
	public function isKnownNonConfigurationWrite(string $table, array $source): bool
	{
		foreach ($this->adapters as $adapterId => $adapter) {
			if (! $adapter instanceof DatabaseWriteAwareAdapter) {
				continue;
			}

			$manifest = $this->manifests[$adapterId] ?? null;
			$version  = null === $manifest ? null : $this->installedVersion($manifest);
			if (
				null === $manifest
				|| null === $version
				|| ! $this->versionMatches($version, $manifest->testedVersion)
			) {
				continue;
			}

			try {
				if ($adapter->isKnownNonConfigurationWrite($table, $source)) {
					return true;
				}
			} catch (Throwable) {
				// A failed write-noise rule must remain visible as an unknown signal.
			}
		}

		return false;
	}

	public function field(string $adapterId, int $schemaVersion, string $optionName, string $jsonPointer): ?FieldDefinition
	{
		if ('' === $adapterId) {
			return $this->wordpressReferences->field($optionName, $jsonPointer);
		}

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

	/**
	 * Add current preview availability without changing stored evidence.
	 *
	 * @param list<array<string, mixed>> $changes
	 * @return list<array<string, mixed>>
	 */
	public function presentReferences(array $changes): array
	{
		return $this->references->present($changes);
	}

	/**
	 * @param list<array<string, mixed>> $changes
	 */
	public function assertRestorableReferences(array $changes): void
	{
		$this->references->assertRestoreTargetsAvailable($changes);
	}

	/**
	 * @param list<array<string, mixed>> $changes Nested diff entries.
	 * @return list<array<string, mixed>>
	 */
	private function describeChanges(ConfigAdapter $adapter, string $optionName, array $changes): array
	{
		$described = array();
		foreach ($changes as $change) {
			$path = is_string($change['path'] ?? null) ? $change['path'] : '/';
			$field = $adapter instanceof ChangeAwareAdapter
				? $adapter->fieldForChange($optionName, $path, $change, $changes)
				: $adapter->field($optionName, $path);
			$described[] = null === $field ? $change : $field->applyTo($change);
		}

		return $described;
	}

	/**
	 * @param list<array<string, mixed>> $changes
	 * @return list<array<string, mixed>>
	 */
	private function describeWordPressChanges(string $optionName, array $changes): array
	{
		foreach ($changes as &$change) {
			$path = is_string($change['path'] ?? null) ? $change['path'] : '/';
			$field = $this->wordpressReferences->field($optionName, $path);
			if (null !== $field) {
				$change = $field->applyTo($change);
			}
		}
		unset($change);

		return $changes;
	}

	/**
	 * @param list<array<string, mixed>> $changes Decorated nested diff entries.
	 * @return array{
	 *   classification: string,
	 *   reason: string,
	 *   allows_restore: bool,
	 *   adapter_id: ?string,
	 *   adapter_schema_version: ?int,
	 *   component_version: ?string,
	 *   changes: list<array<string, mixed>>,
	 *   review_change_count: int,
	 *   technical_change_count: int,
	 *   secret_change_count: int,
	 *   safe_restore_change_count: int
	 * }
	 */
	private function analysisPayload(
		array $changes,
		string $classification,
		string $reason,
		bool $allowsRestore,
		?string $adapterId,
		?int $schemaVersion,
		?string $componentVersion,
		int $safeRestoreCount
	): array {
		$reviewCount    = 0;
		$technicalCount = 0;
		$secretCount    = 0;
		foreach ($changes as $change) {
			$kind = is_string($change['kind'] ?? null) ? $change['kind'] : '';
			if ('derived' === $classification || 'runtime' === $kind) {
				++$technicalCount;
				continue;
			}

			++$reviewCount;
			if (
				'secret' === $kind
				|| true === ($change['redacted'] ?? false)
				|| $this->containsRedaction($change['before'] ?? null)
				|| $this->containsRedaction($change['after'] ?? null)
			) {
				++$secretCount;
			}
		}

		return array(
			'classification'            => $classification,
			'reason'                    => $reason,
			'allows_restore'            => $allowsRestore,
			'adapter_id'                 => $adapterId,
			'adapter_schema_version'     => $schemaVersion,
			'component_version'          => $componentVersion,
			'changes'                    => $changes,
			'review_change_count'        => $reviewCount,
			'technical_change_count'     => $technicalCount,
			'secret_change_count'        => $secretCount,
			'safe_restore_change_count' => $safeRestoreCount,
		);
	}

	/**
	 * @param array<string, mixed> $change Nested diff entry.
	 */
	private function isSafePatchChange(array $change): bool
	{
		$kind = is_string($change['kind'] ?? null) ? $change['kind'] : 'unknown';
		$path = is_string($change['path'] ?? null) ? $change['path'] : '/';
		$op   = is_string($change['op'] ?? null) ? $change['op'] : '';
		if (
			'/' === $path
			|| ! in_array($op, array('add', 'remove', 'replace'), true)
			|| ! in_array($kind, array('portable', 'environment', 'reference'), true)
			|| true === ($change['redacted'] ?? false)
		) {
			return false;
		}

		return ! $this->containsRedaction($change['before'] ?? null)
			&& ! $this->containsRedaction($change['after'] ?? null);
	}

	private function containsRedaction(mixed $value): bool
	{
		if ('••••••••' === $value) {
			return true;
		}
		if (! is_array($value)) {
			return false;
		}

		foreach ($value as $item) {
			if ($this->containsRedaction($item)) {
				return true;
			}
		}

		return false;
	}

	private function manifestSupportsRestore(AdapterManifest $manifest): bool
	{
		foreach ($manifest->capabilities as $capability) {
			if ('restore' === ($capability['id'] ?? '') && 'planned' !== ($capability['level'] ?? 'planned')) {
				return true;
			}
		}

		return false;
	}

	public function manifest(string $adapterId): ?AdapterManifest
	{
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
			$active     = $this->isActive($manifest);
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
				'componentType' => $manifest->componentType,
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
			&& in_array($manifest->componentType, array('plugin', 'wordpress'), true)
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

		if ('wordpress' === $manifest->componentType) {
			global $wp_version;

			$version = is_string($wp_version ?? null) ? trim($wp_version) : '';
			if ('' === $version && function_exists('get_bloginfo')) {
				$version = trim((string) get_bloginfo('version'));
			}
			$this->versions[$manifest->id] = $version;

			return '' === $version ? null : $version;
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

	private function isActive(AdapterManifest $manifest): bool
	{
		if ('wordpress' === $manifest->componentType) {
			return null !== $this->installedVersion($manifest);
		}
		if (! function_exists('get_option')) {
			return false;
		}

		$active = get_option('active_plugins', array());

		return is_array($active) && in_array($manifest->pluginFile, $active, true);
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
		$scheme = wp_parse_url($sourceUrl, PHP_URL_SCHEME);

		return 'https' === $scheme ? $sourceUrl : '';
	}
}
