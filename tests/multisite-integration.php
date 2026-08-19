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
require_once ABSPATH . 'wp-admin/includes/plugin.php';

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
		fwrite(STDERR, "Multisite assertion failed: {$message}\n");
		throw new RuntimeException($message);
	}
};

$assert(
	is_plugin_active_for_network('configops/configops.php'),
	'The Multisite contract must exercise a real network activation.'
);

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
$assert(
	10 === (int) get_option('configops_schema_version', 0),
	'A network-active ConfigOps installation should provision storage state for a newly initialized site.'
);
$foreignAdministrator = get_role('administrator');
$assert(
	$foreignAdministrator && $foreignAdministrator->has_cap('configops_capture'),
	'A newly initialized site should receive the site-local ConfigOps capabilities.'
);
$assert(
	false !== wp_next_scheduled(\ConfigOps\Maintenance\HistoryRetention::HOOK),
	'A newly initialized site should schedule its own scoped history retention.'
);
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

$networkScope = \ConfigOps\Multisite\NetworkScope::current();
$networkCaptures = new \ConfigOps\Database\CaptureRepository($wpdb, $networkScope);
$networkMutations = new \ConfigOps\Database\MutationRepository($wpdb, $networkScope);
$assert(
	$networkScope->isNetwork()
	&& $networkScope->isCurrent()
	&& $originNetworkId === $networkScope->networkId()
	&& 0 === $networkScope->siteId(),
	'Network evidence should use the current network identity and reserve blog ID zero.'
);

$networkPointerSession = $networkCaptures->start('Network state fixture', 1, '/wp-admin/network/settings.php');
$assert(
	$networkPointerSession === (int) get_network_option($originNetworkId, 'configops_active_capture_id', 0)
	&& $networkPointerSession !== (int) get_option('configops_active_capture_id', 0),
	'Network capture state must live in network options without colliding with the current site pointer.'
);
$assert(
	$networkPointerSession === $networkCaptures->stop()
	&& false === get_network_option($originNetworkId, 'configops_active_capture_id', false),
	'Stopping a network-scoped capture should clear only its network-owned pointer.'
);

$networkAddedOption = 'network_evidence_fixture_added';
$networkUpdatedOption = 'network_evidence_fixture_updated';
$networkDeletedOption = 'network_evidence_fixture_deleted';
$networkProtectedOption = 'active_sitewide_plugins';
$networkProtectedBefore = get_network_option($originNetworkId, $networkProtectedOption, array());
$networkProtectedBefore = is_array($networkProtectedBefore) ? $networkProtectedBefore : array();
$networkProtectedAfter = $networkProtectedBefore;
$networkProtectedAfter['configops-network-fixture/configops-network-fixture.php'] = time();
delete_network_option($originNetworkId, $networkAddedOption);
delete_network_option($originNetworkId, $networkUpdatedOption);
delete_network_option($originNetworkId, $networkDeletedOption);
$assert(
	add_network_option($originNetworkId, $networkUpdatedOption, 'network-before')
	&& add_network_option($originNetworkId, $networkDeletedOption, 'network-delete-before'),
	'The network evidence fixture should seed values before observation is enabled.'
);

$allowNetworkContext = static fn (bool $allowed): bool => true;
add_filter('configops_network_recording_context_allowed', $allowNetworkContext, 10, 2);
$assert(
	add_network_option($originNetworkId, $networkAddedOption, 'network-added')
	&& update_network_option($originNetworkId, $networkUpdatedOption, 'network-after')
	&& delete_network_option($originNetworkId, $networkDeletedOption)
	&& update_network_option($originNetworkId, $networkProtectedOption, $networkProtectedAfter),
	'Network settings and lifecycle writes should continue to use the native WordPress API while ConfigOps observes them.'
);
remove_filter('configops_network_recording_context_allowed', $allowNetworkContext, 10);

$networkAutomaticSession = null;
foreach ($networkCaptures->recent(10) as $candidate) {
	if ('automatic' === (string) $candidate->capture_mode && 'active' === (string) $candidate->status) {
		$networkAutomaticSession = $candidate;
		break;
	}
}
$assert(null !== $networkAutomaticSession, 'The first network option change should open one request-local network observation.');
$networkAutomaticSessionId = (int) $networkAutomaticSession->id;
$networkRows = $networkMutations->forSession($networkAutomaticSessionId);
$networkRowsByOption = array_column($networkRows, null, 'option_name');
$assert(
	4 === count($networkRows)
	&& isset($networkRowsByOption[$networkAddedOption], $networkRowsByOption[$networkUpdatedOption], $networkRowsByOption[$networkDeletedOption], $networkRowsByOption[$networkProtectedOption]),
	'Network add, update, delete, and lifecycle operations should each produce scoped evidence.'
);
$assert(
	$originNetworkId === (int) $networkAutomaticSession->network_id
	&& 0 === (int) $networkAutomaticSession->blog_id
	&& array_reduce(
		$networkRows,
		static fn (bool $valid, object $row): bool => $valid
			&& $originNetworkId === (int) $row->network_id
			&& 0 === (int) $row->blog_id,
		true
	),
	'Every network session and mutation row should carry network identity with reserved blog ID zero.'
);
$assert(
	str_contains((string) $networkRowsByOption[$networkUpdatedOption]->old_value, 'network-before')
	&& str_contains((string) $networkRowsByOption[$networkUpdatedOption]->new_value, 'network-after'),
	'Network updates should preserve their before and after values.'
);
$assert(
	'delete' === (string) $networkRowsByOption[$networkDeletedOption]->mutation_type
	&& 0 === (int) $networkRowsByOption[$networkDeletedOption]->restorable
	&& str_contains((string) $networkRowsByOption[$networkDeletedOption]->old_value, 'previous network value unavailable')
	&& ! str_contains((string) $networkRowsByOption[$networkDeletedOption]->old_value, 'network-delete-before'),
	'Network deletes should be visible but non-restorable when WordPress does not expose the previous value.'
);
$assert(
	null === $captures->find($networkAutomaticSessionId)
	&& array() === $mutations->forSession($networkAutomaticSessionId),
	'Site-scoped repositories must not resolve network-owned evidence from the shared tables.'
);

