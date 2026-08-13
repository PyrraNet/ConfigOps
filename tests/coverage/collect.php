<?php
/**
 * Execute one existing test script under Xdebug and persist raw line coverage.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

if ($argc < 3) {
	fwrite(STDERR, "Usage: php tests/coverage/collect.php <test-script> <output-json>\n");
	exit(2);
}

$testScript = realpath((string) $argv[1]);
$outputFile = (string) $argv[2];
$sourceRoot = realpath((string) (getenv('CONFIGOPS_COVERAGE_SOURCE_ROOT') ?: dirname(__DIR__, 2)));
$wordpressRoot = realpath((string) (getenv('CONFIGOPS_WP_ROOT') ?: '/var/www/html'));
$sourceManifest = realpath((string) (getenv('CONFIGOPS_COVERAGE_SOURCE_MANIFEST') ?: ''));

if (false === $testScript || ! is_file($testScript)) {
	fwrite(STDERR, "Coverage target does not exist.\n");
	exit(2);
}
if (false === $sourceRoot || ! is_dir($sourceRoot . '/src')) {
	fwrite(STDERR, "Coverage source root does not contain src/.\n");
	exit(2);
}
$xdebugModes = function_exists('xdebug_info') ? xdebug_info('mode') : array();
if (! function_exists('xdebug_start_code_coverage') || ! is_array($xdebugModes) || ! in_array('coverage', $xdebugModes, true)) {
	fwrite(STDERR, "Xdebug line coverage is unavailable. Run through npm run test:coverage or enable XDEBUG_MODE=coverage.\n");
	exit(2);
}

if (! defined('ABSPATH')) {
	if (false === $wordpressRoot || ! is_file($wordpressRoot . '/wp-load.php')) {
		fwrite(STDERR, "WordPress is unavailable for the coverage target.\n");
		exit(2);
	}
	require_once $wordpressRoot . '/wp-load.php';
}

xdebug_start_code_coverage(XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE);

require $testScript;

// Load every production declaration while coverage is active. Xdebug can then
// report executable-but-unvisited lines for the complete src/ tree instead of
// quietly dropping files that a test never reached.
$sourceFiles = array();
$manifestEntries = false !== $sourceManifest ? file($sourceManifest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
if (false === $manifestEntries) {
	throw new RuntimeException('The tracked production-source manifest is unavailable.');
}
foreach ($manifestEntries as $manifestEntry) {
	$manifestEntry = str_replace('\\', '/', trim($manifestEntry));
	if (! str_starts_with($manifestEntry, 'src/') || ! str_ends_with($manifestEntry, '.php') || str_contains($manifestEntry, '..')) {
		throw new RuntimeException('The production-source manifest contains an invalid path.');
	}
	$path = realpath($sourceRoot . '/' . $manifestEntry);
	if (false === $path || ! str_starts_with($path, $sourceRoot . '/src/')) {
		throw new RuntimeException("Production source is missing: {$manifestEntry}");
	}
	$sourceFiles[] = $path;
	$relative = substr($path, strlen($sourceRoot . '/src/'));
	if ('Autoload.php' === $relative) {
		continue;
	}
	$symbol = 'ConfigOps\\' . str_replace(array('/', '.php'), array('\\', ''), $relative);
	class_exists($symbol) || interface_exists($symbol) || trait_exists($symbol) || enum_exists($symbol);
}
sort($sourceFiles, SORT_STRING);

$rawCoverage = xdebug_get_code_coverage();
xdebug_stop_code_coverage();

$files = array();
foreach ($sourceFiles as $path) {
	$relative = 'src/' . substr($path, strlen($sourceRoot . '/src/'));
	$lineData = $rawCoverage[$path] ?? array();
	$lines = array();
	if (is_array($lineData)) {
		foreach ($lineData as $line => $status) {
			$line = (int) $line;
			$status = (int) $status;
			if ($line > 0) {
				$lines[(string) $line] = $status > 0 ? 1 : $status;
			}
		}
	}
	ksort($lines, SORT_NUMERIC);
	$files[$relative] = $lines;
}

$directory = dirname($outputFile);
if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
	throw new RuntimeException('Coverage output directory could not be created.');
}
$payload = array(
	'schemaVersion' => 1,
	'test'          => basename($testScript),
	'generatedAt'   => gmdate(DATE_ATOM),
	'files'         => $files,
);
$encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
if (false === file_put_contents($outputFile, $encoded . "\n")) {
	throw new RuntimeException('Coverage output could not be written.');
}

fwrite(STDOUT, sprintf("Coverage fragment written for %s (%d production files).\n", basename($testScript), count($files)));
