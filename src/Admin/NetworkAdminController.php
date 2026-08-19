<?php
/**
 * Evidence and reviewed mutation undo inside WordPress Network Admin.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Admin;

use ConfigOps\Capture\IntentContext;
use ConfigOps\Multisite\NetworkScope;

final readonly class NetworkAdminController
{
	private const PAGE = 'configops';

	public function __construct(
		private NetworkAdminPayloadFactory $payloads,
		private NetworkScope $scope
	) {
	}

	public function register(): void
	{
		add_action('network_admin_menu', array($this, 'addMenu'));
		add_action('admin_enqueue_scripts', array($this, 'enqueueAssets'));
	}

	public function addMenu(): void
	{
		if (! $this->scope->isCurrent()) {
			return;
		}

		add_menu_page(
			__('ConfigOps network changes', 'configops'),
			__('ConfigOps', 'configops'),
			'manage_network_options',
			self::PAGE,
			array($this, 'render'),
			'dashicons-controls-repeat',
			28
		);
	}

	public function enqueueAssets(string $hookSuffix): void
	{
		if (! is_network_admin() || ! $this->scope->isCurrent()) {
			return;
		}

		$isConfigOps = 'toplevel_page_' . self::PAGE === $hookSuffix;
		if ($isConfigOps) {
			$this->enqueueStyles();
			wp_enqueue_script(
				'configops-runtime',
				CONFIGOPS_URL . 'assets/ui/runtime.js',
				array('wp-element', 'wp-api-fetch', 'wp-i18n'),
				$this->assetVersion('assets/ui/runtime.js'),
				true
			);
			wp_set_script_translations('configops-runtime', 'configops');
		}

		if (! $isConfigOps && current_user_can('manage_network_options')) {
			wp_enqueue_script(
				'configops-intent-observer',
				CONFIGOPS_URL . 'assets/intent-observer.js',
				array(),
				$this->assetVersion('assets/intent-observer.js'),
				true
			);
			$settings = wp_json_encode(
				array(
					'sessionId' => 0,
					'cookieName' => IntentContext::COOKIE_NAME,
				)
			);
			if (is_string($settings)) {
				wp_add_inline_script('configops-intent-observer', 'window.configOpsIntent = ' . $settings . ';', 'before');
			}
		}
	}

	public function render(): void
	{
		if (! $this->scope->isCurrent() || ! current_user_can('manage_network_options')) {
			wp_die(esc_html__('You are not allowed to view ConfigOps network evidence.', 'configops'));
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only capture selection.
		$requested = isset($_GET['session']) ? absint($_GET['session']) : 0;
		$bootstrap = $this->payloads->state($requested > 0 ? $requested : null, false);
		$view = 'network';

		include CONFIGOPS_PATH . '/templates/admin-page.php';
	}

	private function enqueueStyles(): void
	{
		wp_enqueue_style(
			'configops-admin',
			CONFIGOPS_URL . 'assets/admin.css',
			array(),
			$this->assetVersion('assets/admin.css')
		);
	}

	private function assetVersion(string $relativePath): string
	{
		$path     = CONFIGOPS_PATH . '/' . $relativePath;
		$modified = is_file($path) ? filemtime($path) : false;

		return false === $modified ? CONFIGOPS_VERSION : CONFIGOPS_VERSION . '-' . $modified;
	}
}
