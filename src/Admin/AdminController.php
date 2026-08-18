<?php
/**
 * WordPress administration surface for the recorder spike.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Admin;

use ConfigOps\Api\RestRoutes;
use ConfigOps\Capture\IntentContext;
use ConfigOps\Command\CaptureCommands;
use ConfigOps\Database\CaptureRepository;
use ConfigOps\Multisite\SiteBoundaryGuard;
use ConfigOps\Multisite\SiteScope;
use Throwable;
use WP_Admin_Bar;

final class AdminController
{
	private const PAGE = 'configops';
	private readonly SiteBoundaryGuard $siteBoundary;

	public function __construct(
		private readonly CaptureRepository $captures,
		private readonly CaptureCommands $commands,
		private readonly FlashNoticeStore $notices,
		private readonly AdminPayloadFactory $payloads,
		?SiteBoundaryGuard $siteBoundary = null
	) {
		$this->siteBoundary = $siteBoundary ?? new SiteBoundaryGuard(SiteScope::current(), $captures);
	}

	public function register(): void
	{
		add_action('admin_menu', array($this, 'addMenu'));
		add_action('admin_enqueue_scripts', array($this, 'enqueueAdminAssets'));
		add_action('wp_enqueue_scripts', array($this, 'enqueueToolbarAssets'));
		add_action('admin_bar_menu', array($this, 'addToolbarNode'), 100);
		add_action('admin_post_configops_start_capture', array($this, 'startCapture'));
		add_action('admin_post_configops_stop_capture', array($this, 'stopCapture'));
		add_action('admin_post_configops_restore_mutation', array($this, 'restoreMutation'));
		add_action('admin_post_configops_restore_session', array($this, 'restoreSession'));
		add_filter('script_loader_tag', array($this, 'moduleScriptTag'), 10, 3);
	}

	public function addMenu(): void
	{
		if (! $this->siteBoundary->isCurrentSite()) {
			return;
		}

		add_menu_page(
			__('ConfigOps', 'configops'),
			__('ConfigOps', 'configops'),
			'configops_view',
			self::PAGE,
			array($this, 'render'),
			'dashicons-controls-repeat',
			58
		);
	}

	public function enqueueAdminAssets(string $hookSuffix): void
	{
		if (! $this->siteBoundary->isCurrentSite()) {
			return;
		}

		$activeId = $this->captures->activeId();
		$isConfigOps = 'toplevel_page_' . self::PAGE === $hookSuffix;
		$canObserve = current_user_can('configops_capture');
		if (! $isConfigOps && ! $canObserve) {
			return;
		}

		$this->enqueueStyles();
		if ($canObserve) {
			wp_enqueue_script(
				'configops-intent-observer',
				CONFIGOPS_URL . 'assets/intent-observer.js',
				array(),
				$this->assetVersion('assets/intent-observer.js'),
				true
			);
			$settings = wp_json_encode(
				array(
					'sessionId' => $activeId ?? 0,
					'cookieName' => IntentContext::COOKIE_NAME,
				)
			);
			if (is_string($settings)) {
				wp_add_inline_script('configops-intent-observer', 'window.configOpsIntent = ' . $settings . ';', 'before');
			}

			wp_enqueue_script(
				'configops-automatic-feedback',
				CONFIGOPS_URL . 'assets/automatic-feedback.js',
				array('wp-i18n'),
				$this->assetVersion('assets/automatic-feedback.js'),
				true
			);
			wp_set_script_translations('configops-automatic-feedback', 'configops');
			$feedback = wp_json_encode(
				array(
					'endpoint'            => rest_url(RestRoutes::NAMESPACE . '/evidence'),
					'acknowledgeEndpoint' => rest_url(RestRoutes::NAMESPACE . '/evidence/acknowledge'),
					'nonce'               => wp_create_nonce('wp_rest'),
				)
			);
			if (is_string($feedback)) {
				wp_add_inline_script('configops-automatic-feedback', 'window.configOpsFeedback = ' . $feedback . ';', 'before');
			}
		}

		if ($isConfigOps) {
			wp_enqueue_script(
				'configops-runtime',
				CONFIGOPS_URL . 'assets/ui/runtime.js',
				array('wp-element', 'wp-api-fetch', 'wp-i18n'),
				$this->assetVersion('assets/ui/runtime.js'),
				true
			);
			wp_set_script_translations('configops-runtime', 'configops');
		}
	}

	public function enqueueToolbarAssets(): void
	{
		if (! $this->siteBoundary->isCurrentSite()) {
			return;
		}

		if (is_admin_bar_showing() && null !== $this->captures->activeId()) {
			$this->enqueueStyles();
		}
	}

	public function addToolbarNode(WP_Admin_Bar $adminBar): void
	{
		if (! $this->siteBoundary->isCurrentSite() || ! current_user_can('configops_capture')) {
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
					esc_html__('ConfigOps recording', 'configops'),
					esc_html__('CONFIGOPS RECORDING', 'configops'),
					$reviewChangeCount
				),
				'href'  => admin_url('admin.php?page=' . self::PAGE),
				'meta'  => array('class' => 'configops-toolbar-recording'),
			)
		);
	}

	public function render(): void
	{
		$this->siteBoundary->assertCurrentSite();
		if (! current_user_can('configops_view')) {
			wp_die(esc_html__('You are not allowed to view ConfigOps.', 'configops'));
		}

		// Read-only navigation does not require a nonce.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$view = isset($_GET['view']) ? sanitize_key(wp_unslash($_GET['view'])) : 'review';
		$view = 'support' === $view ? 'support' : 'review';
		if ('support' === $view) {
			$bootstrap = $this->payloads->support();
		} else {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only capture selection.
			$requested = isset($_GET['session']) ? absint($_GET['session']) : 0;
			$flash     = $this->notices->pull();
			$bootstrap = $this->payloads->state(
				$requested > 0 ? $requested : null,
				$flash['code'],
				$flash['message'],
				false
			);
		}
		$bootstrap['view'] = $view;

		include CONFIGOPS_PATH . '/templates/admin-page.php';
	}

	public function moduleScriptTag(string $tag, string $handle, string $src): string
	{
		unset($src);

		if ('configops-runtime' !== $handle) {
			return $tag;
		}

		if (preg_match('/\stype=(["\']).*?\1/', $tag)) {
			$moduleTag = preg_replace('/\stype=(["\']).*?\1/', ' type="module"', $tag, 1);

			return is_string($moduleTag) ? $moduleTag : $tag;
		}

		return str_replace('<script ', '<script type="module" ', $tag);
	}

	public function startCapture(): void
	{
		$this->authorize('configops_capture');
		check_admin_referer('configops_start_capture');

		$name = isset($_POST['capture_name']) ? sanitize_text_field(wp_unslash($_POST['capture_name'])) : '';

		try {
			$id = $this->commands->start($name);
			$this->redirect('started', '', $id);
		} catch (Throwable $error) {
			$this->redirect('error', $error->getMessage());
		}
	}

	public function stopCapture(): void
	{
		$this->authorize('configops_capture');
		check_admin_referer('configops_stop_capture');

		try {
			$id = $this->commands->stop();
			$this->redirect(null === $id ? 'nothing-to-stop' : 'stopped', '', $id);
		} catch (Throwable $error) {
			$this->redirect('error', $error->getMessage());
		}
	}

	public function restoreMutation(): void
	{
		$this->authorize('configops_rollback');
		check_admin_referer('configops_restore_mutation');
		$id = isset($_POST['mutation_id']) ? absint($_POST['mutation_id']) : 0;

		try {
			$this->commands->restoreMutation($id);
			$this->redirect('mutation-restored');
		} catch (Throwable $error) {
			$this->redirect('error', $error->getMessage());
		}
	}

	public function restoreSession(): void
	{
		$this->authorize('configops_rollback');
		check_admin_referer('configops_restore_session');
		$id = isset($_POST['session_id']) ? absint($_POST['session_id']) : 0;

		try {
			$count = $this->commands->restoreSession($id);
			$this->redirect('session-restored', (string) $count, $id);
		} catch (Throwable $error) {
			$this->redirect('error', $error->getMessage(), $id);
		}
	}

	private function authorize(string $capability): void
	{
		if (! current_user_can($capability)) {
			wp_die(esc_html__('You are not allowed to perform this ConfigOps action.', 'configops'));
		}
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

	private function redirect(string $notice, string $message = '', ?int $sessionId = null): never
	{
		$this->notices->put($notice, $message);
		$args = array('page' => self::PAGE);
		if (null !== $sessionId) {
			$args['session'] = $sessionId;
		}

		wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
		exit;
	}
}
