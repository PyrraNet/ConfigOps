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
	private readonly string $ownPluginPath;

	/** @var array<string, ?string> */
	private array $componentVersions = array();

	public function __construct(string $ownPluginPath)
	{
		$this->ownPluginPath = rtrim(wp_normalize_path($ownPluginPath), '/') . '/';
	}

	/**
	 * @return array{type: string, component: string, file: string, line: int, basis: string}
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
				'basis'     => 'caller',
			);
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Source attribution is a bounded product feature; arguments are never captured.
		$trace        = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 25);
		$coreFallback = null;

		foreach ($trace as $frame) {
			$file = isset($frame['file']) ? wp_normalize_path((string) $frame['file']) : '';
			if ('' === $file || str_starts_with($file, $this->ownPluginPath)) {
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
			'basis'     => 'caller',
		);
	}

	/**
	 * Resolve a bounded component version from the exact source owner observed in
	 * this request. A missing or ambiguous version remains unknown.
	 *
	 * @param array{type: string, component: string, file: string, line: int, basis?: string} $source
	 */
	public function componentVersion(array $source): ?string
	{
		$type      = sanitize_key($source['type']);
		$component = sanitize_key($source['component']);
		$cacheKey  = $type . ':' . $component;
		if (array_key_exists($cacheKey, $this->componentVersions)) {
			return $this->componentVersions[$cacheKey];
		}

		$version = match ($type) {
			'core' => $this->wordpressVersion(),
			'plugin' => $this->pluginVersion($component, $source['file']),
			'mu-plugin' => $this->mustUsePluginVersion($component, $source['file']),
			'theme' => $this->themeVersion($component),
			default => null,
		};
		$this->componentVersions[$cacheKey] = $this->boundedVersion($version);

		return $this->componentVersions[$cacheKey];
	}

	private function isMeaningfulCoreCaller(string $relativeFile): bool
	{
		if (str_starts_with($relativeFile, 'wp-admin/')) {
			return true;
		}

		return 1 !== preg_match(
			'#^(?:wp-includes/(class-wp-hook|class-wpdb|plugin|option)\.php|(?:.*/)?class-wp-sqlite-db\.php)$#',
			$relativeFile
		);
	}

	/**
	 * @return array{type: string, component: string, file: string, line: int, basis: string}
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
			'basis'     => 'caller',
		);
	}

	/**
	 * @return array{type: string, component: string, file: string, line: int, basis: string}
	 */
	private function result(string $type, string $relative, string $file): array
	{
		return array(
			'type'      => $type,
			'component' => $this->pluginFileComponent($relative),
			'file'      => $this->relativeToWordPress($file),
			'line'      => 0,
			'basis'     => 'caller',
		);
	}

	private function relativeToWordPress(string $file): string
	{
		$root = wp_normalize_path(ABSPATH);

		return str_starts_with($file, $root) ? substr($file, strlen($root)) : basename($file);
	}

	private function wordpressVersion(): ?string
	{
		global $wp_version;

		$version = is_string($wp_version ?? null) ? $wp_version : '';
		if ('' === trim($version) && function_exists('get_bloginfo')) {
			$version = (string) get_bloginfo('version');
		}

		return $version;
	}

	private function pluginVersion(string $component, string $sourceFile): ?string
	{
		if ('' === $component || ! defined('WP_PLUGIN_DIR')) {
			return null;
		}

		$source = $this->componentSourceFile(WP_PLUGIN_DIR, $component, $sourceFile);
		if (null !== $source) {
			$version = $this->versionFromFile($source, true);
			if (null !== $version) {
				return $version;
			}
		}

		$pluginFiles = get_option('active_plugins', array());
		$pluginFiles = is_array($pluginFiles) ? $pluginFiles : array();
		if (function_exists('is_multisite') && is_multisite()) {
			$networkPlugins = get_site_option('active_sitewide_plugins', array());
			if (is_array($networkPlugins)) {
				$pluginFiles = array_merge($pluginFiles, array_keys($networkPlugins));
			}
		}
		$pluginFiles = array_values(array_filter($pluginFiles, 'is_string'));

		foreach (array_unique($pluginFiles) as $pluginFile) {
			if ($component !== $this->pluginFileComponent($pluginFile)) {
				continue;
			}

			$file = $this->safeComponentFile(WP_PLUGIN_DIR, $pluginFile);
			if (null === $file) {
				continue;
			}

			$version = $this->versionFromFile($file);
			if (null !== $version) {
				return $version;
			}
		}

		return null;
	}

	private function mustUsePluginVersion(string $component, string $sourceFile): ?string
	{
		if ('' === $component || ! defined('WPMU_PLUGIN_DIR')) {
			return null;
		}

		$file = $this->componentSourceFile(WPMU_PLUGIN_DIR, $component, $sourceFile);

		return null === $file ? null : $this->versionFromFile($file, true);
	}

	private function themeVersion(string $component): ?string
	{
		if ('' === $component || ! function_exists('wp_get_theme')) {
			return null;
		}

		$theme = wp_get_theme($component);
		if (! $theme->exists()) {
			return null;
		}

		return (string) $theme->get('Version');
	}

	private function componentSourceFile(string $root, string $component, string $relativeFile): ?string
	{
		$file = $this->safeComponentFile(ABSPATH, $relativeFile);
		if (null === $file) {
			return null;
		}

		$normalizedRoot = rtrim(wp_normalize_path($root), '/') . '/';
		if (! str_starts_with($file, $normalizedRoot)) {
			return null;
		}

		$componentFile = substr($file, strlen($normalizedRoot));

		return $component === $this->pluginFileComponent($componentFile) ? $file : null;
	}

	private function safeComponentFile(string $root, string $relativeFile): ?string
	{
		$relativeFile = wp_normalize_path($relativeFile);
		if (
			'' === $relativeFile
			|| str_contains($relativeFile, "\0")
			|| str_starts_with($relativeFile, '/')
			|| in_array('..', explode('/', $relativeFile), true)
		) {
			return null;
		}

		$normalizedRoot = rtrim(wp_normalize_path($root), '/') . '/';
		$file           = $normalizedRoot . ltrim($relativeFile, '/');

		return str_starts_with($file, $normalizedRoot) && is_readable($file) ? $file : null;
	}

	private function pluginFileComponent(string $pluginFile): string
	{
		$parts = explode('/', ltrim(wp_normalize_path($pluginFile), '/'));
		$first = (string) ($parts[0] ?? '');
		if (1 === count($parts) && str_ends_with(strtolower($first), '.php')) {
			$first = substr($first, 0, -4);
		}

		return sanitize_key($first);
	}

	private function versionFromFile(string $file, bool $requirePluginName = false): ?string
	{
		if (! function_exists('get_file_data')) {
			return null;
		}

		$data = get_file_data(
			$file,
			array(
				'Name'    => 'Plugin Name',
				'Version' => 'Version',
			),
			false
		);
		if ($requirePluginName && '' === trim((string) ($data['Name'] ?? ''))) {
			return null;
		}

		$version = trim((string) ($data['Version'] ?? ''));

		return '' === $version ? null : $version;
	}

	private function boundedVersion(?string $version): ?string
	{
		if (null === $version) {
			return null;
		}

		$version = trim(wp_strip_all_tags($version));
		if ('' === $version) {
			return null;
		}

		return function_exists('mb_substr')
			? mb_substr($version, 0, 64, 'UTF-8')
			: substr($version, 0, 64);
	}
}
