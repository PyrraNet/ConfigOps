<?php
/**
 * Failure-isolated reference enrichment for capture, review, and local undo.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Reference;

use RuntimeException;
use Throwable;

final class ReferenceRegistry
{
	/** @var array<string, ReferenceResolver> */
	private array $resolvers = array();

	/**
	 * @param list<mixed> $resolvers
	 */
	public function __construct(array $resolvers = array())
	{
		$resolvers = empty($resolvers) ? array(new MediaReferenceResolver()) : $resolvers;
		foreach ($resolvers as $resolver) {
			if (! $resolver instanceof ReferenceResolver) {
				continue;
			}
			try {
				$type = $resolver->type();
			} catch (Throwable) {
				continue;
			}
			if (1 === preg_match('/^[a-z][a-z0-9-]{1,31}$/', $type) && ! isset($this->resolvers[$type])) {
				$this->resolvers[$type] = $resolver;
			}
		}
	}

	/**
	 * @param list<array<string, mixed>> $changes
	 * @return list<array<string, mixed>>
	 */
	public function capture(array $changes): array
	{
		foreach ($changes as &$change) {
			$type = is_string($change['reference_type'] ?? null) ? $change['reference_type'] : '';
			$resolver = $this->resolvers[$type] ?? null;
			if (null === $resolver) {
				continue;
			}

			foreach (array('before', 'after') as $side) {
				if (! array_key_exists($side, $change)) {
					continue;
				}
				try {
					$snapshot = $resolver->snapshot($change[$side]);
				} catch (Throwable) {
					$snapshot = null;
				}
				if (null !== $snapshot) {
					$change[$side . '_reference'] = $snapshot;
				}
			}
		}
		unset($change);

		return $changes;
	}

	/**
	 * @param list<array<string, mixed>> $changes
	 * @return list<array<string, mixed>>
	 */
	public function present(array $changes): array
	{
		foreach ($changes as &$change) {
			foreach (array('before_reference', 'after_reference') as $key) {
				$snapshot = $change[$key] ?? null;
				if (! is_array($snapshot)) {
					continue;
				}
				$type = is_string($snapshot['type'] ?? null) ? $snapshot['type'] : '';
				$resolver = $this->resolvers[$type] ?? null;
				if (null === $resolver) {
					continue;
				}
				try {
					$change[$key] = $resolver->present($snapshot);
				} catch (Throwable) {
					$change[$key] = $snapshot;
				}
			}
		}
		unset($change);

		return $changes;
	}

	/**
	 * Validate the values an undo is about to restore, without changing anything.
	 *
	 * @param list<array<string, mixed>> $changes
	 */
	public function assertRestoreTargetsAvailable(array $changes): void
	{
		foreach ($changes as $change) {
			if (! in_array((string) ($change['op'] ?? ''), array('remove', 'replace'), true)) {
				continue;
			}
			$snapshot = $change['before_reference'] ?? null;
			if (! is_array($snapshot) || (int) ($snapshot['id'] ?? 0) <= 0) {
				continue;
			}
			$type = is_string($snapshot['type'] ?? null) ? $snapshot['type'] : '';
			$resolver = $this->resolvers[$type] ?? null;
			try {
				$available = null !== $resolver && $resolver->isAvailable($snapshot);
			} catch (Throwable) {
				$available = false;
			}
			if (! $available) {
				throw new RuntimeException('Reference missing: the media item this undo would restore no longer exists on this website. Nothing was changed.');
			}
		}
	}
}
