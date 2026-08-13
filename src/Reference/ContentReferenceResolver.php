<?php
/**
 * Bounded identity snapshots for WordPress posts, pages, and custom content.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Reference;

final class ContentReferenceResolver implements ReferenceResolver
{
	private const TYPE = 'content';
	private const MAX_TEXT_LENGTH = 255;

	public function type(): string
	{
		return self::TYPE;
	}

	public function snapshot(mixed $value): ?array
	{
		$id = $this->contentId($value);
		if (null === $id) {
			return null;
		}
		$snapshot = array(
			'type'   => self::TYPE,
			'id'     => $id,
			'status' => 0 === $id ? 'unset' : 'missing',
		);
		if (0 === $id) {
			return $snapshot;
		}

		$content = get_post($id);
		if (! $this->isUsableContent($content)) {
			return $snapshot;
		}

		$type = get_post_type_object($content->post_type);
		$snapshot['status']      = 'available';
		$snapshot['title']       = $this->boundedText((string) $content->post_title);
		$snapshot['post_type']   = $this->boundedText((string) $content->post_type, 100);
		$snapshot['type_label']  = $this->boundedText((string) ($type->labels->singular_name ?? $content->post_type), 100);
		$snapshot['post_status'] = $this->boundedText((string) $content->post_status, 50);

		return $snapshot;
	}

	public function present(array $snapshot): array
	{
		$id = (int) ($snapshot['id'] ?? 0);
		$snapshot['current_status'] = $id <= 0
			? 'unset'
			: ($this->isUsableContent(get_post($id)) ? 'available' : 'missing');

		return $snapshot;
	}

	public function isAvailable(array $snapshot): bool
	{
		$id = (int) ($snapshot['id'] ?? 0);

		return $id <= 0
			|| ('available' === ($snapshot['status'] ?? null) && $this->isUsableContent(get_post($id)));
	}

	private function contentId(mixed $value): ?int
	{
		if (is_int($value)) {
			return max(0, $value);
		}
		if (is_string($value) && 1 === preg_match('/^\d+$/', $value)) {
			return max(0, (int) $value);
		}

		return null;
	}

	private function isUsableContent(mixed $content): bool
	{
		return $content instanceof \WP_Post
			&& 'attachment' !== $content->post_type
			&& ! in_array($content->post_status, array('trash', 'auto-draft'), true);
	}

	private function boundedText(string $value, int $limit = self::MAX_TEXT_LENGTH): string
	{
		$value = sanitize_text_field($value);

		return function_exists('mb_substr') ? mb_substr($value, 0, $limit, 'UTF-8') : substr($value, 0, $limit);
	}
}
