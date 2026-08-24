<?php
/**
 * Conservative three-way patch eligibility for unclaimed wp_options arrays.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Restore;

use ConfigOps\Adapter\AdapterRegistry;
use ConfigOps\Capture\ValueCodec;
use ConfigOps\Experiment\ExperimentalFeatures;
use Throwable;

final readonly class GenericArrayUndo
{
	private const MAX_CHANGES = 100;
	private const MAX_PATH_DEPTH = 16;

	public function __construct(
		private ValueCodec $codec,
		private ExperimentalFeatures $features,
		private AdapterRegistry $adapters
	) {
	}

	/**
	 * Return a complete, snapshot-verified patch or nothing. Partial acceptance
	 * would make a generic option look safer than the captured evidence proves.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function changesFor(object $mutation): array
	{
		if (
			! $this->features->genericArrayUndoEnabled()
			|| 1 !== (int) ($mutation->restorable ?? 0)
			|| 0 !== (int) ($mutation->is_redacted ?? 0)
			|| 0 !== (int) ($mutation->secret_change_count ?? 0)
			|| 'update' !== (string) ($mutation->mutation_type ?? '')
			|| 'unknown' !== (string) ($mutation->classification ?? '')
			|| '' !== (string) ($mutation->adapter_id ?? '')
			|| ! $this->adapters->isOptionUnclaimed((string) ($mutation->option_name ?? ''))
		) {
			return array();
		}

		try {
			$before = $this->codec->decode((string) ($mutation->old_value ?? ''));
			$after = $this->codec->decode((string) ($mutation->new_value ?? ''));
			$diff = json_decode((string) ($mutation->diff ?? ''), true, 64, JSON_THROW_ON_ERROR);
		} catch (Throwable) {
			return array();
		}

		if (
			! $this->isSettingsMap($before)
			|| ! $this->isSettingsMap($after)
			|| ! is_array($diff)
			|| empty($diff)
			|| count($diff) > self::MAX_CHANGES
		) {
			return array();
		}

		$changes = array_values(array_filter($diff, 'is_array'));
		if (count($changes) !== count($diff) || $this->hasOverlappingPaths($changes)) {
			return array();
		}

		foreach ($changes as $change) {
			if (! $this->isSnapshotVerifiedChange($before, $after, $change)) {
				return array();
			}
		}

		return $changes;
	}

	/**
	 * The current value must retain the same unambiguous map parents used to
	 * validate the snapshots. This prevents a numeric-looking string key such
	 * as "01" from being reinterpreted as list index 1 after structural drift.
	 *
	 * @param array<int|string, mixed>      $current
	 * @param list<array<string, mixed>>    $changes
	 */
	public function currentStructureSupports(array $current, array $changes): bool
	{
		foreach ($changes as $change) {
			$path = is_string($change['path'] ?? null) ? $change['path'] : '';
			if ('' === $path || '/' === $path) {
				return false;
			}

			$parts = $this->pointerParts($path);
			if (empty($parts) || ! $this->hasAssociativeParents($current, $parts)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array<int|string, mixed> $before
	 * @param array<int|string, mixed> $after
	 * @param array<string, mixed>     $change
	 */
	private function isSnapshotVerifiedChange(array $before, array $after, array $change): bool
	{
		$operation = is_string($change['op'] ?? null) ? $change['op'] : '';
		$path = is_string($change['path'] ?? null) ? $change['path'] : '';
		$kind = is_string($change['kind'] ?? null) ? $change['kind'] : '';
		if (
			! in_array($operation, array('add', 'remove', 'replace'), true)
			|| '' === $path
			|| '/' === $path
			|| ! str_starts_with($path, '/')
			|| 1 === preg_match('/~(?![01])/', $path)
			|| ! in_array($kind, array('', 'unknown'), true)
			|| true === ($change['redacted'] ?? false)
		) {
			return false;
		}

		$parts = $this->pointerParts($path);
		if (empty($parts) || count($parts) > self::MAX_PATH_DEPTH) {
			return false;
		}
		if (! $this->hasAssociativeParents($before, $parts) || ! $this->hasAssociativeParents($after, $parts)) {
			return false;
		}

		[$beforeExists, $beforeValue] = $this->valueAtPath($before, $parts);
		[$afterExists, $afterValue] = $this->valueAtPath($after, $parts);
		return match ($operation) {
			'add' => ! $beforeExists
				&& $afterExists
				&& array_key_exists('after', $change)
				&& $this->codec->semanticallyEqual($afterValue, $change['after']),
			'remove' => $beforeExists
				&& ! $afterExists
				&& array_key_exists('before', $change)
				&& $this->codec->semanticallyEqual($beforeValue, $change['before']),
			'replace' => $beforeExists
				&& $afterExists
				&& array_key_exists('before', $change)
				&& array_key_exists('after', $change)
				&& $this->codec->semanticallyEqual($beforeValue, $change['before'])
				&& $this->codec->semanticallyEqual($afterValue, $change['after']),
			default => false,
		};
	}

	/**
	 * Every traversed parent must be an associative settings map. A list may be
	 * replaced as one leaf, but generic index surgery is deliberately excluded.
	 *
	 * @param array<int|string, mixed> $value
	 * @param list<string>             $parts
	 */
	private function hasAssociativeParents(array $value, array $parts): bool
	{
		$current = $value;
		foreach (array_slice($parts, 0, -1) as $part) {
			if (! $this->isSettingsMap($current)) {
				return false;
			}
			$key = $this->arrayKey($current, $part);
			if (! array_key_exists($key, $current)) {
				// A missing branch is valid only at the terminal add/remove key.
				return false;
			}
			$current = $current[$key];
			if (! is_array($current)) {
				return false;
			}
		}

		return $this->isSettingsMap($current);
	}

	private function isSettingsMap(mixed $value): bool
	{
		if (! is_array($value) || array_is_list($value)) {
			return false;
		}

		foreach (array_keys($value) as $key) {
			if (! is_string($key)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array<int|string, mixed> $value
	 * @param list<string>             $parts
	 * @return array{0: bool, 1: mixed}
	 */
	private function valueAtPath(array $value, array $parts): array
	{
		$current = $value;
		foreach ($parts as $part) {
			if (! is_array($current)) {
				return array(false, null);
			}
			$key = $this->arrayKey($current, $part);
			if (! array_key_exists($key, $current)) {
				return array(false, null);
			}
			$current = $current[$key];
		}

		return array(true, $current);
	}

	/**
	 * @param list<array<string, mixed>> $changes
	 */
	private function hasOverlappingPaths(array $changes): bool
	{
		$paths = array();
		foreach ($changes as $change) {
			$path = is_string($change['path'] ?? null) ? $change['path'] : '';
			foreach ($paths as $existing) {
				if (str_starts_with($path . '/', $existing . '/') || str_starts_with($existing . '/', $path . '/')) {
					return true;
				}
			}
			$paths[] = $path;
		}

		return false;
	}

	/**
	 * @return list<string>
	 */
	private function pointerParts(string $pointer): array
	{
		return array_map(
			static fn (string $part): string => str_replace(array('~1', '~0'), array('/', '~'), $part),
			explode('/', ltrim($pointer, '/'))
		);
	}

	/**
	 * @param array<int|string, mixed> $value
	 */
	private function arrayKey(array $value, string $part): int|string
	{
		if (array_key_exists($part, $value)) {
			return $part;
		}
		if (ctype_digit($part) && array_key_exists((int) $part, $value)) {
			return (int) $part;
		}

		return $part;
	}
}
