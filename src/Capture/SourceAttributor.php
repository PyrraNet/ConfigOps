<?php
/**
 * Bounded source attribution without arguments or sensitive request data.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Capture;

final class SourceAttributor
{
	private const MAX_TRACES_PER_REQUEST = 100;

	private int $traceCount = 0;

	public function __construct(private readonly string $ownPluginPath)
	{
	}

	/**
	 * @return array{type: string, component: string, file: string, line: int}
	 */
	public function capture(): array
	{
		++$this->traceCount;
		if ($this->traceCount > self::MAX_TRACES_PER_REQUEST) {
			return array(
				'type'      => 'unknown',
				'component' => 'trace-budget-exceeded',
				'file'      => '',
				'line'      => 0,
			);
		}

		$trace        = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 25);
		$coreFallback = null;

		foreach ($trace as $frame) {
			$file = isset($frame['file']) ? wp_normalize_path((string) $frame['file']) : '';
			if ('' === $file || str_starts_with($file, wp_normalize_path($this->ownPluginPath))) {
				continue;
			}

			$source = $this->classify($file);
			if ('core' !== $source['type']) {
				$source['line'] = isset($frame['line']) ? (int) $frame['line'] : 0;

				return $source;
			}

			$source['line'] = isset($frame['line']) ? (int) $frame['line'] : 0;
			$coreFallback ??= $source;

			if ($this->isMeaningfulCoreCaller($source['file'])) {
				return $source;
			}
		}

		return $coreFallback ?? array(
			'type'      => 'core',
			'component' => 'wordpress',
			'file'      => '',
			'line'      => 0,
		);
	}

	private function isMeaningfulCoreCaller(string $relativeFile): bool
	{
		if (str_starts_with($relativeFile, 'wp-admin/')) {
			return true;
		}

		return 1 !== preg_match(
			'#^wp-includes/(class-wp-hook|plugin|option)\.php$#',
			$relativeFile
		);
	}

	/**
	 * @return array{type: string, component: string, file: string, line: int}
	 */
	private function classify(string $file): array
	{
		$pluginRoot = wp_normalize_path(WP_PLUGIN_DIR) . '/';
		$muRoot     = defined('WPMU_PLUGIN_DIR') ? wp_normalize_path(WPMU_PLUGIN_DIR) . '/' : '';
		$themeRoot  = wp_normalize_path(get_theme_root()) . '/';

		if (str_starts_with($file, $pluginRoot)) {
			$relative = substr($file, strlen($pluginRoot));

			return $this->result('plugin', $relative, $file);
		}
		if ('' !== $muRoot && str_starts_with($file, $muRoot)) {
			$relative = substr($file, strlen($muRoot));

			return $this->result('mu-plugin', $relative, $file);
		}
		if (str_starts_with($file, $themeRoot)) {
			$relative = substr($file, strlen($themeRoot));

			return $this->result('theme', $relative, $file);
		}

		return array(
			'type'      => 'core',
			'component' => 'wordpress',
			'file'      => $this->relativeToWordPress($file),
			'line'      => 0,
		);
	}

	/**
	 * @return array{type: string, component: string, file: string, line: int}
	 */
	private function result(string $type, string $relative, string $file): array
	{
		$parts = explode('/', $relative);

		return array(
			'type'      => $type,
			'component' => sanitize_key((string) ($parts[0] ?? 'unknown')),
			'file'      => $this->relativeToWordPress($file),
			'line'      => 0,
		);
	}

	private function relativeToWordPress(string $file): string
	{
		$root = wp_normalize_path(ABSPATH);

		return str_starts_with($file, $root) ? substr($file, strlen($root)) : basename($file);
	}
}