do_action('shutdown');
$networkAutomaticSession = $networkCaptures->find($networkAutomaticSessionId);
$assert(
	$networkAutomaticSession
	&& 'completed' === (string) $networkAutomaticSession->status
	&& 4 === (int) $networkAutomaticSession->mutation_count,
	'Network request shutdown should finalize the observed changes as one completed session.'
);

do_action('rest_api_init');
$networkStateResponse = rest_do_request(new \WP_REST_Request('GET', '/configops/v1/network/state'));
$networkState = $networkStateResponse->get_data();
$assert(
	200 === $networkStateResponse->get_status()
	&& is_array($networkState)
	&& 'network' === (string) ($networkState['scope']['type'] ?? '')
	&& $originNetworkId === (int) ($networkState['scope']['networkId'] ?? 0)
	&& false === (bool) ($networkState['capabilities']['capture'] ?? true)
	&& true === (bool) ($networkState['capabilities']['rollback'] ?? false)
	&& false === (bool) ($networkState['capabilities']['sessionRollback'] ?? true),
	'The Network Admin REST state should permit mutation undo without exposing capture or session-undo authority.'
);
$networkMutationResponse = rest_do_request(
	new \WP_REST_Request('GET', '/configops/v1/network/captures/' . $networkAutomaticSessionId . '/mutations')
);
$networkMutationPage = $networkMutationResponse->get_data();
$assert(
	200 === $networkMutationResponse->get_status()
	&& is_array($networkMutationPage)
	&& 4 === array_sum(array_map(
		static fn (array $group): int => count($group['mutations'] ?? array()),
		$networkMutationPage['groups'] ?? array()
	)),
	'The network mutation route should return the complete scoped ledger page.'
);
$networkPageMutations = array();
foreach ($networkMutationPage['groups'] ?? array() as $group) {
	$networkPageMutations = array_merge($networkPageMutations, $group['mutations'] ?? array());
}
$networkPageMutationsByOption = array_column($networkPageMutations, null, 'optionName');
$assert(
	true === ($networkPageMutationsByOption[$networkAddedOption]['restorable'] ?? false)
	&& true === ($networkPageMutationsByOption[$networkUpdatedOption]['restorable'] ?? false)
	&& false === ($networkPageMutationsByOption[$networkDeletedOption]['restorable'] ?? true)
	&& false === ($networkPageMutationsByOption[$networkProtectedOption]['restorable'] ?? true)
	&& str_contains(
		(string) ($networkPageMutationsByOption[$networkProtectedOption]['undoUnavailableReason'] ?? ''),
		'network authority, lifecycle, or derived state'
	),
	'Network payloads should offer full-value undo only for ordinary settings additions and updates.'
);

$updatedRestoreRequest = new \WP_REST_Request(
	'POST',
	'/configops/v1/network/mutations/' . (int) $networkRowsByOption[$networkUpdatedOption]->id . '/restore'
);
$networkRestoreLockOption = 'configops_operation_lock_' . hash('sha256', 'network:restore');
add_network_option(
	$originNetworkId,
	$networkRestoreLockOption,
	array('token' => 'abandoned', 'expires_at' => time() - 1)
);
$updatedRestoreResponse = rest_do_request($updatedRestoreRequest);
$assert(
	200 === $updatedRestoreResponse->get_status()
	&& 'network-before' === get_network_option($originNetworkId, $networkUpdatedOption)
	&& false === get_network_option($originNetworkId, $networkRestoreLockOption, false),
	'Network update undo should recover an expired lock, restore the exact captured value, and release its own lock.'
);

$addedRestoreRequest = new \WP_REST_Request(
	'POST',
	'/configops/v1/network/mutations/' . (int) $networkRowsByOption[$networkAddedOption]->id . '/restore'
);
$forceNetworkDeleteFailure = static function (string $option, int $networkId) use ($networkAddedOption, $originNetworkId): void {
	if ($networkAddedOption === $option && $originNetworkId === $networkId) {
		throw new \RuntimeException('Injected network post-delete failure.');
	}
};
add_action('delete_site_option', $forceNetworkDeleteFailure, PHP_INT_MAX, 2);
$compensatedAddedRestoreResponse = rest_do_request($addedRestoreRequest);
remove_action('delete_site_option', $forceNetworkDeleteFailure, PHP_INT_MAX);
$assert(
	409 === $compensatedAddedRestoreResponse->get_status()
	&& 'network-added' === get_network_option($originNetworkId, $networkAddedOption),
	'Network undo should reapply and verify the original current value after a post-write failure.'
);
$addedRestoreResponse = rest_do_request($addedRestoreRequest);
$assert(
	200 === $addedRestoreResponse->get_status()
	&& false === get_network_option($originNetworkId, $networkAddedOption, false),
	'Network addition undo should restore the captured absence of the option.'
);

