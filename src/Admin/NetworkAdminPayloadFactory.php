<?php
/**
 * Scoped payload contract for the current Network Admin workspace.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Admin;

use ConfigOps\Multisite\NetworkScope;
use ConfigOps\Restore\NetworkRestorePolicy;

final readonly class NetworkAdminPayloadFactory
{
	public function __construct(
		private AdminPayloadFactory $payloads,
		private NetworkScope $scope,
		private NetworkRestorePolicy $restorePolicy
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function state(?int $sessionId = null, bool $includeReview = true, string $noticeCode = ''): array
	{
		$state = $this->payloads->state($sessionId, $noticeCode, '', $includeReview);
		$state['capabilities'] = array(
			'capture'         => false,
			'rollback'        => current_user_can('manage_network_options'),
			'sessionRollback' => false,
		);
		$state['review'] = $this->networkReview($state['review']);
		$state['scope'] = $this->scopePayload();

		return $state;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function mutationPage(int $sessionId, int $afterId, int $limit): array
	{
		return $this->networkReview($this->payloads->mutationPage($sessionId, $afterId, $limit));
	}

	/**
	 * Network undo is deliberately mutation-only and requires a complete value.
	 *
	 * @param array<string, mixed> $review
	 * @return array<string, mixed>
	 */
	private function networkReview(array $review): array
	{
		if (! isset($review['groups']) || ! is_array($review['groups'])) {
			return $review;
		}
		foreach ($review['groups'] as &$group) {
			if (! isset($group['mutations']) || ! is_array($group['mutations'])) {
				continue;
			}
			foreach ($group['mutations'] as &$mutation) {
				$policyAllowsRestore = $this->restorePolicy->allows((string) ($mutation['optionName'] ?? ''));
				$networkRestorable = true === ($mutation['restorable'] ?? false)
					&& 'full' === (string) ($mutation['restoreMode'] ?? 'none')
					&& in_array((string) ($mutation['type'] ?? ''), array('add', 'update'), true)
					&& $policyAllowsRestore;
				$mutation['restorable'] = $networkRestorable;
				if (! $networkRestorable) {
					$mutation['restoreMode'] = 'none';
				}
				if (! $policyAllowsRestore) {
					$mutation['undoUnavailableReason'] = __(
						'This network authority, lifecycle, or derived state needs a dedicated WordPress command. ConfigOps keeps the evidence but will not replace it as a raw option.',
						'configops'
					);
				}
			}
			unset($mutation);
		}
		unset($group);

		return $review;
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
