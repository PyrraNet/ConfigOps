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
	private const MAX_TEXT_LENGTH = 255;

	public function type(): string
	{
		return 'media';
	}

	public function snapshot(mixed $value): ?array
	{
		$id = $this->attachmentId($value);
		if (null === $id) {
			return null;
		}
		if (0 === $id) {
			return array(
				'type'   => $this->type(),
				'id'     => 0,
				'status' => 'unset',
			);
		}

		$attachment = get_post($id);
		if (! $this->isUsableAttachment($attachment)) {
			return array(
				'type'   => $this->type(),
				'id'     => $id,
				'status' => 'missing',
			);
		}

		$metadata = wp_get_attachment_metadata($id);
		$metadata = is_array($metadata) ? $metadata : array();
		$attached = (string) get_post_meta($id, '_wp_attached_file', true);
		$filename = '' !== $attached ? wp_basename($attached) : '';
		if ('' === $filename && is_string($metadata['file'] ?? null)) {
			$filename = wp_basename($metadata['file']);
		}

		$snapshot = array(
			'type'     => $this->type(),
			'id'       => $id,
			'status'   => 'available',
			'title'    => $this->boundedText((string) $attachment->post_title),
			'filename' => $this->boundedText($filename),
			'mime'     => $this->boundedText((string) $attachment->post_mime_type, 100),
		);
		foreach (array('width', 'height', 'filesize') as $key) {
			$value = filter_var($metadata[$key] ?? null, FILTER_VALIDATE_INT);
			if (false !== $value && $value >= 0 && $value <= PHP_INT_MAX) {
				$snapshot[$key] = (int) $value;
			}
		}

		return $snapshot;
	}

	public function present(array $snapshot): array
	{
		$id = (int) ($snapshot['id'] ?? 0);
		if ($id <= 0) {
			$snapshot['current_status'] = 'unset';
			$snapshot['preview_url'] = '';

			return $snapshot;
		}

		$attachment = get_post($id);
		if (! $this->isUsableAttachment($attachment)) {
			$snapshot['current_status'] = 'missing';
			$snapshot['preview_url'] = '';

			return $snapshot;
		}

		$preview = wp_get_attachment_image_url($id, 'thumbnail');
		$snapshot['current_status'] = 'available';
		$snapshot['preview_url'] = is_string($preview) ? esc_url_raw($preview, array('http', 'https')) : '';

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
