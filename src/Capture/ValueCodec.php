<?php
/**
 * Type-preserving, secret-aware option value codec.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Capture;

use ConfigOps\Adapter\OptionValueNormalizer;
use RuntimeException;

final class ValueCodec
{
	private const MAX_DEPTH = 32;
	private const MAX_NODES = 20000;
	private const MAX_STRING_BYTES = 262144;
	private const MAX_PAYLOAD_BYTES = 1048576;
	private SensitiveValueDetector $sensitiveValues;
	private ?OptionValueNormalizer $valueNormalizer;

	public function __construct(?SensitiveValueDetector $sensitiveValues = null)
	{
		$this->sensitiveValues = $sensitiveValues ?? new HeuristicSensitiveValueDetector();
		$this->valueNormalizer = $this->sensitiveValues instanceof OptionValueNormalizer
			? $this->sensitiveValues
			: null;
	}

	public function encode(mixed $value, string $optionName = ''): EncodedValue
	{
		if ($this->isEntireOptionSensitive($optionName)) {
			return $this->redacted();
		}
		if (null !== $this->valueNormalizer && is_scalar($value)) {
			$value = $this->valueNormalizer->normalizeOptionValue($optionName, $value);
		}

		$state = array(
			'restorable' => true,
			'redacted'   => false,
			'nodes'      => 0,
			'limited'    => false,
		);

		$node = $this->encodeNode($value, $state, 0, $optionName, array());

		if ($state['limited']) {
			$node = array(
				'type'  => 'unsupported',
				'label' => 'value exceeds the 20,000-node safety limit',
			);
		}

		$payload = $this->jsonEncode($node);
		if (strlen($payload) > self::MAX_PAYLOAD_BYTES) {
			$state['restorable'] = false;
			$node                = array(
				'type'  => 'unsupported',
				'label' => 'value larger than 1 MiB',
			);
			$payload             = $this->jsonEncode($node);
		}

		return new EncodedValue(
			$payload,
			$this->displayNode($node),
			(bool) $state['restorable'],
			(bool) $state['redacted']
		);
	}

	public function missing(): EncodedValue
	{
		$node = array('type' => 'missing');

		return new EncodedValue($this->jsonEncode($node), '[not set]', true, false);
	}

	public function redacted(): EncodedValue
	{
		$node = array('type' => 'redacted');

		return new EncodedValue($this->jsonEncode($node), '••••••••', false, true);
	}

	public function unavailable(string $label): EncodedValue
	{
		$label = trim(sanitize_text_field($label));
		$node = array(
			'type'  => 'unsupported',
			'label' => '' === $label ? 'value unavailable' : $label,
		);

		return new EncodedValue($this->jsonEncode($node), '[' . $node['label'] . ']', false, false);
	}

	public function isEntireOptionSensitive(string $optionName): bool
	{
		return $this->sensitiveValues->isSensitive($optionName, array());
	}

	public function decode(string $payload): mixed
	{
		$node = json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
		if (! is_array($node)) {
			throw new RuntimeException('The stored value is malformed.');
		}

		return $this->decodeNode($node);
	}

	public function isMissing(string $payload): bool
	{
		$node = json_decode($payload, true);

		return is_array($node) && 'missing' === ($node['type'] ?? null);
	}

	public function matches(mixed $currentValue, string $expectedPayload, string $optionName): bool
	{
		$current = $this->encode($currentValue, $optionName);
		if (! $current->restorable) {
			return false;
		}

		try {
			$expected = $this->decode($expectedPayload);
			$actual   = $this->decode($current->payload);
		} catch (\Throwable) {
			return false;
		}

		return $this->semanticValue($expected) === $this->semanticValue($actual);
	}

	public function semanticallyEqual(mixed $left, mixed $right): bool
	{
		return $this->semanticValue($left) === $this->semanticValue($right);
	}

	/**
	 * @param array{restorable: bool, redacted: bool, nodes: int, limited: bool} $state Encoding state.
	 * @return array<string, mixed>
	 */
	private function encodeNode(mixed $value, array &$state, int $depth, string $optionName, array $path): array
	{
		++$state['nodes'];
		if ($state['nodes'] > self::MAX_NODES) {
			$state['restorable'] = false;
			$state['limited']    = true;

			return array('type' => 'unsupported', 'label' => 'structural node limit exceeded');
		}

		if ($depth > self::MAX_DEPTH) {
			$state['restorable'] = false;

			return array('type' => 'unsupported', 'label' => 'maximum depth exceeded');
		}

		if (! empty($path) && $this->sensitiveValues->isSensitive($optionName, $path)) {
			$state['restorable'] = false;
			$state['redacted']   = true;

			return array('type' => 'redacted');
		}

		if (null === $value) {
			return array('type' => 'null');
		}
		if (is_bool($value)) {
			return array('type' => 'bool', 'value' => $value);
		}
		if (is_int($value)) {
			return array('type' => 'int', 'value' => $value);
		}
		if (is_float($value)) {
			if (! is_finite($value)) {
				$state['restorable'] = false;

				return array('type' => 'unsupported', 'label' => 'non-finite float');
			}

			return array('type' => 'float', 'value' => $value);
		}
		if (is_string($value)) {
			if (strlen($value) > self::MAX_STRING_BYTES) {
				$state['restorable'] = false;

				return array('type' => 'unsupported', 'label' => 'string larger than 256 KiB');
			}
			if ($this->opaqueStringContainsSecret($value, $optionName, $path)) {
				$state['restorable'] = false;
				$state['redacted']   = true;

				return array('type' => 'redacted');
			}

			return array('type' => 'string', 'value' => $value);
		}
		if (is_array($value)) {
			$items = array();
			foreach ($value as $itemKey => $itemValue) {
				$items[] = array(
					'key'   => is_int($itemKey)
						? array('type' => 'int', 'value' => $itemKey)
						: array('type' => 'string', 'value' => $itemKey),
					'value' => $this->encodeNode($itemValue, $state, $depth + 1, $optionName, array_merge($path, array($itemKey))),
				);
				if ($state['limited']) {
					break;
				}
			}

			return array('type' => 'array', 'items' => $items);
		}
		if ($value instanceof \stdClass) {
			$items = array();
			foreach (get_object_vars($value) as $itemKey => $itemValue) {
				$items[] = array(
					'key'   => array('type' => 'string', 'value' => $itemKey),
					'value' => $this->encodeNode($itemValue, $state, $depth + 1, $optionName, array_merge($path, array($itemKey))),
				);
				if ($state['limited']) {
					break;
				}
			}

			return array('type' => 'stdClass', 'items' => $items);
		}

		$state['restorable'] = false;
		$label               = is_object($value) ? 'object(' . $value::class . ')' : get_debug_type($value);

		return array('type' => 'unsupported', 'label' => $label);
	}

	/**
	 * Fail closed for secrets hidden inside strings that plugins treat as mini
	 * documents. The complete opaque string is redacted because changing its
	 * byte representation could break the owning plugin.
	 *
	 * @param list<int|string> $path Current option path.
	 */
	private function opaqueStringContainsSecret(string $value, string $optionName, array $path): bool
	{
		$trimmed = trim($value);
		if ('' === $trimmed) {
			return false;
		}

		if (1 === preg_match('/-----BEGIN(?: [A-Z0-9]+)? PRIVATE KEY-----/', $trimmed)) {
			return true;
		}

		if (1 === preg_match('/(?:^|[\r\n])\s*Authorization\s*[:=]\s*(?:Basic|Bearer)\s+\S+/i', $trimmed)) {
			return true;
		}

		if (1 === preg_match('~^[a-z][a-z0-9+.-]*://[^/@\s:]+:[^@\s]+@~i', $trimmed)) {
			return true;
		}

		if ($this->looksLikeCredentialToken($trimmed)) {
			return true;
		}

		if (1 === preg_match(
			'/(?:^|[?&;\s])(?:password|passwd|passphrase|pwd|secret|token|access_token|refresh_token|api_key|apikey|private_key|client_secret|consumer_secret|authorization|credentials?)\s*=/i',
			$trimmed
		)) {
			return true;
		}

		if (1 === preg_match(
			'/["\'](?:password|passwd|passphrase|pwd|secret|token|access[_-]?token|refresh[_-]?token|api[_-]?key|private[_-]?key|client[_-]?secret|consumer[_-]?secret|authorization|credentials?)["\']\s*:/i',
			$trimmed
		)) {
			return true;
		}

		if (1 === preg_match(
			'/<(?:password|passwd|passphrase|pwd|secret|token|api[_-]?key|private[_-]?key|client[_-]?secret|credentials?)(?:\s[^>]*)?>/i',
			$trimmed
		)) {
			return true;
		}

		$first = $trimmed[0] ?? '';
		if ('{' !== $first && '[' !== $first) {
			return false;
		}

		try {
			$decoded = json_decode($trimmed, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
		} catch (\JsonException $error) {
			return JSON_ERROR_DEPTH === $error->getCode();
		}

		$visited = 0;

		return $this->structuredValueContainsSecret($decoded, $optionName, $path, 0, $visited);
	}

	private function looksLikeCredentialToken(string $value): bool
	{
		$knownPatterns = array(
			'/(?:^|[^A-Za-z0-9_-])sk-(?:proj-|live-|test-)?[A-Za-z0-9_-]{16,}(?:$|[^A-Za-z0-9_-])/i',
			'/(?:^|[^A-Za-z0-9_])(?:sk|rk)_(?:live|test)_[A-Za-z0-9]{16,}(?:$|[^A-Za-z0-9_])/i',
			'/(?:^|[^A-Za-z0-9_])(?:gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,})(?:$|[^A-Za-z0-9_])/',
			'/(?:^|[^A-Za-z0-9-])(?:glpat-[A-Za-z0-9_-]{20,}|xox[baprs]-[A-Za-z0-9-]{10,})(?:$|[^A-Za-z0-9-])/',
			'/(?:^|[^A-Z0-9])AKIA[A-Z0-9]{16}(?:$|[^A-Z0-9])/',
			'/(?:^|[^A-Za-z0-9_-])AIza[A-Za-z0-9_-]{20,}(?:$|[^A-Za-z0-9_-])/',
			'/(?:^|[^A-Za-z0-9_])(?:npm_[A-Za-z0-9]{20,}|SK[0-9a-f]{32})(?:$|[^A-Za-z0-9_])/i',
			'/(?:^|[^A-Za-z0-9_.-])SG\.[A-Za-z0-9_-]{16,}\.[A-Za-z0-9_-]{16,}(?:$|[^A-Za-z0-9_.-])/',
			'/(?:^|[\s"\'=:])eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}(?:$|[\s"\',;&])/',
		);
		foreach ($knownPatterns as $pattern) {
			if (1 === preg_match($pattern, $value)) {
				return true;
			}
		}

		$length = strlen($value);
		if (
			$length < 48
			|| $length > 4096
			|| 1 !== preg_match('/^[A-Za-z0-9._~+\/=\-]+$/', $value)
			|| 1 !== preg_match('/[A-Za-z]/', $value)
			|| 1 !== preg_match('/[0-9]/', $value)
			|| 1 === preg_match('/^(?:sha(?:256|384|512)-[A-Za-z0-9+\/=]+|[a-f0-9]{40,128})$/i', $value)
		) {
			return false;
		}

		$entropy = 0.0;
		foreach (count_chars($value, 1) as $occurrences) {
			$probability = $occurrences / $length;
			$entropy -= $probability * log($probability, 2);
		}

		return $entropy >= 4.3;
	}

	/**
	 * @param list<int|string> $path Current path within the decoded document.
	 */
	private function structuredValueContainsSecret(
		mixed $value,
		string $optionName,
		array $path,
		int $depth,
		int &$visited
	): bool {
		++$visited;
		// A structured document that exceeds the inspection budget is opaque by
		// definition. Redact it instead of assuming the unvisited tail is safe.
		if ($visited > self::MAX_NODES || $depth > self::MAX_DEPTH) {
			return true;
		}
		if (! is_array($value)) {
			return false;
		}

		foreach ($value as $key => $child) {
			$childPath = array_merge($path, array($key));
			if ($this->sensitiveValues->isSensitive($optionName, $childPath)) {
				return true;
			}
			if ($this->structuredValueContainsSecret($child, $optionName, $childPath, $depth + 1, $visited)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $node Encoded node.
	 */
	private function decodeNode(array $node): mixed
	{
		$type = $node['type'] ?? null;

		return match ($type) {
			'null' => null,
			'bool' => (bool) ($node['value'] ?? false),
			'int' => (int) ($node['value'] ?? 0),
			'float' => (float) ($node['value'] ?? 0.0),
			'string' => (string) ($node['value'] ?? ''),
			'array' => $this->decodeArray($node['items'] ?? array()),
			'stdClass' => (object) $this->decodeArray($node['items'] ?? array()),
			default => throw new RuntimeException('This value was redacted or cannot be restored safely.'),
		};
	}

	/**
	 * @param mixed $items Encoded array items.
	 * @return array<int|string, mixed>
	 */
	private function decodeArray(mixed $items): array
	{
		if (! is_array($items)) {
			throw new RuntimeException('The stored array is malformed.');
		}

		$result = array();
		foreach ($items as $item) {
			if (! is_array($item) || ! is_array($item['key'] ?? null) || ! is_array($item['value'] ?? null)) {
				throw new RuntimeException('The stored array item is malformed.');
			}

			$key          = 'int' === ($item['key']['type'] ?? null)
				? (int) ($item['key']['value'] ?? 0)
				: (string) ($item['key']['value'] ?? '');
			$result[$key] = $this->decodeNode($item['value']);
		}

		return $result;
	}

	/**
	 * @param array<string, mixed> $node Encoded node.
	 */
	private function displayNode(array $node): mixed
	{
		$type = $node['type'] ?? null;

		if ('array' === $type || 'stdClass' === $type) {
			$result = array();
			foreach (($node['items'] ?? array()) as $item) {
				if (! is_array($item) || ! is_array($item['key'] ?? null) || ! is_array($item['value'] ?? null)) {
					continue;
				}
				$key          = 'int' === ($item['key']['type'] ?? null)
					? (int) ($item['key']['value'] ?? 0)
					: (string) ($item['key']['value'] ?? '');
				$result[$key] = $this->displayNode($item['value']);
			}

			return $result;
		}

		return match ($type) {
			'null' => null,
			'bool', 'int', 'float', 'string' => $node['value'] ?? null,
			'redacted' => '••••••••',
			'missing' => '[not set]',
			'unsupported' => '[' . (string) ($node['label'] ?? 'unsupported value') . ']',
			default => '[invalid value]',
		};
	}

	/**
	 * Build a typed, order-independent representation for semantic conflict checks.
	 *
	 * List order stays significant; associative array and stdClass property order does not.
	 *
	 * @return array<string, mixed>
	 */
	private function semanticValue(mixed $value): array
	{
		if (is_array($value)) {
			if (array_is_list($value)) {
				return array(
					'type'  => 'list',
					'items' => array_map(fn (mixed $item): array => $this->semanticValue($item), $value),
				);
			}

			$items = array();
			foreach ($value as $key => $item) {
				$typedKey         = is_int($key) ? 'int:' . $key : 'string:' . $key;
				$items[$typedKey] = $this->semanticValue($item);
			}
			ksort($items, SORT_STRING);

			return array('type' => 'map', 'items' => $items);
		}

		if ($value instanceof \stdClass) {
			$items = array();
			foreach (get_object_vars($value) as $key => $item) {
				$items[$key] = $this->semanticValue($item);
			}
			ksort($items, SORT_STRING);

			return array('type' => 'stdClass', 'items' => $items);
		}

		return array(
			'type'  => get_debug_type($value),
			'value' => $value,
		);
	}

	/**
	 * @param array<string, mixed> $value Value to encode.
	 */
	private function jsonEncode(array $value): string
	{
		$json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
		if (false === $json) {
			throw new RuntimeException('The option value could not be encoded.');
		}

		return $json;
	}
}
