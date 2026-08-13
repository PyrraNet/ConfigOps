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
	private const VALUE_SIDES = array('before', 'after');
	private const REFERENCE_KEYS = array('before_reference', 'after_reference');
	private const RESTORED_VALUE_OPERATIONS = array('remove', 'replace');

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
			$resolver = $this->resolver($change['reference_type'] ?? null);
			if (null === $resolver) {
				continue;
			}

			foreach (self::VALUE_SIDES as $side) {
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
			foreach (self::REFERENCE_KEYS as $key) {
				$snapshot = $change[$key] ?? null;
				if (! is_array($snapshot)) {
					continue;
				}
				$resolver = $this->resolver($snapshot['type'] ?? null);
				if (null === $resolver) {
					continue;
				}
				try {
					$change[$key] = $resolver->present($snapshot);
				} catch (Throwable) {
					// Stored evidence remains unchanged when current presentation fails.
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
			if (! in_array((string) ($change['op'] ?? ''), self::RESTORED_VALUE_OPERATIONS, true)) {
				continue;
			}
			$snapshot = $change['before_reference'] ?? null;
			if (! is_array($snapshot) || (int) ($snapshot['id'] ?? 0) <= 0) {
				continue;
			}
			$resolver = $this->resolver($snapshot['type'] ?? null);
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

	private function resolver(mixed $type): ?ReferenceResolver
	{
		return is_string($type) ? ($this->resolvers[$type] ?? null) : null;
	}
}
