<?php
/**
 * Convert persistence records into stable review-template data.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Admin;

final class ReviewPresenter
{
	/**
	 * @param list<object> $mutations Stored mutations for the current page.
	 * @param array{total: int, derived: int, redacted: int, not_restorable: int} $summary Whole-session summary.
	 */
	public function present(array $mutations, array $summary, string $noticeCode, string $noticeMessage): ReviewViewModel
	{
		$grouped = array();

		foreach ($mutations as $mutation) {
			$requestId = (string) $mutation->request_id;
			$grouped[$requestId] ??= array();
			$grouped[$requestId][] = $mutation;
		}

		$groups = array();
		foreach (array_values($grouped) as $offset => $requestMutations) {
			$preparedMutations = array();
			foreach ($requestMutations as $mutation) {
				$diff = json_decode((string) $mutation->diff, true);
				$preparedMutations[] = array(
					'mutation'             => $mutation,
					'diff'                 => is_array($diff) ? $diff : array(),
					'classification_label' => $this->classificationLabel((string) $mutation->classification),
				);
			}

			$groups[] = array(
				'index'      => str_pad((string) ($offset + 1), 2, '0', STR_PAD_LEFT),
				'request_id' => (string) $requestMutations[0]->request_id,
				'head'       => $requestMutations[0],
				'mutations'  => $preparedMutations,
			);
		}

		$totalCount = $summary['total'];

		return new ReviewViewModel(
			$groups,
			$totalCount,
			$totalCount - $summary['derived'],
			$summary['derived'],
			$summary['redacted'],
			$totalCount > 0 && 0 === $summary['not_restorable'],
			$noticeCode,
			$this->noticeText($noticeCode, $noticeMessage)
		);
	}

	public function noticeText(string $code, string $message): string
	{
		return match ($code) {
			'started' => __('Capture started. Make the configuration change in WordPress, then return here to review it.', 'configops'),
			'stopped' => __('Capture stopped. The recorded request groups are ready for review.', 'configops'),
			'nothing-to-stop' => __('There was no active capture to stop.', 'configops'),
			'mutation-restored' => __('The option was restored to its captured previous state.', 'configops'),
			'session-restored' => sprintf(
				/* translators: %d: number of restored options. */
				_n('%d option was restored.', '%d options were restored.', (int) $message, 'configops'),
				(int) $message
			),
			'error' => $message,
			default => '',
		};
	}

	private function classificationLabel(string $classification): string
	{
		return match ($classification) {
			'derived' => __('Likely derived', 'configops'),
			'portable' => __('Portable', 'configops'),
			'environment' => __('Environment-specific', 'configops'),
			'secret' => __('Secret', 'configops'),
			'destructive' => __('Potentially destructive', 'configops'),
			'unknown' => __('Unknown', 'configops'),
			default => ucwords(str_replace(array('-', '_'), ' ', $classification)),
		};
	}
}
