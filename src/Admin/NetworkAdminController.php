<?php
/**
 * Evidence and reviewed mutation undo inside WordPress Network Admin.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Admin;

use ConfigOps\Capture\IntentContext;
use ConfigOps\Command\NetworkCaptureCommands;
use ConfigOps\Database\CaptureRepository;
use ConfigOps\Multisite\NetworkScope;
use Throwable;
use WP_Admin_Bar;

final readonly class NetworkAdminController
{
	private const PAGE = 'configops';

	public function __construct(
		private CaptureRepository $captures,
		private NetworkCaptureCommands $commands,
		private NetworkAdminPayloadFactory $payloads,
		private NetworkScope $scope
	) {
	}

	public function register(): void
	{
		add_action('network_admin_menu', array($this, 'addMenu'));
		add_action('admin_enqueue_scripts', array($this, 'enqueueAssets'));
		add_action('admin_bar_menu', array($this, 'addToolbarNode'), 100);
		add_action('admin_post_configops_stop_network_capture', array($this, 'stopCapture'));
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
		if (! $isConfigOps && null !== $this->captures->activeId()) {
			$this->enqueueStyles();
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
					'sessionId' => $this->captures->activeId() ?? 0,
					'cookieName' => IntentContext::COOKIE_NAME,
				)
			);
			if (is_string($settings)) {
				wp_add_inline_script('configops-intent-observer', 'window.configOpsIntent = ' . $settings . ';', 'before');
			}
		}
	}

	public function addToolbarNode(WP_Admin_Bar $adminBar): void
	{
		if (! is_network_admin() || ! $this->scope->isCurrent() || ! current_user_can('manage_network_options')) {
			return;
		}

		$session = $this->captures->activeSession();
		if (! $session) {
			return;
		}
		$reviewChangeCount = (int) ($session->review_change_count ?? 0);
		$technicalChangeCount = (int) ($session->technical_change_count ?? 0);
		if ((int) $session->mutation_count > 0 && 0 === $reviewChangeCount + $technicalChangeCount) {
			$reviewChangeCount = (int) $session->mutation_count;
		}

		$adminBar->add_node(
			array(
				'id'    => 'configops-recording',
				'title' => sprintf(
					'<span class="configops-recording-dot" aria-hidden="true"></span><span class="screen-reader-text">%s: </span>%s <span class="configops-recording-count">%d</span>',
					esc_html__('ConfigOps network recording', 'configops'),
					esc_html__('CONFIGOPS RECORDING', 'configops'),
					$reviewChangeCount
				),
				'href'  => network_admin_url('admin.php?page=' . self::PAGE),
				'meta'  => array('class' => 'configops-toolbar-recording'),
			)
		);
	}

	public function render(): void
	{
		if (! $this->scope->isCurrent() || ! current_user_can('manage_network_options')) {
			wp_die(esc_html__('You are not allowed to view ConfigOps network evidence.', 'configops'));
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only capture selection.
		$requested = isset($_GET['session']) ? absint($_GET['session']) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Fixed notice codes only; no state change.
		$notice = isset($_GET['configops_notice']) ? sanitize_key(wp_unslash($_GET['configops_notice'])) : '';
		$notice = in_array($notice, array('stopped', 'nothing-to-stop', 'error'), true) ? $notice : '';
		$bootstrap = $this->payloads->state($requested > 0 ? $requested : null, false, $notice);
		$view = 'network';

		include CONFIGOPS_PATH . '/templates/admin-page.php';
	}

	public function stopCapture(): void
	{
		if (! $this->scope->isCurrent() || ! current_user_can('manage_network_options')) {
			wp_die(esc_html__('You are not allowed to stop this ConfigOps network capture.', 'configops'));
		}
		check_admin_referer('configops_stop_network_capture');

		try {
			$id = $this->commands->stop();
			$notice = null === $id ? 'nothing-to-stop' : 'stopped';
			$url = add_query_arg(
				array_filter(
					array(
						'page'             => self::PAGE,
						'session'          => $id,
						'configops_notice' => $notice,
					),
					static fn (mixed $value): bool => null !== $value
				),
				network_admin_url('admin.php')
			);
		} catch (Throwable) {
			$url = add_query_arg(
				array('page' => self::PAGE, 'configops_notice' => 'error'),
				network_admin_url('admin.php')
			);
		}

		wp_safe_redirect($url);
		exit;
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
