<?php
/**
 * Conservative first-pass noise classification.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Noise;

final class NoiseClassifier implements MutationClassifier
{
	/**
	 * @return array{classification: string, reason: string}
	 */
	public function classify(string $optionName): array
	{
		if (str_starts_with($optionName, '_transient_') || str_starts_with($optionName, '_site_transient_')) {
			return $this->derived('WordPress transient or runtime cache.');
		}

		if (str_starts_with($optionName, 'action_scheduler_') || str_starts_with($optionName, 'as_has_')) {
			return $this->derived('Action Scheduler generated queue or migration state.');
		}

		if (in_array($optionName, array('update_comment_type.lock', 'finished_updating_comment_type'), true)) {
			return $this->derived('WordPress comment-type migration lock or completion state.');
		}

		if (1 === preg_match('/(^|_)(cache|cached|lock|heartbeat|last_checked|update_status)(_|$)/i', $optionName)) {
			return $this->derived('Likely cache, lock, or runtime status value.');
		}

		if (in_array($optionName, array('cron', 'recently_activated', 'recovery_keys', 'rewrite_rules', 'update_core', 'update_plugins', 'update_themes'), true)) {
			return $this->derived('Known WordPress-generated runtime state.');
		}

		return array(
			'classification' => 'unknown',
			'reason'         => 'Not covered by a trusted adapter or noise rule yet.',
		);
	}

	/**
	 * @return array{classification: string, reason: string}
	 */
	private function derived(string $reason): array
	{
		return array('classification' => 'derived', 'reason' => $reason);
	}
}
