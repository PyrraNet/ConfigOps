<?php
/**
 * Convert warnings and deprecations originating in production code into test failures.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

$configOpsProductionRoots = array_filter(
	array(
		realpath(dirname(__DIR__) . '/src'),
		realpath(dirname(__DIR__) . '/templates'),
	)
);
$configOpsProductionFiles = array_filter(
	array(
		realpath(dirname(__DIR__) . '/configops.php'),
		realpath(dirname(__DIR__) . '/uninstall.php'),
	)
);
$configOpsRuntimeViolations = array();

set_error_handler(
	static function (int $severity, string $message, string $file, int $line) use (&$configOpsRuntimeViolations, $configOpsProductionRoots, $configOpsProductionFiles): bool {
		if (0 === (error_reporting() & $severity)) {
			return false;
		}

		$resolvedFile = realpath($file) ?: $file;
		$isProduction = in_array($resolvedFile, $configOpsProductionFiles, true);
		foreach ($configOpsProductionRoots as $root) {
			if ($resolvedFile === $root || str_starts_with($resolvedFile, $root . DIRECTORY_SEPARATOR)) {
				$isProduction = true;
				break;
			}
		}
		if (! $isProduction) {
			if ('wp-config.php' === basename($resolvedFile) && str_contains($message, 'Constant WP_DEBUG already defined')) {
				return true;
			}
			return false;
		}

		$violation = sprintf('%s in %s:%d', $message, $resolvedFile, $line);
		$configOpsRuntimeViolations[] = $violation;
		throw new ErrorException($message, 0, $severity, $resolvedFile, $line);
	}
);

register_shutdown_function(
	static function () use (&$configOpsRuntimeViolations): void {
		if (empty($configOpsRuntimeViolations)) {
			return;
		}

		fwrite(STDERR, "ConfigOps production warnings/deprecations:\n" . implode("\n", $configOpsRuntimeViolations) . "\n");
		exit(1);
	}
);
