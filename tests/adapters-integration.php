<?php
/**
 * Contract checks against exact public releases of supported plugins.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

require_once '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

if (! defined('CONFIGOPS_FILE')) {
	require_once '/wordpress/wp-content/plugins/configops/configops.php';
}

\ConfigOps\Plugin::activate();
\ConfigOps\Plugin::boot();

global $wpdb;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
	++$assertions;
	if (! $condition) {
		throw new RuntimeException($message);
	}
};

$mailData  = get_plugin_data(WP_PLUGIN_DIR . '/wp-mail-smtp/wp_mail_smtp.php', false, false);
$yoastData = get_plugin_data(WP_PLUGIN_DIR . '/wordpress-seo/wp-seo.php', false, false);
$assert('4.9.0' === ($mailData['Version'] ?? ''), 'The real-plugin contract must run against WP Mail SMTP 4.9.0.');
$assert('28.2' === ($yoastData['Version'] ?? ''), 'The real-plugin contract must run against Yoast SEO 28.2.');
$assert(class_exists('WPMailSMTP\\Options'), 'WP Mail SMTP should be active, not just unpacked.');
$assert(class_exists('WPSEO_Options'), 'Yoast SEO should be active, not just unpacked.');

$captures  = new \ConfigOps\Database\CaptureRepository($wpdb);
$mutations = new \ConfigOps\Database\MutationRepository($wpdb);
$signals   = new \ConfigOps\Database\DatabaseWriteSignalRepository($wpdb);
$adapters  = new \ConfigOps\Adapter\AdapterRegistry(
	array(new \ConfigOps\Adapter\WpMailSmtpAdapter(), new \ConfigOps\Adapter\YoastSeoAdapter()),
	new \ConfigOps\Noise\NoiseClassifier(),
	new \ConfigOps\Capture\HeuristicSensitiveValueDetector()
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
	)
);
\WPSEO_Options::set('enable_admin_bar_menu', false, 'wpseo');
add_option('wpseo_tracking_only', array('last_updated' => time()), '', false);
$captures->stop();

$rows = $mutations->forSession($sessionId, 100);
$byName = array();
foreach ($rows as $row) {
	$byName[(string) $row->option_name] = $row;
}

$assert(isset($byName['wp_mail_smtp']), 'The official WP Mail SMTP settings API should be captured.');
$assert('wp-mail-smtp' === (string) $byName['wp_mail_smtp']->adapter_id, 'WP Mail SMTP mutations should persist adapter identity.');
$assert('4.9.0' === (string) $byName['wp_mail_smtp']->component_version, 'WP Mail SMTP captures should pin the observed plugin version.');
$assert('environment' === (string) $byName['wp_mail_smtp']->classification, 'Mixed WP Mail SMTP connection changes should require a per-website check.');
$assert(1 === (int) $byName['wp_mail_smtp']->restorable, 'Secret-free WP Mail SMTP settings should retain conflict-checked rollback.');

$assert(isset($byName['wpseo']), 'The official Yoast options API should be captured.');
$assert('yoast-seo' === (string) $byName['wpseo']->adapter_id, 'Yoast mutations should persist adapter identity.');
$assert('28.2' === (string) $byName['wpseo']->component_version, 'Yoast captures should pin the observed plugin version.');
$assert('portable' === (string) $byName['wpseo']->classification, 'A supported Yoast feature toggle should be reusable configuration.');
$assert(isset($byName['wpseo_tracking_only']) && 'derived' === (string) $byName['wpseo_tracking_only']->classification, 'Yoast tracking state should be separated from settings.');
$assert(0 === (int) $byName['wpseo_tracking_only']->restorable, 'Technical Yoast state should not enter rollback.');

$presenter = new \ConfigOps\Admin\ReviewPresenter($adapters);
$payloads  = new \ConfigOps\Admin\AdminPayloadFactory($captures, $mutations, $signals, $presenter, $adapters);
$payload   = $payloads->mutationPage($sessionId, 0, 100);
$payloadRows = array_merge(...array_map(static fn (array $group): array => $group['mutations'], $payload['groups']));
$mailPayload = current(array_filter($payloadRows, static fn (array $row): bool => 'wp_mail_smtp' === $row['optionName']));
$yoastPayload = current(array_filter($payloadRows, static fn (array $row): bool => 'wpseo' === $row['optionName']));
$mailLabels = array_column($mailPayload['diff'], 'label');
$yoastLabels = array_column($yoastPayload['diff'], 'label');
$assert(in_array('Sender email', $mailLabels, true), 'The review payload should explain WP Mail SMTP fields without database vocabulary.');
$assert(in_array('SEO menu in the toolbar', $yoastLabels, true), 'The review payload should explain Yoast fields without database vocabulary.');

$support = $payloads->support();
$supportById = array_column($support['adapters'], null, 'id');
$assert(true === $supportById['wp-mail-smtp']['active'] && true === $supportById['wp-mail-smtp']['compatible'], 'The support list should reflect the active, tested WP Mail SMTP installation.');
$assert(true === $supportById['yoast-seo']['active'] && true === $supportById['yoast-seo']['compatible'], 'The support list should reflect the active, tested Yoast installation.');

$secretCodec = new \ConfigOps\Capture\ValueCodec($adapters);
$secretValue = $secretCodec->encode(array('smtp' => array('pass' => 'must-never-be-stored')), 'wp_mail_smtp');
$assert($secretValue->redacted && ! str_contains($secretValue->payload, 'must-never-be-stored'), 'The real WP Mail SMTP schema should redact its password before persistence.');

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

fwrite(STDOUT, "ConfigOps real-plugin adapter checks passed ({$assertions} assertions).\n");
