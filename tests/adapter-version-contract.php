<?php
/**
 * Real-plugin smoke contract for one WordPress.org-visible version line.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

require_once __DIR__ . '/production-error-trap.php';

$wordpressRoot = rtrim((string) (getenv('CONFIGOPS_WP_ROOT') ?: '/wordpress'), '/');
require_once $wordpressRoot . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

if (! defined('CONFIGOPS_FILE')) {
	require_once WP_PLUGIN_DIR . '/configops/configops.php';
}

$expected = get_option('configops_adapter_contract_expectation', array());
if (! is_array($expected)) {
	throw new RuntimeException('The adapter-version expectation is missing.');
}

$adapterId = (string) ($expected['id'] ?? '');
$expectedVersion = (string) ($expected['version'] ?? '');
$pluginFile = (string) ($expected['pluginFile'] ?? '');
if ('' === $adapterId || '' === $expectedVersion || '' === $pluginFile) {
	throw new RuntimeException('The adapter-version expectation is incomplete.');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
	++$assertions;
	if (! $condition) {
		throw new RuntimeException($message);
	}
};

$pluginData = get_plugin_data(WP_PLUGIN_DIR . '/' . $pluginFile, false, false);
$assert($expectedVersion === ($pluginData['Version'] ?? ''), "Expected {$adapterId} {$expectedVersion} to be installed.");
$assert(is_plugin_active($pluginFile), "Expected {$adapterId} {$expectedVersion} to be active.");

\ConfigOps\Plugin::activate();
\ConfigOps\Plugin::boot();

global $wpdb;

$captures = new \ConfigOps\Database\CaptureRepository($wpdb);
$mutations = new \ConfigOps\Database\MutationRepository($wpdb);
$adapters = new \ConfigOps\Adapter\AdapterRegistry(
	\ConfigOps\Adapter\BuiltInAdapters::create(),
	new \ConfigOps\Noise\NoiseClassifier(),
	new \ConfigOps\Capture\HeuristicSensitiveValueDetector()
);
$supportById = array_column($adapters->supportPayload(), null, 'id');
$assert(true === ($supportById[$adapterId]['active'] ?? false), "Expected {$adapterId} to be active in the support contract.");
$assert(true === ($supportById[$adapterId]['compatible'] ?? false), "Expected {$adapterId} {$expectedVersion} to match its tested line.");
$assert($expectedVersion === ($supportById[$adapterId]['version'] ?? null), 'The support payload should expose the exact installed patch release.');

$optionName = '';
$expectedLabel = '';
$beforeValue = null;
$afterValue = null;

switch ($adapterId) {
	case 'wp-mail-smtp':
		$assert(class_exists('WPMailSMTP\\Options'), 'The WP Mail SMTP settings API should be available.');
		$optionName = 'wp_mail_smtp';
		$expectedLabel = 'Sender name';
		$mailOptions = \WPMailSMTP\Options::init();
		$mailBefore = get_option($optionName, array());
		$mailBefore = is_array($mailBefore) ? $mailBefore : array();
		$mailBefore['mail'] = is_array($mailBefore['mail'] ?? null) ? $mailBefore['mail'] : array();
		$mailBefore['mail']['from_name'] = 'Before active-line contract';
		$mailOptions->set($mailBefore);
		$beforeValue = 'Before active-line contract';
		$afterValue = 'After active-line contract';
		$sessionId = $captures->start("{$adapterId} {$expectedVersion}", 1, '/wp-admin/admin.php?page=wp-mail-smtp');
		$mailAfter = get_option($optionName, array());
		$mailAfter['mail']['from_name'] = $afterValue;
		$mailOptions->set($mailAfter);
		$captures->stop();
		break;

	case 'yoast-seo':
		$assert(class_exists('WPSEO_Options'), 'The Yoast settings API should be available.');
		$optionName = 'wpseo';
		$expectedLabel = 'Admin bar menu';
		\WPSEO_Options::set('enable_admin_bar_menu', true, $optionName);
		$beforeValue = true;
		$afterValue = false;
		$sessionId = $captures->start("{$adapterId} {$expectedVersion}", 1, '/wp-admin/admin.php?page=wpseo_page_settings');
		\WPSEO_Options::set('enable_admin_bar_menu', false, $optionName);
		$captures->stop();
		break;

	case 'woocommerce':
		$assert(defined('WC_ABSPATH'), 'WooCommerce should publish its plugin path.');
		if (! class_exists('WC_Admin_Settings')) {
			require_once WC_ABSPATH . 'includes/admin/class-wc-admin-settings.php';
		}
		$assert(class_exists('WC_Admin_Settings'), 'The WooCommerce settings API should be available.');
		$optionName = 'woocommerce_currency';
		$expectedLabel = 'Store currency';
		update_option($optionName, 'USD', false);
		$beforeValue = 'USD';
		$afterValue = 'EUR';
		$sessionId = $captures->start("{$adapterId} {$expectedVersion}", 1, '/wp-admin/admin.php?page=wc-settings&tab=general');
		\WC_Admin_Settings::save_fields(
			array(
				array(
					'id' => $optionName,
					'type' => 'select',
					'options' => array('USD' => 'USD', 'EUR' => 'EUR'),
					'default' => 'USD',
				)
			),
			array($optionName => $afterValue)
		);
		$captures->stop();
		break;

	default:
		throw new RuntimeException("No active-line test is defined for {$adapterId}.");
}

$rows = $mutations->forSession($sessionId, 100);
$mutation = current(array_filter($rows, static fn (object $row): bool => $optionName === (string) $row->option_name));
$assert(false !== $mutation, "Expected {$adapterId} {$expectedVersion} to write {$optionName} through its public settings path.");
$assert($adapterId === (string) $mutation->adapter_id, 'The mutation should persist the owning adapter ID.');
$assert($expectedVersion === (string) $mutation->component_version, 'The mutation should persist the exact installed plugin version.');
$assert(1 === (int) $mutation->restorable, 'The supported setting should retain conflict-checked undo.');

$diff = json_decode((string) $mutation->diff, true);
$labels = is_array($diff) ? array_column($diff, 'label') : array();
$assert(in_array($expectedLabel, $labels, true), "Expected the active-line contract to explain {$expectedLabel}.");

$codec = new \ConfigOps\Capture\ValueCodec($adapters);
$restore = new \ConfigOps\Restore\RestoreService(
	$captures,
	$mutations,
	$codec,
	new \ConfigOps\Database\OptionMetadataRepository($wpdb),
	new \ConfigOps\Execution\OperationLock($wpdb),
	$adapters,
	new \ConfigOps\Database\RestoreAuditRepository($wpdb)
);
$restore->restoreMutation((int) $mutation->id);

$restored = get_option($optionName);
if ('wp-mail-smtp' === $adapterId) {
	$restored = is_array($restored) ? ($restored['mail']['from_name'] ?? null) : null;
} elseif ('yoast-seo' === $adapterId) {
	$restored = is_array($restored) ? ($restored['enable_admin_bar_menu'] ?? null) : null;
}
$assert($beforeValue === $restored, "Expected {$adapterId} {$expectedVersion} undo to restore the original setting.");

fwrite(STDOUT, "ConfigOps {$adapterId} {$expectedVersion} active-line contract passed ({$assertions} assertions).\n");
