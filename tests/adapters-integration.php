<?php
/**
 * Contract checks against exact public releases of supported plugins.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

require_once __DIR__ . '/production-error-trap.php';
require_once __DIR__ . '/adapter-surface-contract.php';

$wordpressRoot = rtrim((string) (getenv('CONFIGOPS_WP_ROOT') ?: '/wordpress'), '/');
require_once $wordpressRoot . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

if (! defined('CONFIGOPS_FILE')) {
	require_once WP_PLUGIN_DIR . '/configops/configops.php';
}

\ConfigOps\Plugin::activate();
\ConfigOps\Plugin::boot();

global $wpdb;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
	++$assertions;
	if (! $condition) {
		fwrite(STDERR, "Adapter assertion failed: {$message}\n");
		throw new RuntimeException($message);
	}
};

$mailData  = get_plugin_data(WP_PLUGIN_DIR . '/wp-mail-smtp/wp_mail_smtp.php', false, false);
$yoastData = get_plugin_data(WP_PLUGIN_DIR . '/wordpress-seo/wp-seo.php', false, false);
$wooData   = get_plugin_data(WP_PLUGIN_DIR . '/woocommerce/woocommerce.php', false, false);
$assert('4.9.0' === ($mailData['Version'] ?? ''), 'The real-plugin contract must run against WP Mail SMTP 4.9.0.');
$assert('28.3' === ($yoastData['Version'] ?? ''), 'The current real-plugin contract must run against Yoast SEO 28.3.');
$assert('11.0.1' === ($wooData['Version'] ?? ''), 'The current real-plugin contract must run against WooCommerce 11.0.1.');
$assert(class_exists('WPMailSMTP\\Options'), 'WP Mail SMTP should be active, not just unpacked.');
$assert(class_exists('WPSEO_Options'), 'Yoast SEO should be active, not just unpacked.');
$assert(defined('WC_ABSPATH'), 'WooCommerce should be active, not just unpacked.');
if (! class_exists('WC_Admin_Settings')) {
	require_once WC_ABSPATH . 'includes/admin/class-wc-admin-settings.php';
}
$assert(class_exists('WC_Admin_Settings'), 'The WooCommerce settings API should be available.');

$captures  = new \ConfigOps\Database\CaptureRepository($wpdb);
$mutations = new \ConfigOps\Database\MutationRepository($wpdb);
$signals   = new \ConfigOps\Database\DatabaseWriteSignalRepository($wpdb);
$adapters  = new \ConfigOps\Adapter\AdapterRegistry(
	\ConfigOps\Adapter\BuiltInAdapters::create(),
	new \ConfigOps\Noise\NoiseClassifier(),
	new \ConfigOps\Capture\HeuristicSensitiveValueDetector()
);

$unknownAdapterFields = array_merge(
	configops_unknown_adapter_surface_fields('wp-mail-smtp', '4.9.0'),
	configops_unknown_adapter_surface_fields('yoast-seo', '28.3'),
	configops_unknown_adapter_surface_fields('woocommerce', '11.0.1')
);
$assert(
	array() === $unknownAdapterFields,
	"Current plugin settings without a tested adapter meaning:\n- " . implode("\n- ", $unknownAdapterFields)
);
$wooAdapter = new \ConfigOps\Adapter\WooCommerceAdapter();
$hposAnalysis = $wooAdapter->analyze(
	'woocommerce_custom_orders_table_enabled',
	array(array('path' => '/', 'before' => 'no', 'after' => 'yes'))
);
$assert(
	'unsupported' === $hposAnalysis->classification && ! $hposAnalysis->allowsGenericRestore,
	'WooCommerce order-storage migrations should be explained without offering option-only undo.'
);
$runtimeOptionPrefixes = array(
	'wp-mail-smtp' => array('wp_mail_smtp_', array('wp_mail_smtp')),
	'yoast-seo' => array('wpseo', array('wpseo', 'wpseo_titles', 'wpseo_social', 'wpseo_llmstxt')),
);
$unknownRuntimeOptions = array();
foreach ($runtimeOptionPrefixes as $expectedAdapterId => [$prefix, $configurationOptions]) {
	$optionNames = $wpdb->get_col(
		$wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like($prefix) . '%')
	);
	foreach ($optionNames as $runtimeOptionName) {
		if (! is_string($runtimeOptionName) || in_array($runtimeOptionName, $configurationOptions, true)) {
			continue;
		}
		$runtimeAnalysis = $adapters->analyze(
			$runtimeOptionName,
			array(array('path' => '/', 'before' => null, 'after' => get_option($runtimeOptionName)))
		);
		if ($expectedAdapterId !== ($runtimeAnalysis['adapter_id'] ?? null) || 'unknown' === ($runtimeAnalysis['classification'] ?? null)) {
			$unknownRuntimeOptions[] = "{$expectedAdapterId}: {$runtimeOptionName}";
		}
	}
}
$assert(
	array() === $unknownRuntimeOptions,
	"Activated plugins created options without a runtime classification:\n- " . implode("\n- ", $unknownRuntimeOptions)
);

delete_option('wp_mail_smtp');
\WPMailSMTP\Options::init()->set(
	array(
		'mail' => array(
			'from_email'       => 'before@example.test',
			'from_name'        => 'Before sender',
			'mailer'           => 'smtp',
			'return_path'      => false,
			'from_email_force' => true,
			'from_name_force'  => false,
		),
		'smtp' => array(
			'host'       => 'smtp.before.example.test',
			'port'       => 587,
			'encryption' => 'tls',
			'autotls'    => true,
			'auth'       => true,
			'user'       => 'before-user',
		),
		'mailgun' => array(
			'domain' => 'mg.before.example.test',
			'region' => 'US',
		),
		'postmark' => array(
			'message_stream' => 'broadcast-before',
		),
		'general' => array(
			'do_not_send' => false,
			'usage-tracking-enabled' => false,
		),
	)
);

$yoastBefore = get_option('wpseo', array());
if (! is_array($yoastBefore)) {
	$yoastBefore = array();
}
$yoastBefore['enable_admin_bar_menu'] = true;
$yoastBefore['indexing_started'] = false;
update_option('wpseo', $yoastBefore, false);
delete_option('wpseo_tracking_only');
$postsPerPageBefore = (int) get_option('posts_per_page', 10);

$yoastLogoBefore = wp_insert_attachment(
	array('post_mime_type' => 'image/png', 'post_title' => 'Original Yoast social image', 'post_status' => 'inherit')
);
$yoastLogoAfter = wp_insert_attachment(
	array('post_mime_type' => 'image/png', 'post_title' => 'Updated Yoast social image', 'post_status' => 'inherit')
);
update_post_meta((int) $yoastLogoBefore, '_wp_attached_file', '2026/08/yoast-social-before.png');
update_post_meta((int) $yoastLogoAfter, '_wp_attached_file', '2026/08/yoast-social-after.png');
wp_update_attachment_metadata((int) $yoastLogoBefore, array('file' => '2026/08/yoast-social-before.png', 'width' => 1200, 'height' => 630));
wp_update_attachment_metadata((int) $yoastLogoAfter, array('file' => '2026/08/yoast-social-after.png', 'width' => 1200, 'height' => 630));
$yoastSocialBefore = get_option('wpseo_social', array());
$yoastSocialBefore = is_array($yoastSocialBefore) ? $yoastSocialBefore : array();
$yoastSocialBefore['og_default_image_id'] = (int) $yoastLogoBefore;
update_option('wpseo_social', $yoastSocialBefore, false);

$yoastPageBefore = wp_insert_post(
	array('post_type' => 'page', 'post_title' => 'Original contact page', 'post_status' => 'publish')
);
$yoastPageAfter = wp_insert_post(
	array('post_type' => 'page', 'post_title' => 'Updated contact page', 'post_status' => 'publish')
);
$assert(! is_wp_error($yoastPageBefore) && ! is_wp_error($yoastPageAfter), 'Yoast content-reference fixtures should be created.');
$yoastLlmBefore = get_option('wpseo_llmstxt', array());
$yoastLlmBefore = is_array($yoastLlmBefore) ? $yoastLlmBefore : array();
$yoastLlmBefore['contact_page'] = (int) $yoastPageBefore;
update_option('wpseo_llmstxt', $yoastLlmBefore, false);

$yoastTitlesBefore = get_option('wpseo_titles', array());
$yoastTitlesBefore = is_array($yoastTitlesBefore) ? $yoastTitlesBefore : array();
$yoastTitlesBefore['social-image-id-post'] = (int) $yoastLogoBefore;
update_option('wpseo_titles', $yoastTitlesBefore, false);
$wooCurrencyBefore = get_option('woocommerce_currency', 'USD');
update_option('woocommerce_currency', 'USD', false);
$wooPosPolicyExisted = false !== get_option('woocommerce_pos_refund_returns_policy', false);
$wooPosPolicyBefore = get_option('woocommerce_pos_refund_returns_policy', '');
update_option('woocommerce_pos_refund_returns_policy', 'Original receipt policy', false);

$sessionId = $captures->start('Supported plugin contract', 1, '/wp-admin/admin.php?page=configops');
\WPMailSMTP\Options::init()->set(
	array(
		'mail' => array(
			'from_email'       => 'noreply@example.test',
			'from_name'        => 'Agency sender',
			'mailer'           => 'smtp',
			'return_path'      => true,
			'from_email_force' => true,
			'from_name_force'  => true,
		),
		'smtp' => array(
			'host'       => 'smtp.example.test',
			'port'       => 465,
			'encryption' => 'ssl',
			'autotls'    => false,
			'auth'       => true,
			'user'       => 'agency-user',
		),
		'mailgun' => array(
			'domain' => 'mg.example.test',
			'region' => 'EU',
		),
		'postmark' => array(
			'message_stream' => 'broadcast-production',
		),
		'general' => array(
			'do_not_send' => true,
			'usage-tracking-enabled' => false,
		),
	)
);
\WPSEO_Options::set('enable_admin_bar_menu', false, 'wpseo');
\WPSEO_Options::set('og_default_image_id', (int) $yoastLogoAfter, 'wpseo_social');
\WPSEO_Options::set('contact_page', (int) $yoastPageAfter, 'wpseo_llmstxt');
\WPSEO_Options::set('social-image-id-post', (int) $yoastLogoAfter, 'wpseo_titles');
add_option('wpseo_tracking_only', array('last_updated' => time()), '', false);
\WC_Admin_Settings::save_fields(
	array(
		array(
			'id' => 'woocommerce_currency',
			'type' => 'select',
			'options' => array('USD' => 'USD', 'EUR' => 'EUR'),
			'default' => 'USD',
		),
		array(
			'id' => 'woocommerce_pos_refund_returns_policy',
			'type' => 'textarea',
			'default' => '',
		),
	),
	array(
		'woocommerce_currency' => 'EUR',
		'woocommerce_pos_refund_returns_policy' => 'Returns accepted within 30 days.',
	)
);
update_option('posts_per_page', $postsPerPageBefore + 2, false);
$captures->stop();

$rows = $mutations->forSession($sessionId, 100);
$byName = array();
foreach ($rows as $row) {
	$byName[(string) $row->option_name] = $row;
}

$assert(isset($byName['wp_mail_smtp']), 'The official WP Mail SMTP settings API should be captured.');
$assert('wp-mail-smtp' === (string) $byName['wp_mail_smtp']->adapter_id, 'WP Mail SMTP mutations should persist adapter identity.');
$assert(3 === (int) $byName['wp_mail_smtp']->adapter_schema_version, 'WP Mail SMTP captures should pin the deep field-aware adapter schema.');
$assert('4.9.0' === (string) $byName['wp_mail_smtp']->component_version, 'WP Mail SMTP captures should pin the observed plugin version.');
$assert('environment' === (string) $byName['wp_mail_smtp']->classification, 'Mixed WP Mail SMTP connection changes should require a per-website check.');
$assert(1 === (int) $byName['wp_mail_smtp']->restorable, 'Secret-free WP Mail SMTP settings should retain conflict-checked rollback.');

$assert(isset($byName['wpseo']), 'The official Yoast options API should be captured.');
$assert('yoast-seo' === (string) $byName['wpseo']->adapter_id, 'Yoast mutations should persist adapter identity.');
$assert(3 === (int) $byName['wpseo']->adapter_schema_version, 'Yoast captures should pin the deep field-aware adapter schema.');
$assert('28.3' === (string) $byName['wpseo']->component_version, 'Yoast captures should pin the observed plugin version.');
$assert('portable' === (string) $byName['wpseo']->classification, 'A supported Yoast feature toggle should be reusable configuration.');
$assert(isset($byName['wpseo_social']) && 'reference' === (string) $byName['wpseo_social']->classification, 'A real Yoast social-image selection should be classified as a local reference.');
$yoastMediaDiff = json_decode((string) $byName['wpseo_social']->diff, true);
$yoastMediaChange = is_array($yoastMediaDiff) ? ($yoastMediaDiff[0] ?? array()) : array();
$assert(
	'media' === ($yoastMediaChange['reference_type'] ?? '')
	&& 'yoast-social-before.png' === ($yoastMediaChange['before_reference']['filename'] ?? '')
	&& 1200 === ($yoastMediaChange['after_reference']['width'] ?? 0),
	'The current Yoast option contract should resolve its default social-image attachments.'
);
$assert(isset($byName['wpseo_llmstxt']) && 'reference' === (string) $byName['wpseo_llmstxt']->classification, 'A real Yoast LLMs.txt page selection should be classified as a local content reference.');
$yoastContentDiff = json_decode((string) $byName['wpseo_llmstxt']->diff, true);
$yoastContentChange = is_array($yoastContentDiff) ? ($yoastContentDiff[0] ?? array()) : array();
$assert(
	'content' === ($yoastContentChange['reference_type'] ?? '')
	&& 'Original contact page' === ($yoastContentChange['before_reference']['title'] ?? '')
	&& 'page' === ($yoastContentChange['after_reference']['post_type'] ?? ''),
	'The current Yoast LLMs.txt contract should resolve selected page identities.'
);
$assert(isset($byName['wpseo_titles']) && 'reference' === (string) $byName['wpseo_titles']->classification, 'A dynamic Yoast post-type social image should be a local media reference.');
$yoastTitleDiff = json_decode((string) $byName['wpseo_titles']->diff, true);
$yoastDynamicImage = is_array($yoastTitleDiff) ? current(array_filter($yoastTitleDiff, static fn (array $change): bool => '/social-image-id-post' === ($change['path'] ?? ''))) : false;
$assert(
	is_array($yoastDynamicImage)
	&& 'media' === ($yoastDynamicImage['reference_type'] ?? '')
	&& 'Post social image' === ($yoastDynamicImage['label'] ?? ''),
	'Dynamic Yoast content-type social images should receive media identity and a useful label.'
);
$assert(isset($byName['wpseo_tracking_only']) && 'derived' === (string) $byName['wpseo_tracking_only']->classification, 'Yoast tracking state should be separated from settings.');
$assert(0 === (int) $byName['wpseo_tracking_only']->restorable, 'Technical Yoast state should not enter rollback.');
$assert(isset($byName['woocommerce_currency']), 'The official WooCommerce settings API should be captured.');
$assert('woocommerce' === (string) $byName['woocommerce_currency']->adapter_id, 'WooCommerce mutations should persist adapter identity.');
$assert(1 === (int) $byName['woocommerce_currency']->adapter_schema_version, 'WooCommerce captures should pin the field-aware adapter schema.');
$assert('11.0.1' === (string) $byName['woocommerce_currency']->component_version, 'WooCommerce captures should pin the observed plugin version.');
$assert('environment' === (string) $byName['woocommerce_currency']->classification, 'A store currency change should require a per-store check.');
$assert(1 === (int) $byName['woocommerce_currency']->restorable, 'A supported WooCommerce setting should retain conflict-checked undo.');
$assert(isset($byName['woocommerce_pos_refund_returns_policy']), 'The WooCommerce Point of Sale receipt policy should be captured.');
$assert(
	'woocommerce' === (string) $byName['woocommerce_pos_refund_returns_policy']->adapter_id
	&& 'portable' === (string) $byName['woocommerce_pos_refund_returns_policy']->classification
	&& 1 === (int) $byName['woocommerce_pos_refund_returns_policy']->restorable,
	'A Point of Sale receipt policy should retain its WooCommerce meaning and conflict-checked undo.'
);
$assert(isset($byName['posts_per_page']), 'A standard WordPress Reading setting should be captured by the Core adapter.');
$assert(
	'wordpress-core' === (string) $byName['posts_per_page']->adapter_id
	&& 1 === (int) $byName['posts_per_page']->adapter_schema_version
	&& 'portable' === (string) $byName['posts_per_page']->classification,
	'The real WordPress runtime should pin and classify the Core settings contract.'
);

$presenter = new \ConfigOps\Admin\ReviewPresenter($adapters);
$payloads  = new \ConfigOps\Admin\AdminPayloadFactory(
	$captures,
	$mutations,
	$signals,
	$presenter,
	$adapters,
	new \ConfigOps\Database\RestoreAuditRepository($wpdb)
);
$payload   = $payloads->mutationPage($sessionId, 0, 100);
$payloadRows = array_merge(...array_map(static fn (array $group): array => $group['mutations'], $payload['groups']));
$mailPayload = current(array_filter($payloadRows, static fn (array $row): bool => 'wp_mail_smtp' === $row['optionName']));
$yoastPayload = current(array_filter($payloadRows, static fn (array $row): bool => 'wpseo' === $row['optionName']));
$yoastMediaPayload = current(array_filter($payloadRows, static fn (array $row): bool => 'wpseo_social' === $row['optionName']));
$yoastContentPayload = current(array_filter($payloadRows, static fn (array $row): bool => 'wpseo_llmstxt' === $row['optionName']));
$wooPayload = current(array_filter($payloadRows, static fn (array $row): bool => 'woocommerce_currency' === $row['optionName']));
$wooPosPayload = current(array_filter($payloadRows, static fn (array $row): bool => 'woocommerce_pos_refund_returns_policy' === $row['optionName']));
$corePayload = current(array_filter($payloadRows, static fn (array $row): bool => 'posts_per_page' === $row['optionName']));
$mailLabels = array_column($mailPayload['diff'], 'label');
$yoastLabels = array_column($yoastPayload['diff'], 'label');
$assert(in_array('Sender email', $mailLabels, true), 'The review payload should explain WP Mail SMTP fields without database vocabulary.');
$assert(in_array('Sending domain', $mailLabels, true), 'The review payload should name provider-specific WP Mail SMTP fields.');
$assert(in_array('Message stream', $mailLabels, true), 'The review payload should identify Postmark routing settings.');
$assert(in_array('Stop all outgoing email', $mailLabels, true), 'The review payload should identify high-impact WP Mail SMTP delivery policy.');
$assert(in_array('Admin bar menu', $yoastLabels, true), 'The review payload should use the Yoast field label instead of database vocabulary.');
$assert('Store currency' === ($wooPayload['diff'][0]['label'] ?? ''), 'The review payload should name the WooCommerce setting instead of exposing its option key.');
$assert('Point of Sale refund policy' === ($wooPosPayload['diff'][0]['label'] ?? ''), 'The review payload should explain the Point of Sale receipt setting.');
$assert('Posts per page' === ($corePayload['diff'][0]['label'] ?? ''), 'The review payload should explain standard WordPress Core settings.');
$assert(
	'available' === ($yoastMediaPayload['diff'][0]['after_reference']['current_status'] ?? ''),
	'The Yoast review payload should expose the current availability of a referenced social image.'
);
$assert(
	'available' === ($yoastContentPayload['diff'][0]['after_reference']['current_status'] ?? ''),
	'The Yoast review payload should expose the current availability of a referenced LLMs.txt page.'
);

$support = $payloads->support();
$supportById = array_column($support['adapters'], null, 'id');
$assert(true === $supportById['wordpress-core']['active'] && true === $supportById['wordpress-core']['compatible'], 'The support list should reflect the running, tested WordPress Core version.');
$assert(true === $supportById['wp-mail-smtp']['active'] && true === $supportById['wp-mail-smtp']['compatible'], 'The support list should reflect the active, tested WP Mail SMTP installation.');
$assert(true === $supportById['yoast-seo']['active'] && true === $supportById['yoast-seo']['compatible'], 'The support list should reflect the active, tested Yoast installation.');
$assert(true === $supportById['woocommerce']['active'] && true === $supportById['woocommerce']['compatible'], 'The support list should reflect the active, tested WooCommerce installation.');

$secretCodec = new \ConfigOps\Capture\ValueCodec($adapters);
$secretValue = $secretCodec->encode(array('smtp' => array('pass' => 'must-never-be-stored')), 'wp_mail_smtp');
$assert($secretValue->redacted && ! str_contains($secretValue->payload, 'must-never-be-stored'), 'The real WP Mail SMTP schema should redact its password before persistence.');
$providerSecrets = $secretCodec->encode(
	array(
		'amazonses' => array('client_id' => 'AKIA-MUST-NEVER-PERSIST'),
		'postmark' => array('server_api_token' => 'postmark-must-never-persist'),
		'sendlayer' => array('free_upgrade_url' => 'https://sendlayer.example/upgrade/signed-account-token'),
	),
	'wp_mail_smtp'
);
$assert(
	$providerSecrets->redacted
	&& ! str_contains($providerSecrets->payload, 'AKIA-MUST-NEVER-PERSIST')
	&& ! str_contains($providerSecrets->payload, 'postmark-must-never-persist')
	&& ! str_contains($providerSecrets->payload, 'signed-account-token'),
	'The exact WP Mail SMTP provider contract should redact less-obvious credentials and signed account URLs.'
);

$secretSession = $captures->start('WP Mail SMTP secret contract', 1, '/wp-admin/admin.php?page=wp-mail-smtp');
$mailWithSecret = get_option('wp_mail_smtp', array());
$mailWithSecret['smtp']['pass'] = 'capture-plaintext-must-not-survive';
\WPMailSMTP\Options::init()->set($mailWithSecret);
$captures->stop();
$secretRows = $mutations->forSession($secretSession);
$secretMutation = current(array_filter($secretRows, static fn (object $row): bool => 'wp_mail_smtp' === $row->option_name));
$assert(false !== $secretMutation, 'The official WP Mail SMTP API should expose a credential change to the capture adapter.');
$assert('secret' === (string) $secretMutation->classification && 0 === (int) $secretMutation->restorable, 'A real WP Mail SMTP credential change should be labeled secret and excluded from undo.');
$assert(! str_contains((string) $secretMutation->new_value, 'capture-plaintext-must-not-survive'), 'A real WP Mail SMTP credential must never reach ConfigOps persistence as plaintext.');

$protectedBefore = get_option('wp_mail_smtp', array());
$patchSession = $captures->start('Protected WP Mail SMTP setting contract', 1, '/wp-admin/admin.php?page=wp-mail-smtp');
$protectedAfter = $protectedBefore;
$protectedAfter['mail']['from_name'] = 'Name changed beside an existing secret';
\WPMailSMTP\Options::init()->set($protectedAfter);
$protectedPasswordAfterSave = get_option('wp_mail_smtp', array())['smtp']['pass'] ?? null;
$captures->stop();
$patchRows = $mutations->forSession($patchSession);
$patchMutation = current(array_filter($patchRows, static fn (object $row): bool => 'wp_mail_smtp' === $row->option_name));
$assert(false !== $patchMutation, 'A visible mail setting beside an existing secret should still be captured.');
$assert('patch' === (string) $patchMutation->restore_mode && 1 === (int) $patchMutation->restorable, 'An existing hidden credential should allow field-level undo for supported non-secret settings.');
$assert(1 === (int) $patchMutation->safe_restore_change_count, 'The adapter should disclose the exact number of safely reversible fields.');

$restore = new \ConfigOps\Restore\RestoreService(
	$captures,
	$mutations,
	$secretCodec,
	new \ConfigOps\Database\OptionMetadataRepository($wpdb),
	new \ConfigOps\Execution\OperationLock($wpdb),
	$adapters,
	new \ConfigOps\Database\RestoreAuditRepository($wpdb)
);
$restore->restoreMutation((int) $byName['woocommerce_currency']->id);
$assert('USD' === get_option('woocommerce_currency'), 'WooCommerce currency undo should restore the prior value through the Options API.');
$restore->restoreMutation((int) $byName['woocommerce_pos_refund_returns_policy']->id);
$assert('Original receipt policy' === get_option('woocommerce_pos_refund_returns_policy'), 'Point of Sale policy undo should restore the prior receipt text.');
wp_delete_post((int) $yoastPageBefore, true);
$missingContentRejected = false;
try {
	$restore->restoreMutation((int) $byName['wpseo_llmstxt']->id);
} catch (RuntimeException $error) {
	$missingContentRejected = str_contains($error->getMessage(), 'Reference missing');
}
$assert(
	$missingContentRejected && (int) $yoastPageAfter === (int) get_option('wpseo_llmstxt', array())['contact_page'],
	'Yoast page-reference undo should stop before writing an ID whose captured page was deleted.'
);
$blockProtectedPatch = static function (mixed $value, mixed $oldValue): mixed {
	return 'Agency sender' === ($value['mail']['from_name'] ?? null) ? $oldValue : $value;
};
add_filter('pre_update_option_wp_mail_smtp', $blockProtectedPatch, 10, 2);
$patchCompensated = false;
try {
	$restore->restoreMutation((int) $patchMutation->id);
} catch (RuntimeException $error) {
	$patchCompensated = str_contains($error->getMessage(), 'reapplied and verified');
}
remove_filter('pre_update_option_wp_mail_smtp', $blockProtectedPatch, 10);
$assert($patchCompensated, 'A blocked field-level undo should verify and report compensation instead of claiming success.');
$afterBlockedPatch = get_option('wp_mail_smtp', array());
$assert(
	'Name changed beside an existing secret' === ($afterBlockedPatch['mail']['from_name'] ?? null)
	&& $protectedPasswordAfterSave === ($afterBlockedPatch['smtp']['pass'] ?? null),
	'A compensated field-level failure must preserve both the visible current value and the plugin-owned credential.'
);
$restore->restoreMutation((int) $patchMutation->id);
$protectedRestored = get_option('wp_mail_smtp', array());
$assert('Agency sender' === ($protectedRestored['mail']['from_name'] ?? null), 'Field-level undo should restore the visible sender name.');
$assert($protectedPasswordAfterSave === ($protectedRestored['smtp']['pass'] ?? null), 'Field-level undo must preserve the current plugin-owned credential exactly.');
$patchAudit = new \ConfigOps\Database\RestoreAuditRepository($wpdb);
$patchRuns = $patchAudit->forSession($patchSession);
$assert(
	'succeeded' === (string) $patchRuns[0]->status
	&& 'compensated' === (string) $patchRuns[1]->status,
	'Field-level compensation and its successful retry should both remain auditable.'
);

wp_delete_attachment((int) $yoastLogoBefore, true);
wp_delete_attachment((int) $yoastLogoAfter, true);
wp_delete_post((int) $yoastPageBefore, true);
wp_delete_post((int) $yoastPageAfter, true);
update_option('posts_per_page', $postsPerPageBefore, false);
update_option('woocommerce_currency', $wooCurrencyBefore, false);
if ($wooPosPolicyExisted) {
	update_option('woocommerce_pos_refund_returns_policy', $wooPosPolicyBefore, false);
} else {
	delete_option('woocommerce_pos_refund_returns_policy');
}

fwrite(STDOUT, "ConfigOps real-plugin adapter checks passed ({$assertions} assertions).\n");
