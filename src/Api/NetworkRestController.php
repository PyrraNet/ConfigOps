<?php
/**
 * REST boundary for evidence and reviewed undo in the current network scope.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Api;

use ConfigOps\Admin\AdminPayloadFactory;
use ConfigOps\Admin\NetworkAdminPayloadFactory;
use ConfigOps\Command\NetworkCaptureCommands;
use ConfigOps\Database\CaptureRepository;
use ConfigOps\Database\MutationRepository;
use ConfigOps\Multisite\NetworkScope;
use ConfigOps\Restore\NetworkRestoreService;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final readonly class NetworkRestController
{
	public function __construct(
		private CaptureRepository $captures,
		private MutationRepository $mutations,
		private NetworkCaptureCommands $commands,
		private NetworkAdminPayloadFactory $payloads,
		private NetworkRestoreService $restore,
		private NetworkScope $scope
	) {
	}

	public function register(): void
	{
		add_action('rest_api_init', array($this, 'registerRoutes'));
	}

	public function registerRoutes(): void
	{
		register_rest_route(
			RestRoutes::NAMESPACE,
			'/network/state',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array($this, 'state'),
				'permission_callback' => array($this, 'authorized'),
				'args'                => array('session' => array('type' => 'integer', 'minimum' => 1)),
			)
		);
		register_rest_route(
			RestRoutes::NAMESPACE,
			'/network/captures',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'startCapture'),
				'permission_callback' => array($this, 'authorized'),
				'args'                => array('name' => array('type' => 'string', 'maxLength' => 191)),
			)
		);
		register_rest_route(
			RestRoutes::NAMESPACE,
			'/network/captures/active/stop',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'stopCapture'),
				'permission_callback' => array($this, 'authorized'),
			)
		);
		register_rest_route(
			RestRoutes::NAMESPACE,
			'/network/mutations/(?P<id>\d+)/restore',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'restoreMutation'),
				'permission_callback' => array($this, 'authorized'),
				'args'                => array(
					'id' => array('type' => 'integer', 'minimum' => 1, 'required' => true),
				),
			)
		);
		register_rest_route(
			RestRoutes::NAMESPACE,
			'/network/captures/(?P<id>\d+)/mutations',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array($this, 'mutations'),
				'permission_callback' => array($this, 'authorized'),
				'args'                => array(
					'id'    => array('type' => 'integer', 'minimum' => 1, 'required' => true),
					'after' => array('type' => 'integer', 'minimum' => 0, 'default' => 0),
					'limit' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => AdminPayloadFactory::PAGE_SIZE,
						'default' => AdminPayloadFactory::PAGE_SIZE,
					),
				),
			)
		);
	}

	public function authorized(): bool
	{
		return $this->scope->isCurrent() && current_user_can('manage_network_options');
	}

	public function state(WP_REST_Request $request): WP_REST_Response
	{
		$sessionId = $request->has_param('session') ? (int) $request['session'] : null;

		return $this->response($this->payloads->state($sessionId));
	}

	public function mutations(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$sessionId = (int) $request['id'];
		if (! $this->captures->find($sessionId)) {
			return new WP_Error(
				'configops_network_capture_not_found',
				__('The network capture no longer exists.', 'configops'),
				array('status' => 404)
			);
		}

		return $this->response(
			$this->payloads->mutationPage($sessionId, (int) $request['after'], (int) $request['limit'])
		);
	}

	public function startCapture(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$name = sanitize_text_field((string) $request['name']);

		return $this->command(
			function () use ($name): array {
				$id = $this->commands->start($name);

				return $this->payloads->state($id, true, 'started');
			},
			'configops_network_capture_failed'
		);
	}

	public function stopCapture(): WP_REST_Response|WP_Error
	{
		return $this->command(
			function (): array {
				$id = $this->commands->stop();

				return $this->payloads->state($id, true, null === $id ? 'nothing-to-stop' : 'stopped');
			},
			'configops_network_capture_failed'
		);
	}

	public function restoreMutation(WP_REST_Request $request): WP_REST_Response|WP_Error
	{
		$mutation = $this->mutations->find((int) $request['id']);
		if (! $mutation) {
			return new WP_Error(
				'configops_network_mutation_not_found',
				__('The network mutation no longer exists.', 'configops'),
				array('status' => 404)
			);
		}

		return $this->command(
			function () use ($mutation): array {
				$this->restore->restoreMutation((int) $mutation->id);

				return $this->payloads->state((int) $mutation->session_id, true, 'mutation-restored');
			},
			'configops_network_restore_failed'
		);
	}

	/**
	 * @param callable(): array<string, mixed> $operation Operation.
	 * @param string                           $errorCode Stable REST error code.
	 */
	private function command(callable $operation, string $errorCode): WP_REST_Response|WP_Error
	{
		try {
			return $this->response($operation());
		} catch (Throwable $error) {
			return new WP_Error(
				$errorCode,
				$error->getMessage(),
				array('status' => 409)
			);
		}
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function response(array $data): WP_REST_Response
	{
		$response = new WP_REST_Response($data, 200);
		$response->header('Cache-Control', 'private, no-store');
		$response->header('X-Content-Type-Options', 'nosniff');

		return $response;
	}
}
