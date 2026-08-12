<?php
/**
 * Short-lived, per-user admin notices that do not leak errors into URLs.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Admin;

final class FlashNoticeStore
{
	private const LIFETIME = 60;

	public function put(string $code, string $message = ''): void
	{
		set_transient(
			$this->key(),
			array(
				'code'    => sanitize_key($code),
				'message' => sanitize_text_field($message),
			),
			self::LIFETIME
		);
	}

	/**
	 * @return array{code: string, message: string}
	 */
	public function pull(): array
	{
		$key   = $this->key();
		$value = get_transient($key);
		delete_transient($key);

		if (! is_array($value)) {
			return array('code' => '', 'message' => '');
		}

		return array(
			'code'    => sanitize_key((string) ($value['code'] ?? '')),
			'message' => sanitize_text_field((string) ($value['message'] ?? '')),
		);
	}

	private function key(): string
	{
		return 'configops_flash_' . get_current_user_id();
	}
}
