<?php
/**
 * Machine-readable failure from the ConfigOps automation boundary.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Agent;

use RuntimeException;

final class AgentException extends RuntimeException
{
	public function __construct(
		public readonly string $errorCode,
		string $message,
		public readonly int $status = 409,
		public readonly bool $retryable = false
	) {
		parent::__construct($message);
	}
}
