<?php
/**
 * Bounded identity snapshots for WordPress users referenced by configuration.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Reference;

final class UserReferenceResolver implements ReferenceResolver
{
	private const TYPE = 'user';
	private const MAX_TEXT_LENGTH = 255;

	public function type(): string
	{
		return self::TYPE;
	}

	public function snapshot(mixed $value): ?array
	{
		$id = $this->userId($value);
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

		$user = get_userdata($id);
		if (! $user instanceof \WP_User || ! $user->exists()) {
			return $snapshot;
		}

		$snapshot['status'] = 'available';
		$snapshot['display_name'] = $this->boundedText((string) $user->display_name);

		return $snapshot;
	}

	public function present(array $snapshot): array
	{
		$id = (int) ($snapshot['id'] ?? 0);
		$user = $id > 0 ? get_userdata($id) : false;
		$snapshot['current_status'] = $id <= 0
			? 'unset'
			: ($user instanceof \WP_User && $user->exists() ? 'available' : 'missing');

		return $snapshot;
	}

	public function isAvailable(array $snapshot): bool
	{
		$id = (int) ($snapshot['id'] ?? 0);
		$user = $id > 0 ? get_userdata($id) : false;

		return $id <= 0
			|| ('available' === ($snapshot['status'] ?? null) && $user instanceof \WP_User && $user->exists());
	}

	private function userId(mixed $value): ?int
	{
		if (is_int($value)) {
			return max(0, $value);
		}
		if (is_string($value) && 1 === preg_match('/^\d+$/', $value)) {
			return max(0, (int) $value);
		}

		return null;
	}

	private function boundedText(string $value): string
	{
		$value = sanitize_text_field($value);

		return function_exists('mb_substr') ? mb_substr($value, 0, self::MAX_TEXT_LENGTH, 'UTF-8') : substr($value, 0, self::MAX_TEXT_LENGTH);
	}
}
