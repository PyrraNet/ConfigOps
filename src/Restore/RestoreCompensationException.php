<?php
/**
 * Restore failure after compensating already-applied option writes.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Restore;

use RuntimeException;

final class RestoreCompensationException extends RuntimeException
{
	public function __construct(string $message, public readonly bool $compensationFailed, \Throwable $previous)
	{
		parent::__construct($message, 0, $previous);
	}
}
