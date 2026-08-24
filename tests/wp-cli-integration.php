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

if (! class_exists('WP_CLI')) {
	final class WP_CLI
	{
		/** @var array<string, array{callback: callable, args: array<string, mixed>}> */
		public static array $commands = array();
		/** @var list<string> */
		public static array $lines = array();

		/**
		 * @param callable               $callback Command callback.
		 * @param array<string, mixed>   $args Registration arguments.
		 */
		public static function add_command(string $name, callable $callback, array $args = array()): bool
		{
			self::$commands[$name] = array('callback' => $callback, 'args' => $args);

			return true;
		}

		public static function line(string $message): void
		{
			self::$lines[] = $message;
		}

		public static function error(string $message): never
		{
			throw new RuntimeException($message);
		}
	}
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

$expectedCommands = array(
	'configops state',
	'configops captures list',
	'configops capture get',
	'configops mutations list',
	'configops mutation inspect',
	'configops restore plan',
	'configops capture start',
	'configops capture stop',
);
$assert(
	$expectedCommands === array_keys(WP_CLI::$commands),
	'ConfigOps should register its complete JSON WP-CLI command vocabulary when WP-CLI is available.'
);
$assert(
	false === (WP_CLI::$commands['configops restore plan']['args']['synopsis'][0]['optional'] ?? true),
	'The restore-plan command must require an explicit mutation ID.'
);

wp_set_current_user(1);
$stateCommand = WP_CLI::$commands['configops state']['callback'];
$stateCommand(array(), array());
$stateOutput = json_decode(WP_CLI::$lines[0] ?? '', true);
$assert(
	is_array($stateOutput)
	&& true === ($stateOutput['ok'] ?? false)
	&& 'site' === ($stateOutput['scope']['type'] ?? ''),
	'The WP-CLI transport should execute the registered ability and emit one versioned JSON object.'
);
wp_set_current_user(0);
$anonymousReadRejected = false;
try {
	$stateCommand(array(), array());
} catch (RuntimeException $error) {
	$errorPayload = json_decode($error->getMessage(), true);
	$anonymousReadRejected = is_array($errorPayload)
		&& false === ($errorPayload['ok'] ?? true)
		&& 'ability_invalid_permissions' === ($errorPayload['error']['code'] ?? '');
}
$assert(
	$anonymousReadRejected,
	'ConfigOps WP-CLI operations should require an explicit WordPress user even though passive shell observation accepts actor zero.'
);

fwrite(STDOUT, "ConfigOps WP-CLI compatibility checks passed ({$assertions} assertions).\n");
