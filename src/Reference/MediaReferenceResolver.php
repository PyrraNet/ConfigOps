<?php
/**
 * Bounded attachment identity snapshots and current review previews.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Reference;

final class MediaReferenceResolver implements ReferenceResolver
{
	private const TYPE = 'media';
	private const MAX_TEXT_LENGTH = 255;

	public function type(): string
	{
		return self::TYPE;
	}

	public function snapshot(mixed $value): ?array
	{
		$id = $this->attachmentId($value);
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

		$attachment = get_post($id);
		if (! $this->isUsableAttachment($attachment)) {
			return $snapshot;
		}

		$metadata = wp_get_attachment_metadata($id);
		$metadata = is_array($metadata) ? $metadata : array();
		$attached = (string) get_post_meta($id, '_wp_attached_file', true);
		$filename = '' !== $attached ? wp_basename($attached) : '';
		if ('' === $filename && is_string($metadata['file'] ?? null)) {
			$filename = wp_basename($metadata['file']);
		}

		$snapshot['status']   = 'available';
		$snapshot['title']    = $this->boundedText((string) $attachment->post_title);
		$snapshot['filename'] = $this->boundedText($filename);
		$snapshot['mime']     = $this->boundedText((string) $attachment->post_mime_type, 100);
		foreach (array('width', 'height', 'filesize') as $key) {
			$number = filter_var($metadata[$key] ?? null, FILTER_VALIDATE_INT);
			if (false !== $number && $number >= 0) {
				$snapshot[$key] = (int) $number;
			}
		}

		return $snapshot;
	}

	public function present(array $snapshot): array
	{
		$id = (int) ($snapshot['id'] ?? 0);
		$status = $id <= 0 ? 'unset' : 'missing';
		$previewUrl = '';
		if ($id > 0 && $this->isUsableAttachment(get_post($id))) {
			$preview = wp_get_attachment_image_url($id, 'thumbnail');
			$status = 'available';
			$previewUrl = is_string($preview) ? esc_url_raw($preview, array('http', 'https')) : '';
		}

		$snapshot['current_status'] = $status;
		$snapshot['preview_url'] = $previewUrl;

		return $snapshot;
	}

	public function isAvailable(array $snapshot): bool
	{
		$id = (int) ($snapshot['id'] ?? 0);

		return $id <= 0
			|| ('available' === ($snapshot['status'] ?? null) && $this->isUsableAttachment(get_post($id)));
	}

	private function attachmentId(mixed $value): ?int
	{
		if (is_int($value)) {
			return max(0, $value);
		}
		if (is_string($value) && 1 === preg_match('/^\d+$/', $value)) {
			$value = (int) $value;

			return max(0, $value);
		}

		return null;
	}

	private function isUsableAttachment(mixed $attachment): bool
	{
		return $attachment instanceof \WP_Post
			&& 'attachment' === $attachment->post_type
			&& ! in_array($attachment->post_status, array('trash', 'auto-draft'), true);
	}

	private function boundedText(string $value, int $limit = self::MAX_TEXT_LENGTH): string
	{
		$value = sanitize_text_field($value);

		return function_exists('mb_substr') ? mb_substr($value, 0, $limit, 'UTF-8') : substr($value, 0, $limit);
	}
}
