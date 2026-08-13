<?php
/**
 * Core WordPress option paths that contain website-local object references.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Reference;

use ConfigOps\Adapter\FieldDefinition;

final class WordPressReferenceFields
{
	public function field(string $optionName, string $path): ?FieldDefinition
	{
		$definition = match (true) {
			'/' === $path && 'site_icon' === $optionName => array(
				__('Site icon', 'configops'),
				__('Site identity', 'configops'),
				__('The image WordPress uses for browser tabs and app icons.', 'configops'),
			),
			'/' === $path && 'site_logo' === $optionName => array(
				__('Site logo', 'configops'),
				__('Site identity', 'configops'),
				__('The logo selected for this website.', 'configops'),
				'media',
			),
			'/' === $path && 'page_on_front' === $optionName => array(
				__('Homepage', 'configops'),
				__('Homepage', 'configops'),
				__('The local page selected as the website homepage.', 'configops'),
				'content',
			),
			'/' === $path && 'page_for_posts' === $optionName => array(
				__('Posts page', 'configops'),
				__('Homepage', 'configops'),
				__('The local page selected to display the posts index.', 'configops'),
				'content',
			),
			'/' === $path && 'wp_page_for_privacy_policy' === $optionName => array(
				__('Privacy policy page', 'configops'),
				__('Privacy', 'configops'),
				__('The page WordPress identifies as this website\'s privacy policy.', 'configops'),
				'content',
			),
			str_starts_with($optionName, 'theme_mods_') && '/custom_logo' === $path => array(
				__('Site logo', 'configops'),
				__('Theme identity', 'configops'),
				__('The logo selected for the active theme.', 'configops'),
				'media',
			),
			default => null,
		};
		if (null === $definition) {
			return null;
		}

		return new FieldDefinition($definition[0], $definition[1], 'reference', $definition[2], $definition[3] ?? 'media');
	}
}
