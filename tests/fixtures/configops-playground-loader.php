<?php
/**
 * Keeps activated plugins coherent across isolated Playground workers.
 *
 * Playground's workers retain separate in-memory option caches. The marker is
 * written only after normal plugin activation has completed, so activation
 * hooks still run before this test-only fallback becomes active.
 */

declare(strict_types=1);

if (! is_file(WP_CONTENT_DIR . '/configops-adapters-ready')) {
	return;
}

add_filter(
	'option_active_plugins',
	static function ($plugins): array {
		$active = is_array($plugins) ? $plugins : array();
		$required = array(
			'configops/configops.php',
			'wp-mail-smtp/wp_mail_smtp.php',
			'wordpress-seo/wp-seo.php',
		);

		foreach ($required as $plugin) {
			if (is_file(WP_PLUGIN_DIR . '/' . $plugin) && ! in_array($plugin, $active, true)) {
				$active[] = $plugin;
			}
		}

		return $active;
	},
	PHP_INT_MIN
);
