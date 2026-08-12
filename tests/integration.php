<?php
/**
 * WordPress integration smoke test for capture, redaction, classification, and restore.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

if (! defined('WP_DEBUG')) {
	define('WP_DEBUG', true);
	define('WP_DEBUG_DISPLAY', true);
}
register_shutdown_function(
	static function (): void {
		$error = error_get_last();
		if (is_array($error) && in_array((int) $error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
			fwrite(STDERR, sprintf("Fatal integration error: %s in %s:%d\n", $error['message'], $error['file'], $error['line']));
		}
	}
);

$wordpressRoot = rtrim((string) (getenv('CONFIGOPS_WP_ROOT') ?: '/wordpress'), '/');
require_once $wordpressRoot . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

if (! defined('CONFIGOPS_FILE')) {
	require_once WP_PLUGIN_DIR . '/configops/configops.php';
}

\ConfigOps\Plugin::activate();
\ConfigOps\Plugin::boot();

$fixturePlugin = WP_PLUGIN_DIR . '/configops-hostile-fixture/configops-hostile-fixture.php';
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

$sessionTable = $wpdb->prefix . 'configops_capture_sessions';
$sessionColumns = $wpdb->get_col("SHOW COLUMNS FROM `{$sessionTable}`", 0);
$assert(
	in_array('capture_error_count', $sessionColumns, true)
	&& in_array('last_error_code', $sessionColumns, true)
	&& in_array('last_error_at', $sessionColumns, true),
	'Schema activation must verify and expose every capture-integrity column before boot.'
);
$restoreRunTable = $wpdb->prefix . 'configops_restore_runs';
$restoreRunColumns = $wpdb->get_col("SHOW COLUMNS FROM `{$restoreRunTable}`", 0);
$assert(
	in_array('status', $restoreRunColumns, true)
	&& in_array('failure_code', $restoreRunColumns, true)
	&& ! in_array('option_value', $restoreRunColumns, true),
	'Schema activation must provide a value-free restore audit table before boot.'
);
update_option('configops_schema_version', 6, false);
(new \ConfigOps\Database\Schema($wpdb))->maybeUpgrade();
$assert(8 === (int) get_option('configops_schema_version'), 'A stale schema version should upgrade idempotently under the schema lock.');

update_option('configops_schema_version', 7, false);
$hideSchemaTables = static function (string $query): string {
	return str_starts_with($query, 'SHOW TABLES LIKE') ? "SELECT 'missing_configops_table'" : $query;
};
add_filter('query', $hideSchemaTables, PHP_INT_MIN, 1);
$schemaFailureRejected = false;
try {
	(new \ConfigOps\Database\Schema($wpdb))->install();
} catch (RuntimeException) {
	$schemaFailureRejected = true;
}
remove_filter('query', $hideSchemaTables, PHP_INT_MIN);
$assert($schemaFailureRejected, 'Schema verification should reject a storage layer that cannot prove its tables exist.');
$assert(7 === (int) get_option('configops_schema_version'), 'A failed schema verification must never advance the committed schema version.');
(new \ConfigOps\Database\Schema($wpdb))->maybeUpgrade();
$assert(8 === (int) get_option('configops_schema_version'), 'A schema upgrade should recover after the injected storage failure clears.');

$administrator = get_role('administrator');
$assert(false !== $administrator, 'WordPress should provide an administrator role for capability checks.');
$administrator->remove_cap('configops_view');
(new \ConfigOps\Access\CapabilityManager())->maybeInstall();
$assert($administrator->has_cap('configops_view'), 'ConfigOps should repair a missing administrator capability even when its install version is current.');

$captures  = new \ConfigOps\Database\CaptureRepository($wpdb);
$mutations = new \ConfigOps\Database\MutationRepository($wpdb);
$writeSignals = new \ConfigOps\Database\DatabaseWriteSignalRepository($wpdb);
$adapters = new \ConfigOps\Adapter\AdapterRegistry(
	array(new \ConfigOps\Adapter\WpMailSmtpAdapter(), new \ConfigOps\Adapter\YoastSeoAdapter()),
	new \ConfigOps\Noise\NoiseClassifier(),
	new \ConfigOps\Capture\HeuristicSensitiveValueDetector()
);
$codec     = new \ConfigOps\Capture\ValueCodec($adapters);
$metadata  = new \ConfigOps\Database\OptionMetadataRepository($wpdb);
$operationLock = new \ConfigOps\Execution\OperationLock($wpdb);
$restoreAudit  = new \ConfigOps\Database\RestoreAuditRepository($wpdb);
$restore       = new \ConfigOps\Restore\RestoreService($captures, $mutations, $codec, $metadata, $operationLock, $adapters, $restoreAudit);

$longCaptureName = str_repeat('Ü', 220);
$longNameSession = $captures->start($longCaptureName, 0, '/wp-admin/options-general.php');
$captures->stop();
$storedLongName = (string) $captures->find($longNameSession)->name;
$assert(191 === mb_strlen($storedLongName, 'UTF-8'), 'Capture names should be centrally limited to the native 191-character database boundary.');

$markupNameSession = $captures->start('Review <img src=x onerror=alert(1)> settings', 0, '/wp-admin/options-general.php');
$captures->stop();
$markupName = (string) $captures->find($markupNameSession)->name;
$assert(! str_contains($markupName, '<') && str_contains($markupName, 'Review'), 'Capture names must discard executable markup at the central storage boundary.');

$ownershipSession = $captures->start('Atomic ownership check', 0, '/wp-admin/options-general.php');
$secondStartRejected = false;
try {
	(new \ConfigOps\Database\CaptureRepository($wpdb))->start('Competing capture', 0, '/wp-admin/options-general.php');
} catch (RuntimeException) {
	$secondStartRejected = true;
}
$assert($secondStartRejected, 'Two requests must never own the active capture pointer at the same time.');
$assert($ownershipSession === $captures->stop(), 'The original capture owner should remain stoppable after a competing start is rejected.');

$stopFailureSession = $captures->start('Stop failure recovery', 0, '/wp-admin/options-general.php');
$breakStopSummary = static function (string $query): string {
	return str_contains($query, 'SUM(occurrence_count)') && str_contains($query, 'configops_write_signals')
		? 'SELECT NULL'
		: $query;
};
add_filter('query', $breakStopSummary, PHP_INT_MIN, 1);
$stopFailureRejected = false;
try {
	$captures->stop();
} catch (RuntimeException $error) {
	$stopFailureRejected = str_contains($error->getMessage(), 'remains active');
}
remove_filter('query', $breakStopSummary, PHP_INT_MIN);
$assert($stopFailureRejected, 'Capture stop must fail closed when its persisted summary cannot be verified.');
$assert($stopFailureSession === $captures->activeId(), 'A failed stop must preserve the active capture for a safe retry.');
$assert($stopFailureSession === $captures->stop(), 'Capture stop should succeed cleanly after the storage failure clears.');

$lateMutationSession = $captures->start('Late mutation integrity check', 0, '/wp-admin/options-general.php');
$captures->stop();
$captures->incrementMutationCount($lateMutationSession, 1, 0);
$lateMutationCapture = $captures->find($lateMutationSession);
$assert(
	1 === (int) $lateMutationCapture->capture_error_count
	&& 'late_mutation' === (string) $lateMutationCapture->last_error_code,
	'A mutation completing after capture finalization must permanently invalidate whole-capture trust.'
);
$lateSignalSession = $captures->start('Late database write integrity check', 0, '/wp-admin/options-general.php');
$captures->stop();
$captures->incrementWriteSignalCount($lateSignalSession);
$lateSignalCapture = $captures->find($lateSignalSession);
$assert(
	1 === (int) $lateSignalCapture->capture_error_count
	&& 'late_database_write' === (string) $lateSignalCapture->last_error_code,
	'A database-write signal completing after finalization must mark the evidence incomplete.'
);

$timedOutStopSession = $captures->start('Timed out stop recovery', 0, '/wp-admin/options-general.php');
$wpdb->update(
	$wpdb->prefix . 'configops_capture_sessions',
	array(
		'status'   => 'stopping',
		'ended_at' => gmdate('Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS),
	),
	array('id' => $timedOutStopSession),
	array('%s', '%s'),
	array('%d')
);
$staleStopRepository = new \ConfigOps\Database\CaptureRepository($wpdb);
$assert(null === $staleStopRepository->activeId(), 'An abandoned stopping state should not block WordPress indefinitely.');
$timedOutCapture = $staleStopRepository->find($timedOutStopSession);
$assert(
	'interrupted' === (string) $timedOutCapture->status
	&& 'stop_timed_out' === (string) $timedOutCapture->last_error_code
	&& 1 === (int) $timedOutCapture->capture_error_count,
	'An abandoned stop should recover as explicit incomplete evidence, never as a completed capture.'
);
$assert(false === get_option('configops_active_capture_id', false), 'Timed-out stop recovery should release the stale active pointer.');
$postTimeoutSession = $captures->start('Capture after timed out stop', 0, '/wp-admin/options-general.php');
$assert($postTimeoutSession === $captures->stop(), 'A new capture should work after an abandoned stop is recovered.');

delete_option('fixture_stop_boundary_add');
$stopBoundarySession = $captures->start('Stop boundary evidence', 0, '/wp-admin/options-general.php');
$stopDuringAddedOption = static function (string $option) use ($captures): void {
	if ('fixture_stop_boundary_add' === $option) {
		$captures->stop();
	}
};
add_action('added_option', $stopDuringAddedOption, 1, 1);
add_option('fixture_stop_boundary_add', 'written-before-after-hook', '', false);
remove_action('added_option', $stopDuringAddedOption, 1);
$stopBoundaryCapture = $captures->find($stopBoundarySession);
$stopBoundaryRows = $mutations->forSession($stopBoundarySession);
$assert(
	1 === count($stopBoundaryRows)
	&& 'fixture_stop_boundary_add' === (string) $stopBoundaryRows[0]->option_name,
	'An option write that crossed the stop boundary must remain visible as captured evidence.'
);
$assert(
	1 === (int) $stopBoundaryCapture->capture_error_count
	&& 'late_mutation' === (string) $stopBoundaryCapture->last_error_code,
	'A mutation finishing after stop began must fail closed instead of making the capture look complete.'
);
delete_option('fixture_stop_boundary_add');

update_option('fixture_failed_evidence_write', 'before', false);
$failedEvidenceSession = $captures->start('Evidence table failure', 0, '/wp-admin/options-general.php');
$breakMutationInsert = static function (string $query) use ($wpdb): string {
	return str_starts_with($query, "INSERT INTO `{$wpdb->prefix}configops_mutations`")
		? "INSERT INTO `{$wpdb->prefix}configops_missing_mutations` (`id`) VALUES (1)"
		: $query;
};
$previousSuppression = $wpdb->suppress_errors(true);
add_filter('query', $breakMutationInsert, PHP_INT_MAX, 1);
$hostWriteSurvived = update_option('fixture_failed_evidence_write', 'after', false);
remove_filter('query', $breakMutationInsert, PHP_INT_MAX);
$wpdb->suppress_errors($previousSuppression);
$captures->stop();
$failedEvidenceCapture = $captures->find($failedEvidenceSession);
$assert(true === $hostWriteSurvived && 'after' === get_option('fixture_failed_evidence_write'), 'A ConfigOps evidence-table failure must never break the host setting save.');
$assert(
	1 === (int) $failedEvidenceCapture->capture_error_count
	&& 'option_capture_failed' === (string) $failedEvidenceCapture->last_error_code,
	'A failed mutation insert must make the completed capture visibly incomplete.'
);
$assert(array() === $mutations->forSession($failedEvidenceSession), 'A failed mutation insert must not leave fabricated evidence behind.');
delete_option('fixture_failed_evidence_write');

$fallbackSession = $captures->start('Integrity fallback recovery', 0, '/wp-admin/options-general.php');
$breakIntegrityUpdate = static function (string $query) use ($wpdb): string {
	return str_contains($query, "UPDATE {$wpdb->prefix}configops_capture_sessions")
		&& str_contains($query, 'capture_error_count = capture_error_count + 1')
		? "UPDATE `{$wpdb->prefix}configops_missing_sessions` SET `id` = `id`"
		: $query;
};
$previousSuppression = $wpdb->suppress_errors(true);
add_filter('query', $breakIntegrityUpdate, PHP_INT_MAX, 1);
$fallbackRaised = false;
try {
	$captures->recordCaptureError($fallbackSession, 'forced_integrity_failure');
} catch (RuntimeException) {
	$fallbackRaised = true;
}
remove_filter('query', $breakIntegrityUpdate, PHP_INT_MAX);
$wpdb->suppress_errors($previousSuppression);
$fallbackLedger = get_option(\ConfigOps\Database\CaptureRepository::INTEGRITY_FALLBACK_OPTION, array());
$assert($fallbackRaised, 'A canonical integrity-write failure must be reported to its caller.');
$assert(
	is_array($fallbackLedger)
	&& 'forced_integrity_failure' === (string) ($fallbackLedger['events'][(string) $fallbackSession]['code'] ?? ''),
	'A value-free emergency marker must survive when the canonical session warning cannot be written.'
);
$fallbackRepository = new \ConfigOps\Database\CaptureRepository($wpdb);
$assert(false === $fallbackRepository->reconcileIntegrityFallback(), 'A recovered session table should absorb its emergency integrity marker.');
$assert(false === get_option(\ConfigOps\Database\CaptureRepository::INTEGRITY_FALLBACK_OPTION, false), 'A reconciled emergency marker should be removed from wp_options.');
$reconciledFallbackCapture = $fallbackRepository->find($fallbackSession);
$assert(
	1 === (int) $reconciledFallbackCapture->capture_error_count
	&& 'forced_integrity_failure' === (string) $reconciledFallbackCapture->last_error_code,
	'Emergency-marker reconciliation must permanently make the original capture incomplete.'
);
$assert($fallbackSession === $captures->stop(), 'A capture should remain stoppable after its integrity fallback is reconciled.');

delete_option('fixture_retention');
$retentionSession = $captures->start('Expired retention fixture', 0, '/wp-admin/options-general.php');
add_option('fixture_retention', 'temporary', '', false);
$captures->stop();
$retentionMutation = $mutations->forSession($retentionSession)[0];
$restore->restoreMutation((int) $retentionMutation->id);
$retentionSignalId = $writeSignals->insert(
	array(
		'session_id'      => $retentionSession,
		'request_id'       => wp_generate_uuid4(),
		'operation'        => 'update',
		'table_name'       => $wpdb->prefix . 'fixture_retention',
		'occurrence_count' => 1,
		'source_type'      => 'plugin',
		'source_component' => 'retention-fixture',
		'source_file'      => 'retention-fixture.php',
		'source_line'      => 1,
		'request_method'   => 'POST',
		'request_uri'      => '/fixture-retention',
		'admin_screen'     => 'fixture-retention',
		'actor_id'         => 0,
		'occurred_at'      => current_time('mysql', true),
	)
);
$wpdb->update(
	$wpdb->prefix . 'configops_capture_sessions',
	array('ended_at' => gmdate('Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS)),
	array('id' => $retentionSession),
	array('%s'),
	array('%d')
);
$retention = new \ConfigOps\Maintenance\HistoryRetention($wpdb, $operationLock);
$assert(1 === $retention->run(), 'Retention should remove an expired completed capture in a bounded batch.');
$assert(null === $captures->find($retentionSession), 'Retention should remove the expired parent capture last.');
$assert(null === $mutations->find((int) $retentionMutation->id), 'Retention should remove expired mutation payloads before their capture.');
$assert(array() === $restoreAudit->forSession($retentionSession), 'Retention should remove value-free restore audits with their expired capture.');
$assert(array() === $writeSignals->forSession($retentionSession), 'Retention should remove unmanaged-write evidence with its expired capture.');
unset($retentionSignalId);

$resumableRetentionSession = $captures->start('Interrupted retention fixture', 0, '/wp-admin/options-general.php');
add_option('fixture_retention_resume', 'temporary', '', false);
$captures->stop();
$wpdb->update(
	$wpdb->prefix . 'configops_capture_sessions',
	array('ended_at' => gmdate('Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS)),
	array('id' => $resumableRetentionSession),
	array('%s'),
	array('%d')
);
$breakRetentionDelete = static function (string $query) use ($wpdb): string {
	return str_starts_with($query, 'DELETE FROM ' . $wpdb->prefix . 'configops_mutations')
		? 'DELETE FROM configops_injected_missing_table'
		: $query;
};
add_filter('query', $breakRetentionDelete, PHP_INT_MIN, 1);
$retentionFailureRejected = false;
try {
	$retention->run();
} catch (RuntimeException) {
	$retentionFailureRejected = true;
}
remove_filter('query', $breakRetentionDelete, PHP_INT_MIN);
$assert($retentionFailureRejected, 'An interrupted retention batch should report the failed child cleanup.');
$assert('deleting' === (string) $captures->find($resumableRetentionSession)->status, 'Interrupted retention should leave a resumable tombstone instead of a half-visible capture.');
$recentSessionIds = array_map(static fn (object $row): int => (int) $row->id, $captures->recent(100));
$assert(! in_array($resumableRetentionSession, $recentSessionIds, true), 'A half-deleted retention batch must not appear as a trustworthy review.');
$assert(1 === $retention->run(), 'The next retention run should finish an interrupted deleting batch.');
$assert(null === $captures->find($resumableRetentionSession), 'Resumed retention should remove its tombstone after dependent evidence is gone.');
delete_option('fixture_retention_resume');

$activeRetentionSession = $captures->start('Active retention fixture', 0, '/wp-admin/options-general.php');
$wpdb->update(
	$wpdb->prefix . 'configops_capture_sessions',
	array('started_at' => gmdate('Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS)),
	array('id' => $activeRetentionSession),
	array('%s'),
	array('%d')
);
$assert(0 === $retention->run(), 'Retention must not delete an active capture regardless of age.');
$assert(null !== $captures->find($activeRetentionSession), 'An old active capture must remain available to stop safely.');
$captures->stop();

$interruptedSession = $captures->start('Interrupted lifecycle check', 0, '/wp-admin/options-general.php');
update_option('fixture_interrupted', 'changed', false);
$assert($interruptedSession === $captures->interruptActive('plugin_deactivated'), 'Deactivation should explicitly close the active capture.');
$interruptedCapture = $captures->find($interruptedSession);
$assert(
	'interrupted' === (string) $interruptedCapture->status
	&& 1 === (int) $interruptedCapture->capture_error_count
	&& 'plugin_deactivated' === (string) $interruptedCapture->last_error_code,
	'An interrupted capture must remain visibly incomplete after the plugin returns.'
);
$assert(null === $captures->activeId(), 'An interrupted capture must never resume implicitly after reactivation.');
$interruptedRestoreRejected = false;
try {
	$restore->restoreSession($interruptedSession);
} catch (RuntimeException $error) {
	$interruptedRestoreRejected = str_contains($error->getMessage(), 'did not complete cleanly');
}
$assert($interruptedRestoreRejected, 'Whole-capture undo must reject a session interrupted by deactivation.');
delete_option('fixture_interrupted');

delete_option('fixture_nested');
delete_option('fixture_deleted');
delete_option('fixture_credentials');
delete_option('fixture_opaque_credentials');
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
add_option('fixture_opaque_credentials', '{"smtp":{"password":"opaque-never-store-me"}}', '', false);
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
		$adapters,
		new \ConfigOps\Capture\SourceAttributor(CONFIGOPS_PATH),
		new \ConfigOps\Capture\RequestContext()
	);
	$failureProbe->beforeUpdate(chr(0xB1) . 'invalid', 'fixture_invalid_utf8', 'valid');
	$failureProbe->onUpdated('fixture_invalid_utf8', 'valid', chr(0xB1) . 'invalid');
} catch (Throwable) {
	$observerCallbackSurvived = false;
}
remove_action('configops_capture_error', $throwingReporter);

$captures->stop();

$incompleteCapture = $captures->find($sessionId);
$assert(1 === (int) $incompleteCapture->capture_error_count, 'A failed observation must permanently mark its capture as incomplete.');
$assert('option_capture_failed' === (string) $incompleteCapture->last_error_code, 'Capture integrity failures should retain a bounded machine-readable code.');
$assert('' !== (string) $incompleteCapture->last_error_at, 'Capture integrity failures should retain their UTC occurrence time.');

$incompleteRestoreRejected = false;
try {
	$restore->restoreSession($sessionId);
} catch (RuntimeException $error) {
	$incompleteRestoreRejected = str_contains($error->getMessage(), 'incomplete');
}
$assert($incompleteRestoreRejected, 'Whole-capture undo must fail closed when ConfigOps missed evidence.');

$rows = $mutations->forSession($sessionId);
$assert(5 === count($rows), 'The active capture should record add, update, runtime, opaque-secret, and delete mutations.');
$summary = $mutations->summaryForSession($sessionId);
$assert(
	array('total' => 6, 'derived' => 1, 'redacted' => 2, 'not_restorable' => 2) === $summary,
	'Session summaries should remain accurate without loading every mutation payload.'
);
$iteratedRows = iterator_to_array($mutations->iterateForSession($sessionId, 2), false);
$assert(5 === count($iteratedRows), 'Batched mutation iteration should traverse the complete session.');

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
$assert(1 === (int) $byName['fixture_opaque_credentials']->is_redacted, 'Opaque JSON credentials should be marked as protected evidence.');
$assert(
	! str_contains((string) $byName['fixture_opaque_credentials']->new_value, 'opaque-never-store-me'),
	'Opaque JSON credential plaintext must never enter the mutation table.'
);
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
$mainRestoreAudits = $restoreAudit->forSession($sessionId);
$assert('succeeded' === (string) $mainRestoreAudits[0]->status, 'A successful individual undo should finalize its audit record.');
$assert(1 === (int) $mainRestoreAudits[0]->restored_option_count, 'Individual undo should audit one restored option.');
$assert(
	! property_exists($mainRestoreAudits[0], 'failure_message')
	&& ! property_exists($mainRestoreAudits[0], 'option_name'),
	'Restore audits must retain outcome metadata without copying setting names or values.'
);
$conflictAudits = array_values(array_filter($mainRestoreAudits, static fn (object $run): bool => 'target_conflict' === (string) $run->failure_code));
$assert(1 === count($conflictAudits) && 'failed' === (string) $conflictAudits[0]->status, 'A refused conflict should remain visible as a failed, value-free audit event.');

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

update_option('fixture_restore_meaningful', 'baseline', false);
update_option('_transient_fixture_restore_runtime', 'baseline-runtime', false);
$derivedRestoreSession = $captures->start('Derived restore exclusion', 0, '/wp-admin/options-general.php');
update_option('fixture_restore_meaningful', 'changed', false);
update_option('_transient_fixture_restore_runtime', 'changed-runtime', false);
$captures->stop();
$derivedRestoreRows = $mutations->forSession($derivedRestoreSession);
$derivedRestoreByName = array_column($derivedRestoreRows, null, 'option_name');
$assert(0 === (int) $derivedRestoreByName['_transient_fixture_restore_runtime']->restorable, 'Technical runtime values should never expose individual undo.');
$wpdb->update(
	$wpdb->prefix . 'configops_mutations',
	array('restorable' => 1),
	array('id' => (int) $derivedRestoreByName['_transient_fixture_restore_runtime']->id),
	array('%d'),
	array('%d')
);
$legacyDerivedRejected = false;
try {
	$restore->restoreMutation((int) $derivedRestoreByName['_transient_fixture_restore_runtime']->id);
} catch (RuntimeException) {
	$legacyDerivedRejected = true;
}
$assert($legacyDerivedRejected, 'Technical mutations from an older schema must remain ineligible for individual undo.');
$derivedRestoreSummary = $mutations->summaryForSession($derivedRestoreSession);
$assert(0 === $derivedRestoreSummary['not_restorable'], 'Technical values skipped by design should not falsely block safe session undo.');
$assert(1 === $restore->restoreSession($derivedRestoreSession), 'Session undo should plan only meaningful configuration options.');
$assert('baseline' === get_option('fixture_restore_meaningful'), 'Session undo should restore the meaningful configuration value.');
$assert('changed-runtime' === get_option('_transient_fixture_restore_runtime'), 'Session undo should leave plugin-generated runtime state alone.');

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
$compensationAudits = $restoreAudit->forSession($compensationSession);
$assert(
	'succeeded' === (string) $compensationAudits[0]->status
	&& 'compensated' === (string) $compensationAudits[1]->status
	&& 'apply_failed_compensated' === (string) $compensationAudits[1]->failure_code,
	'Failure injection should leave both the compensated attempt and successful retry in the audit trail.'
);

delete_option('fixture_nested');
delete_option('fixture_credentials');
delete_option('fixture_opaque_credentials');
delete_option('_transient_fixture_cache');
delete_option('fixture_semantic_reorder');
delete_option('fixture_compensation_failure');
delete_option('fixture_restore_meaningful');
delete_option('_transient_fixture_restore_runtime');
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
\ConfigOpsHostileFixture\SettingsFixture::writeDirectly('intermediate');
\ConfigOpsHostileFixture\SettingsFixture::writeDirectly('after');
$captures->stop();

$hostileRows = $mutations->forSession($hostileSession);
$assert(8 === count($hostileRows), 'The hostile fixture should produce eight observable Options API mutations.');
$hostileSummary = $mutations->summaryForSession($hostileSession);
$assert(
	array('total' => 13, 'derived' => 3, 'redacted' => 1, 'not_restorable' => 1) === $hostileSummary,
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
$hostileSignals = $writeSignals->forSession($hostileSession);
$assert(1 === count($hostileSignals), 'Repeated direct writes from one source should collapse into one database write signal.');
$assert(
	'UPDATE' === strtoupper((string) $hostileSignals[0]->operation)
	&& $wpdb->options === (string) $hostileSignals[0]->table_name
	&& 2 === (int) $hostileSignals[0]->occurrence_count,
	'The SQL Sentry should retain only operation, table, and a bounded occurrence count.'
);
$assert(
	'configops-hostile-fixture' === (string) $hostileSignals[0]->source_component
	&& ! property_exists($hostileSignals[0], 'query')
	&& ! property_exists($hostileSignals[0], 'sql'),
	'Database write signals should retain plugin provenance without persisting raw SQL. Received: '
	. wp_json_encode($hostileSignals[0])
);
$hostileCapture = $captures->find($hostileSession);
$assert(2 === (int) $hostileCapture->write_signal_count, 'Capture summaries should count every unmanaged write occurrence.');

$originalActorId = get_current_user_id();
$originalCronUri = $_SERVER['REQUEST_URI'] ?? null;
wp_set_current_user(0);
$_SERVER['REQUEST_URI'] = '/wp-cron.php?doing_wp_cron=fixture';
$cronNoiseSession = $captures->start('Uncorrelated cron noise', 0, '/wp-cron.php');
update_user_meta(1, 'configops_cron_noise_probe', 'runtime');
$captures->stop();
$assert(array() === $writeSignals->forSession($cronNoiseSession), 'Unauthenticated core cron writes should not contaminate an explicit admin capture.');
delete_user_meta(1, 'configops_cron_noise_probe');
wp_set_current_user($originalActorId);
if (null === $originalCronUri) {
	unset($_SERVER['REQUEST_URI']);
} else {
	$_SERVER['REQUEST_URI'] = $originalCronUri;
}

$hostilePayloadFactory = new \ConfigOps\Admin\AdminPayloadFactory(
	$captures,
	$mutations,
	$writeSignals,
	new \ConfigOps\Admin\ReviewPresenter($adapters),
	$adapters,
	$restoreAudit
);
$hostilePayload = $hostilePayloadFactory->mutationPage($hostileSession);
$hostilePayloadSignals = array_merge(
	...array_map(
		static fn (array $group): array => $group['writeSignals'],
		$hostilePayload['groups']
	)
);
$assert(
	2 === $hostilePayload['summary']['unmanagedWrites']
	&& false === $hostilePayload['summary']['allRestorable'],
	'Unmanaged writes should make the transport contract and full-session rollback boundary explicit.'
);
$assert(
	1 === count($hostilePayloadSignals)
	&& $wpdb->options === $hostilePayloadSignals[0]['table']
	&& 2 === $hostilePayloadSignals[0]['occurrenceCount'],
	'The review payload should attach a bounded database write signal to its causal request group.'
);
$hostileState = $hostilePayloadFactory->state($hostileSession);
$assert(
	2 === $hostileState['selected']['writeSignalCount'],
	'Session transport should expose the unmanaged-write occurrence count without loading signal rows.'
);
$unmanagedSessionRejected = false;
try {
	$restore->restoreSession($hostileSession);
} catch (RuntimeException $error) {
	$unmanagedSessionRejected = str_contains($error->getMessage(), 'unmanaged database writes');
}
$assert($unmanagedSessionRejected, 'The domain service must reject full-session restore when unmanaged writes were observed.');

$separateDirectSession = $captures->start('Separate direct write capture', 0, '/wp-admin/admin.php?page=configops-hostile-fixture');
\ConfigOpsHostileFixture\SettingsFixture::writeDirectly('separate-capture');
$captures->stop();
$separateSignals = $writeSignals->forSession($separateDirectSession);
$assert(
	1 === count($separateSignals)
	&& 1 === (int) $separateSignals[0]->occurrence_count
	&& (int) $hostileSignals[0]->id !== (int) $separateSignals[0]->id,
	'In-request deduplication must never merge identical write signatures across separate capture sessions.'
);

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php?action=configops_fixture_save';
$ajaxSession = $captures->start('Hostile fixture AJAX save', 0, '/wp-admin/admin-ajax.php');
do_action('wp_ajax_configops_fixture_save');
$captures->stop();
$ajaxRows = $mutations->forSession($ajaxSession);
$assert(1 === count($ajaxRows), 'An AJAX handler writing through update_option() should remain observable.');
$assert(0 === $writeSignals->occurrenceCountForSession($ajaxSession), 'Options API writes must not be duplicated as unmanaged SQL signals.');
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

$bulkQueryStart = (int) $wpdb->num_queries;
$bulkMemoryStart = memory_get_usage(true);
$bulkSession = $captures->start('Pagination and budget check', 0, '/wp-admin/options-general.php');
for ($index = 0; $index < 101; ++$index) {
	add_option('fixture_bulk_' . $index, $index, '', false);
}
$captures->stop();
$bulkQueryCount = (int) $wpdb->num_queries - $bulkQueryStart;
$bulkMemoryGrowth = memory_get_usage(true) - $bulkMemoryStart;
$assert(
	$bulkQueryCount <= 101 * 8 + 40,
	"A 101-mutation capture exceeded its bounded query budget: {$bulkQueryCount} queries."
);
$assert(
	$bulkMemoryGrowth <= 24 * 1024 * 1024,
	"A 101-mutation capture retained too much request memory: {$bulkMemoryGrowth} bytes."
);

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
	$writeSignals,
	new \ConfigOps\Admin\ReviewPresenter($adapters),
	$adapters,
	$restoreAudit
);
$incompletePayload = $payloadFactory->mutationPage($sessionId);
$assert(
	1 === $incompletePayload['summary']['captureErrors']
	&& false === $incompletePayload['summary']['allRestorable'],
	'The UI contract must expose incomplete evidence and disable whole-capture undo.'
);
$incompletePayloadRows = array_merge(
	...array_map(static fn (array $group): array => $group['mutations'], $incompletePayload['groups'])
);
$restoredNestedPayload = current(array_filter($incompletePayloadRows, static fn (array $row): bool => 'fixture_nested' === $row['optionName']));
$assert(
	false !== $restoredNestedPayload
	&& 'succeeded' === ($restoredNestedPayload['lastRestore']['status'] ?? ''),
	'Historical mutations should expose their latest completed undo instead of offering the same action again.'
);
$assert(
	1 === $incompletePayload['summary']['individuallyUndone'],
	'An individually undone setting should permanently disable the stale whole-capture undo plan.'
);
$restoredSessionPayload = $payloadFactory->mutationPage($secondSession);
$assert(
	'succeeded' === ($restoredSessionPayload['summary']['lastSessionRestore']['status'] ?? '')
	&& 2 === ($restoredSessionPayload['summary']['lastSessionRestore']['restoredOptionCount'] ?? 0),
	'Capture review should expose the latest successful whole-session undo and its audited option count.'
);
$incompleteState = $payloadFactory->state($sessionId, '', '', false);
$assert(
	1 === $incompleteState['selected']['captureErrorCount'],
	'Session navigation must keep capture integrity failures visible without loading the review.'
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
$stateHeaders = $stateResponse->get_headers();
$assert(
	'private, no-store' === ($stateHeaders['Cache-Control'] ?? '')
	&& 'nosniff' === ($stateHeaders['X-Content-Type-Options'] ?? ''),
	'The Agent state contract must prevent caching and MIME sniffing of configuration evidence.'
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
$assert(
	'private, no-store' === ($pageResponse->get_headers()['Cache-Control'] ?? ''),
	'Configuration evidence must never be retained by browser, proxy, or shared wp-admin caches.'
);

$startRequest = new WP_REST_Request('POST', '/configops/v1/captures');
$startRequest->set_body_params(array('name' => 'REST command contract'));
$startResponse = $restServer->dispatch($startRequest);
$startData = $startResponse->get_data();
$assert(200 === $startResponse->get_status() && is_array($startData) && null !== $startData['active'], 'The REST command layer should start a capture through the same domain service.');

$stopRequest = new WP_REST_Request('POST', '/configops/v1/captures/active/stop');
$stopResponse = $restServer->dispatch($stopRequest);
$stopData = $stopResponse->get_data();
$assert(200 === $stopResponse->get_status() && is_array($stopData) && null === $stopData['active'], 'The REST command layer should return the refreshed state after stopping.');

$viewerId = wp_create_user('configops-viewer', wp_generate_password(32), 'configops-viewer@example.test');
$assert(! is_wp_error($viewerId), 'The capability-boundary fixture user should be created.');
$viewer = get_user_by('id', (int) $viewerId);
$viewer->add_cap('configops_view');
wp_set_current_user((int) $viewerId);
$viewerReadResponse = $restServer->dispatch(new WP_REST_Request('GET', '/configops/v1/state'));
$assert(200 === $viewerReadResponse->get_status(), 'A read-only ConfigOps operator should be able to inspect evidence.');
$viewerStartRequest = new WP_REST_Request('POST', '/configops/v1/captures');
$viewerStartRequest->set_body_params(array('name' => 'Forbidden viewer capture'));
$viewerStartResponse = $restServer->dispatch($viewerStartRequest);
$assert(
	$viewerStartResponse->get_status() >= 400
	&& 'rest_forbidden' === ($viewerStartResponse->get_data()['code'] ?? ''),
	'Read-only operators must not start captures through the Agent API.'
);
$viewerRestoreRequest = new WP_REST_Request('POST', "/configops/v1/mutations/{$firstBulkMutationId}/restore");
$viewerRestoreRequest->set_param('id', $firstBulkMutationId);
$viewerRestoreResponse = $restServer->dispatch($viewerRestoreRequest);
$assert(
	$viewerRestoreResponse->get_status() >= 400
	&& 'rest_forbidden' === ($viewerRestoreResponse->get_data()['code'] ?? ''),
	'Read-only operators must not execute undo through the Agent API.'
);
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user((int) $viewerId);

wp_set_current_user(0);
$forbiddenResponse = $restServer->dispatch(new WP_REST_Request('GET', '/configops/v1/state'));
$assert($forbiddenResponse->get_status() >= 400, 'The local Agent API must fail closed without a ConfigOps capability.');

fwrite(STDOUT, "ConfigOps WordPress integration checks passed ({$assertions} assertions).\n");
