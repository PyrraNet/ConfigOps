<?php
/**
 * Strict, executable-content-free ConfigOps Pack contract.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Pack;

use RuntimeException;

final class PackValidator
{
	public const FORMAT = 'configops-pack';
	public const SCHEMA_VERSION = 1;
	public const MAX_BYTES = 1048576;
	public const MAX_SETTINGS = 250;
	private const MAX_VALUE_DEPTH = 32;
	private const MAX_VALUE_NODES = 20000;
	private const MAX_STRING_BYTES = 262144;

	/**
	 * @param array<string, mixed> $pack Untrusted decoded JSON object.
	 * @return array<string, mixed>
	 */
	public function validate(array $pack): array
	{
		$encoded = wp_json_encode($pack, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
		if (! is_string($encoded) || strlen($encoded) > self::MAX_BYTES) {
			throw new RuntimeException('The Pack exceeds the 1 MiB safety limit.');
		}

		$this->assertOnlyKeys(
			$pack,
			array(
				'format',
				'schema_version',
				'id',
				'pack_version',
				'name',
				'description',
				'created_at',
				'created_with',
				'requirements',
				'variables',
				'settings',
				'extensions',
			)
		);
		if (self::FORMAT !== ($pack['format'] ?? null)) {
			throw new RuntimeException('This file is not a ConfigOps Pack.');
		}
		if (self::SCHEMA_VERSION !== ($pack['schema_version'] ?? null)) {
			throw new RuntimeException('This ConfigOps Pack schema is not supported.');
		}

		$id = $this->requiredString($pack, 'id', 64);
		if (1 !== preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $id)) {
			throw new RuntimeException('The Pack ID must be a UUID.');
		}
		$packVersion = $this->requiredString($pack, 'pack_version', 32);
		if (1 !== preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?$/', $packVersion)) {
			throw new RuntimeException('The Pack version must use semantic versioning.');
		}

		$name        = $this->requiredString($pack, 'name', 120);
		$description = $this->optionalString($pack, 'description', 2000);
		$createdAt   = $this->requiredString($pack, 'created_at', 40);
		if (
			1 !== preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/', $createdAt)
			|| false === strtotime($createdAt)
		) {
			throw new RuntimeException('The Pack creation timestamp is invalid.');
		}
		$createdWith = $this->requiredString($pack, 'created_with', 32);
		if (1 !== preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?$/', $createdWith)) {
			throw new RuntimeException('The ConfigOps creator version is invalid.');
		}

		$requirements = $this->requirements($pack['requirements'] ?? null);
		$variables    = $pack['variables'] ?? array();
		if (! is_array($variables) || ! empty($variables)) {
			throw new RuntimeException('Pack variables are reserved but are not executable in schema version 1.');
		}
		$extensions = $pack['extensions'] ?? array();
		if (! is_array($extensions) || ! empty($extensions)) {
			throw new RuntimeException('Pack extensions are reserved for a future signed format.');
		}

		$settings = $pack['settings'] ?? null;
		if (! is_array($settings) || ! array_is_list($settings) || empty($settings)) {
			throw new RuntimeException('A ConfigOps Pack must contain at least one setting.');
		}
		if (count($settings) > self::MAX_SETTINGS) {
			throw new RuntimeException('A ConfigOps Pack can contain at most 250 settings.');
		}

		$normalizedSettings = array();
		$seen               = array();
		$nodes              = 0;
		foreach ($settings as $setting) {
			if (! is_array($setting)) {
				throw new RuntimeException('Every Pack setting must be a JSON object.');
			}
			$normalized = $this->setting($setting, $nodes);
			$option     = $normalized['option'];
			if (isset($seen[$option])) {
				throw new RuntimeException("The Pack declares {$option} more than once.");
			}
			$seen[$option]         = true;
			$normalizedSettings[] = $normalized;
		}

		return array(
			'format'         => self::FORMAT,
			'schema_version' => self::SCHEMA_VERSION,
			'id'             => strtolower($id),
			'pack_version'   => $packVersion,
			'name'           => $name,
			'description'    => $description,
			'created_at'     => gmdate('c', (int) strtotime($createdAt)),
			'created_with'   => $createdWith,
			'requirements'   => $requirements,
			'variables'      => array(),
			'settings'       => $normalizedSettings,
			'extensions'     => array(),
		);
	}

	public function versionMatches(string $version, string $constraint): bool
	{
		$alternatives = preg_split('/\s*\|\|\s*/', trim($constraint)) ?: array();
		if (empty($alternatives) || in_array('', array_map('trim', $alternatives), true)) {
			return false;
		}
		foreach ($alternatives as $alternative) {
			$matchesAlternative = true;
			foreach (preg_split('/\s+/', trim($alternative)) ?: array() as $rule) {
				if (! preg_match('/^(>=|<=|>|<|=)?([0-9][0-9A-Za-z.+-]*)$/', $rule, $matches)) {
					$matchesAlternative = false;
					break;
				}
				$operator = '' === ($matches[1] ?? '') ? '=' : $matches[1];
				if (! version_compare($version, $matches[2], $operator)) {
					$matchesAlternative = false;
					break;
				}
			}
			if ($matchesAlternative) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $setting Setting object.
	 * @return array<string, mixed>
	 */
	private function setting(array $setting, int &$nodes): array
	{
		$this->assertOnlyKeys($setting, array('option', 'state', 'value', 'adapter'));
		$option = $this->requiredString($setting, 'option', 191);
		if (1 !== preg_match('/^[A-Za-z0-9_.:-]+$/', $option)) {
			throw new RuntimeException('Pack option names may contain only letters, numbers, dots, colons, underscores, and hyphens.');
		}
		$state = isset($setting['state']) ? $this->requiredString($setting, 'state', 12) : 'present';
		if (! in_array($state, array('present', 'absent'), true)) {
			throw new RuntimeException("The Pack state for {$option} is invalid.");
		}
		if ('present' === $state && ! array_key_exists('value', $setting)) {
			throw new RuntimeException("The Pack setting {$option} has no desired value.");
		}
		if ('absent' === $state && array_key_exists('value', $setting)) {
			throw new RuntimeException("The absent Pack setting {$option} must not contain a value.");
		}

		$adapter = $setting['adapter'] ?? null;
		if (null !== $adapter) {
			if (! is_array($adapter)) {
				throw new RuntimeException("The adapter contract for {$option} is invalid.");
			}
			$this->assertOnlyKeys($adapter, array('id', 'schema_version'));
			$adapterId = $this->requiredString($adapter, 'id', 191);
			if (1 !== preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $adapterId)) {
				throw new RuntimeException("The adapter ID for {$option} is invalid.");
			}
			$schemaVersion = $adapter['schema_version'] ?? null;
			if (! is_int($schemaVersion) || $schemaVersion < 1 || $schemaVersion > 1000000) {
				throw new RuntimeException("The adapter schema for {$option} is invalid.");
			}
			$adapter = array('id' => $adapterId, 'schema_version' => $schemaVersion);
		}

		$normalized = array(
			'option'  => $option,
			'state'   => $state,
			'adapter' => $adapter,
		);
		if ('present' === $state) {
			$this->assertPortableValue($setting['value'], 0, $nodes);
			$normalized['value'] = $setting['value'];
		}

		return $normalized;
	}

	/**
	 * @return array{wordpress: string, plugins: array<string, string>}
	 */
	private function requirements(mixed $requirements): array
	{
		if (! is_array($requirements)) {
			throw new RuntimeException('The Pack requirements object is missing.');
		}
		$this->assertOnlyKeys($requirements, array('wordpress', 'plugins'));
		$wordpress = $this->requiredString($requirements, 'wordpress', 80);
		if (! $this->validConstraint($wordpress)) {
			throw new RuntimeException('The WordPress version requirement is invalid.');
		}
		$plugins = $requirements['plugins'] ?? array();
		if (
			! is_array($plugins)
			|| (array_is_list($plugins) && ! empty($plugins))
			|| (is_array($plugins) && count($plugins) > 50)
		) {
			throw new RuntimeException('The Pack plugin requirements are invalid.');
		}

		$normalized = array();
		foreach ($plugins as $pluginFile => $constraint) {
			if (
				! is_string($pluginFile)
				|| strlen($pluginFile) > 191
				|| 1 !== preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\.php$~', $pluginFile)
				|| ! is_string($constraint)
				|| strlen($constraint) > 80
				|| ! $this->validConstraint($constraint)
			) {
				throw new RuntimeException('A Pack plugin requirement is invalid.');
			}
			$normalized[$pluginFile] = trim($constraint);
		}
		ksort($normalized, SORT_STRING);

		return array('wordpress' => trim($wordpress), 'plugins' => $normalized);
	}

	private function validConstraint(string $constraint): bool
	{
		return 1 === preg_match(
			'/^(?:[><=]{0,2}[0-9][0-9A-Za-z.+-]*(?:\s+[><=]{0,2}[0-9][0-9A-Za-z.+-]*)*)(?:\s*\|\|\s*(?:[><=]{0,2}[0-9][0-9A-Za-z.+-]*(?:\s+[><=]{0,2}[0-9][0-9A-Za-z.+-]*)*))*$/',
			trim($constraint)
		);
	}

	private function assertPortableValue(mixed $value, int $depth, int &$nodes): void
	{
		++$nodes;
		if ($nodes > self::MAX_VALUE_NODES || $depth > self::MAX_VALUE_DEPTH) {
			throw new RuntimeException('A Pack value exceeds the structural safety limit.');
		}
		if (is_string($value)) {
			if (strlen($value) > self::MAX_STRING_BYTES) {
				throw new RuntimeException('A Pack string exceeds the 256 KiB safety limit.');
			}

			return;
		}
		if (null === $value || is_bool($value) || is_int($value)) {
			return;
		}
		if (is_float($value)) {
			if (! is_finite($value)) {
				throw new RuntimeException('A Pack cannot contain a non-finite number.');
			}

			return;
		}
		if (! is_array($value)) {
			throw new RuntimeException('Pack values may contain only JSON data.');
		}
		foreach ($value as $child) {
			$this->assertPortableValue($child, $depth + 1, $nodes);
		}
	}

	/**
	 * @param array<string, mixed> $value Object to inspect.
	 * @param list<string> $allowed Allowed keys.
	 */
	private function assertOnlyKeys(array $value, array $allowed): void
	{
		$unknown = array_diff(array_keys($value), $allowed);
		if (! empty($unknown)) {
			throw new RuntimeException('The Pack contains unsupported fields: ' . implode(', ', $unknown) . '.');
		}
	}

	/**
	 * @param array<string, mixed> $value Object containing the string.
	 */
	private function requiredString(array $value, string $key, int $maxBytes): string
	{
		$result = $value[$key] ?? null;
		if (! is_string($result)) {
			throw new RuntimeException("The Pack field {$key} is required.");
		}
		$result = trim($result);
		if ('' === $result || strlen($result) > $maxBytes || 1 === preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $result)) {
			throw new RuntimeException("The Pack field {$key} is invalid.");
		}

		return $result;
	}

	/**
	 * @param array<string, mixed> $value Object containing the string.
	 */
	private function optionalString(array $value, string $key, int $maxBytes): string
	{
		if (! array_key_exists($key, $value)) {
			return '';
		}
		$result = $value[$key];
		if (! is_string($result)) {
			throw new RuntimeException("The Pack field {$key} is invalid.");
		}
		$result = trim($result);
		if (strlen($result) > $maxBytes || 1 === preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $result)) {
			throw new RuntimeException("The Pack field {$key} is invalid.");
		}

		return $result;
	}
}