$deletedRestoreRequest = new \WP_REST_Request(
	'POST',
	'/configops/v1/network/mutations/' . (int) $networkRowsByOption[$networkDeletedOption]->id . '/restore'
);
$deletedRestoreResponse = rest_do_request($deletedRestoreRequest);
$assert(
	409 === $deletedRestoreResponse->get_status()
	&& false === get_network_option($originNetworkId, $networkDeletedOption, false),
	'Network delete undo must fail closed because the previous value was unavailable at observation time.'
);

$protectedRestoreRequest = new \WP_REST_Request(
	'POST',
	'/configops/v1/network/mutations/' . (int) $networkRowsByOption[$networkProtectedOption]->id . '/restore'
);
$protectedRestoreResponse = rest_do_request($protectedRestoreRequest);
$assert(
	409 === $protectedRestoreResponse->get_status()
	&& $networkProtectedAfter === get_network_option($originNetworkId, $networkProtectedOption),
	'Network authority and lifecycle state should remain review-only even when its stored values are complete.'
);

$activeNetworkLock = array('token' => 'concurrent', 'expires_at' => time() + 60);
add_network_option($originNetworkId, $networkRestoreLockOption, $activeNetworkLock);
$lockedRestoreResponse = rest_do_request($updatedRestoreRequest);
$lockedRestoreError = $lockedRestoreResponse->get_data();
$assert(
	409 === $lockedRestoreResponse->get_status()
	&& is_array($lockedRestoreError)
	&& str_contains((string) ($lockedRestoreError['message'] ?? ''), 'already changing this network configuration')
	&& 'network-before' === get_network_option($originNetworkId, $networkUpdatedOption)
	&& $activeNetworkLock === get_network_option($originNetworkId, $networkRestoreLockOption),
	'An active token-owned network lock should reject overlapping undo without changing or releasing another request\'s lock.'
);
delete_network_option($originNetworkId, $networkRestoreLockOption);

$conflictRestoreResponse = rest_do_request($updatedRestoreRequest);
$assert(
	409 === $conflictRestoreResponse->get_status()
	&& 'network-before' === get_network_option($originNetworkId, $networkUpdatedOption),
	'Replaying network undo after the target changed should stop on a conflict without another write.'
);

$networkAudits = new \ConfigOps\Database\RestoreAuditRepository($wpdb, $networkScope);
$networkAuditRows = $networkAudits->forSession($networkAutomaticSessionId, 10);
$networkAuditStatuses = array_count_values(array_map(static fn (object $row): string => (string) $row->status, $networkAuditRows));
$networkAuditCodes = array_column($networkAuditRows, 'failure_code');
$assert(
	2 === ($networkAuditStatuses['succeeded'] ?? 0)
	&& 4 === ($networkAuditStatuses['failed'] ?? 0)
	&& 1 === ($networkAuditStatuses['compensated'] ?? 0)
	&& in_array('apply_failed_compensated', $networkAuditCodes, true)
	&& in_array('network_restore_unsupported', $networkAuditCodes, true)
	&& in_array('network_restore_failed', $networkAuditCodes, true)
	&& in_array('target_conflict', $networkAuditCodes, true)
	&& array_reduce(
		$networkAuditRows,
		static fn (bool $valid, object $row): bool => $valid
			&& $originNetworkId === (int) $row->network_id
			&& 0 === (int) $row->blog_id,
		true
	),
	'Every successful, compensated, locked, refused, and conflicting network undo should leave a scoped value-free audit record.'
);
$remainingNetworkLocks = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->sitemeta}
		WHERE site_id = %d AND meta_key LIKE %s",
		$originNetworkId,
		$wpdb->esc_like('configops_operation_lock_') . '%'
	)
);
$assert(0 === $remainingNetworkLocks, 'Network restore should release its token-owned operation lock after every outcome.');

$restoredMutationResponse = rest_do_request(
	new \WP_REST_Request('GET', '/configops/v1/network/captures/' . $networkAutomaticSessionId . '/mutations')
);
$restoredMutationPage = $restoredMutationResponse->get_data();
$restoredMutations = array();
foreach (is_array($restoredMutationPage) ? ($restoredMutationPage['groups'] ?? array()) : array() as $group) {
	$restoredMutations = array_merge($restoredMutations, $group['mutations'] ?? array());
}
$restoredMutationsByOption = array_column($restoredMutations, null, 'optionName');
$assert(
	'succeeded' === (string) ($restoredMutationsByOption[$networkAddedOption]['lastRestore']['status'] ?? '')
	&& 'succeeded' === (string) ($restoredMutationsByOption[$networkUpdatedOption]['lastRestore']['status'] ?? '')
	&& null === ($restoredMutationsByOption[$networkDeletedOption]['lastRestore'] ?? null),
	'The Network Admin ledger should expose successful mutation audits without presenting a refused delete as undone.'
);

