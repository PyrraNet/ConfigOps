<?php
/**
 * Parse every production and test PHP file with the active PHP runtime.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

$root   = dirname(__DIR__);
$errors = array();
$count  = 0;

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
	if (! $file instanceof SplFileInfo || 'php' !== strtolower($file->getExtension())) {
		continue;
	}

	++$count;
	try {
		$source = file_get_contents($file->getPathname());
		if (false === $source) {
			throw new RuntimeException('Could not read file.');
		}
		token_get_all($source, TOKEN_PARSE);
	} catch (Throwable $error) {
		$errors[] = $file->getPathname() . ': ' . $error->getMessage();
	}
}

if (! empty($errors)) {
	fwrite(STDERR, implode("\n", $errors) . "\n");
	exit(1);
}

fwrite(STDOUT, "Parsed {$count} PHP files successfully.\n");
