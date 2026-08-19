<?php
/**
 * Read-only REST boundary for the current Network Admin evidence scope.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Api;

use ConfigOps\Admin\AdminPayloadFactory;
use ConfigOps\Admin\NetworkAdminPayloadFactory;
use ConfigOps\Database\CaptureRepository;
use ConfigOps\Multisite\NetworkScope;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final readonly class NetworkRestController
{
	public function __construct(
		private CaptureRepository $captures,
		private NetworkAdminPayloadFactory $payloads,
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
