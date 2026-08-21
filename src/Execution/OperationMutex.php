<?php
/**
 * Scope-owned serialization for state-changing ConfigOps operations.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Execution;

interface OperationMutex
{
	/**
	 * @template T
	 * @param callable(): T $operation Operation guarded by the lock.
	 * @return T
	 */
	public function run(string $scope, callable $operation): mixed;
}
