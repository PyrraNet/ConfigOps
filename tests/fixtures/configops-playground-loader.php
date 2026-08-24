<?php
/**
 * Keeps installed test plugins coherent across isolated Playground workers.
 *
 * Playground workers can fork before the Blueprint's active-plugin and marker
 * writes become visible. This file is mounted only by the browser contract and
 * starts enforcing the fixture after every required public plugin exists.
 */

declare(strict_types=1);

$configopsRequiredPlugins = array(
	'configops/configops.php',
	'wp-mail-smtp/wp_mail_smtp.php',
	'wordpress-seo/wp-seo.php',
	'woocommerce/woocommerce.php',
);
foreach ($configopsRequiredPlugins as $configopsRequiredPlugin) {
	if (! is_file(WP_PLUGIN_DIR . '/' . $configopsRequiredPlugin)) {
		return;
	}
}

if (! function_exists('add_filter')) {
	return;
}

add_filter('woocommerce_prevent_automatic_wizard_redirect', '__return_true', PHP_INT_MIN);

add_filter(
	'option_active_plugins',
	static function ($plugins) use ($configopsRequiredPlugins): array {
		$active = is_array($plugins) ? $plugins : array();

		foreach ($configopsRequiredPlugins as $plugin) {
			if (is_file(WP_PLUGIN_DIR . '/' . $plugin) && ! in_array($plugin, $active, true)) {
				$active[] = $plugin;
			}
		}

		return $active;
	},
	PHP_INT_MIN
);
