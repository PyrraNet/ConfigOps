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
use ConfigOps\Adapter\WpMailSmtpAdapter;
use ConfigOps\Adapter\YoastSeoAdapter;
use ConfigOps\Admin\AdminPayloadFactory;
use ConfigOps\Admin\AdminController;
use ConfigOps\Admin\FlashNoticeStore;
use ConfigOps\Admin\ReviewPresenter;
use ConfigOps\Api\RestController;
use ConfigOps\Capture\InternalOptionPolicy;
use ConfigOps\Capture\HeuristicSensitiveValueDetector;
use ConfigOps\Capture\MutationObserver;
use ConfigOps\Capture\RequestContext;
use ConfigOps\Capture\SqlWriteSentry;
use ConfigOps\Capture\SourceAttributor;
use ConfigOps\Capture\ValueCodec;
use ConfigOps\Database\CaptureRepository;
use ConfigOps\Database\DatabaseWriteSignalRepository;
use ConfigOps\Database\MutationRepository;
use ConfigOps\Database\OptionMetadataRepository;
use ConfigOps\Database\RestoreAuditRepository;
use ConfigOps\Database\Schema;
use ConfigOps\Diff\NestedDiff;
use ConfigOps\Execution\OperationLock;
use ConfigOps\Noise\NoiseClassifier;
use ConfigOps\Privacy\PrivacyPolicy;
use ConfigOps\Restore\RestoreService;
use ConfigOps\Maintenance\HistoryRetention;

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

		$captures  = new CaptureRepository($wpdb);
		$captures->reconcileIntegrityFallback();
		self::registerIntegrityFallbackNotice($captures);
		$mutations = new MutationRepository($wpdb);
		$signals   = new DatabaseWriteSignalRepository($wpdb);
		$metadata  = new OptionMetadataRepository($wpdb);
		$builtInAdapters = array(new WpMailSmtpAdapter(), new YoastSeoAdapter());
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
		(new HistoryRetention($wpdb, new OperationLock($wpdb)))->register();
		(new PrivacyPolicy())->register();

		(new SqlWriteSentry($wpdb, $captures, $signals, $source, $request, $adapters))->register();

		$observer = new MutationObserver(
			$captures,
			$mutations,
			$metadata,
			new InternalOptionPolicy(),
			$codec,
			new NestedDiff(),
			$adapters,
			$source,
			$request
		);
		$observer->register();

		$restore   = new RestoreService(
			$captures,
			$mutations,
			$codec,
			$metadata,
			new OperationLock($wpdb),
			$adapters,
			new RestoreAuditRepository($wpdb)
		);
		$presenter = new ReviewPresenter($adapters);
		$payloads  = new AdminPayloadFactory(
			$captures,
			$mutations,
			$signals,
			$presenter,
			$adapters,
			new RestoreAuditRepository($wpdb)
		);

		(new RestController($captures, $mutations, $restore, $payloads))->register();
		(new AdminController($captures, $restore, new FlashNoticeStore(), $payloads))->register();
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

	public static function activate(): void
	{
		global $wpdb;

		(new Schema($wpdb))->install();
		(new CapabilityManager())->install();
		HistoryRetention::schedule();
	}

	public static function deactivate(): void
	{
		global $wpdb;

		try {
			(new CaptureRepository($wpdb))->interruptActive('plugin_deactivated');
		} catch (\Throwable $error) {
			// A deactivation must not strand WordPress. The active pointer is removed
			// by the repository before it reports an interrupted-session write error.
			try {
				do_action('configops_deactivation_error', $error);
			} catch (\Throwable) {
				// Extension diagnostics cannot break deactivation.
			}
		}
		HistoryRetention::unschedule();
	}

	/**
	 * @return list<string>
	 */
	public static function capabilities(): array
	{
		return CapabilityManager::capabilities();
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
