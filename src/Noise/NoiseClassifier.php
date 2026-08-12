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
			return array(
				'classification' => 'derived',
				'reason'         => 'WordPress transient or runtime cache.',
			);
		}

		if (1 === preg_match('/(^|_)(cache|cached|lock|heartbeat|last_checked|update_status)(_|$)/i', $optionName)) {
			return array(
				'classification' => 'derived',
				'reason'         => 'Likely cache, lock, or runtime status value.',
			);
		}

		if (in_array($optionName, array('cron', 'rewrite_rules', 'update_core', 'update_plugins', 'update_themes'), true)) {
			return array(
				'classification' => 'derived',
				'reason'         => 'Known WordPress-generated runtime state.',
			);
		}

		return array(
			'classification' => 'unknown',
			'reason'         => 'Not covered by a trusted adapter or noise rule yet.',
		);
	}
}
