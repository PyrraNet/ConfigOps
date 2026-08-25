<?php
/**
 * Value-free portability warnings for private cross-site Packs.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Pack;

final class PackPortabilityInspector
{
	/**
	 * @param list<array<string, mixed>> $changes Decorated desired-state changes.
	 * @return list<array{code: string, message: string}>
	 */
	public function inspect(mixed $value, array $changes = array()): array
	{
		$codes = array();
		foreach ($changes as $change) {
			$kind = (string) ($change['kind'] ?? '');
			if ('environment' === $kind) {
				$codes['environment'] = 'This value may need to differ between websites or environments.';
			}
			if ('reference' === $kind) {
				$codes['local_reference'] = 'This value points to content, media, or a user on the source website.';
			}
		}

		$this->inspectValue($value, $codes, 0);

		$result = array();
		foreach ($codes as $code => $message) {
			$result[] = array('code' => $code, 'message' => $message);
		}

		return $result;
	}

	/**
	 * @param array<string, string> $codes Warning map.
	 */
	private function inspectValue(mixed $value, array &$codes, int $depth): void
	{
		if ($depth > 32) {
			return;
		}
		if (is_array($value)) {
			foreach ($value as $child) {
				$this->inspectValue($child, $codes, $depth + 1);
			}

			return;
		}
		if (! is_string($value) || '' === trim($value)) {
			return;
		}

		$siteUrl = function_exists('site_url') ? untrailingslashit((string) site_url()) : '';
		$homeUrl = function_exists('home_url') ? untrailingslashit((string) home_url()) : '';
		foreach (array_filter(array_unique(array($siteUrl, $homeUrl))) as $url) {
			if (str_contains($value, $url)) {
				$codes['source_site_url'] = 'This value contains the source website URL. Remove it or replace it after import.';
				break;
			}
		}

		$paths = array();
		if (defined('ABSPATH')) {
			$paths[] = rtrim((string) ABSPATH, '/\\');
		}
		if (defined('WP_CONTENT_DIR')) {
			$paths[] = rtrim((string) WP_CONTENT_DIR, '/\\');
		}
		foreach (array_filter(array_unique($paths)) as $path) {
			if ('' !== $path && str_contains($value, $path)) {
				$codes['absolute_path'] = 'This value contains an absolute path from the source website.';
				break;
			}
		}
		if (1 === preg_match('~^(?:/[A-Za-z0-9._-]+){2,}/?|^[A-Za-z]:\\\\~', trim($value))) {
			$codes['absolute_path'] = 'This value looks like an absolute filesystem path.';
		}
		if (1 === preg_match('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', $value)) {
			$codes['email'] = 'This value contains an email address that may belong to the source website.';
		}
		if (
			! isset($codes['source_site_url'])
			&& 1 === preg_match('~https?://[^\s"\'<>]+~i', $value)
		) {
			$codes['external_url'] = 'This value contains an absolute URL. Confirm that it belongs on the destination website.';
		}
	}
}
