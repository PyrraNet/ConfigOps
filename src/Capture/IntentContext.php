<?php
/**
 * Correlate value-free browser field evidence with persisted option diffs.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Capture;

final class IntentContext
{
	public const COOKIE_NAME = 'configops_intent';

	private const MAX_COOKIE_BYTES = 3800;
	private const MAX_FIELDS = 20;
	private const MAX_FIELD_DEPTH = 12;
	private const MAX_AGE_SECONDS = 180;

	/** @var array<string, mixed>|null */
	private ?array $context = null;
	private bool $loaded = false;

	/**
	 * Add review-only intent evidence without changing classification or restore
	 * eligibility. A field must belong to the option and match its JSON Pointer;
	 * ambiguous observations are deliberately ignored.
	 *
	 * @param list<array<string, mixed>> $changes Decorated nested diff entries.
	 * @return list<array<string, mixed>>
	 */
	public function enrich(int $sessionId, string $optionName, array $changes): array
	{
		$context = $this->forSession($sessionId);
		if (null === $context || empty($changes)) {
			return $changes;
		}

		foreach ($changes as &$change) {
			$path = is_string($change['path'] ?? null) ? $change['path'] : '/';
			$match = $this->matchField($optionName, $path, $changes, $context['fields']);
			if (null === $match) {
				continue;
			}

			$field = $match['field'];
			$intent = array(
				'field_name'      => $field['name'],
				'label'           => $field['label'],
				'group'           => $field['group'],
				'screen'          => $context['screen'],
				'action'          => $context['action'],
				'confidence'      => $match['confidence'],
				'match_reason'    => $match['reason'],
				'observed_fields' => count($context['fields']),
			);
			$change['intent'] = $intent;

			$kind = is_string($change['kind'] ?? null) ? $change['kind'] : '';
			if ('' !== $kind && 'unknown' !== $kind) {
				continue;
			}

			$label = '' !== $field['label'] ? $field['label'] : $this->humanize($path);
			$group = '' !== $field['group']
				? $field['group']
				: ('' !== $context['screen'] ? $context['screen'] : __('Observed setting', 'configops'));
			$change['label'] = $label;
			$change['group'] = $group;
			$change['kind'] = 'unknown';
			$change['explanation'] = __(
				'ConfigOps matched this saved path to a field you changed in the WordPress admin. This explains likely intent, but it does not make the field adapter-trusted or expand automatic undo.',
				'configops'
			);
		}
		unset($change);

		return $changes;
	}

	/**
	 * @return array{screen: string, action: string, fields: list<array{name: string, label: string, group: string}>}|null
	 */
	private function forSession(int $sessionId): ?array
	{
		if (! $this->loaded) {
			$this->context = $this->readCookie();
			$this->loaded = true;
		}

		if (null === $this->context || $sessionId !== (int) ($this->context['session'] ?? 0)) {
			return null;
		}

		return array(
			'screen' => (string) $this->context['screen'],
			'action' => (string) $this->context['action'],
			'fields' => $this->context['fields'],
		);
	}

	/**
	 * @return array{session: int, screen: string, action: string, fields: list<array{name: string, label: string, group: string}>}|null
	 */
	private function readCookie(): ?array
	{
		$cookie = $_COOKIE[self::COOKIE_NAME] ?? '';
		if (! is_string($cookie)) {
			return null;
		}

		$raw = sanitize_text_field(wp_unslash($cookie));
		if ('' === $raw || strlen($raw) > self::MAX_COOKIE_BYTES) {
			return null;
		}

		$encoded = strtr($raw, '-_', '+/');
		$remainder = strlen($encoded) % 4;
		if (0 !== $remainder) {
			$encoded .= str_repeat('=', 4 - $remainder);
		}
		$json = base64_decode($encoded, true);
		if (! is_string($json)) {
			return null;
		}

		$payload = json_decode($json, true);
		if (! is_array($payload) || 1 !== (int) ($payload['v'] ?? 0)) {
			return null;
		}

		$session = (int) ($payload['session'] ?? 0);
		$capturedAt = (int) ($payload['capturedAt'] ?? 0);
		$age = time() - $capturedAt;
		if ($session <= 0 || $capturedAt <= 0 || $age < -30 || $age > self::MAX_AGE_SECONDS) {
			return null;
		}

		$fields = array();
		$candidates = is_array($payload['fields'] ?? null) ? $payload['fields'] : array();
		foreach (array_slice($candidates, 0, self::MAX_FIELDS) as $candidate) {
			if (! is_array($candidate)) {
				continue;
			}
			$name = $this->boundedText($candidate['name'] ?? '', 191);
			if ('' === $name || null === $this->parseFieldName($name)) {
				continue;
			}
			$fields[] = array(
				'name'  => $name,
				'label' => $this->boundedText($candidate['label'] ?? '', 120),
				'group' => $this->boundedText($candidate['group'] ?? '', 120),
			);
		}

		if (empty($fields)) {
			return null;
		}

		return array(
			'session' => $session,
			'screen'  => $this->boundedText($payload['screen'] ?? '', 120),
			'action'  => $this->boundedText($payload['action'] ?? '', 120),
			'fields'  => $fields,
		);
	}

	/**
	 * @param list<array<string, mixed>> $changes
	 * @param list<array{name: string, label: string, group: string}> $fields
	 * @return array{field: array{name: string, label: string, group: string}, confidence: string, reason: string}|null
	 */
	private function matchField(string $optionName, string $path, array $changes, array $fields): ?array
	{
		$best = array();
		$bestScore = 0;
		foreach ($fields as $field) {
			$parsed = $this->parseFieldName($field['name']);
			if (null === $parsed || $parsed['option'] !== $optionName) {
				continue;
			}

			$score = 0;
			$confidence = 'medium';
			$reason = 'option_scope';
			if ($parsed['pointer'] === $path) {
				$score = 3;
				$confidence = 'high';
				$reason = 'exact_path';
			} elseif (
				'/' !== $parsed['pointer']
				&& str_starts_with($path, $parsed['pointer'] . '/')
			) {
				$score = 2;
				$reason = 'nested_path';
			} elseif (1 === count($changes)) {
				$score = 1;
			}

			if (0 === $score || $score < $bestScore) {
				continue;
			}
			if ($score > $bestScore) {
				$best = array();
				$bestScore = $score;
			}
			$best[] = array(
				'field'      => $field,
				'confidence' => $confidence,
				'reason'     => $reason,
			);
		}

		return 1 === count($best) ? $best[0] : null;
	}

	/**
	 * @return array{option: string, pointer: string}|null
	 */
	private function parseFieldName(string $name): ?array
	{
		if (1 !== preg_match('/^([^\[\]]+)((?:\[[^\[\]]*\])*)$/u', $name, $matches)) {
			return null;
		}

		$option = trim((string) $matches[1]);
		if ('' === $option || strlen($option) > 191) {
			return null;
		}

		preg_match_all('/\[([^\[\]]*)\]/u', (string) ($matches[2] ?? ''), $parts);
		$segments = array_values(
			array_filter(
				array_map('strval', $parts[1] ?? array()),
				static fn (string $part): bool => '' !== $part
			)
		);
		if (count($segments) > self::MAX_FIELD_DEPTH) {
			return null;
		}

		$escaped = array_map(
			static fn (string $part): string => str_replace(array('~', '/'), array('~0', '~1'), $part),
			$segments
		);

		return array(
			'option'  => $option,
			'pointer' => empty($escaped) ? '/' : '/' . implode('/', $escaped),
		);
	}

	private function boundedText(mixed $value, int $maxLength): string
	{
		$text = is_string($value) ? sanitize_text_field($value) : '';
		if (function_exists('mb_substr')) {
			return mb_substr($text, 0, $maxLength);
		}

		return substr($text, 0, $maxLength);
	}

	private function humanize(string $path): string
	{
		$parts = explode('/', trim($path, '/'));
		$key = (string) ($parts[array_key_last($parts)] ?? __('Setting', 'configops'));
		$key = str_replace(array('~1', '~0'), array('/', '~'), $key);
		$spaced = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $key) ?? $key;
		$spaced = preg_replace('/[_-]+/', ' ', $spaced) ?? $spaced;

		return ucwords(trim($spaced));
	}
}
