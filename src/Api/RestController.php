<?php
/**
 * Capability-gated local Agent API for the React admin islands.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Api;

use ConfigOps\Admin\AdminPayloadFactory;
use ConfigOps\Admin\EvidenceNoticeStore;
use ConfigOps\Capture\AutomaticRecorder;
use ConfigOps\Database\CaptureRepository;
use ConfigOps\Database\MutationRepository;
use ConfigOps\Restore\RestoreService;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class RestController
{
	private const NAMESPACE = 'configops/v1';

	public function __construct(
		private readonly CaptureRepository $captures,
		private readonly MutationRepository $mutations,
		private readonly RestoreService $restore,
		private readonly AdminPayloadFactory $payloads,
		private readonly ?EvidenceNoticeStore $evidenceNotices = null,
		private readonly ?AutomaticRecorder $automatic = null
	) {
	}

	public function register(): void
	{
		add_action('rest_api_init', array($this, 'registerRoutes'));
	}

	public function registerRoutes(): void
	{
		$this->registerRoute(
			'/state',
			WP_REST_Server::READABLE,
			'state',
			'configops_view',
			array('session' => array('type' => 'integer', 'minimum' => 1))
		);

		if (null !== $this->evidenceNotices) {
			$this->registerRoute(
				'/evidence',
				WP_REST_Server::READABLE,
				'evidence',
				'configops_view'
			);
		}

		$this->registerRoute(
			'/captures/(?P<id>\d+)/mutations',
			WP_REST_Server::READABLE,
			'mutations',
			'configops_view',
			array(
				'id'    => array('type' => 'integer', 'minimum' => 1, 'required' => true),
				'after' => array('type' => 'integer', 'minimum' => 0, 'default' => 0),
				'limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 100),
			)
		);

		$this->registerRoute(
			'/captures',
			WP_REST_Server::CREATABLE,
			'startCapture',
			'configops_capture',
			array('name' => array('type' => 'string', 'maxLength' => 191))
		);

		$this->registerRoute(
			'/captures/active/stop',
			WP_REST_Server::CREATABLE,
			'stopCapture',
			'configops_capture'
		);

		$this->registerRoute(
			'/mutations/(?P<id>\d+)/restore',
			WP_REST_Server::CREATABLE,
			'restoreMutation',
			'configops_rollback',
			array('id' => array('type' => 'integer', 'minimum' => 1, 'required' => true))
		);

		$this->registerRoute(
			'/captures/(?P<id>\d+)/restore',
			WP_REST_Server::CREATABLE,
			'restoreSession',
			'configops_rollback',
			array('id' => array('type' => 'integer', 'minimum' => 1, 'required' => true))
		);
	}

	/**
	 * @param array<string, array<string, bool|int|string>> $args
	 */
	private function registerRoute(string $path, string $methods, string $callback, string $capability, array $args = array()): void
	{
		$route = array(
			'methods'             => $methods,
			'callback'            => array($this, $callback),
			'permission_callback' => static fn (): bool => current_user_can($capability),
		);
		if (! empty($args)) {
			$route['args'] = $args;
		}

		register_rest_route(self::NAMESPACE, $path, $route);
	}

	public function state(WP_REST_Request $request): WP_REST_Response
	{
		$sessionId = $request->has_param('session') ? (int) $request['session'] : null;

		return $this->response($this->payloads->state($sessionId));
	}

	public function mutations(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$sessionId = (int) $request['id'];
		$session   = $this->captures->find($sessionId);
		if (! $session) {
			return new WP_Error('configops_capture_not_found', __('The capture session no longer exists.', 'configops'), array('status' => 404));
		}

		return $this->response(
			$this->payloads->mutationPage($sessionId, (int) $request['after'], (int) $request['limit'])
		);
	}

	public function evidence(): WP_REST_Response
	{
		$sessionIds = null === $this->evidenceNotices
			? array()
			: $this->evidenceNotices->pull(get_current_user_id());

		return $this->response(array('items' => $this->payloads->evidence($sessionIds)));
	}

	public function startCapture(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$name = sanitize_text_field((string) $request['name']);
		if ('' === $name) {
			$name = sprintf(
				/* translators: %s: UTC date and time. */
				__('Capture %s', 'configops'),
				gmdate('Y-m-d H:i')
			);
		}

		return $this->command(
			function () use ($name): array {
				$id = $this->captures->start($name, get_current_user_id(), wp_get_referer() ?: admin_url());

				return $this->payloads->state($id, 'started');
			}
		);
	}

	public function stopCapture(): WP_REST_Response|WP_Error
	{
		return $this->command(
			function (): array {
				$id = $this->captures->stop();

				return $this->payloads->state($id, null === $id ? 'nothing-to-stop' : 'stopped');
			}
		);
	}

	public function restoreMutation(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$mutation = $this->mutations->find((int) $request['id']);
		if (! $mutation) {
			return new WP_Error('configops_mutation_not_found', __('The mutation no longer exists.', 'configops'), array('status' => 404));
		}

		return $this->command(
			function () use ($mutation): array {
				$this->restore->restoreMutation((int) $mutation->id);

				return $this->payloads->state((int) $mutation->session_id, 'mutation-restored');
			}
		);
	}

	public function restoreSession(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$sessionId = (int) $request['id'];

		return $this->command(
			function () use ($sessionId): array {
				$count = $this->restore->restoreSession($sessionId);

				return $this->payloads->state($sessionId, 'session-restored', (string) $count);
			}
		);
	}

	/**
	 * @param callable(): array<string, mixed> $operation Operation.
	 */
	private function command(callable $operation): WP_REST_Response|WP_Error
	{
		$this->automatic?->suppress();

		try {
			return $this->response($operation());
		} catch (Throwable $error) {
			return new WP_Error(
				'configops_operation_failed',
				$error->getMessage(),
				array('status' => 409)
			);
		}
	}

	/**
	 * @param array<string, mixed> $data Response payload.
	 */
	private function response(array $data): WP_REST_Response
	{
		$response = new WP_REST_Response($data, 200);
		$response->header('Cache-Control', 'private, no-store');
		$response->header('X-Content-Type-Options', 'nosniff');

		return $response;
	}
}
