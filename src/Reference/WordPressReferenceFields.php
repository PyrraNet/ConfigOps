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
		if ('/' === $path && 'site_icon' === $optionName) {
			return new FieldDefinition(
				__('Site icon', 'configops'),
				__('Site identity', 'configops'),
				'reference',
				__('The image WordPress uses for browser tabs and app icons.', 'configops'),
				'media'
			);
		}
		if ('/' === $path && 'site_logo' === $optionName) {
			return new FieldDefinition(
				__('Site logo', 'configops'),
				__('Site identity', 'configops'),
				'reference',
				__('The logo selected for this website.', 'configops'),
				'media'
			);
		}
		if (str_starts_with($optionName, 'theme_mods_') && '/custom_logo' === $path) {
			return new FieldDefinition(
				__('Site logo', 'configops'),
				__('Theme identity', 'configops'),
				'reference',
				__('The logo selected for the active theme.', 'configops'),
				'media'
			);
		}

		return null;
	}
}
