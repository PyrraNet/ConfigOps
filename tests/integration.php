<?php
/**
 * WordPress integration smoke test for capture, redaction, classification, and restore.
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

$fixturePlugin = '/wordpress/wp-content/plugins/configops-hostile-fixture/configops-hostile-fixture.php';
if (! is_file($fixturePlugin)) {
	throw new RuntimeException('The hostile ConfigOps fixture plugin was not mounted.');
}
require_once $fixturePlugin;

global $wpdb;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
	++$assertions;
	if (! $condition) {
		throw new RuntimeException($message);
	}
};

$captures  = new \ConfigOps\Database\CaptureRepository($wpdb);
$mutations = new \ConfigOps\Database\MutationRepository($wpdb);
$codec     = new \ConfigOps\Capture\ValueCodec();
$metadata  = new \ConfigOps\Database\OptionMetadataRepository($wpdb);
$operationLock = new \ConfigOps\Execution\OperationLock($wpdb);
$restore       = new \ConfigOps\Restore\RestoreService($captures, $mutations, $codec, $metadata, $operationLock);

delete_option('fixture_nested');
delete_option('fixture_deleted');
delete_option('fixture_credentials');
delete_option('_transient_fixture_cache');
delete_option('fixture_semantic_reorder');

add_option('fixture_nested', array('mail' => array('enabled' => false, 'retry' => 3)), '', false);
add_option('fixture_deleted', 'baseline', '', false);
add_option('fixture_semantic_reorder', array('first' => 1, 'second' => 2), '', false);

$sessionId = $captures->start('Integration capture', 0, '/wp-admin/options-general.php');
update_option('fixture_nested', array('mail' => array('retry' => 4, 'enabled' => true)));
update_option('fixture_semantic_reorder', array('second' => 2, 'first' => 1));
update_option('_transient_fixture_cache', array('checked' => time()));
add_option('fixture_credentials', array('username' => 'mailer', 'password' => 'never-store-me'), '', false);
delete_option('fixture_deleted');
set_transient('configops_flash_integration', array('code' => 'internal'), 60);

$throwingReporter = static function (): void {
	throw new RuntimeException('A capture error listener must not escape into the host request.');
};
add_action('configops_capture_error', $throwingReporter);
$observerCallbackSurvived = true;
try {
	$failureProbe = new \ConfigOps\Capture\MutationObserver(
		$captures,
		$mutations,
		new \ConfigOps\Database\OptionMetadataRepository($wpdb),
		new \ConfigOps\Capture\InternalOptionPolicy(),
		$codec,
		new \ConfigOps\Diff\NestedDiff(),
		new \ConfigOps\Noise\NoiseClassifier(),
		new \ConfigOps\Capture\SourceAttributor(CONFIGOPS_PATH),
		new \ConfigOps\Capture\RequestContext()
	);
	$failureProbe->onUpdated('fixture_invalid_utf8', 'valid', chr(0xB1) . 'invalid');
} catch (Throwable) {
	$observerCallbackSurvived = false;
}
remove_action('configops_capture_error', $throwingReporter);

$captures->stop();

$rows = $mutations->forSession($sessionId);
$assert(4 === count($rows), 'The active capture should record add, update, runtime, and delete mutations.');
$summary = $mutations->summaryForSession($sessionId);
$assert(
	array('total' => 4, 'derived' => 1, 'redacted' => 1, 'not_restorable' => 1) === $summary,
	'Session summaries should remain accurate without loading every mutation payload.'
);
$iteratedRows = iterator_to_array($mutations->iterateForSession($sessionId, 2), false);
$assert(4 === count($iteratedRows), 'Batched mutation iteration should traverse the complete session.');

$byName = array();
foreach ($rows as $row) {
	$byName[(string) $row->option_name] = $row;
}

$assert(isset($byName['fixture_nested']), 'The nested option update should be present.');
$nestedDiff = json_decode((string) $byName['fixture_nested']->diff, true, 64, JSON_THROW_ON_ERROR);
$assert('/mail/enabled' === $nestedDiff[0]['path'], 'The nested boolean path should be explicit.');
$assert('/mail/retry' === $nestedDiff[1]['path'], 'The nested integer path should be explicit.');
$assert('derived' === $byName['_transient_fixture_cache']->classification, 'Transients should be classified as derived noise.');
$assert(1 === (int) $byName['fixture_credentials']->is_redacted, 'Nested passwords should be redacted before storage.');
$assert(! str_contains((string) $byName['fixture_credentials']->new_value, 'never-store-me'), 'Stored mutation payloads must contain no secret plaintext.');
$assert(0 === (int) $byName['fixture_credentials']->restorable, 'Redacted mutations must not be restorable.');
$assert($observerCallbackSurvived, 'A codec and error-listener failure must not escape the observer callback.');
$assert(! isset($byName['configops_flash_integration']), 'ConfigOps-owned transients must never observe themselves.');
$assert(! isset($byName['fixture_semantic_reorder']), 'Semantically unchanged associative key order must not create capture noise.');

$nestedLockRejected = false;
$operationLock->run(
	'integration-probe',
	static function () use ($operationLock, &$nestedLockRejected): void {
		try {
			$operationLock->run('integration-probe', static fn (): null => null);
		} catch (RuntimeException) {
			$nestedLockRejected = true;
		}
	}
);
$assert($nestedLockRejected, 'Concurrent state-changing operations in the same scope must be rejected atomically.');

$staleScope = 'integration-stale-probe';
$staleLockOption = 'configops_operation_lock_' . hash('sha256', $staleScope);
add_option(
	$staleLockOption,
	array('token' => 'abandoned', 'expires_at' => time() - 1),
	'',
	false
);
$staleLockRecovered = false;
$operationLock->run(
	$staleScope,
	static function () use (&$staleLockRecovered): void {
		$staleLockRecovered = true;
	}
);
$assert($staleLockRecovered, 'An expired operation lock should be recovered with a compare-and-swap takeover.');
$assert(false === get_option($staleLockOption, false), 'A recovered operation lock should still be released by token ownership.');

$expectedAutoload = (string) $byName['fixture_nested']->new_autoload;
$conflictingAutoload = in_array($expectedAutoload, array('on', 'yes', 'auto-on'), true) ? 'off' : 'on';
$wpdb->update(
	$wpdb->options,
	array('autoload' => $conflictingAutoload),
	array('option_name' => 'fixture_nested'),
	array('%s'),
	array('%s')
);
wp_cache_delete('fixture_nested', 'options');
wp_cache_delete('alloptions', 'options');

$autoloadConflictDetected = false;
try {
	$restore->restoreMutation((int) $byName['fixture_nested']->id);
} catch (RuntimeException) {
	$autoloadConflictDetected = true;
}
$assert($autoloadConflictDetected, 'Restore should detect a changed autoload state even when the value still matches.');

$wpdb->update(
	$wpdb->options,
	array('autoload' => $expectedAutoload),
	array('option_name' => 'fixture_nested'),
	array('%s'),
	array('%s')
);
wp_cache_delete('fixture_nested', 'options');
wp_cache_delete('alloptions', 'options');

update_option('fixture_nested', array('mail' => array('enabled' => true, 'retry' => 4)));
$restore->restoreMutation((int) $byName['fixture_nested']->id);
$assert(
	array('mail' => array('enabled' => false, 'retry' => 3)) === get_option('fixture_nested'),
	'Individual restore should reinstate the exact nested baseline.'
);

$redactedRejected = false;
try {
	$restore->restoreMutation((int) $byName['fixture_credentials']->id);
} catch (RuntimeException) {
	$redactedRejected = true;
}
$assert($redactedRejected, 'Restore must reject a mutation whose secret material was redacted.');

$secondSession = $captures->start('Session restore check', 0, '/wp-admin/options-general.php');
update_option('fixture_nested', array('mail' => array('enabled' => true, 'retry' => 9)));
update_option('fixture_deleted', 'recreated');
$captures->stop();

$restoreOrder = array();
$updatedOrderListener = static function (string $option) use (&$restoreOrder): void {
	if (in_array($option, array('fixture_nested', 'fixture_deleted'), true)) {
		$restoreOrder[] = $option;
	}
};
$deletedOrderListener = static function (string $option) use (&$restoreOrder): void {
	if (in_array($option, array('fixture_nested', 'fixture_deleted'), true)) {
		$restoreOrder[] = $option;
	}
};
add_action('updated_option', $updatedOrderListener, 20, 1);
add_action('delete_option', $deletedOrderListener, 20, 1);
$restoredCount = $restore->restoreSession($secondSession);
remove_action('updated_option', $updatedOrderListener, 20);
remove_action('delete_option', $deletedOrderListener, 20);

$assert(2 === $restoredCount, 'Session restore should collapse changes to distinct option baselines.');
$assert(
	array('fixture_deleted', 'fixture_nested') === $restoreOrder,
	'Session restore should compensate distinct options in reverse mutation order.'
);
$assert(
	array('mail' => array('enabled' => false, 'retry' => 3)) === get_option('fixture_nested'),
	'Session restore should reinstate the first state for each option.'
);
$assert(false === get_option('fixture_deleted', false), 'Session restore should delete an option that did not exist at capture start.');

delete_option('fixture_compensated_add');
update_option('fixture_compensation_failure', 'baseline', false);
$compensationSession = $captures->start('Partial restore compensation', 0, '/wp-admin/options-general.php');
update_option('fixture_compensation_failure', 'changed', false);
add_option('fixture_compensated_add', 'created', '', false);
$captures->stop();

$blockBaselineRestore = static function (mixed $value, mixed $oldValue): mixed {
	return 'baseline' === $value ? $oldValue : $value;
};
add_filter('pre_update_option_fixture_compensation_failure', $blockBaselineRestore, 10, 2);
$compensationTriggered = false;
try {
	$restore->restoreSession($compensationSession);
} catch (RuntimeException $error) {
	$compensationTriggered = str_contains($error->getMessage(), 'Earlier restore steps were compensated.');
}
remove_filter('pre_update_option_fixture_compensation_failure', $blockBaselineRestore, 10);

$assert($compensationTriggered, 'A partial session restore should report its compensating recovery.');
$assert('created' === get_option('fixture_compensated_add'), 'Earlier restore writes should be compensated after a later failure.');
$restore->restoreSession($compensationSession);
$assert(false === get_option('fixture_compensated_add', false), 'The operation lock must be released after a failed restore.');
$assert('baseline' === get_option('fixture_compensation_failure'), 'A later retry should restore the full baseline cleanly.');

delete_option('fixture_nested');
delete_option('fixture_credentials');
delete_option('_transient_fixture_cache');
delete_option('fixture_semantic_reorder');
delete_option('fixture_compensation_failure');
delete_transient('configops_flash_integration');

\ConfigOpsHostileFixture\SettingsFixture::cleanup();
\ConfigOpsHostileFixture\SettingsFixture::seed();
$originalRequestMethod = $_SERVER['REQUEST_METHOD'] ?? null;
$originalRequestUri = $_SERVER['REQUEST_URI'] ?? null;
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=configops-hostile-fixture';

$hostileSession = $captures->start('Hostile fixture settings save', 0, '/wp-admin/admin.php?page=configops-hostile-fixture');
\ConfigOpsHostileFixture\SettingsFixture::saveSettings(321);
\ConfigOpsHostileFixture\SettingsFixture::migrateToVersionTwo();
\ConfigOpsHostileFixture\SettingsFixture::writeDirectly('after');
$captures->stop();

$hostileRows = $mutations->forSession($hostileSession);
$assert(8 === count($hostileRows), 'The hostile fixture should produce eight observable Options API mutations.');
$hostileSummary = $mutations->summaryForSession($hostileSession);
$assert(
	array('total' => 8, 'derived' => 3, 'redacted' => 1, 'not_restorable' => 1) === $hostileSummary,
	'The hostile fixture summary should distinguish decisions, runtime noise, and its redacted secret.'
);

$hostileByName = array();
foreach ($hostileRows as $row) {
	$hostileByName[(string) $row->option_name] = $row;
}

$fixtureClass = \ConfigOpsHostileFixture\SettingsFixture::class;
$enabledOption = $fixtureClass::ENABLED_OPTION;
$settingsOption = $fixtureClass::SETTINGS_OPTION;
$credentialsOption = $fixtureClass::CREDENTIALS_OPTION;
$obsoleteOption = $fixtureClass::OBSOLETE_OPTION;
$lastCheckedOption = $fixtureClass::LAST_CHECKED_OPTION;
$schemaOption = $fixtureClass::SCHEMA_OPTION;
$directOption = $fixtureClass::DIRECT_OPTION;

$assert(isset($hostileByName[$enabledOption]), 'A simple fixture toggle should be captured.');
$assert('delete' === (string) $hostileByName[$obsoleteOption]->mutation_type, 'The fixture deletion should retain its operation type.');

$settingsDiff = json_decode((string) $hostileByName[$settingsOption]->diff, true, 64, JSON_THROW_ON_ERROR);
$settingsByPath = array();
foreach ($settingsDiff as $change) {
	$settingsByPath[(string) $change['path']] = $change;
}
$assert(
	321 === $settingsByPath['/content/contact_page_id']['after'],
	'WordPress object IDs should remain typed and visible at their exact nested path.'
);
$assert(
	true === $settingsByPath['/mail/enabled']['after'] && 5 === $settingsByPath['/mail/retry']['after'],
	'The hostile nested save should expose only the changed mail leaves.'
);

$credentials = $hostileByName[$credentialsOption];
$assert(1 === (int) $credentials->is_redacted, 'The fixture password should be redacted before persistence.');
$assert(
	! str_contains((string) $credentials->new_value, \ConfigOpsHostileFixture\SettingsFixture::SECRET),
	'The hostile fixture secret must never enter stored mutation payloads.'
);

$assert('derived' === (string) $hostileByName[$lastCheckedOption]->classification, 'A save-time last_checked side effect should be classified as derived.');
$assert(
	(string) $hostileByName[$settingsOption]->request_id === (string) $hostileByName[$lastCheckedOption]->request_id,
	'Settings and their synchronous runtime side effects should stay in one causal request group.'
);
$assert(
	'plugin' === (string) $hostileByName[$settingsOption]->source_type
	&& 'configops-hostile-fixture' === (string) $hostileByName[$settingsOption]->source_component
	&& str_contains((string) $hostileByName[$settingsOption]->source_file, 'configops-hostile-fixture.php'),
	'Source attribution should retain a sibling plugin whose directory starts with the ConfigOps slug.'
);

$schemaDiff = json_decode((string) $hostileByName[$schemaOption]->diff, true, 64, JSON_THROW_ON_ERROR);
$schemaPaths = array_column($schemaDiff, 'path');
$assert(
	in_array('/mailer', $schemaPaths, true)
	&& in_array('/mail', $schemaPaths, true)
	&& in_array('/features', $schemaPaths, true)
	&& in_array('/schema', $schemaPaths, true),
	'A plugin schema migration should expose removed, added, and versioned paths without flattening the option.'
);

$assert('after' === get_option($directOption), 'The fixture direct SQL write should actually change its target value.');
$assert(! isset($hostileByName[$directOption]), 'Direct SQL writes must remain explicitly outside generic Options API capture.');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php?action=configops_fixture_save';
$ajaxSession = $captures->start('Hostile fixture AJAX save', 0, '/wp-admin/admin-ajax.php');
do_action('wp_ajax_configops_fixture_save');
$captures->stop();
$ajaxRows = $mutations->forSession($ajaxSession);
$assert(1 === count($ajaxRows), 'An AJAX handler writing through update_option() should remain observable.');
$assert(
	'POST' === (string) $ajaxRows[0]->request_method
	&& '/wp-admin/admin-ajax.php' === (string) $ajaxRows[0]->request_uri
	&& 'configops-hostile-fixture' === (string) $ajaxRows[0]->source_component,
	'AJAX captures should retain safe request metadata and plugin provenance.'
);

if (null === $originalRequestMethod) {
	unset($_SERVER['REQUEST_METHOD']);
} else {
	$_SERVER['REQUEST_METHOD'] = $originalRequestMethod;
}
if (null === $originalRequestUri) {
	unset($_SERVER['REQUEST_URI']);
} else {
	$_SERVER['REQUEST_URI'] = $originalRequestUri;
}
\ConfigOpsHostileFixture\SettingsFixture::cleanup();

$bulkSession = $captures->start('Pagination and budget check', 0, '/wp-admin/options-general.php');
for ($index = 0; $index < 101; ++$index) {
	add_option('fixture_bulk_' . $index, $index, '', false);
}
$captures->stop();

$bulkSummary = $mutations->summaryForSession($bulkSession);
$bulkFirstPage = $mutations->forSession($bulkSession, 100, 0);
$bulkSecondPage = $mutations->forSession($bulkSession, 100, 100);
$assert(101 === $bulkSummary['total'], 'Large-session summaries should cover mutations beyond the first review page.');
$assert(100 === count($bulkFirstPage) && 1 === count($bulkSecondPage), 'Review reads should remain explicitly paged.');
$assert(
	'trace-budget-exceeded' === (string) $bulkSecondPage[0]->source_component,
	'Source attribution should fail closed after the per-request trace budget.'
);

for ($index = 0; $index < 101; ++$index) {
	delete_option('fixture_bulk_' . $index);
}

update_option('configops_active_capture_id', 999999, false);
$freshCaptures = new \ConfigOps\Database\CaptureRepository($wpdb);
$assert(null === $freshCaptures->activeId(), 'A stale active-session pointer should heal itself.');
$assert(false === get_option('configops_active_capture_id', false), 'A healed session pointer should be removed.');

wp_set_current_user(1);
$payloadFactory = new \ConfigOps\Admin\AdminPayloadFactory(
	$freshCaptures,
	$mutations,
	new \ConfigOps\Admin\ReviewPresenter()
);
$shellPayload = $payloadFactory->state($bulkSession, '', '', false);
$assert(true === $shellPayload['review']['deferred'], 'The PHP shell must defer mutation history instead of inflating initial HTML.');
$assert(array() === $shellPayload['review']['groups'], 'Deferred shell state must contain no mutation diff payloads.');

$restServer = rest_get_server();
$stateRequest = new WP_REST_Request('GET', '/configops/v1/state');
$stateResponse = $restServer->dispatch($stateRequest);
$stateData = $stateResponse->get_data();
$assert(200 === $stateResponse->get_status(), 'The local Agent state endpoint should be available to authorized administrators.');
$assert(
	is_array($stateData) && isset($stateData['sessions'], $stateData['review'], $stateData['capabilities']),
	'The Agent state contract should expose stable session, review, and capability boundaries.'
);

$firstBulkMutationId = (int) $bulkFirstPage[array_key_last($bulkFirstPage)]->id;
$pageRequest = new WP_REST_Request('GET', "/configops/v1/captures/{$bulkSession}/mutations");
$pageRequest->set_param('id', $bulkSession);
$pageRequest->set_param('after', $firstBulkMutationId);
$pageRequest->set_param('limit', 100);
$pageResponse = $restServer->dispatch($pageRequest);
$pageData = $pageResponse->get_data();
$assert(
	200 === $pageResponse->get_status() && is_array($pageData) && 1 === count($pageData['groups']),
	'The mutation connection should continue from an opaque monotonic boundary without an offset scan.'
);
$assert(false === $pageData['pageInfo']['hasNext'], 'The final mutation connection page should close its continuation honestly.');

$startRequest = new WP_REST_Request('POST', '/configops/v1/captures');
$startRequest->set_body_params(array('name' => 'REST command contract'));
$startResponse = $restServer->dispatch($startRequest);
$startData = $startResponse->get_data();
$assert(200 === $startResponse->get_status() && is_array($startData) && null !== $startData['active'], 'The REST command layer should start a capture through the same domain service.');

$stopRequest = new WP_REST_Request('POST', '/configops/v1/captures/active/stop');
$stopResponse = $restServer->dispatch($stopRequest);
$stopData = $stopResponse->get_data();
$assert(200 === $stopResponse->get_status() && is_array($stopData) && null === $stopData['active'], 'The REST command layer should return the refreshed state after stopping.');

wp_set_current_user(0);
$forbiddenResponse = $restServer->dispatch(new WP_REST_Request('GET', '/configops/v1/state'));
$assert($forbiddenResponse->get_status() >= 400, 'The local Agent API must fail closed without a ConfigOps capability.');

fwrite(STDOUT, "ConfigOps WordPress integration checks passed ({$assertions} assertions).\n");
