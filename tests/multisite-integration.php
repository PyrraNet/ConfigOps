<?php
/**
 * Real WordPress Multisite isolation checks for the request-pinned site boundary.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

require_once __DIR__ . '/production-error-trap.php';

$wordpressRoot = rtrim((string) (getenv('CONFIGOPS_WP_ROOT') ?: '/wordpress'), '/');
require_once $wordpressRoot . '/wp-load.php';

if (! is_multisite()) {
	throw new RuntimeException('The Multisite integration check requires an enabled WordPress network.');
}
if (! defined('CONFIGOPS_FILE')) {
	require_once WP_PLUGIN_DIR . '/configops/configops.php';
	\ConfigOps\Plugin::activate();
	\ConfigOps\Plugin::boot();
}

global $wpdb;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
	++$assertions;
	if (! $condition) {
		throw new RuntimeException($message);
	}
};

$originSiteId = get_current_blog_id();
$originNetworkId = get_current_network_id();
$originPrefix = $wpdb->prefix;
$captures = new \ConfigOps\Database\CaptureRepository($wpdb);
$mutations = new \ConfigOps\Database\MutationRepository($wpdb);
$sessionId = $captures->start('Multisite boundary fixture', 0, '/wp-admin/options-general.php');

$domain = (string) wp_parse_url(network_home_url('/'), PHP_URL_HOST);
$domain = '' === $domain ? 'configops.test' : $domain;
$foreignSiteId = wpmu_create_blog(
	$domain,
	'/configops-boundary/',
	'ConfigOps boundary target',
	1,
	array('public' => 0),
	$originNetworkId
);
if (is_wp_error($foreignSiteId)) {
	throw new RuntimeException('Could not create the foreign Multisite fixture: ' . $foreignSiteId->get_error_message());
}
$foreignSiteId = (int) $foreignSiteId;
$assert($foreignSiteId > 0 && $foreignSiteId !== $originSiteId, 'The Multisite fixture should create a distinct target site.');

$assert(switch_to_blog($foreignSiteId), 'The integration check should enter the target site.');
$assert($foreignSiteId === get_current_blog_id(), 'The target site should be current before its option write.');
$saved = add_option('boundary_target_setting', 'foreign-site-value', '', false);
$assert($saved, 'The unsupported cross-site host write must still succeed normally.');
$assert(
	'foreign-site-value' === get_option('boundary_target_setting'),
	'The site boundary must never interfere with the target site\'s Options API result.'
);
$assert($foreignSiteId === get_current_blog_id(), 'Integrity reporting must preserve the target site context.');
$assert(restore_current_blog(), 'The integration check should restore the network\'s originating site.');
$assert($originSiteId === get_current_blog_id(), 'The originating site should be current after the target write.');
$assert($originPrefix === $wpdb->prefix, 'The originating database prefix should be restored with the blog-switch stack.');

$capture = $captures->find($sessionId);
$assert(
	1 === (int) $capture->capture_error_count
	&& 'cross_site_write_ignored' === (string) $capture->last_error_code,
	'The origin capture should retain one durable cross-site integrity warning.'
);
$assert(
	array() === $mutations->forSession($sessionId),
	'The target site value must never be stored as a mutation in the origin site\'s evidence.'
);
$assert($sessionId === $captures->stop(), 'The incomplete origin capture should remain safely stoppable.');

wp_set_current_user(1);
$assert(current_user_can('configops_capture'), 'The network owner should retain the site-local capture capability on the origin site.');
$allowAutomaticContext = static fn (bool $allowed): bool => true;
add_filter('configops_automatic_recording_context_allowed', $allowAutomaticContext, 10, 2);
$assert(
	add_option('boundary_origin_automatic_setting', 'origin-site-value', '', false),
	'The origin write should establish one automatic request-local observation.'
);
remove_filter('configops_automatic_recording_context_allowed', $allowAutomaticContext, 10);
$automaticSession = null;
foreach ($captures->recent(10) as $candidate) {
	if ('automatic' === (string) $candidate->capture_mode && 'active' === (string) $candidate->status) {
		$automaticSession = $candidate;
		break;
	}
}
$assert(null !== $automaticSession, 'The origin write should leave an automatic observation open until request shutdown.');
$automaticSessionId = (int) $automaticSession->id;
$assert(
	1 === count($mutations->forSession($automaticSessionId)),
	'The automatic observation should contain only its origin-site setting change before the foreign write.'
);

$assert(switch_to_blog($foreignSiteId), 'The automatic-observation check should enter the target site.');
$assert(
	add_option('boundary_automatic_target_setting', 'second-foreign-value', '', false),
	'The foreign write should still succeed while an origin automatic observation exists.'
);
$assert(restore_current_blog(), 'The automatic-observation check should restore the origin site.');
$automaticCapture = $captures->find($automaticSessionId);
$automaticRows = $mutations->forSession($automaticSessionId);
$assert(
	1 === (int) $automaticCapture->capture_error_count
	&& 'cross_site_write_ignored' === (string) $automaticCapture->last_error_code,
	'A foreign write must make an already-open automatic observation incomplete exactly once.'
);
$assert(
	1 === count($automaticRows)
	&& ! str_contains((string) $automaticRows[0]->old_value, 'second-foreign-value')
	&& ! str_contains((string) $automaticRows[0]->new_value, 'second-foreign-value'),
	'Automatic evidence must retain its origin mutation without persisting the foreign value.'
);
do_action('shutdown');
$automaticCapture = $captures->find($automaticSessionId);
$assert(
	'interrupted' === (string) $automaticCapture->status && null !== $automaticCapture->ended_at,
	'Automatic shutdown finalization must preserve the cross-site integrity warning as an interrupted observation.'
);

fwrite(STDOUT, "ConfigOps Multisite boundary checks passed ({$assertions} assertions).\n");
