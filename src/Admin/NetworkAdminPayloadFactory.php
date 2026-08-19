<?php
/**
 * Read-only payload contract for the current Network Admin workspace.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Admin;

use ConfigOps\Multisite\NetworkScope;

final readonly class NetworkAdminPayloadFactory
{
	public function __construct(
		private AdminPayloadFactory $payloads,
		private NetworkScope $scope
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function state(?int $sessionId = null, bool $includeReview = true): array
	{
		$state = $this->payloads->state($sessionId, '', '', $includeReview);
		$state['capabilities'] = array(
			'capture'  => false,
			'rollback' => false,
		);
		$state['scope'] = $this->scopePayload();

		return $state;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function mutationPage(int $sessionId, int $afterId, int $limit): array
	{
		return $this->payloads->mutationPage($sessionId, $afterId, $limit);
	}

	/**
	 * @return array{type: string, networkId: int, name: string, siteCount: int}
	 */
	private function scopePayload(): array
	{
		$name = get_network_option($this->scope->networkId(), 'site_name', '');
		$count = get_sites(
			array(
				'network_id' => $this->scope->networkId(),
				'count'      => true,
			)
		);

		return array(
			'type'      => 'network',
			'networkId' => $this->scope->networkId(),
			'name'      => is_string($name) && '' !== trim($name) ? $name : __('WordPress network', 'configops'),
			'siteCount' => is_numeric($count) ? max(0, (int) $count) : 0,
		);
	}
}
