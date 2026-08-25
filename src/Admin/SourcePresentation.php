<?php
/**
 * Human-facing labels for bounded source-attribution evidence.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Admin;

final class SourcePresentation
{
	/** @var array<string, string> */
	private const ACRONYMS = array(
		'ai'          => 'AI',
		'api'         => 'API',
		'configops'   => 'ConfigOps',
		'db'          => 'DB',
		'dns'         => 'DNS',
		'gdpr'        => 'GDPR',
		'http'        => 'HTTP',
		'https'       => 'HTTPS',
		'id'          => 'ID',
		'ids'         => 'IDs',
		'json'        => 'JSON',
		'llm'         => 'LLM',
		'mcp'         => 'MCP',
		'php'         => 'PHP',
		'rest'        => 'REST',
		'seo'         => 'SEO',
		'smtp'        => 'SMTP',
		'ssl'         => 'SSL',
		'ui'          => 'UI',
		'url'         => 'URL',
		'urls'        => 'URLs',
		'woocommerce' => 'WooCommerce',
		'wordpress'   => 'WordPress',
		'wp'          => 'WP',
		'xml'         => 'XML',
	);

	public static function displayName(string $type, string $component): string
	{
		if ('core' === $type || 'wordpress' === $component) {
			return __('WordPress', 'configops');
		}

		$label = self::humanize($component);
		if ('' !== $label) {
			return $label;
		}

		return match ($type) {
			'plugin' => __('Unknown plugin', 'configops'),
			'mu-plugin' => __('Unknown must-use plugin', 'configops'),
			'theme' => __('Unknown theme', 'configops'),
			default => __('Unknown source', 'configops'),
		};
	}

	public static function settingsGroup(string $type): string
	{
		return match ($type) {
			'plugin' => __('Plugin setting', 'configops'),
			'mu-plugin' => __('Must-use plugin setting', 'configops'),
			'theme' => __('Theme setting', 'configops'),
			'core' => __('WordPress setting', 'configops'),
			default => __('Unmapped setting', 'configops'),
		};
	}

	public static function unmappedExplanation(string $type, string $component, string $basis = 'caller'): string
	{
		$owner = self::displayName($type, $component);
		if ('registered-setting' === $basis) {
			return match ($type) {
				'plugin' => sprintf(
					/* translators: %s: plugin name derived from its source slug. */
					__('The %s plugin registered this option with the WordPress Settings API. WordPress performed the captured option write. No tested adapter maps this field, so ConfigOps does not guess what it controls.', 'configops'),
					$owner
				),
				'mu-plugin' => sprintf(
					/* translators: %s: must-use plugin name derived from its source slug. */
					__('The %s must-use plugin registered this option with the WordPress Settings API. WordPress performed the captured option write. No tested adapter maps this field, so ConfigOps does not guess what it controls.', 'configops'),
					$owner
				),
				'theme' => sprintf(
					/* translators: %s: theme name derived from its source slug. */
					__('The %s theme registered this option with the WordPress Settings API. WordPress performed the captured option write. No tested adapter maps this field, so ConfigOps does not guess what it controls.', 'configops'),
					$owner
				),
				default => __('This option was registered with the WordPress Settings API, which performed the captured write. No tested adapter maps the field, so ConfigOps does not guess what it controls.', 'configops'),
			};
		}

		return match ($type) {
			'plugin' => sprintf(
				/* translators: %s: plugin name derived from its source slug. */
				__('Changed through the %s plugin. No tested adapter maps this field, so ConfigOps shows the captured values without guessing what the setting controls.', 'configops'),
				$owner
			),
			'mu-plugin' => sprintf(
				/* translators: %s: must-use plugin name derived from its source slug. */
				__('Changed through the %s must-use plugin. No tested adapter maps this field, so ConfigOps shows the captured values without guessing what the setting controls.', 'configops'),
				$owner
			),
			'theme' => sprintf(
				/* translators: %s: theme name derived from its source slug. */
				__('Changed through the %s theme. No tested adapter maps this field, so ConfigOps shows the captured values without guessing what the setting controls.', 'configops'),
				$owner
			),
			'core' => __('Changed through WordPress. No tested adapter maps this field, so ConfigOps shows the captured values without guessing what the setting controls.', 'configops'),
			default => __('No tested adapter maps this field. ConfigOps shows the captured values and source evidence without guessing what the setting controls.', 'configops'),
		};
	}

	public static function fieldLabel(string $optionName, string $jsonPointer): string
	{
		if ('/' === $jsonPointer || '' === $jsonPointer) {
			return self::humanize($optionName);
		}

		$parts = explode('/', ltrim($jsonPointer, '/'));
		$part  = (string) ($parts[array_key_last($parts)] ?? '');
		$part  = str_replace(array('~1', '~0'), array('/', '~'), $part);
		if (1 === preg_match('/^\d+$/D', $part)) {
			return sprintf(
				/* translators: %d: one-based position in a captured list. */
				__('Item %d', 'configops'),
				(int) $part + 1
			);
		}

		$label = self::humanize($part);

		return '' === $label ? self::humanize($optionName) : $label;
	}

	private static function humanize(string $value): string
	{
		$value = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', trim($value));
		$value = is_string($value) ? str_replace(array('_', '-', '.'), ' ', $value) : '';
		$parts = preg_split('/\s+/', trim($value)) ?: array();
		$parts = array_map(
			static function (string $part): string {
				$lower = strtolower($part);

				return self::ACRONYMS[$lower] ?? ucfirst($lower);
			},
			array_filter($parts, static fn (string $part): bool => '' !== $part)
		);

		return implode(' ', $parts);
	}
}