$originalNetworkUserId = get_current_user_id();
wp_set_current_user(0);
$unauthorizedNetworkResponse = rest_do_request(new \WP_REST_Request('GET', '/configops/v1/network/state'));
$unauthorizedNetworkRestore = rest_do_request($deletedRestoreRequest);
$assert(
	in_array($unauthorizedNetworkResponse->get_status(), array(401, 403), true)
	&& in_array($unauthorizedNetworkRestore->get_status(), array(401, 403), true),
	'Network evidence and undo routes should reject requests without manage_network_options.'
);
wp_set_current_user($originalNetworkUserId);

delete_network_option($originNetworkId, $networkUpdatedOption);
update_network_option($originNetworkId, $networkProtectedOption, $networkProtectedBefore);
$assert(
	4 === count($networkMutations->forSession($networkAutomaticSessionId)),
	'A finalized network session must reject later same-request undo and cleanup writes instead of mutating its evidence.'
);

$expiredNetworkSession = $networkCaptures->start('Expired network history fixture', 1, '/wp-admin/network/settings.php');
$assert($expiredNetworkSession === $networkCaptures->stop(), 'The retention fixture should create completed network evidence.');
$expiredAt = gmdate('Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS);
$updatedExpiredNetwork = $wpdb->update(
	$wpdb->base_prefix . 'configops_capture_sessions',
	array('ended_at' => $expiredAt),
	array(
		'id'         => $expiredNetworkSession,
		'network_id' => $originNetworkId,
		'blog_id'    => 0,
	),
	array('%s'),
	array('%d', '%d', '%d')
);
$assert(1 === $updatedExpiredNetwork, 'The network retention fixture should become older than the default history window.');
$networkRetention = new \ConfigOps\Maintenance\HistoryRetention(
	$wpdb,
	new \ConfigOps\Execution\OperationLock($wpdb, \ConfigOps\Multisite\SiteScope::current()),
	$networkScope
);
$assert(
	1 === $networkRetention->run()
	&& null === $networkCaptures->find($expiredNetworkSession)
	&& null !== $captures->find($automaticSessionId),
	'Network retention should delete only expired blog-ID-zero evidence from its own network scope.'
);

$insertFixtureMutation = static function (
	\ConfigOps\Database\MutationRepository $repository,
	int $sessionId,
	string $value
): int {
	return $repository->insert(
		array(
			'session_id'   => $sessionId,
			'request_id'    => wp_generate_uuid4(),
			'mutation_type' => 'update',
			'option_name'   => 'shared_multisite_fixture',
			'old_value'     => 'before',
			'new_value'     => $value,
			'diff'          => '[]',
			'occurred_at'   => current_time('mysql', true),
		)
	);
};

$originStorageSession = $captures->start('Origin shared-storage fixture', 0, '/wp-admin/options-general.php');
$originMutationId = $insertFixtureMutation($mutations, $originStorageSession, 'origin-row');
$captures->incrementMutationCount($originStorageSession);
$assert($originStorageSession === $captures->stop(), 'The origin site should finalize evidence in shared storage.');
$originSignals = new \ConfigOps\Database\DatabaseWriteSignalRepository($wpdb);
$originSignalId = $originSignals->insert(
	array(
		'session_id'      => $originStorageSession,
		'request_id'      => wp_generate_uuid4(),
		'operation'       => 'update',
		'table_name'      => $originPrefix . 'fixture_table',
		'occurrence_count' => 1,
		'occurred_at'     => current_time('mysql', true),
	)
);
$originAudits = new \ConfigOps\Database\RestoreAuditRepository($wpdb);
$originAuditId = $originAudits->start('mutation', $originMutationId, $originStorageSession, 0);
$originAudits->fail($originAuditId, 'failed', 'migration_fixture');
$originSessionAuditId = $originAudits->start('session', $originStorageSession, $originStorageSession, 0);
$originAudits->fail($originSessionAuditId, 'failed', 'migration_session_fixture');

$assert(switch_to_blog($foreignSiteId), 'The shared-storage check should enter the second site.');
(new \ConfigOps\Database\Schema($wpdb))->maybeUpgrade();
$foreignScope = \ConfigOps\Multisite\SiteScope::current();
$foreignCaptures = new \ConfigOps\Database\CaptureRepository($wpdb, $foreignScope);
$foreignMutations = new \ConfigOps\Database\MutationRepository($wpdb, $foreignScope);
$foreignStorageSession = $foreignCaptures->start('Foreign shared-storage fixture', 0, '/wp-admin/options-general.php');
$foreignMutationId = $insertFixtureMutation($foreignMutations, $foreignStorageSession, 'foreign-row');
$foreignCaptures->incrementMutationCount($foreignStorageSession);
$assert($foreignStorageSession === $foreignCaptures->stop(), 'The second site should finalize its own evidence in shared storage.');
$assert(
	$originPrefix . 'configops_capture_sessions' === $wpdb->base_prefix . 'configops_capture_sessions'
	&& $originPrefix !== $wpdb->prefix,
	'The second site should use a distinct WordPress table prefix while ConfigOps retains one network-wide table namespace.'
);
$assert(restore_current_blog(), 'The shared-storage check should restore the origin site.');

$originRow = $captures->find($originStorageSession);
$originMutation = $mutations->find($originMutationId);
$assert(
	$originNetworkId === (int) $originRow->network_id
	&& $originSiteId === (int) $originRow->blog_id,
	'Origin evidence should persist its immutable network and blog identity.'
);
$assert(
	'origin-row' === (string) $originMutation->new_value
	&& 'shared_multisite_fixture' === (string) $originMutation->option_name,
	'The origin site should retain its value for an option name also used by another site.'
);
$assert(null === $captures->find($foreignStorageSession), 'An origin repository must not resolve a foreign capture ID.');
$assert(null === $mutations->find($foreignMutationId), 'An origin repository must not resolve a foreign mutation ID.');

$assert(switch_to_blog($foreignSiteId), 'The scoped-read check should re-enter the second site.');
$foreignRow = $foreignCaptures->find($foreignStorageSession);
$foreignMutation = $foreignMutations->find($foreignMutationId);
$assert(
	$originNetworkId === (int) $foreignRow->network_id
	&& $foreignSiteId === (int) $foreignRow->blog_id,
	'Foreign evidence should persist the second site identity in the shared table.'
);
$assert(
	'foreign-row' === (string) $foreignMutation->new_value
	&& 'shared_multisite_fixture' === (string) $foreignMutation->option_name,
	'The second site should retain an independent value under the same option name.'
);
$assert(null === $foreignCaptures->find($originStorageSession), 'A foreign repository must not resolve the origin capture ID.');
$assert(null === $foreignMutations->find($originMutationId), 'A foreign repository must not resolve the origin mutation ID.');
$assert(restore_current_blog(), 'The scoped-read check should restore the origin site.');

$originAdapters = new \ConfigOps\Adapter\AdapterRegistry(
	\ConfigOps\Adapter\BuiltInAdapters::create(),
	new \ConfigOps\Noise\NoiseClassifier(),
	new \ConfigOps\Capture\HeuristicSensitiveValueDetector()
);
$originRestore = new \ConfigOps\Restore\RestoreService(
	$captures,
	$mutations,
	new \ConfigOps\Capture\ValueCodec($originAdapters),
	new \ConfigOps\Database\OptionMetadataRepository($wpdb),
	new \ConfigOps\Execution\OperationLock($wpdb),
	$originAdapters,
	$originAudits,
	new \ConfigOps\Multisite\SiteBoundaryGuard(\ConfigOps\Multisite\SiteScope::current(), $captures)
);
$foreignRestoreRejected = false;
try {
	$originRestore->restoreMutation($foreignMutationId);
} catch (RuntimeException) {
	$foreignRestoreRejected = true;
}
$assert($foreignRestoreRejected, 'Restore on the origin site must reject a foreign mutation before any option write or audit starts.');
$assert(array() === $originAudits->forSession($foreignStorageSession), 'A rejected foreign restore must not create an origin-site audit row.');

$legacySiteId = wpmu_create_blog(
	$domain,
	'/configops-legacy/',
	'ConfigOps legacy migration target',
	1,
	array('public' => 0),
	$originNetworkId
);
if (is_wp_error($legacySiteId)) {
	throw new RuntimeException('Could not create the legacy migration fixture: ' . $legacySiteId->get_error_message());
}
$legacySiteId = (int) $legacySiteId;
$assert(switch_to_blog($legacySiteId), 'The migration check should enter its isolated legacy site.');

$copyLegacyTable = static function (string $suffix, string $rowFilter) use ($wpdb, $originNetworkId, $originSiteId): void {
	$sharedTable = $wpdb->base_prefix . $suffix;
	$legacyTable = $wpdb->prefix . $suffix;
	$columns = $wpdb->get_col("SHOW COLUMNS FROM `{$sharedTable}`", 0);
	$columns = array_values(array_diff(is_array($columns) ? $columns : array(), array('network_id', 'blog_id', 'legacy_id')));
	$quotedColumns = implode(', ', array_map(static fn (string $column): string => '`' . str_replace('`', '``', $column) . '`', $columns));
	$definitions = array_map(
		static fn (string $column): string => 'id' === $column
			? '`id` bigint(20) unsigned NOT NULL'
			: '`' . str_replace('`', '``', $column) . '` longtext NULL',
		$columns
	);
	$definitions[] = 'PRIMARY KEY (`id`)';
	$created = $wpdb->query("CREATE TABLE `{$legacyTable}` (" . implode(', ', $definitions) . ')');
	if (false === $created) {
		throw new RuntimeException('Could not create a per-site legacy table fixture: ' . $wpdb->last_error);
	}

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT {$quotedColumns} FROM `{$sharedTable}`
			WHERE network_id = %d AND blog_id = %d AND {$rowFilter}",
			$originNetworkId,
			$originSiteId
		),
		ARRAY_A
	);
	foreach (is_array($rows) ? $rows : array() as $row) {
		if (false === $wpdb->insert($legacyTable, $row)) {
			throw new RuntimeException('Could not populate a per-site legacy table fixture.');
		}
	}
};
$copyLegacyTable('configops_capture_sessions', 'id = ' . $originStorageSession);
$copyLegacyTable('configops_mutations', 'session_id = ' . $originStorageSession);
$copyLegacyTable('configops_write_signals', 'session_id = ' . $originStorageSession);
$copyLegacyTable('configops_restore_runs', 'session_id = ' . $originStorageSession);
update_option('configops_schema_version', 9, false);
update_option('configops_active_capture_id', $originStorageSession, false);
update_option(
	\ConfigOps\Database\CaptureRepository::INTEGRITY_FALLBACK_OPTION,
	array(
		'events' => array(
			(string) $originStorageSession => array(
				'code' => 'legacy_fixture_warning',
				'at'   => current_time('mysql', true),
			),
		),
		'overflow' => false,
	),
	false
);
(new \ConfigOps\Database\Schema($wpdb))->maybeUpgrade();
$assert(10 === (int) get_option('configops_schema_version'), 'A legacy subsite should commit the shared-storage schema only after migration.');

