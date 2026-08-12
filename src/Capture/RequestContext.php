<?php
/**
 * Safe request metadata shared by all mutations in one request.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Capture;

final class RequestContext
{
	private ?string $id = null;

	public function id(): string
	{
		if (null === $this->id) {
			$this->id = wp_generate_uuid4();
		}

		return $this->id;
	}

	public function method(): string
	{
		$method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD'])) : 'unknown';

		return strtoupper(substr($method, 0, 12));
	}

	public function uri(): string
	{
		$uri  = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
		$path = wp_parse_url($uri, PHP_URL_PATH);

		return is_string($path) ? sanitize_text_field($path) : '';
	}

	public function adminScreen(): string
	{
		if (! function_exists('get_current_screen')) {
			return '';
		}

		$screen = get_current_screen();

		return $screen ? sanitize_key($screen->id) : '';
	}

	public function actorId(): int
	{
		return get_current_user_id();
	}
}
