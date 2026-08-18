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
		$uri  = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
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

	/**
	 * Build the shared, value-free evidence metadata persisted for observations.
	 *
	 * @param array{type: string, component: string, file: string, line: int} $source Captured source attribution.
	 * @return array{
	 *   request_id: string,
	 *   source_type: string,
	 *   source_component: string,
	 *   source_file: string,
	 *   source_line: int,
	 *   request_method: string,
	 *   request_uri: string,
	 *   admin_screen: string,
	 *   actor_id: int,
	 *   occurred_at: string
	 * }
	 */
	public function evidenceMetadata(array $source): array
	{
		return array(
			'request_id'       => $this->id(),
			'source_type'      => $source['type'],
			'source_component' => $source['component'],
			'source_file'      => $source['file'],
			'source_line'      => $source['line'],
			'request_method'   => $this->method(),
			'request_uri'      => $this->uri(),
			'admin_screen'     => $this->adminScreen(),
			'actor_id'         => $this->actorId(),
			'occurred_at'      => current_time('mysql', true),
		);
	}
}