$legacyScope = \ConfigOps\Multisite\SiteScope::current();
$legacyCaptures = new \ConfigOps\Database\CaptureRepository($wpdb, $legacyScope);
$legacyMutations = new \ConfigOps\Database\MutationRepository($wpdb, $legacyScope);
$legacySignals = new \ConfigOps\Database\DatabaseWriteSignalRepository($wpdb, $legacyScope);
$legacyAudits = new \ConfigOps\Database\RestoreAuditRepository($wpdb, $legacyScope);
$migratedSessions = array_values(array_filter(
	$legacyCaptures->recent(20),
	static fn (object $row): bool => $originStorageSession === (int) ($row->legacy_id ?? 0)
));
$assert(1 === count($migratedSessions), 'The per-site legacy session should migrate exactly once.');
$migratedSession = $migratedSessions[0];
$migratedSessionId = (int) $migratedSession->id;
$assert(
	$migratedSessionId !== $originStorageSession
	&& $legacySiteId === (int) $migratedSession->blog_id,
	'Legacy session IDs should be remapped when another site already owns the old numeric ID.'
);
$migratedMutations = $legacyMutations->forSession($migratedSessionId);
$assert(
	1 === count($migratedMutations)
	&& $originMutationId === (int) $migratedMutations[0]->legacy_id
	&& $migratedSessionId === (int) $migratedMutations[0]->session_id
	&& 'origin-row' === (string) $migratedMutations[0]->new_value,
	'Legacy mutation payloads and their remapped session relationship should survive migration.'
);
$migratedMutationId = (int) $migratedMutations[0]->id;
$migratedSignalRows = $legacySignals->forSession($migratedSessionId);
$migratedAuditRows = $legacyAudits->forSession($migratedSessionId);
$migratedAuditsByType = array_column($migratedAuditRows, null, 'scope_type');
$assert(
	1 === count($migratedSignalRows)
	&& $originSignalId === (int) $migratedSignalRows[0]->legacy_id
	&& $migratedSessionId === (int) $migratedSignalRows[0]->session_id,
	'Legacy unmanaged-write evidence should follow the remapped session.'
);
$assert(
	2 === count($migratedAuditRows)
	&& $originAuditId === (int) $migratedAuditsByType['mutation']->legacy_id
	&& $migratedSessionId === (int) $migratedAuditsByType['mutation']->session_id
	&& $migratedMutationId === (int) $migratedAuditsByType['mutation']->scope_id
	&& $originSessionAuditId === (int) $migratedAuditsByType['session']->legacy_id
	&& $migratedSessionId === (int) $migratedAuditsByType['session']->scope_id,
	'Legacy restore audits should remap their session, mutation, and whole-session scope references.'
);
$assert(
	$migratedSessionId === (int) get_option('configops_active_capture_id', 0),
	'The site-local active capture pointer should follow its migrated session ID.'
);
$migratedFallback = get_option(\ConfigOps\Database\CaptureRepository::INTEGRITY_FALLBACK_OPTION, array());
$assert(
	'legacy_fixture_warning' === (string) ($migratedFallback['events'][(string) $migratedSessionId]['code'] ?? '')
	&& ! isset($migratedFallback['events'][(string) $originStorageSession]),
	'Integrity fallback pointers should be remapped without attaching warnings to another site.'
);
update_option('configops_schema_version', 9, false);
(new \ConfigOps\Database\Schema($wpdb))->maybeUpgrade();
$migratedCount = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM `{$wpdb->base_prefix}configops_capture_sessions`
		WHERE network_id = %d AND blog_id = %d AND legacy_id = %d",
		$originNetworkId,
		$legacySiteId,
		$originStorageSession
	)
);
$assert(1 === $migratedCount, 'Retrying an interrupted or stale migration must remain idempotent.');
$retriedFallback = get_option(\ConfigOps\Database\CaptureRepository::INTEGRITY_FALLBACK_OPTION, array());
$assert(
	'legacy_fixture_warning' === (string) ($retriedFallback['events'][(string) $migratedSessionId]['code'] ?? '')
	&& false === (bool) ($retriedFallback['overflow'] ?? true),
	'Retrying migration should preserve an already-remapped integrity fallback without degrading it to overflow.'
);
$assert(restore_current_blog(), 'The legacy migration check should restore the origin site.');
$assert($originSiteId === get_current_blog_id(), 'All Multisite storage checks should leave WordPress on the origin site.');

