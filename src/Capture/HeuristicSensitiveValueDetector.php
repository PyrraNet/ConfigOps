<?php
/**
 * Conservative fallback secret detection when no adapter schema exists.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Capture;

final class HeuristicSensitiveValueDetector implements SensitiveValueDetector
{
	public function isSensitive(string $optionName, array $path): bool
	{
		$key = empty($path) ? $optionName : (string) $path[array_key_last($path)];
		$withBoundaries = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $key) ?? $key;
		$normalized     = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $withBoundaries) ?? $withBoundaries);

		return 1 === preg_match(
			'/(^|_)(password|passwd|passphrase|pass|pwd|secret|token|access_token|refresh_token|api_key|apikey|private_key|client_secret|consumer_key|consumer_secret|license_key|encryption_key|signing_key|auth_token|authorization|credentials?|bearer_token|hmac_secret|salt)($|_)/',
			$normalized
		);
	}
}
