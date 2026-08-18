<?php
/**
 * Shared route contract for the local ConfigOps REST API.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Api;

final class RestRoutes
{
	public const NAMESPACE = 'configops/v1';

	private function __construct()
	{
	}

	public static function owns(string $route): bool
	{
		$route = trim($route, '/');
		if ('' === $route) {
			return false;
		}

		return self::ownsQueryRoute($route)
			|| str_contains($route, '/' . self::NAMESPACE . '/');
	}

	public static function ownsQueryRoute(string $route): bool
	{
		$route = trim($route, '/');

		return self::NAMESPACE === $route
			|| str_starts_with($route, self::NAMESPACE . '/');
	}
}