$deletedSiteId = wpmu_create_blog(
	$domain,
	'/configops-deleted/',
	'ConfigOps deletion cleanup target',
	1,
	array('public' => 0),
	$originNetworkId
);
if (is_wp_error($deletedSiteId)) {
	throw new RuntimeException('Could not create the site-deletion fixture: ' . $deletedSiteId->get_error_message());
}
$deletedSiteId = (int) $deletedSiteId;
$deletedPrefix = $wpdb->get_blog_prefix($deletedSiteId);
$assert(switch_to_blog($deletedSiteId), 'The site-deletion check should enter its disposable site.');
$deletedCaptures = new \ConfigOps\Database\CaptureRepository($wpdb, \ConfigOps\Multisite\SiteScope::current());
$deletedSessionId = $deletedCaptures->start('Deleted site fixture', 0, '/wp-admin/options-general.php');
$assert($deletedSessionId > 0, 'The site-deletion fixture should persist scoped evidence before cleanup.');
$createdLegacyTable = $wpdb->query(
	"CREATE TABLE `{$deletedPrefix}configops_capture_sessions` (`id` bigint(20) unsigned NOT NULL, PRIMARY KEY (`id`))"
);
$assert(false !== $createdLegacyTable, 'The deletion fixture should create one retained legacy site table.');
$assert(restore_current_blog(), 'The site-deletion check should restore the origin site before deletion.');
$deletedSite = wp_delete_site($deletedSiteId);
$assert(! is_wp_error($deletedSite), 'WordPress should delete the disposable Multisite fixture.');
$remainingDeletedEvidence = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM `{$wpdb->base_prefix}configops_capture_sessions` WHERE network_id = %d AND blog_id = %d",
		$originNetworkId,
		$deletedSiteId
	)
);
$assert(0 === $remainingDeletedEvidence, 'Deleting a site must remove its rows from shared ConfigOps storage.');
$assert(
	null === $wpdb->get_var(
		$wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($deletedPrefix . 'configops_capture_sessions'))
	),
	'Deleting a site must remove any retained legacy ConfigOps tables for that site.'
);
$assert(null === get_site($deletedSiteId), 'The disposable site should no longer exist after its cleanup contract runs.');

