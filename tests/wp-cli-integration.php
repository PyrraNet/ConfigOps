<?php
/**
 * WordPress integration contract for WP-CLI requests without --user.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

require_once __DIR__ . '/production-error-trap.php';

if (! defined('WP_CLI')) {
	define('WP_CLI', true);
}

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
		throw new RuntimeException($message);
	}
};

wp_set_current_user(0);
$assert(
	0 === get_current_user_id() && ! current_user_can('configops_capture'),
	'The WP-CLI compatibility fixture must run without an impersonated WordPress user.'
);

$captures = new \ConfigOps\Database\CaptureRepository($wpdb);
$recorder = new \ConfigOps\Capture\AutomaticRecorder(
	$captures,
	new \ConfigOps\Admin\EvidenceNoticeStore(),
	new \ConfigOps\Capture\RequestContext()
);
$sessionId = $recorder->sessionId();
$session = null === $sessionId ? null : $captures->find($sessionId);
$assert(
	$session
	&& 'automatic' === (string) $session->capture_mode
	&& 'active' === (string) $session->status
	&& 0 === (int) $session->actor_id,
	'A WP-CLI request without --user should open automatic evidence under the explicit system actor.'
);

$recorder->finalize();
$session = $captures->find((int) $sessionId);
$assert(
	$session && 'discarded' === (string) $session->status,
	'An empty WP-CLI observation should finalize cleanly without leaving an open session.'
);

fwrite(STDOUT, "ConfigOps WP-CLI compatibility checks passed ({$assertions} assertions).\n");
