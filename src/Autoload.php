<?php
/**
 * Small production autoloader so the distributed plugin has no Composer runtime dependency.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

spl_autoload_register(
	static function (string $class): void {
		$prefix = 'ConfigOps\\';

		if (! str_starts_with($class, $prefix)) {
			return;
		}

		$relative = substr($class, strlen($prefix));
		$file     = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

		if (is_readable($file)) {
			require_once $file;
		}
	}
);
