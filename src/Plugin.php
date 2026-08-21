<?php
/**
 * Plugin composition root.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps;

use ConfigOps\Access\CapabilityManager;
use ConfigOps\Adapter\AdapterRegistry;
use ConfigOps\Adapter\BuiltInAdapters;
use ConfigOps\Admin\AdminPayloadFactory;
use ConfigOps\Admin\AdminController;
use ConfigOps\Admin\EvidenceNoticeStore;
use ConfigOps\Admin\FlashNoticeStore;
use ConfigOps\Admin\NetworkAdminController;
use ConfigOps\Admin\NetworkAdminPayloadFactory;
use ConfigOps\Admin\ReviewPresenter;
use ConfigOps\Api\NetworkRestController;
use ConfigOps\Api\RestController;
use ConfigOps\Capture\InternalOptionPolicy;
use ConfigOps\Capture\AutomaticRecorder;
use ConfigOps\Capture\HeuristicSensitiveValueDetector;
use ConfigOps\Capture\IntentContext;
use ConfigOps\Capture\MutationObserver;
use ConfigOps\Capture\MutationRecorder;
use ConfigOps\Capture\NetworkAutomaticRecorder;
use ConfigOps\Capture\NetworkMutationObserver;
use ConfigOps\Capture\RequestContext;
use ConfigOps\Capture\SqlWriteSentry;
use ConfigOps\Capture\SourceAttributor;
use ConfigOps\Capture\ValueCodec;
use ConfigOps\Command\CaptureCommands;
use ConfigOps\Command\NetworkCaptureCommands;
use ConfigOps\Database\CaptureRepository;
use ConfigOps\Database\DatabaseWriteSignalRepository;
use ConfigOps\Database\MutationRepository;
use ConfigOps\Database\OptionMetadataRepository;
use ConfigOps\Database\RestoreAuditRepository;
use ConfigOps\Database\Schema;
use ConfigOps\Diff\NestedDiff;
use ConfigOps\Execution\NetworkOperationLock;
use ConfigOps\Execution\OperationLock;
use ConfigOps\Maintenance\HistoryRetention;
use ConfigOps\Multisite\NetworkScope;
use ConfigOps\Multisite\SiteBoundaryGuard;
use ConfigOps\Multisite\SiteLifecycle;
use ConfigOps\Multisite\SiteScope;
use ConfigOps\Noise\NoiseClassifier;
use ConfigOps\Privacy\PrivacyPolicy;
use ConfigOps\Restore\NetworkRestorePolicy;
use ConfigOps\Restore\NetworkRestoreService;
use ConfigOps\Restore\RestoreService;

final class Plugin
{
	private static ?self $instance = null;
	private static bool $bootAttempted = false;

	private function __construct()
	{
		global $wpdb;

		$schema = new Schema($wpdb);
		$schema->maybeUpgrade();
		(new CapabilityManager())->maybeInstall();

		$siteScope = SiteScope::current();
		$captures  = new CaptureRepository($wpdb, $siteScope);
		$siteBoundary = new SiteBoundaryGuard($siteScope, $captures);
		$captures->reconcileIntegrityFallback();
		self::registerIntegrityFallbackNotice($captures);
		$mutations = new MutationRepository($wpdb, $siteScope);
		$signals   = new DatabaseWriteSignalRepository($wpdb, $siteScope);
		$metadata  = new OptionMetadataRepository($wpdb);
		$builtInAdapters = BuiltInAdapters::create();
		try {
			$adapterList = apply_filters('configops_adapters', $builtInAdapters);
		} catch (\Throwable $error) {
			try {
				do_action('configops_adapter_registration_error', $error);
			} catch (\Throwable) {
				// Extension diagnostics cannot break ConfigOps boot.
			}
			$adapterList = $builtInAdapters;
		}
		$adapters  = new AdapterRegistry(
			is_array($adapterList) ? $adapterList : $builtInAdapters,
			new NoiseClassifier(),
			new HeuristicSensitiveValueDetector()
		);
		$codec     = new ValueCodec($adapters);
		$source    = new SourceAttributor(CONFIGOPS_PATH);
		$request   = new RequestContext();
		$evidenceNotices = new EvidenceNoticeStore();
		$automatic = new AutomaticRecorder($captures, $evidenceNotices, $request, $siteBoundary);
		$automatic->register();
		$operationLock = new OperationLock($wpdb, $siteScope);
		$restoreAudits = new RestoreAuditRepository($wpdb, $siteScope);
		(new HistoryRetention($wpdb, $operationLock, $siteScope))->register();
		(new PrivacyPolicy())->register();

		(new SqlWriteSentry($wpdb, $captures, $signals, $source, $request, $adapters, $automatic, $siteBoundary))->register();

		$observer = new MutationObserver(
			$captures,
			$mutations,
			$metadata,
			new InternalOptionPolicy(),
			$codec,
			new NestedDiff(),
			$adapters,
			$source,
			$request,
			new IntentContext(),
			$automatic,
			$siteBoundary
		);
		$observer->register();

		$restore   = new RestoreService(
			$captures,
			$mutations,
			$codec,
			$metadata,
			$operationLock,
			$adapters,
			$restoreAudits,
			$siteBoundary
		);
		$commands  = new CaptureCommands($captures, $restore, $automatic, $siteBoundary);
		$presenter = new ReviewPresenter($adapters);
		$payloads  = new AdminPayloadFactory(
			$captures,
			$mutations,
			$signals,
			$presenter,
			$adapters,
			$restoreAudits
		);

		(new RestController($captures, $mutations, $commands, $payloads, $evidenceNotices, $siteBoundary))->register();
		(new AdminController($captures, $commands, new FlashNoticeStore(), $payloads, $siteBoundary))->register();

		if (self::networkFeaturesEnabled()) {
			$networkScope = NetworkScope::current();
			if ($siteScope->siteId() === (int) get_main_site_id($networkScope->networkId())) {
				(new HistoryRetention($wpdb, $operationLock, $networkScope))->register();
			}
			$networkCaptures = new CaptureRepository($wpdb, $networkScope);
			$networkMutations = new MutationRepository($wpdb, $networkScope);
			$networkAutomatic = new NetworkAutomaticRecorder($networkCaptures, $request, $networkScope);
			$networkAutomatic->register();
			$networkCommands = new NetworkCaptureCommands($networkCaptures, $networkAutomatic, $networkScope);
			$networkRecorder = new MutationRecorder(
				$networkCaptures,
				$networkMutations,
				$codec,
				new NestedDiff(),
				$adapters,
				$source,
				$request,
				$networkScope,
				new IntentContext()
			);
			(new NetworkMutationObserver(
				$networkCaptures,
				new InternalOptionPolicy(),
				$codec,
				$networkRecorder,
				$networkAutomatic,
				$networkScope
			))->register();

			$networkRestorePolicy = new NetworkRestorePolicy();
			$networkPayloads = new NetworkAdminPayloadFactory(
				new AdminPayloadFactory(
					$networkCaptures,
					$networkMutations,
					new DatabaseWriteSignalRepository($wpdb, $networkScope),
					$presenter,
					$adapters,
					new RestoreAuditRepository($wpdb, $networkScope)
				),
				$networkScope,
				$networkRestorePolicy
			);
			$networkRestore = new NetworkRestoreService(
				$networkCaptures,
				$networkMutations,
				$codec,
				new NetworkOperationLock($wpdb, $networkScope),
				new RestoreAuditRepository($wpdb, $networkScope),
				$networkScope,
				$networkRestorePolicy
			);
			(new NetworkAdminController($networkCaptures, $networkCommands, $networkPayloads, $networkScope))->register();
			(new NetworkRestController(
				$networkCaptures,
				$networkMutations,
				$networkCommands,
				$networkPayloads,
				$networkRestore,
				$networkScope
			))->register();
		}
		(new SiteLifecycle($wpdb))->register();
	}

	public static function boot(): void
	{
		if (null !== self::$instance || self::$bootAttempted) {
			return;
		}

		self::$bootAttempted = true;
		try {
			self::$instance = new self();
		} catch (\Throwable $error) {
			self::registerBootFailure($error);
		}
	}

	public static function activate(bool $networkWide = false): void
	{
		global $wpdb;

		(new SiteLifecycle($wpdb))->activate($networkWide);
	}

	public static function deactivate(bool $networkWide = false): void
	{
		global $wpdb;

		(new SiteLifecycle($wpdb))->deactivate($networkWide);
	}

	/**
	 * @return list<string>
	 */
	public static function capabilities(): array
	{
		return CapabilityManager::capabilities();
	}

	private static function networkFeaturesEnabled(): bool
	{
		if (! is_multisite()) {
			return false;
		}

		$plugins = get_network_option((int) get_current_network_id(), 'active_sitewide_plugins', array());

		return is_array($plugins) && isset($plugins[plugin_basename(CONFIGOPS_FILE)]);
	}

	private static function registerBootFailure(\Throwable $error): void
	{
		try {
			do_action('configops_boot_error', $error);
		} catch (\Throwable) {
			// Failure reporting must not replace the original boot failure.
		}

		$notice = static function (): void {
			if (! current_user_can('activate_plugins')) {
				return;
			}
			?>
			<div class="notice notice-error">
				<p>
					<strong><?php esc_html_e('ConfigOps is not recording.', 'configops'); ?></strong>
					<?php esc_html_e('Its storage could not be prepared safely. WordPress is still running; check the database health, then reactivate ConfigOps after fixing the storage problem.', 'configops'); ?>
				</p>
			</div>
			<?php
		};

		add_action('admin_notices', $notice);
		add_action('network_admin_notices', $notice);
	}

	private static function registerIntegrityFallbackNotice(CaptureRepository $captures): void
	{
		$notice = static function () use ($captures): void {
			if (! $captures->hasUnresolvedIntegrityFallback() || ! current_user_can('configops_view')) {
				return;
			}
			?>
			<div class="notice notice-error">
				<p>
					<strong><?php esc_html_e('ConfigOps found unresolved capture-integrity evidence.', 'configops'); ?></strong>
					<?php esc_html_e('At least one recording warning could not be attached to its original session. Do not treat that recording as complete; repair the ConfigOps database tables and review the PHP error log.', 'configops'); ?>
				</p>
			</div>
			<?php
		};

		add_action('admin_notices', $notice);
		add_action('network_admin_notices', $notice);
	}
}