$originLifecycleSession = $captures->start('Network deactivation origin fixture', 0, '/wp-admin/options-general.php');
$originLifecycleAutomatic = $captures->startAutomatic('Network deactivation origin automatic fixture', 0, '/wp-admin/options-general.php');
$networkLifecycleSession = $networkCaptures->start('Network deactivation scope fixture', 1, '/wp-admin/network/settings.php');
$networkLifecycleAutomatic = $networkCaptures->startAutomatic('Network deactivation automatic scope fixture', 1, '/wp-admin/network/settings.php');
$assert(switch_to_blog($foreignSiteId), 'The network-deactivation check should enter the second site.');
$foreignLifecycleCaptures = new \ConfigOps\Database\CaptureRepository($wpdb, \ConfigOps\Multisite\SiteScope::current());
$foreignLifecycleSession = $foreignLifecycleCaptures->start('Network deactivation foreign fixture', 0, '/wp-admin/options-general.php');
$foreignLifecycleAutomatic = $foreignLifecycleCaptures->startAutomatic('Network deactivation foreign automatic fixture', 0, '/wp-admin/options-general.php');
$assert(restore_current_blog(), 'The network-deactivation check should restore the origin site.');

\ConfigOps\Plugin::deactivate(true);
$originInterrupted = $captures->find($originLifecycleSession);
$originAutomaticInterrupted = $captures->find($originLifecycleAutomatic);
$assert(
	$originInterrupted
	&& 'interrupted' === (string) $originInterrupted->status
	&& 'plugin_deactivated' === (string) $originInterrupted->last_error_code
	&& $originAutomaticInterrupted
	&& 'interrupted' === (string) $originAutomaticInterrupted->status
	&& 'plugin_deactivated' === (string) $originAutomaticInterrupted->last_error_code
	&& false === get_option('configops_active_capture_id', false)
	&& false === wp_next_scheduled(\ConfigOps\Maintenance\HistoryRetention::HOOK),
	'Network deactivation should interrupt the origin capture, clear its pointer, and unschedule retention.'
);
$assert(switch_to_blog($foreignSiteId), 'The network-deactivation verification should enter the second site.');
$foreignInterrupted = $foreignLifecycleCaptures->find($foreignLifecycleSession);
$foreignAutomaticInterrupted = $foreignLifecycleCaptures->find($foreignLifecycleAutomatic);
$assert(
	$foreignInterrupted
	&& 'interrupted' === (string) $foreignInterrupted->status
	&& 'plugin_deactivated' === (string) $foreignInterrupted->last_error_code
	&& $foreignAutomaticInterrupted
	&& 'interrupted' === (string) $foreignAutomaticInterrupted->status
	&& 'plugin_deactivated' === (string) $foreignAutomaticInterrupted->last_error_code
	&& false === get_option('configops_active_capture_id', false)
	&& false === wp_next_scheduled(\ConfigOps\Maintenance\HistoryRetention::HOOK),
	'Network deactivation should interrupt and unschedule every site in the current network.'
);
$assert(restore_current_blog(), 'The network-deactivation verification should restore the origin site.');
$networkInterrupted = $networkCaptures->find($networkLifecycleSession);
$networkAutomaticInterrupted = $networkCaptures->find($networkLifecycleAutomatic);
$assert(
	$networkInterrupted
	&& 'interrupted' === (string) $networkInterrupted->status
	&& 'plugin_deactivated' === (string) $networkInterrupted->last_error_code
	&& $networkAutomaticInterrupted
	&& 'interrupted' === (string) $networkAutomaticInterrupted->status
	&& 'plugin_deactivated' === (string) $networkAutomaticInterrupted->last_error_code
	&& false === get_network_option($originNetworkId, 'configops_active_capture_id', false),
	'Network deactivation should interrupt network-owned evidence and clear its network option pointer.'
);

