<?php
/**
 * Recursive semantic diff using JSON Pointer paths.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Diff;

final class NestedDiff
{
	private const MAX_CHANGES = 1000;

	/**
	 * @return list<array{op: string, path: string, before?: mixed, after?: mixed}>
	 */
	public function compare(mixed $before, mixed $after): array
	{
		$changes  = array();
		$truncated = false;
		$this->walk($this->canonicalize($before), $this->canonicalize($after), '', $changes, $truncated);

		if ($truncated) {
			$changes[] = array(
				'op'    => 'truncated',
				'path'  => '/',
				'after' => '[Diff truncated after 1,000 changes]',
			);
		}

		return $changes;
	}

	/**
	 * @param list<array{op: string, path: string, before?: mixed, after?: mixed}> $changes Changes.
	 */
	private function walk(mixed $before, mixed $after, string $path, array &$changes, bool &$truncated): void
	{
		if (count($changes) >= self::MAX_CHANGES) {
			$truncated = true;
			return;
		}

		if ($before === $after) {
			return;
		}

		if (! is_array($before) || ! is_array($after) || array_is_list($before) !== array_is_list($after)) {
			$changes[] = array(
				'op'     => 'replace',
				'path'   => '' === $path ? '/' : $path,
				'before' => $before,
				'after'  => $after,
			);

			return;
		}

		if (array_is_list($before) && array_is_list($after)) {
			$keys = range(0, max(count($before), count($after)) - 1);
			if (array(0) === $keys && empty($before) && empty($after)) {
				$keys = array();
			}
		} else {
			$typedKeys = array();
			foreach (array_merge(array_keys($before), array_keys($after)) as $key) {
				$typedKeys[(is_int($key) ? 'int:' : 'string:') . $key] = $key;
			}
			ksort($typedKeys, SORT_STRING);
			$keys = array_values($typedKeys);
		}

		foreach ($keys as $key) {
			if ($truncated) {
				break;
			}

			$childPath = $path . '/' . $this->escapePointerToken((string) $key);
			$hasBefore = array_key_exists($key, $before);
			$hasAfter  = array_key_exists($key, $after);

			if (! $hasBefore) {
				$changes[] = array('op' => 'add', 'path' => $childPath, 'after' => $after[$key]);
				continue;
			}
			if (! $hasAfter) {
				$changes[] = array('op' => 'remove', 'path' => $childPath, 'before' => $before[$key]);
				continue;
			}

			$this->walk($before[$key], $after[$key], $childPath, $changes, $truncated);
		}
	}

	private function canonicalize(mixed $value): mixed
	{
		if (! is_array($value)) {
			return $value;
		}

		$result = array();
		foreach ($value as $key => $item) {
			$result[$key] = $this->canonicalize($item);
		}

		if (! array_is_list($result)) {
			uksort($result, static fn (int|string $left, int|string $right): int => (string) $left <=> (string) $right);
		}

		return $result;
	}

	private function escapePointerToken(string $token): string
	{
		return str_replace(array('~', '/'), array('~0', '~1'), $token);
	}
}
