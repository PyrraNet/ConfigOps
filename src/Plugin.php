<?php
/**
 * Plugin composition root.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps;

use ConfigOps\Access\CapabilityManager;
use ConfigOps\Admin\AdminPayloadFactory;
use ConfigOps\Admin\AdminController;
use ConfigOps\Admin\FlashNoticeStore;
use ConfigOps\Admin\ReviewPresenter;
use ConfigOps\Api\RestController;
use ConfigOps\Capture\InternalOptionPolicy;
use ConfigOps\Capture\MutationObserver;
use ConfigOps\Capture\RequestContext;
use ConfigOps\Capture\SourceAttributor;
use ConfigOps\Capture\ValueCodec;
use ConfigOps\Database\CaptureRepository;
use ConfigOps\Database\MutationRepository;
use ConfigOps\Database\OptionMetadataRepository;
use ConfigOps\Database\Schema;
use ConfigOps\Diff\NestedDiff;
use ConfigOps\Execution\OperationLock;
use ConfigOps\Noise\NoiseClassifier;
use ConfigOps\Restore\RestoreService;

final class Plugin
{
	private static ?self $instance = null;

	private function __construct()
	{
		global $wpdb;

		$schema = new Schema($wpdb);
		$schema->maybeUpgrade();
		(new CapabilityManager())->maybeInstall();

		$captures  = new CaptureRepository($wpdb);
		$mutations = new MutationRepository($wpdb);
		$metadata  = new OptionMetadataRepository($wpdb);
		$codec     = new ValueCodec();

		$observer = new MutationObserver(
			$captures,
			$mutations,
			$metadata,
			new InternalOptionPolicy(),
			$codec,
			new NestedDiff(),
			new NoiseClassifier(),
			new SourceAttributor(CONFIGOPS_PATH),
			new RequestContext()
		);
		$observer->register();

		$restore   = new RestoreService($captures, $mutations, $codec, $metadata, new OperationLock($wpdb));
		$presenter = new ReviewPresenter();
		$payloads  = new AdminPayloadFactory($captures, $mutations, $presenter);

		(new RestController($captures, $mutations, $restore, $payloads))->register();
		(new AdminController($captures, $restore, new FlashNoticeStore(), $payloads))->register();
	}

	public static function boot(): void
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}
	}

	public static function activate(): void
	{
		global $wpdb;

		(new Schema($wpdb))->install();
		(new CapabilityManager())->install();
	}

	/**
	 * @return list<string>
	 */
	public static function capabilities(): array
	{
		return CapabilityManager::capabilities();
	}
}
