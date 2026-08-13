<?php
/**
 * Prepared, immutable data for the capture review template.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Admin;

final readonly class ReviewViewModel
{
	/**
	 * @param list<array{
	 *   index: string,
	 *   request_id: string,
	 *   head: object,
	 *   title: string,
	 *   intent: ?array<string, mixed>,
	 *   mutations: list<array{mutation: object, diff: list<array<string, mixed>>, classification_label: string, adapter: ?array<string, mixed>}>
	 * }> $groups Prepared request groups.
	 */
	public function __construct(
		public array $groups,
		public int $totalCount,
		public int $needsReviewCount,
		public int $derivedCount,
		public int $redactedCount,
		public bool $allRestorable,
		public string $noticeCode,
		public string $noticeText
	) {
	}
}
