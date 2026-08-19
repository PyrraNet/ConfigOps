<?php
/**
 * WordPress integration smoke test for capture, redaction, classification, and restore.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

require_once __DIR__ . '/production-error-trap.php';

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
	in_array('capture_mode', $sessionColumns, true)
	&& in_array('network_id', $sessionColumns, true)
	&& in_array('blog_id', $sessionColumns, true)
	&& in_array('capture_error_count', $sessionColumns, true)
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
update_option('configops_schema_version', 8, false);
(new \ConfigOps\Database\Schema($wpdb))->maybeUpgrade();
$assert(10 === (int) get_option('configops_schema_version'), 'A stale schema version should upgrade idempotently under the schema lock.');

update_option('configops_schema_version', 8, false);
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
$assert(8 === (int) get_option('configops_schema_version'), 'A failed schema verification must never advance the committed schema version.');
(new \ConfigOps\Database\Schema($wpdb))->maybeUpgrade();
$assert(10 === (int) get_option('configops_schema_version'), 'A schema upgrade should recover after the injected storage failure clears.');

$sharedStorageLock = new \ConfigOps\Database\SharedStorageLock($wpdb);
$nestedSharedLockRejected = false;
$sharedStorageLock->run(
	static function () use ($sharedStorageLock, &$nestedSharedLockRejected): void {
		try {
			$sharedStorageLock->run(static fn (): null => null);
		} catch (RuntimeException) {
			$nestedSharedLockRejected = true;
		}
	}
);
$assert($nestedSharedLockRejected, 'Concurrent shared-schema upgrades should be rejected across site-local lock namespaces.');
$baseOptionsTable = $wpdb->base_prefix . 'options';
$sharedLockValue = $wpdb->get_var(
	$wpdb->prepare(
		"SELECT option_value FROM `{$baseOptionsTable}` WHERE option_name = %s",
		'configops_shared_schema_lock'
	)
);
$assert(null === $sharedLockValue, 'A completed shared-schema operation should release its installation-wide lock.');
$wpdb->insert(
	$baseOptionsTable,
	array(
		'option_name'  => 'configops_shared_schema_lock',
		'option_value' => maybe_serialize(array('token' => 'stale-fixture', 'expires_at' => time() - 1)),
		'autoload'     => 'no',
	),
	array('%s', '%s', '%s')
);
$staleSharedLockRecovered = false;
$sharedStorageLock->run(static function () use (&$staleSharedLockRecovered): void {
	$staleSharedLockRecovered = true;
});
$assert($staleSharedLockRecovered, 'An expired installation-wide schema lock should be recovered atomically.');

$wpdb->insert(
	$sessionTable,
	array(
		'network_id'  => 0,
		'blog_id'     => 0,
		'name'        => 'Legacy shared-table ownership fixture',
		'capture_mode' => 'manual',
		'status'      => 'discarded',
		'actor_id'    => 0,
		'started_at'  => current_time('mysql', true),
	),
	array('%d', '%d', '%s', '%s', '%s', '%d', '%s')
);
$legacySharedSessionId = (int) $wpdb->insert_id;
update_option('configops_schema_version', 9, false);
(new \ConfigOps\Database\Schema($wpdb))->maybeUpgrade();
$legacySharedSession = (new \ConfigOps\Database\CaptureRepository($wpdb))->find($legacySharedSessionId);
$legacySharedScope = \ConfigOps\Multisite\SiteScope::current();
$assert(
	is_object($legacySharedSession)
	&& $legacySharedScope->networkId() === (int) $legacySharedSession->network_id
	&& $legacySharedScope->siteId() === (int) $legacySharedSession->blog_id,
	'Rows from the former single-site table should be assigned to the original site during upgrade.'
);
$wpdb->delete($sessionTable, array('id' => $legacySharedSessionId), array('%d'));

$administrator = get_role('administrator');
$assert(false !== $administrator, 'WordPress should provide an administrator role for capability checks.');
$administrator->remove_cap('configops_view');
(new \ConfigOps\Access\CapabilityManager())->maybeInstall();
$assert($administrator->has_cap('configops_view'), 'ConfigOps should repair a missing administrator capability even when its install version is current.');


$captures  = new \ConfigOps\Database\CaptureRepository($wpdb);
$mutations = new \ConfigOps\Database\MutationRepository($wpdb);
$writeSignals = new \ConfigOps\Database\DatabaseWriteSignalRepository($wpdb);
$adapters = new \ConfigOps\Adapter\AdapterRegistry(
	\ConfigOps\Adapter\BuiltInAdapters::create(),
	new \ConfigOps\Noise\NoiseClassifier(),
	new \ConfigOps\Capture\HeuristicSensitiveValueDetector()
);
$codec     = new \ConfigOps\Capture\ValueCodec($adapters);
$metadata  = new \ConfigOps\Database\OptionMetadataRepository($wpdb);
$operationLock = new \ConfigOps\Execution\OperationLock($wpdb);
$restoreAudit  = new \ConfigOps\Database\RestoreAuditRepository($wpdb);
$restore       = new \ConfigOps\Restore\RestoreService($captures, $mutations, $codec, $metadata, $operationLock, $adapters, $restoreAudit);

if (! function_exists('switch_to_blog')) {
	require_once ABSPATH . WPINC . '/ms-blogs.php';
}
$siteScope = \ConfigOps\Multisite\SiteScope::current();
$siteBoundary = new \ConfigOps\Multisite\SiteBoundaryGuard($siteScope, $captures);
$scopeObserver = new \ConfigOps\Capture\MutationObserver(
	$captures,
	$mutations,
	$metadata,
	new \ConfigOps\Capture\InternalOptionPolicy(),
	$codec,
	new \ConfigOps\Diff\NestedDiff(),
	$adapters,
	new \ConfigOps\Capture\SourceAttributor(WP_PLUGIN_DIR . '/configops'),
	new \ConfigOps\Capture\RequestContext(),
	new \ConfigOps\Capture\IntentContext(),
	null,
	$siteBoundary
);
$siteBoundarySession = $captures->start('Site boundary isolation', 0, '/wp-admin/options-general.php');
$scopeObserver->beforeUpdate('after', 'site_boundary_fixture_setting', 'before');
$originSiteId = get_current_blog_id();
$foreignSiteId = $originSiteId + 100000;
$assert(switch_to_blog($foreignSiteId), 'The site-boundary test should enter a foreign WordPress site context.');
$assert($foreignSiteId === get_current_blog_id(), 'The foreign site context should be active before exercising the guard.');
$scopeObserver->onUpdated('site_boundary_fixture_setting', 'before', 'after');
$assert(
	$foreignSiteId === get_current_blog_id(),
	'The guard may enter the owning site to persist an integrity warning, but it must restore the caller\'s switch stack position.'
);
$scopeObserver->onUpdated('site_boundary_fixture_setting', 'before', 'after-again');
$assert(
	! $siteBoundary->acceptsCurrentSite($siteBoundarySession),
	'Repeated work in another site must remain rejected without creating duplicate integrity warnings.'
);
$crossSiteCommandRejected = false;
try {
	(new \ConfigOps\Command\CaptureCommands($captures, $restore, null, $siteBoundary))->stop();
} catch (RuntimeException) {
	$crossSiteCommandRejected = true;
}
$assert($crossSiteCommandRejected, 'ConfigOps commands must fail closed while WordPress is switched to another site.');
$assert(restore_current_blog(), 'The site-boundary test should restore the original WordPress site context.');
$assert($originSiteId === get_current_blog_id(), 'The original site context should be restored after the boundary test.');
$siteBoundaryCapture = $captures->find($siteBoundarySession);
$assert(
	1 === (int) $siteBoundaryCapture->capture_error_count
	&& 'cross_site_write_ignored' === (string) $siteBoundaryCapture->last_error_code,
	'A rejected cross-site write must make the owning capture visibly incomplete exactly once.'
);
$assert(
	array() === $mutations->forSession($siteBoundarySession),
	'Values observed after a site-context switch must never be persisted in the owning site\'s evidence table.'
);
$captures->stop();

$lockScope = 'site-boundary-release-' . wp_generate_uuid4();
$lockOption = 'configops_operation_lock_' . hash('sha256', $lockScope);
$scopedLock = new \ConfigOps\Execution\OperationLock($wpdb, $siteScope);
$scopedLock->run(
	$lockScope,
	static function () use ($foreignSiteId): void {
		if (! switch_to_blog($foreignSiteId)) {
			throw new RuntimeException('Could not enter the foreign site context during lock cleanup testing.');
		}
	}
);
$assert($foreignSiteId === get_current_blog_id(), 'Lock cleanup must preserve the caller\'s foreign site context.');
$assert(restore_current_blog(), 'The lock-cleanup test should restore the original WordPress site context.');
$assert(false === get_option($lockOption, false), 'A scoped operation lock must be released from its owning site even after a nested site switch.');

update_option('site_boundary_restore_setting', 'before', false);
$siteBoundaryRestoreSession = $captures->start('Site boundary restore guard', 0, '/wp-admin/options-general.php');
update_option('site_boundary_restore_setting', 'after', false);
$captures->stop();
$siteBoundaryRestoreMutation = $mutations->forSession($siteBoundaryRestoreSession)[0];
$switchDuringRestoreRead = static function (mixed $value) use ($foreignSiteId): mixed {
	if (! switch_to_blog($foreignSiteId)) {
		throw new RuntimeException('Could not enter the foreign site context during restore guarding.');
	}

	return $value;
};
add_filter('option_site_boundary_restore_setting', $switchDuringRestoreRead);
$crossSiteRestoreRejected = false;
try {
	$restore->restoreMutation((int) $siteBoundaryRestoreMutation->id);
} catch (RuntimeException) {
	$crossSiteRestoreRejected = true;
}
remove_filter('option_site_boundary_restore_setting', $switchDuringRestoreRead);
$assert($crossSiteRestoreRejected, 'Undo must abort before writing if an option callback changes the WordPress site context.');
$assert($foreignSiteId === get_current_blog_id(), 'Failed undo cleanup must preserve the caller\'s changed site context.');
$assert(restore_current_blog(), 'The guarded undo check should restore the original WordPress site context.');
$assert(
	'after' === get_option('site_boundary_restore_setting'),
	'A site-context change during conflict checking must leave the origin setting untouched.'
);
$siteBoundaryRestoreAudits = $restoreAudit->forSession($siteBoundaryRestoreSession);
$assert(
	'failed' === (string) $siteBoundaryRestoreAudits[array_key_last($siteBoundaryRestoreAudits)]->status,
	'A site-boundary restore refusal must retain a value-free failed audit record.'
);

$createMediaFixture = static function (string $filename, string $title, int $width, int $height): int {
	$attachment = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/png',
			'post_title'     => $title,
			'post_status'    => 'inherit',
			'guid'           => home_url('/wp-content/uploads/' . $filename),
		),
		'',
		0,
		true
	);
	if (is_wp_error($attachment)) {
		throw new RuntimeException('Could not insert the media-reference fixture.');
	}
	update_post_meta((int) $attachment, '_wp_attached_file', $filename);
	wp_update_attachment_metadata(
		(int) $attachment,
		array(
			'file'     => $filename,
			'width'    => $width,
			'height'   => $height,
			'filesize' => 1024,
		)
	);

	return (int) $attachment;
};

$siteIconBefore = $createMediaFixture('configops-icon-before.png', 'Original site icon', 512, 512);
$siteIconAfter  = $createMediaFixture('configops-icon-after.png', 'New site icon', 512, 512);
update_option('site_icon', $siteIconBefore, false);
$mediaSession = $captures->start('Core media reference', 0, '/wp-admin/options-general.php');
update_option('site_icon', $siteIconAfter, false);
$captures->stop();
$mediaRows = $mutations->forSession($mediaSession);
$assert(1 === count($mediaRows), 'A site-icon selection should be captured as one Options API mutation.');
$mediaMutation = $mediaRows[0];
$mediaDiff = json_decode((string) $mediaMutation->diff, true);
$mediaChange = is_array($mediaDiff) ? ($mediaDiff[0] ?? array()) : array();
$assert('reference' === (string) $mediaMutation->classification && 1 === (int) $mediaMutation->restorable, 'Site icons should remain locally undoable media references.');
$assert(
	'wordpress-core' === (string) $mediaMutation->adapter_id
	&& 1 === (int) $mediaMutation->adapter_schema_version
	&& '' !== (string) $mediaMutation->component_version,
	'Site icons should pin the tested WordPress Core adapter contract.'
);
$assert('media' === ($mediaChange['reference_type'] ?? '') && 'Site icon' === ($mediaChange['label'] ?? ''), 'Site-icon evidence should carry the media type and a plain-language label.');
$assert(
	'configops-icon-before.png' === ($mediaChange['before_reference']['filename'] ?? '')
	&& 512 === ($mediaChange['before_reference']['width'] ?? 0)
	&& 'image/png' === ($mediaChange['before_reference']['mime'] ?? ''),
	'Media evidence should retain a bounded filename, dimensions, and MIME type instead of only a raw ID.'
);

$mediaPayloadFactory = new \ConfigOps\Admin\AdminPayloadFactory(
	$captures,
	$mutations,
	$writeSignals,
	new \ConfigOps\Admin\ReviewPresenter($adapters),
	$adapters,
	$restoreAudit
);
$mediaPayload = $mediaPayloadFactory->mutationPage($mediaSession);
$mediaPayloadChange = $mediaPayload['groups'][0]['mutations'][0]['diff'][0] ?? array();
$assert(
	'available' === ($mediaPayloadChange['before_reference']['current_status'] ?? '')
	&& '' !== ($mediaPayloadChange['after_reference']['preview_url'] ?? ''),
	'Review payloads should add current media availability and a thumbnail URL without persisting the URL in evidence.'
);

$restore->restoreMutation((int) $mediaMutation->id);
$assert($siteIconBefore === (int) get_option('site_icon'), 'Local media undo should restore the earlier attachment ID after its normal conflict check.');

$missingMediaSession = $captures->start('Deleted media reference', 0, '/wp-admin/options-general.php');
update_option('site_icon', $siteIconAfter, false);
$captures->stop();
$missingMediaMutation = $mutations->forSession($missingMediaSession)[0];
wp_delete_attachment($siteIconBefore, true);
$missingMediaPayload = $mediaPayloadFactory->mutationPage($missingMediaSession);
$missingMediaChange = $missingMediaPayload['groups'][0]['mutations'][0]['diff'][0] ?? array();
$assert(
	'missing' === ($missingMediaChange['before_reference']['current_status'] ?? '')
	&& 'Original site icon' === ($missingMediaChange['before_reference']['title'] ?? ''),
	'A deleted attachment should be marked missing while its captured identity remains reviewable.'
);
$missingReferenceRejected = false;
try {
	$restore->restoreMutation((int) $missingMediaMutation->id);
} catch (RuntimeException $error) {
	$missingReferenceRejected = str_starts_with($error->getMessage(), 'Reference missing:');
}
$assert($missingReferenceRejected && $siteIconAfter === (int) get_option('site_icon'), 'Undo must stop before writing a media reference whose earlier attachment was deleted.');
$missingReferenceRuns = $restoreAudit->forSession($missingMediaSession);
$assert('reference_missing' === (string) $missingReferenceRuns[0]->failure_code, 'A blocked media undo should leave a value-free reference-missing audit code.');
delete_option('site_icon');
wp_delete_attachment($siteIconAfter, true);

$postsPerPageBefore = (int) get_option('posts_per_page', 10);
$coreSettingsSession = $captures->start('WordPress Core settings', 0, '/wp-admin/options-reading.php');
update_option('posts_per_page', $postsPerPageBefore + 3, false);
$captures->stop();
$coreSettingsRows = $mutations->forSession($coreSettingsSession);
$assert(1 === count($coreSettingsRows), 'A standard Reading setting should be captured as one Core mutation.');
$coreSettingsMutation = $coreSettingsRows[0];
$coreSettingsDiff = json_decode((string) $coreSettingsMutation->diff, true);
$assert(
	'wordpress-core' === (string) $coreSettingsMutation->adapter_id
	&& 'portable' === (string) $coreSettingsMutation->classification
	&& 1 === (int) $coreSettingsMutation->restorable,
	'A supported WordPress Core scalar should be reusable and conflict-checkable.'
);
$assert('Posts per page' === ($coreSettingsDiff[0]['label'] ?? ''), 'Core evidence should persist a stable human label at capture time.');
$restore->restoreMutation((int) $coreSettingsMutation->id);
$assert($postsPerPageBefore === (int) get_option('posts_per_page'), 'Core scalar undo should restore the previous Reading value.');

$frontPageBefore = wp_insert_post(array('post_type' => 'page', 'post_title' => 'Original homepage', 'post_status' => 'publish'));
$frontPageAfter = wp_insert_post(array('post_type' => 'page', 'post_title' => 'Updated homepage', 'post_status' => 'publish'));
$assert(! is_wp_error($frontPageBefore) && ! is_wp_error($frontPageAfter), 'Core content-reference fixtures should be created.');
update_option('page_on_front', (int) $frontPageBefore, false);
$coreReferenceSession = $captures->start('WordPress homepage reference', 0, '/wp-admin/options-reading.php');
update_option('page_on_front', (int) $frontPageAfter, false);
$captures->stop();
$coreReferenceRows = $mutations->forSession($coreReferenceSession);
$coreReferenceMutation = current(array_filter($coreReferenceRows, static fn (object $row): bool => 'page_on_front' === (string) $row->option_name));
$assert(false !== $coreReferenceMutation, 'The Core homepage option should remain visible beside generated rewrite state.');
$coreReferenceDiff = json_decode((string) $coreReferenceMutation->diff, true);
$coreReferenceChange = is_array($coreReferenceDiff) ? ($coreReferenceDiff[0] ?? array()) : array();
$assert(
	'reference' === (string) $coreReferenceMutation->classification,
	'Core homepage selections should be classified as local references; received ' . (string) $coreReferenceMutation->classification . ' from ' . (string) $coreReferenceMutation->adapter_id . '.'
);
$assert('content' === ($coreReferenceChange['reference_type'] ?? ''), 'Core homepage evidence should select the content resolver.');
$assert('Original homepage' === ($coreReferenceChange['before_reference']['title'] ?? ''), 'Core homepage evidence should preserve bounded local page identity.');
$restore->restoreMutation((int) $coreReferenceMutation->id);
$assert((int) $frontPageBefore === (int) get_option('page_on_front'), 'Core page-reference undo should restore an existing local page.');
delete_option('page_on_front');
wp_delete_post((int) $frontPageBefore, true);
wp_delete_post((int) $frontPageAfter, true);

$coalesceOption = 'fixture_request_coalescing';
delete_option($coalesceOption);
add_option($coalesceOption, array('primary' => 'baseline', 'secondary' => 0), '', false);
$coalesceSession = $captures->start('Request-local option coalescing', 0, '/wp-admin/options-general.php');
update_option($coalesceOption, array('primary' => 'intermediate', 'secondary' => 0), false);
update_option($coalesceOption, array('primary' => 'final', 'secondary' => 1), false);
$captures->stop();
$coalescedRows = $mutations->forSession($coalesceSession);
$assert(1 === count($coalescedRows), 'Consecutive writes to one option by the same owner should become one logical mutation.');
$coalescedDiff = json_decode((string) $coalescedRows[0]->diff, true, 64, JSON_THROW_ON_ERROR);
$coalescedByPath = array_column($coalescedDiff, null, 'path');
$assert(
	'baseline' === ($coalescedByPath['/primary']['before'] ?? null)
	&& 'final' === ($coalescedByPath['/primary']['after'] ?? null)
	&& 0 === ($coalescedByPath['/secondary']['before'] ?? null)
	&& 1 === ($coalescedByPath['/secondary']['after'] ?? null),
	'The logical mutation should compare the request baseline directly with its final state.'
);
$coalescedCapture = $captures->find($coalesceSession);
$assert(
	1 === (int) $coalescedCapture->mutation_count
	&& 2 === (int) $coalescedCapture->review_change_count,
	'Capture counters should replace the intermediate field count with the final logical diff count.'
);
$restore->restoreMutation((int) $coalescedRows[0]->id);
$assert(
	array('primary' => 'baseline', 'secondary' => 0) === get_option($coalesceOption),
	'Undo should restore the original baseline of a coalesced request mutation.'
);

$revertSession = $captures->start('Request-local full revert', 0, '/wp-admin/options-general.php');
update_option($coalesceOption, array('primary' => 'temporary', 'secondary' => 0), false);
update_option($coalesceOption, array('primary' => 'baseline', 'secondary' => 0), false);
$captures->stop();
$revertCapture = $captures->find($revertSession);
$assert(
	array() === $mutations->forSession($revertSession)
	&& 0 === (int) $revertCapture->mutation_count
	&& 0 === (int) $revertCapture->review_change_count,
	'A same-request change that returns to its baseline should leave no review mutation or count.'
);

$ephemeralOption = 'fixture_request_ephemeral';
delete_option($ephemeralOption);
$ephemeralSession = $captures->start('Request-local add delete', 0, '/wp-admin/options-general.php');
add_option($ephemeralOption, 'temporary', '', false);
delete_option($ephemeralOption);
$captures->stop();
$assert(
	array() === $mutations->forSession($ephemeralSession)
	&& false === get_option($ephemeralOption, false),
	'An option added and removed by one owner in the same request should leave no logical mutation.'
);

add_option($ephemeralOption, 'baseline', '', false);
$replaceSession = $captures->start('Request-local delete add', 0, '/wp-admin/options-general.php');
delete_option($ephemeralOption);
add_option($ephemeralOption, 'final', '', false);
$captures->stop();
$replaceRows = $mutations->forSession($replaceSession);
$replaceDiff = json_decode((string) ($replaceRows[0]->diff ?? ''), true, 64, JSON_THROW_ON_ERROR);
$assert(
	1 === count($replaceRows)
	&& 'update' === (string) $replaceRows[0]->mutation_type
	&& 'baseline' === ($replaceDiff[0]['before'] ?? null)
	&& 'final' === ($replaceDiff[0]['after'] ?? null),
	'Delete followed by add should become one baseline-to-final update instead of two operations.'
);

$fixtureClass = \ConfigOpsHostileFixture\SettingsFixture::class;
$ownerBoundaryOption = $fixtureClass::COALESCE_OPTION;
delete_option($ownerBoundaryOption);
add_option($ownerBoundaryOption, 'baseline', '', false);
$ownerBoundarySession = $captures->start('Request owner boundary', 0, '/wp-admin/options-general.php');
$fixtureClass::writeCoalesceState('plugin-state');
update_option($ownerBoundaryOption, 'core-state', false);
$captures->stop();
$ownerBoundaryRows = $mutations->forSession($ownerBoundarySession);
$assert(2 === count($ownerBoundaryRows), 'Writes by different causal owners must not be merged even inside one request.');
$assert(
	'configops-hostile-fixture' === (string) $ownerBoundaryRows[0]->source_component
	&& 'wordpress' === (string) $ownerBoundaryRows[1]->source_component,
	'The owner boundary should preserve both plugin and WordPress provenance.'
);
delete_option($coalesceOption);
delete_option($ephemeralOption);
delete_option($ownerBoundaryOption);

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

$overflowSession = $captures->start('Integrity overflow recovery', 0, '/wp-admin/admin.php?page=configops');
$captures->stop();
update_option(
	\ConfigOps\Database\CaptureRepository::INTEGRITY_FALLBACK_OPTION,
	array('events' => array(), 'overflow' => true),
	false
);
$overflowRepository = new \ConfigOps\Database\CaptureRepository($wpdb);
$assert(false === $overflowRepository->reconcileIntegrityFallback(), 'A writable canonical table should resolve an overflow fallback without a permanent admin alert.');
$assert(false === get_option(\ConfigOps\Database\CaptureRepository::INTEGRITY_FALLBACK_OPTION, false), 'A reconciled overflow marker should remove its site-wide fallback option.');
$overflowCapture = $overflowRepository->find($overflowSession);
$assert(
	1 === (int) $overflowCapture->capture_error_count
	&& 'integrity_fallback_overflow' === (string) $overflowCapture->last_error_code,
	'Overflow recovery must conservatively invalidate existing evidence before clearing its fallback alert.'
);

$connectorOption = 'connectors_ai_openai_api_key';
$connectorPlaintext = 'sk-delete-hook-must-not-read';
delete_option($connectorOption);
add_option($connectorOption, $connectorPlaintext, '', false);
$connectorReadCount = 0;
$connectorReadProbe = static function (mixed $value) use (&$connectorReadCount): mixed {
	++$connectorReadCount;

	return $value;
};
$connectorSession = $captures->start('Connector secret deletion', 0, '/wp-admin/options-general.php?page=connectors');
add_filter("pre_option_{$connectorOption}", $connectorReadProbe);
delete_option($connectorOption);
remove_filter("pre_option_{$connectorOption}", $connectorReadProbe);
$captures->stop();
$connectorRows = $mutations->forSession($connectorSession);
$assert(0 === $connectorReadCount, 'Deletion capture must not fetch a whole-option credential whose name already proves it is sensitive.');
$assert(1 === count($connectorRows) && 1 === (int) $connectorRows[0]->is_redacted, 'A skipped Connector API key read must still leave explicit redacted deletion evidence.');
$assert(! str_contains((string) $connectorRows[0]->old_value, $connectorPlaintext), 'Connector API key plaintext must never reach deletion evidence storage.');

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
\ConfigOps\Plugin::deactivate(false);
$interruptedCapture = $captures->find($interruptedSession);
$assert(
	'interrupted' === (string) $interruptedCapture->status
	&& 1 === (int) $interruptedCapture->capture_error_count
	&& 'plugin_deactivated' === (string) $interruptedCapture->last_error_code,
	'Site-local deactivation should explicitly close the active capture and leave it visibly incomplete.'
);
$assert(false === wp_next_scheduled(\ConfigOps\Maintenance\HistoryRetention::HOOK), 'Site-local deactivation should remove its retention schedule.');
\ConfigOps\Plugin::activate(false);
$assert(false !== wp_next_scheduled(\ConfigOps\Maintenance\HistoryRetention::HOOK), 'Site-local activation should restore its retention schedule.');
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
delete_option('fixture_scalar_normalization');

add_option('fixture_nested', array('mail' => array('enabled' => false, 'retry' => 3)), '', false);
add_option('fixture_deleted', 'baseline', '', false);
add_option('fixture_semantic_reorder', array('first' => 1, 'second' => 2), '', false);
add_option('fixture_scalar_normalization', array('nullable' => null, 'count' => '7', 'stable' => 'value'), '', false);

$sessionId = $captures->start('Integration capture', 0, '/wp-admin/options-general.php');
update_option('fixture_nested', array('mail' => array('retry' => 4, 'enabled' => true)));
update_option('fixture_semantic_reorder', array('second' => 2, 'first' => 1));
update_option('fixture_scalar_normalization', array('nullable' => '', 'count' => 7, 'stable' => 'value'));
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
$assert(! isset($byName['fixture_scalar_normalization']), 'Empty and canonical-integer storage normalization must not create capture noise.');

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

$postWriteOption = 'fixture_restore_post_write_verification';
delete_option($postWriteOption);
add_option($postWriteOption, 'baseline', '', false);
$postWriteSession = $captures->start('Post-write verification', 0, '/wp-admin/options-general.php');
update_option($postWriteOption, 'captured-change', false);
$captures->stop();
$postWriteMutation = $mutations->forSession($postWriteSession)[0];
$rewriteRestoredValue = static function (string $option, mixed $oldValue, mixed $newValue) use ($postWriteOption): void {
	unset($oldValue);
	if ($postWriteOption === $option && 'baseline' === $newValue) {
		update_option($postWriteOption, 'hook-rewritten', false);
	}
};
add_action('updated_option', $rewriteRestoredValue, 20, 3);
$postWriteCompensated = false;
try {
	$restore->restoreMutation((int) $postWriteMutation->id);
} catch (\ConfigOps\Restore\RestoreCompensationException $error) {
	$postWriteCompensated = ! $error->compensationFailed;
}
remove_action('updated_option', $rewriteRestoredValue, 20);
$assert($postWriteCompensated, 'A synchronous post-update rewrite must make restore fail as compensated instead of claiming success.');
$assert('captured-change' === get_option($postWriteOption), 'Failed post-update verification must reapply and verify the original current value.');
$postWriteAudits = $restoreAudit->forSession($postWriteSession);
$assert('compensated' === (string) $postWriteAudits[0]->status, 'A post-write mismatch must remain visible in the restore audit.');

$postAddOption = 'fixture_restore_post_add_verification';
delete_option($postAddOption);
add_option($postAddOption, 'baseline', '', false);
$postAddSession = $captures->start('Post-add verification', 0, '/wp-admin/options-general.php');
delete_option($postAddOption);
$captures->stop();
$postAddMutation = $mutations->forSession($postAddSession)[0];
$rewriteRestoredAdd = static function (string $option, mixed $value) use ($postAddOption): void {
	if ($postAddOption === $option && 'baseline' === $value) {
		update_option($postAddOption, 'hook-rewritten', false);
	}
};
add_action('added_option', $rewriteRestoredAdd, 20, 2);
$postAddCompensated = false;
try {
	$restore->restoreMutation((int) $postAddMutation->id);
} catch (\ConfigOps\Restore\RestoreCompensationException $error) {
	$postAddCompensated = ! $error->compensationFailed;
}
remove_action('added_option', $rewriteRestoredAdd, 20);
$assert($postAddCompensated, 'A synchronous post-add rewrite must make restore fail as compensated instead of claiming success.');
$assert(false === get_option($postAddOption, false), 'Failed post-add verification must compensate back to the original missing state.');

$postDeleteOption = 'fixture_restore_post_delete_verification';
delete_option($postDeleteOption);
$postDeleteSession = $captures->start('Post-delete verification', 0, '/wp-admin/options-general.php');
add_option($postDeleteOption, 'captured-add', '', false);
$captures->stop();
$postDeleteMutation = $mutations->forSession($postDeleteSession)[0];
$recreateRestoredDelete = static function (string $option) use ($postDeleteOption): void {
	if ($postDeleteOption === $option) {
		add_option($postDeleteOption, 'hook-recreated', '', false);
	}
};
add_action('deleted_option', $recreateRestoredDelete, 20, 1);
$postDeleteCompensated = false;
try {
	$restore->restoreMutation((int) $postDeleteMutation->id);
} catch (\ConfigOps\Restore\RestoreCompensationException $error) {
	$postDeleteCompensated = ! $error->compensationFailed;
}
remove_action('deleted_option', $recreateRestoredDelete, 20);
$assert($postDeleteCompensated, 'A synchronous post-delete recreation must make restore fail as compensated instead of claiming success.');
$assert('captured-add' === get_option($postDeleteOption), 'Failed post-delete verification must compensate back to the original current value.');

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
delete_option('fixture_scalar_normalization');
delete_option('fixture_compensation_failure');
delete_option('fixture_restore_meaningful');
delete_option('_transient_fixture_restore_runtime');
delete_option($postWriteOption);
delete_option($postAddOption);
delete_option($postDeleteOption);
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

$automaticOption = 'fixture_automatic_settings';
update_option($automaticOption, 'before', false);
$automaticNotices = new \ConfigOps\Admin\EvidenceNoticeStore();
$automaticRecorder = new \ConfigOps\Capture\AutomaticRecorder(
	$freshCaptures,
	$automaticNotices,
	new \ConfigOps\Capture\RequestContext()
);
$originalRestRoute = $_GET['rest_route'] ?? null;
$_GET['rest_route'] = '/configops/v1/captures/123/restore';
$internalAutomaticRecorder = new \ConfigOps\Capture\AutomaticRecorder(
	$freshCaptures,
	$automaticNotices,
	new \ConfigOps\Capture\RequestContext()
);
$assert(
	null === $internalAutomaticRecorder->sessionId(),
	'ConfigOps REST requests routed through the non-pretty rest_route query must never observe their own writes.'
);
if (null === $originalRestRoute) {
	unset($_GET['rest_route']);
} else {
	$_GET['rest_route'] = $originalRestRoute;
}
$earlyAutomaticRecorder = new \ConfigOps\Capture\AutomaticRecorder(
	$freshCaptures,
	$automaticNotices,
	new \ConfigOps\Capture\RequestContext()
);
$allowEarlyAutomaticContext = static fn (): bool => true;
add_filter('configops_automatic_recording_context_allowed', $allowEarlyAutomaticContext, 10, 2);
$earlyAutomaticSession = $earlyAutomaticRecorder->sessionId();
remove_filter('configops_automatic_recording_context_allowed', $allowEarlyAutomaticContext, 10);
$assert(
	null !== $earlyAutomaticSession && $freshCaptures->hasOpenAutomatic(),
	'An early settings write may open an automatic observation before an internal REST callback runs.'
);
$earlyAutomaticRecorder->suppress();
$earlyAutomaticRow = $freshCaptures->find((int) $earlyAutomaticSession);
$assert(
	'discarded' === (string) $earlyAutomaticRow->status
	&& ! $freshCaptures->hasOpenAutomatic()
	&& null === $earlyAutomaticRecorder->sessionId(),
	'An internal command must close a request-local automatic observation and keep its own later writes suppressed.'
);
$suppressedAutomaticRecorder = new \ConfigOps\Capture\AutomaticRecorder(
	$freshCaptures,
	$automaticNotices,
	new \ConfigOps\Capture\RequestContext()
);
$suppressedAutomaticRecorder->suppress();
$suppressionSession = $freshCaptures->start('Internal command suppression', 1, '/wp-admin/admin.php?page=configops');
$assert(
	$suppressionSession === $suppressedAutomaticRecorder->sessionId(),
	'An internal command may still finish the named session that was active when its request began.'
);
$freshCaptures->stop();
$assert(
	null === $suppressedAutomaticRecorder->sessionId(),
	'An internal command must not start a new automatic observation after stopping its named session.'
);
$allowAutomaticContext = static fn (): bool => true;
add_filter('configops_automatic_recording_context_allowed', $allowAutomaticContext, 10, 2);
$automaticSession = $automaticRecorder->sessionId();
remove_filter('configops_automatic_recording_context_allowed', $allowAutomaticContext, 10);
$assert(null !== $automaticSession, 'An authorized administrative request should lazily create an automatic observation.');
$automaticObserver = new \ConfigOps\Capture\MutationObserver(
	$freshCaptures,
	$mutations,
	new \ConfigOps\Database\OptionMetadataRepository($wpdb),
	new \ConfigOps\Capture\InternalOptionPolicy(),
	$codec,
	new \ConfigOps\Diff\NestedDiff(),
	$adapters,
	new \ConfigOps\Capture\SourceAttributor(CONFIGOPS_PATH),
	new \ConfigOps\Capture\RequestContext(),
	new \ConfigOps\Capture\IntentContext(),
	$automaticRecorder
);
$automaticObserver->beforeUpdate('after', $automaticOption, 'before');
update_option($automaticOption, 'after', false);
$automaticObserver->onUpdated($automaticOption, 'before', 'after');
$automaticRecorder->finalize();
$automaticRow = $freshCaptures->find((int) $automaticSession);
$assert(
	'completed' === (string) $automaticRow->status
	&& 'automatic' === (string) $automaticRow->capture_mode
	&& null === $freshCaptures->activeId(),
	'An automatic observation should complete request-locally without claiming the named-session pointer.'
);
$queuedAutomatic = $automaticNotices->pull(1);
$assert(
	array((int) $automaticSession) === $queuedAutomatic,
	'Completed automatic evidence should queue one short-lived feedback pointer for its actor.'
);
$automaticEvidence = $payloadFactory->evidence($queuedAutomatic);
$assert(
	1 === count($automaticEvidence)
	&& 1 === $automaticEvidence[0]['writeCount']
	&& null !== $automaticEvidence[0]['undo'],
	'Automatic feedback should expose compact counts and offer undo only for a fully safe observation.'
);
$blockingNamedSession = $freshCaptures->start('Block undo while recording', 1, '/wp-admin/options.php');
$namedSessionRestoreBlocked = false;
try {
	$restore->restoreSession((int) $automaticSession);
} catch (RuntimeException $error) {
	$namedSessionRestoreBlocked = str_contains($error->getMessage(), 'active change session');
}
$freshCaptures->stop();
$assert(
	$namedSessionRestoreBlocked && $blockingNamedSession > 0,
	'Undo must remain blocked while a site-wide named Change Session is recording.'
);
$overlappingAutomatic = $freshCaptures->startAutomatic('Concurrent request', 1, '/wp-admin/options.php');
$restore->restoreSession((int) $automaticSession);
$freshCaptures->interruptAutomatic($overlappingAutomatic, 'automatic_test_complete');
$assert(
	'before' === get_option($automaticOption) && ! $freshCaptures->hasOpenAutomatic(),
	'A request-local automatic observation must not block conflict-checked undo in an independent request.'
);
delete_option($automaticOption);

$incompleteAutomaticNotices = new \ConfigOps\Admin\EvidenceNoticeStore();
$incompleteAutomaticRecorder = new \ConfigOps\Capture\AutomaticRecorder(
	$freshCaptures,
	$incompleteAutomaticNotices,
	new \ConfigOps\Capture\RequestContext()
);
add_filter('configops_automatic_recording_context_allowed', $allowAutomaticContext, 10, 2);
$incompleteAutomaticSession = $incompleteAutomaticRecorder->sessionId();
remove_filter('configops_automatic_recording_context_allowed', $allowAutomaticContext, 10);
$freshCaptures->recordCaptureError((int) $incompleteAutomaticSession, 'automatic_test_failure');
$incompleteAutomaticRecorder->finalize();
$incompleteAutomaticEvidence = $payloadFactory->evidence($incompleteAutomaticNotices->pull(1));
$assert(
	'interrupted' === (string) $freshCaptures->find((int) $incompleteAutomaticSession)->status
	&& true === ($incompleteAutomaticEvidence[0]['incomplete'] ?? false)
	&& null === ($incompleteAutomaticEvidence[0]['undo'] ?? null),
	'An automatic observation with missed evidence should remain visible and disable direct undo.'
);

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
$evidenceResponse = $restServer->dispatch(new WP_REST_Request('GET', '/configops/v1/evidence'));
$evidenceData = $evidenceResponse->get_data();
$assert(
	200 === $evidenceResponse->get_status()
	&& is_array($evidenceData)
	&& isset($evidenceData['items']),
	'The local feedback endpoint should expose a private pending evidence collection.'
);
$automaticNotices->push(1, (int) $automaticSession);
$deliveredEvidence = $restServer->dispatch(new WP_REST_Request('GET', '/configops/v1/evidence'))->get_data();
$assert(
	(int) $automaticSession === (int) ($deliveredEvidence['items'][0]['id'] ?? 0)
	&& array((int) $automaticSession) === $automaticNotices->pending(1),
	'Reading automatic evidence must not consume it before the browser confirms that it rendered.'
);
$acknowledgeEvidenceRequest = new WP_REST_Request('POST', '/configops/v1/evidence/acknowledge');
$acknowledgeEvidenceRequest->set_body_params(array('ids' => array((int) $automaticSession)));
$acknowledgeEvidenceResponse = $restServer->dispatch($acknowledgeEvidenceRequest);
$assert(
	200 === $acknowledgeEvidenceResponse->get_status()
	&& array() === $automaticNotices->pending(1),
	'Automatic evidence should be consumed only by an explicit browser acknowledgement.'
);
$notReadySession = 9999999;
$automaticNotices->push(1, $notReadySession);
$notReadyEvidence = $restServer->dispatch(new WP_REST_Request('GET', '/configops/v1/evidence'))->get_data();
$assert(
	is_array($notReadyEvidence)
	&& array() === ($notReadyEvidence['items'] ?? null)
	&& array($notReadySession) === $automaticNotices->pending(1),
	'Feedback polling must retain a pointer until the finalized session is visible to its reading worker.'
);
$automaticNotices->acknowledge(1, array($notReadySession));

$crossWorkerActor = 4242;
$crossWorkerOption = 'configops_pending_evidence_' . $crossWorkerActor;
delete_option($crossWorkerOption);
get_option($crossWorkerOption, array());
$crossWorkerSession = 9999998;
$wpdb->insert(
	$wpdb->options,
	array(
		'option_name'  => $crossWorkerOption,
		'option_value' => maybe_serialize(
			array(array('session_id' => $crossWorkerSession, 'recorded_at' => time()))
		),
		'autoload'     => 'no',
	),
	array('%s', '%s', '%s')
);
$assert(
	array($crossWorkerSession) === $automaticNotices->pending($crossWorkerActor),
	'Evidence polling must invalidate a worker-local notoptions miss before reading a pointer written elsewhere.'
);
delete_option($crossWorkerOption);

$firstBulkMutationId = (int) $bulkFirstPage[array_key_last($bulkFirstPage)]->id;
$pageRequest = new WP_REST_Request('GET', "/configops/v1/captures/{$bulkSession}/mutations");
$pageRequest->set_param('id', $bulkSession);
$pageRequest->set_param('after', $firstBulkMutationId);
$pageResponse = $restServer->dispatch($pageRequest);
$pageData = $pageResponse->get_data();
$assert(
	200 === $pageResponse->get_status() && is_array($pageData) && 1 === count($pageData['groups']),
	'The mutation connection should continue from an opaque monotonic boundary without an offset scan.'
);
$assert(false === $pageData['pageInfo']['hasNext'], 'The final mutation connection page should close its continuation honestly.');
$oversizedPageRequest = new WP_REST_Request('GET', "/configops/v1/captures/{$bulkSession}/mutations");
$oversizedPageRequest->set_param('id', $bulkSession);
$oversizedPageRequest->set_param('limit', \ConfigOps\Admin\AdminPayloadFactory::PAGE_SIZE + 1);
$oversizedPageResponse = $restServer->dispatch($oversizedPageRequest);
$assert(
	400 === $oversizedPageResponse->get_status(),
	'The REST mutation connection must reject pages above the shared 25-row review budget.'
);
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
$defaultStartRequest = new WP_REST_Request('POST', '/configops/v1/captures');
$defaultStartResponse = $restServer->dispatch($defaultStartRequest);
$defaultStartData = $defaultStartResponse->get_data();
$assert(
	200 === $defaultStartResponse->get_status()
	&& is_array($defaultStartData)
	&& 1 === preg_match('/^Capture \d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', (string) ($defaultStartData['active']['name'] ?? '')),
	'Every command transport should use the same bounded default capture name.'
);
$defaultStopResponse = $restServer->dispatch(new WP_REST_Request('POST', '/configops/v1/captures/active/stop'));
$assert(200 === $defaultStopResponse->get_status(), 'A default-named capture should stop through the shared command service.');

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
$userReferences = new \ConfigOps\Reference\UserReferenceResolver();
$viewerSnapshot = $userReferences->snapshot((int) $viewerId);
$assert(
	is_array($viewerSnapshot)
	&& 'available' === ($viewerSnapshot['status'] ?? '')
	&& ! isset($viewerSnapshot['email'], $viewerSnapshot['user_login']),
	'User references must retain only bounded display identity and never persist login or email data.'
);
$assert($userReferences->isAvailable($viewerSnapshot), 'An existing referenced user should remain restorable.');
$assert(null === $userReferences->snapshot(array('id' => $viewerId)), 'Non-scalar user identifiers must fail closed.');
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user((int) $viewerId);
$missingViewer = $userReferences->present($viewerSnapshot);
$assert(
	'missing' === ($missingViewer['current_status'] ?? '') && ! $userReferences->isAvailable($viewerSnapshot),
	'A deleted referenced user must become visibly unavailable before restore.'
);

wp_set_current_user(0);
$forbiddenResponse = $restServer->dispatch(new WP_REST_Request('GET', '/configops/v1/state'));
$assert($forbiddenResponse->get_status() >= 400, 'The local Agent API must fail closed without a ConfigOps capability.');

wp_dequeue_style('configops-admin');
wp_dequeue_script('configops-intent-observer');
wp_dequeue_script('configops-automatic-feedback');
wp_dequeue_script('configops-runtime');
$adminController = new \ConfigOps\Admin\AdminController(
	$freshCaptures,
	new \ConfigOps\Command\CaptureCommands($freshCaptures, $restore),
	new \ConfigOps\Admin\FlashNoticeStore(),
	$payloadFactory
);
$adminController->enqueueToolbarAssets();
$adminController->enqueueAdminAssets('dashboard_page_unrelated');
$assert(
	! wp_style_is('configops-admin', 'enqueued')
	&& ! wp_script_is('configops-intent-observer', 'enqueued')
	&& ! wp_script_is('configops-automatic-feedback', 'enqueued')
	&& ! wp_script_is('configops-runtime', 'enqueued'),
	'Anonymous frontend and unrelated inactive admin requests must enqueue no ConfigOps CSS or JavaScript.'
);
$assert(
	'<script src="fixture.js"></script>' === $adminController->moduleScriptTag('<script src="fixture.js"></script>', 'third-party', 'fixture.js'),
	'The module-tag filter must leave every third-party script byte-for-byte unchanged.'
);
$assert(
	str_contains($adminController->moduleScriptTag('<script src="runtime.js"></script>', 'configops-runtime', 'runtime.js'), 'type="module"')
	&& ! str_contains($adminController->moduleScriptTag('<script type="text/javascript" src="runtime.js"></script>', 'configops-runtime', 'runtime.js'), 'text/javascript'),
	'Only the ConfigOps runtime should receive one normalized module type.'
);

wp_set_current_user(1);
$adminCapture = $freshCaptures->start('Admin asset boundary', 1, '/wp-admin/profile.php');
$adminController->enqueueAdminAssets('profile.php');
$assert(
	wp_style_is('configops-admin', 'enqueued')
	&& wp_script_is('configops-intent-observer', 'enqueued')
	&& wp_script_is('configops-automatic-feedback', 'enqueued'),
	'An authorized active capture should load its bounded admin observer outside the ConfigOps screen.'
);
$automaticFeedbackScript = wp_scripts()->registered['configops-automatic-feedback'] ?? null;
$assert(
	$automaticFeedbackScript && 'configops' === ($automaticFeedbackScript->textdomain ?? ''),
	'Automatic evidence should register its JavaScript translations with the ConfigOps text domain.'
);
$freshCaptures->stop();
$adminController->enqueueAdminAssets('toplevel_page_configops');
$assert(wp_script_is('configops-runtime', 'enqueued'), 'The ConfigOps screen should load its review runtime explicitly.');
$runtimeScript = wp_scripts()->registered['configops-runtime'] ?? null;
$assert(
	$runtimeScript && 'configops' === ($runtimeScript->textdomain ?? ''),
	'The review runtime should register its JavaScript translations with the ConfigOps text domain.'
);
$assert($adminCapture > 0, 'The admin asset boundary must execute against a persisted capture.');

$flashNotices = new \ConfigOps\Admin\FlashNoticeStore();
$flashNotices->put('<script>Error</script>', '<b>Database detail</b>');
$flashPayload = $flashNotices->pull();
$assert(
	! str_contains($flashPayload['code'], '<')
	&& ! str_contains($flashPayload['message'], '<')
	&& str_contains($flashPayload['message'], 'Database detail'),
	'Flash notices must be sanitized before crossing a request boundary.'
);
$assert(
	array('code' => '', 'message' => '') === $flashNotices->pull(),
	'Flash notices must be one-shot and disappear immediately after retrieval.'
);

set_transient('configops_flash_uninstall_fixture', array('code' => 'fixture'), MINUTE_IN_SECONDS);
add_option('configops_operation_lock_uninstall_fixture', array('token' => 'fixture', 'expires_at' => time() + 60), '', false);
\ConfigOps\Uninstall::run();
$assert(false === wp_next_scheduled(\ConfigOps\Maintenance\HistoryRetention::HOOK), 'Uninstall must remove the scheduled retention event.');
$assert(false === get_option('configops_schema_version', false), 'Uninstall must remove ConfigOps installation options.');
$assert(false === get_transient('configops_flash_uninstall_fixture'), 'Uninstall must remove per-user ConfigOps flash transients.');
$assert(false === get_option('configops_operation_lock_uninstall_fixture', false), 'Uninstall must remove outstanding ConfigOps operation locks.');
$assert(! get_role('administrator')->has_cap('configops_view'), 'Uninstall must remove ConfigOps capabilities from WordPress roles.');
foreach (array('configops_restore_runs', 'configops_write_signals', 'configops_mutations', 'configops_capture_sessions') as $suffix) {
	$table = $wpdb->prefix . $suffix;
	$assert(
		null === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))),
		"Uninstall must remove the {$suffix} table."
	);
}

fwrite(STDOUT, "ConfigOps WordPress integration checks passed ({$assertions} assertions).\n");