\ConfigOps\Plugin::activate(true);
$networkFallbackFixture = array(
	'events'   => array('999' => array('code' => 'network_uninstall_fixture', 'at' => current_time('mysql', true))),
	'overflow' => false,
);
update_network_option(
	$originNetworkId,
	\ConfigOps\Database\CaptureRepository::INTEGRITY_FALLBACK_OPTION,
	$networkFallbackFixture
);
$networkLockFixture = 'configops_operation_lock_multisite_uninstall_fixture';
add_network_option(
	$originNetworkId,
	$networkLockFixture,
	array('token' => 'fixture', 'expires_at' => time() + 60)
);
$uninstallSites = array($originSiteId, $foreignSiteId, $legacySiteId);
foreach ($uninstallSites as $uninstallSiteId) {
	$switched = $uninstallSiteId !== get_current_blog_id();
	if ($switched) {
		$assert(switch_to_blog($uninstallSiteId), 'The uninstall fixture should enter each retained site.');
	}
	(new \ConfigOps\Admin\FlashNoticeStore())->put('multisite-uninstall-fixture');
	add_option('configops_operation_lock_multisite_uninstall_fixture', array('token' => 'fixture', 'expires_at' => time() + 60), '', false);
	$siteAdministrator = get_role('administrator');
	$assert(
		10 === (int) get_option('configops_schema_version', 0)
		&& $siteAdministrator
		&& $siteAdministrator->has_cap('configops_view')
		&& false !== wp_next_scheduled(\ConfigOps\Maintenance\HistoryRetention::HOOK),
		'Network activation should provision every existing site before the uninstall contract runs.'
	);
	if ($switched) {
		$assert(restore_current_blog(), 'The uninstall fixture should restore the origin after each site.');
	}
}

$legacyPrefix = $wpdb->get_blog_prefix($legacySiteId);
\ConfigOps\Uninstall::run();
$assert(
	false === get_network_option($originNetworkId, 'configops_active_capture_id', false)
	&& false === get_network_option(
		$originNetworkId,
		\ConfigOps\Database\CaptureRepository::INTEGRITY_FALLBACK_OPTION,
		false
	)
	&& false === get_network_option($originNetworkId, $networkLockFixture, false),
	'Uninstall should remove ConfigOps state stored in network options.'
);
foreach ($uninstallSites as $uninstallSiteId) {
	$switched = $uninstallSiteId !== get_current_blog_id();
	if ($switched) {
		$assert(switch_to_blog($uninstallSiteId), 'The uninstall verification should enter each retained site.');
	}
	$siteAdministrator = get_role('administrator');
	$remainingTransient = get_transient('configops_flash_' . get_current_user_id());
	$assert(false === get_option('configops_schema_version', false), "Uninstall should remove schema state from site {$uninstallSiteId}.");
	$assert(false === $remainingTransient, "Uninstall should remove transients from site {$uninstallSiteId}.");
	$assert(false === get_option('configops_operation_lock_multisite_uninstall_fixture', false), "Uninstall should remove locks from site {$uninstallSiteId}.");
	$assert(false === wp_next_scheduled(\ConfigOps\Maintenance\HistoryRetention::HOOK), "Uninstall should remove retention from site {$uninstallSiteId}.");
	$assert($siteAdministrator && ! $siteAdministrator->has_cap('configops_view'), "Uninstall should remove capabilities from site {$uninstallSiteId}.");
	if ($switched) {
		$assert(restore_current_blog(), 'The uninstall verification should restore the origin after each site.');
	}
}
foreach (array('configops_restore_runs', 'configops_write_signals', 'configops_mutations', 'configops_capture_sessions') as $suffix) {
	$sharedTable = $wpdb->base_prefix . $suffix;
	$legacyTable = $legacyPrefix . $suffix;
	$assert(
		null === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($sharedTable)))
		&& null === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($legacyTable))),
		'Uninstall should remove both shared and retained per-site legacy ConfigOps tables.'
	);
}

fwrite(STDOUT, "ConfigOps Multisite boundary and lifecycle checks passed ({$assertions} assertions).\n");
