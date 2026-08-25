<?php
/**
 * Convert completed Change Sessions into declarative private Packs.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Pack;

use ConfigOps\Adapter\AdapterRegistry;
use ConfigOps\Capture\ValueCodec;
use ConfigOps\Database\CaptureRepository;
use ConfigOps\Database\MutationRepository;
use RuntimeException;

final class PackExporter
{
	public function __construct(
		private readonly CaptureRepository $captures,
		private readonly MutationRepository $mutations,
		private readonly ValueCodec $codec,
		private readonly AdapterRegistry $adapters,
		private readonly PackPortabilityInspector $portability,
		private readonly PackValidator $validator
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function draft(int $sessionId): array
	{
		$session = $this->captures->find($sessionId);
		if (! $session) {
			throw new RuntimeException('The Change Session no longer exists.');
		}
		if ('completed' !== (string) $session->status) {
			throw new RuntimeException('Only a completed Change Session can become a Pack.');
		}
		if ('manual' !== (string) ($session->capture_mode ?? 'manual')) {
			throw new RuntimeException('Start and complete a named Change Session before saving it as a Pack.');
		}
		if ((int) ($session->capture_error_count ?? 0) > 0) {
			throw new RuntimeException('This Change Session is incomplete and cannot become a Pack.');
		}

		$rowsByOption = array();
		foreach ($this->mutations->iterateForSession($sessionId) as $mutation) {
			$rowsByOption[(string) $mutation->option_name] = $mutation;
		}
		ksort($rowsByOption, SORT_STRING);

		$items         = array();
		$eligibleCount = 0;
		foreach ($rowsByOption as $mutation) {
			$item = $this->candidate($mutation);
			$items[] = $item;
			if ($item['eligible']) {
				++$eligibleCount;
			}
		}

		return array(
			'source' => array(
				'id'          => (int) $session->id,
				'name'        => (string) $session->name,
				'status'      => (string) $session->status,
				'settingCount' => count($items),
			),
			'defaults' => array(
				'name'        => (string) $session->name,
				'description' => '',
				'packVersion' => '1.0.0',
			),
			'items'         => $items,
			'eligibleCount' => $eligibleCount,
			'excludedCount' => count($items) - $eligibleCount,
			'limits'        => array(
				'maxSettings' => PackValidator::MAX_SETTINGS,
				'maxBytes'    => PackValidator::MAX_BYTES,
			),
		);
	}

	/**
	 * @param list<string> $selectedKeys Stable candidate keys selected by the actor.
	 * @return array{filename: string, json: string}
	 */
	public function export(
		int $sessionId,
		string $name,
		string $description,
		string $packVersion,
		array $selectedKeys
	): array {
		$draft = $this->draft($sessionId);
		$selected = array_fill_keys(array_values(array_unique($selectedKeys)), true);
		$settings = array();
		$plugins  = array();
		foreach ($draft['items'] as $item) {
			if (! $item['eligible'] || ! isset($selected[$item['key']])) {
				continue;
			}
			$settings[] = $item['setting'];
			$adapter = $item['setting']['adapter'];
			if (! is_array($adapter)) {
				continue;
			}
			$manifest = $this->adapters->manifest((string) $adapter['id']);
			if (null !== $manifest && 'wordpress' !== $manifest->componentType) {
				$plugins[$manifest->pluginFile] = $manifest->testedVersion;
			}
		}
		if (empty($settings)) {
			throw new RuntimeException('Select at least one portable, non-secret setting for this Pack.');
		}
		ksort($plugins, SORT_STRING);

		$pack = array(
			'format'         => PackValidator::FORMAT,
			'schema_version' => PackValidator::SCHEMA_VERSION,
			'id'             => wp_generate_uuid4(),
			'pack_version'   => trim($packVersion),
			'name'           => sanitize_text_field($name),
			'description'    => sanitize_textarea_field($description),
			'created_at'     => gmdate('c'),
			'created_with'   => defined('CONFIGOPS_VERSION') ? (string) CONFIGOPS_VERSION : '0.0.0',
			'requirements'   => array(
				'wordpress' => '>=7.0 <7.2',
				'plugins'   => $plugins,
			),
			'variables'      => array(),
			'settings'       => $settings,
			'extensions'     => array(),
		);
		$pack = $this->validator->validate($pack);
		$slug = sanitize_title($pack['name']);
		$slug = '' === $slug ? 'configops-pack' : $slug;
		$jsonPack = $pack;
		$jsonPack['requirements']['plugins'] = (object) $jsonPack['requirements']['plugins'];
		$jsonPack['variables'] = (object) $jsonPack['variables'];
		$jsonPack['extensions'] = (object) $jsonPack['extensions'];
		$json = wp_json_encode(
			$jsonPack,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
		);
		if (! is_string($json)) {
			throw new RuntimeException('The Pack could not be encoded as JSON.');
		}

		return array(
			'filename' => sanitize_file_name($slug . '-' . $pack['pack_version'] . '.configops.json'),
			'json'     => $json . "\n",
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function candidate(object $mutation): array
	{
		$option  = (string) $mutation->option_name;
		$key     = hash('sha256', $option);
		$changes = $this->storedDiff($mutation);
		$first   = $changes[0] ?? array();
		$label   = trim((string) ($first['label'] ?? '')) ?: $option;
		$group   = trim((string) ($first['group'] ?? '')) ?: 'WordPress option';
		$reason  = '';
		$value   = null;
		$state   = $this->codec->isMissing((string) $mutation->new_value) ? 'absent' : 'present';
		$eligible = true;

		if ('derived' === (string) $mutation->classification) {
			$eligible = false;
			$reason = 'Technical and runtime values never enter a Pack.';
		} elseif (1 !== (int) $mutation->restorable || 'full' !== (string) $mutation->restore_mode) {
			$eligible = false;
			$reason = 1 === (int) ($mutation->is_redacted ?? 0)
				? 'This option contains protected data. ConfigOps excludes the complete option instead of risking a secret export.'
				: 'This option cannot be reconstructed as one complete, conflict-checked setting.';
		} elseif ('present' === $state) {
			try {
				$value = $this->codec->decode((string) $mutation->new_value);
				$encoded = $this->codec->encode($value, $option);
				if (! $encoded->restorable || $encoded->redacted) {
					throw new RuntimeException('protected');
				}
			} catch (\Throwable) {
				$eligible = false;
				$reason = 'This option contains a secret, unsupported object, or oversized value and was excluded.';
				$value = null;
			}
		}

		$adapter = null;
		$adapterId = trim((string) ($mutation->adapter_id ?? ''));
		if ('' === $adapterId) {
			$eligible = false;
			$reason = 'No tested adapter owns this setting. ConfigOps keeps it in History but will not make it portable.';
		} else {
			$schemaVersion = (int) ($mutation->adapter_schema_version ?? 0);
			$manifest = $this->adapters->manifest($adapterId);
			if (null === $manifest || $schemaVersion !== $manifest->schemaVersion) {
				$eligible = false;
				$reason = 'The adapter schema that recorded this setting is no longer available.';
			} else {
				$adapter = array('id' => $adapterId, 'schema_version' => $schemaVersion);
			}
		}

		$warnings = $eligible
			? $this->portability->inspect('present' === $state ? $value : null, $changes)
			: array();
		$setting = array(
			'option'  => $option,
			'state'   => $state,
			'adapter' => $adapter,
		);
		if ('present' === $state && $eligible) {
			$setting['value'] = $value;
		}

		return array(
			'key'            => $key,
			'option'         => $option,
			'label'          => $label,
			'group'          => $group,
			'classification' => (string) ($mutation->classification ?? 'unknown'),
			'eligible'       => $eligible,
			'selected'       => $eligible,
			'reason'         => $reason,
			'warnings'       => $warnings,
			'state'          => $state,
			'value'          => $eligible && 'present' === $state ? $value : null,
			'setting'        => $setting,
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function storedDiff(object $mutation): array
	{
		$diff = json_decode((string) ($mutation->diff ?? ''), true);

		return is_array($diff) ? array_values(array_filter($diff, 'is_array')) : array();
	}
}
