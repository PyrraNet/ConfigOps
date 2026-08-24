<?php
/**
 * Compare an adapter with the settings surface published by a real plugin.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

/**
 * @return list<string> Settings paths that the adapter still treats as unknown.
 */
function configops_unknown_adapter_surface_fields(string $adapterId, string $pluginVersion): array
{
	$unknown = array();
	$check = static function (
		\ConfigOps\Adapter\ConfigAdapter $adapter,
		string $optionName,
		string $path,
		string $source
	) use (&$unknown): void {
		$field = $adapter->field($optionName, $path);
		if (! $field instanceof \ConfigOps\Adapter\FieldDefinition || 'unknown' === $field->kind) {
			$unknown[] = "{$source}: {$optionName}{$path}";
		}
	};

	switch ($adapterId) {
		case 'wp-mail-smtp':
			$adapter = new \ConfigOps\Adapter\WpMailSmtpAdapter();
			$mapProperty = new ReflectionProperty(\WPMailSMTP\Options::class, 'map');
			$optionMap = $mapProperty->getValue();
			if (! is_array($optionMap)) {
				return array("WP Mail SMTP {$pluginVersion}: option map unavailable");
			}
			foreach ($optionMap as $group => $keys) {
				// The Lite package carries Pro alert declarations but does not expose their settings UI.
				if (! is_string($group) || str_starts_with($group, 'alert_') || ! is_array($keys)) {
					continue;
				}
				foreach ($keys as $key) {
					if (is_string($key)) {
						$check($adapter, 'wp_mail_smtp', "/{$group}/{$key}", "WP Mail SMTP {$pluginVersion}");
					}
				}
			}
			break;

		case 'yoast-seo':
			$adapter = new \ConfigOps\Adapter\YoastSeoAdapter();
			foreach (array('wpseo', 'wpseo_titles', 'wpseo_social', 'wpseo_llmstxt') as $optionName) {
				$option = \WPSEO_Options::get_option_instance($optionName);
				if (! $option instanceof \WPSEO_Option) {
					$unknown[] = "Yoast SEO {$pluginVersion}: {$optionName} defaults unavailable";
					continue;
				}
				foreach (array_keys($option->get_defaults()) as $key) {
					if (is_string($key)) {
						$check($adapter, $optionName, "/{$key}", "Yoast SEO {$pluginVersion}");
					}
				}
			}
			break;

		case 'woocommerce':
			if (! class_exists('WC_Admin_Settings')) {
				require_once WC_ABSPATH . 'includes/admin/class-wc-admin-settings.php';
			}
			$adapter = new \ConfigOps\Adapter\WooCommerceAdapter();
			$nonFieldTypes = array('button', 'html', 'info', 'notice', 'sectionend', 'slotfill_placeholder', 'title');
			foreach (\WC_Admin_Settings::get_settings_pages() as $settingsPage) {
				if (! $settingsPage instanceof \WC_Settings_Page) {
					continue;
				}
				$sectionIds = array_merge(array(''), array_keys($settingsPage->get_sections()));
				foreach (array_unique($sectionIds) as $sectionId) {
					foreach ($settingsPage->get_settings_for_section((string) $sectionId) as $setting) {
						$optionName = is_string($setting['id'] ?? null) ? $setting['id'] : '';
						$type = is_string($setting['type'] ?? null) ? $setting['type'] : '';
						if (str_starts_with($optionName, 'woocommerce_') && ! in_array($type, $nonFieldTypes, true)) {
							$check($adapter, $optionName, '/', "WooCommerce {$pluginVersion} settings API");
						}
					}
				}
			}
			break;
	}

	return array_values(array_unique($unknown));
}
