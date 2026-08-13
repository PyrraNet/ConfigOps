<?php
/**
 * Adversarial checks for untrusted metadata and hostile value shapes.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

require_once __DIR__ . '/production-error-trap.php';
require_once dirname(__DIR__) . '/src/Autoload.php';

use ConfigOps\Capture\HeuristicSensitiveValueDetector;
use ConfigOps\Capture\IntentContext;
use ConfigOps\Capture\InternalOptionPolicy;
use ConfigOps\Capture\RequestContext;
use ConfigOps\Capture\ValueCodec;
use ConfigOps\Diff\NestedDiff;

final class ConfigOpsAdversarialWakeupProbe
{
	public static int $wakeups = 0;

	public function __wakeup(): void
	{
		++self::$wakeups;
	}
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
	++$assertions;
	if (! $condition) {
		throw new RuntimeException($message);
	}
};

$encodeIntent = static function (array $payload): string {
	$json = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

	return rtrim(strtr(base64_encode(is_string($json) ? $json : '{}'), '+/', '-_'), '=');
};
$validIntent = static function (array $fields, array $overrides = array()) use ($encodeIntent): string {
	return $encodeIntent(
		array_merge(
			array(
				'v'          => 1,
				'session'    => 77,
				'capturedAt' => time(),
				'screen'     => 'Hostile settings',
				'action'     => 'Save changes',
				'fields'     => $fields,
			),
			$overrides
		)
	);
};
$enrich = static function (string $cookie, int $session = 77, array $changes = array()) use (&$assert): array {
	$_COOKIE[IntentContext::COOKIE_NAME] = $cookie;
	$changes = empty($changes) ? array(array('op' => 'replace', 'path' => '/mail/retry')) : $changes;
	$result = (new IntentContext())->enrich($session, 'fixture_settings', $changes);
	$assert(count($changes) === count($result), 'Intent parsing must never add or remove persisted diff operations.');

	return $result;
};

foreach (
	array(
		'not-base64',
		str_repeat('A', 3801),
		$encodeIntent(array('v' => 2, 'session' => 77, 'capturedAt' => time(), 'fields' => array())),
		$validIntent(array(array('name' => 'fixture_settings[mail][retry]')), array('capturedAt' => time() - 181)),
		$validIntent(array(array('name' => 'fixture_settings[mail][retry]')), array('capturedAt' => time() + 31)),
		$validIntent(array(array('name' => str_repeat('field[part]', 13)))),
		$validIntent(array(array('name' => 'fixture_settings[broken'))),
	) as $hostileCookie
) {
	$result = $enrich($hostileCookie);
	$assert(! isset($result[0]['intent']), 'Malformed, stale, future, deep, or oversized intent evidence must be ignored.');
}

$ambiguous = $enrich(
	$validIntent(
		array(
			array('name' => 'fixture_settings[mail][retry]', 'label' => 'First claim'),
			array('name' => 'fixture_settings[mail][retry]', 'label' => 'Second claim'),
		)
	)
);
$assert(! isset($ambiguous[0]['intent']), 'Two equally strong browser claims must fail closed as ambiguous.');

$sanitized = $enrich(
	$validIntent(
		array(
			array(
				'name'  => 'fixture_settings[mail][retry]',
				'label' => '<img src=x onerror=alert(1)>Retry <script>alert(2)</script>',
				'group' => '<b>Delivery</b>',
			),
		)
	)
);
$intentJson = wp_json_encode($sanitized[0]['intent'] ?? array());
$assert(is_string($intentJson) && ! str_contains($intentJson, '<') && ! str_contains($intentJson, 'onerror'), 'Intent labels must be sanitized before persistence.');
$assert('unknown' === ($sanitized[0]['kind'] ?? ''), 'Untrusted intent metadata must stay outside adapter-backed field kinds.');

$medium = $enrich(
	$validIntent(array(array('name' => 'fixture_settings[mail]', 'label' => 'Mail settings'))),
	77,
	array(array('op' => 'replace', 'path' => '/other/path'))
);
$assert('medium' === ($medium[0]['intent']['confidence'] ?? ''), 'A unique same-option fallback must disclose lower confidence.');

$policy = new InternalOptionPolicy();
foreach (
	array(
		'configops_schema_version',
		'_configops_emergency',
		'_transient_configops_flash',
		'_transient_timeout_configops_flash',
		'_site_transient_configops_state',
		'_site_transient_timeout_configops_state',
	) as $internalOption
) {
	$assert($policy->isInternal($internalOption), "Internal option {$internalOption} must never observe itself.");
}
$assert(! $policy->isInternal('configopsneighbor_setting'), 'A neighboring plugin without the protected prefix must remain observable.');
$assert(! $policy->isInternal('vendor_configops_setting'), 'A third-party option containing the product name must remain observable.');

$previousServer = $_SERVER;
$_SERVER['REQUEST_METHOD'] = 'post<script>';
$_SERVER['REQUEST_URI'] = '/wp-admin/options.php?page=private&token=must-not-survive#fragment';
$request = new RequestContext();
$assert('POSTSCRIPT' === $request->method(), 'Request methods must be sanitized, bounded, and normalized.');
$assert('/wp-admin/options.php' === $request->uri(), 'Request evidence must remove query strings, fragments, and tokens.');
$_SERVER = $previousServer;

$secrets = new HeuristicSensitiveValueDetector();
foreach (
	array(
		array('vendorSettings', array('oauth2ClientSecret')),
		array('connector', array('AUTHORIZATION')),
		array('service', array('private-key-pem')),
		array('licenseKey', array()),
		array('smtp', array('credentials')),
	) as [$optionName, $path]
) {
	$assert($secrets->isSensitive($optionName, $path), 'Secret-key normalization must survive case, camelCase, and punctuation variants.');
}
$assert(! $secrets->isSensitive('public_key', array()), 'A public key label must not be mistaken for a private credential.');

$codec = new ValueCodec();
$oversizedString = $codec->encode(str_repeat('x', 262145), 'fixture_payload');
$assert(! $oversizedString->restorable && str_contains($oversizedString->payload, '256 KiB'), 'Oversized strings must fail closed before persistence.');

$deep = 'leaf';
for ($depth = 0; $depth < 34; ++$depth) {
	$deep = array('next' => $deep);
}
$deepValue = $codec->encode($deep, 'fixture_deep');
$assert(! $deepValue->restorable && str_contains($deepValue->payload, 'maximum depth'), 'Deep option trees must become explicitly non-restorable.');

$redactedRejected = false;
try {
	$codec->decode('{"type":"redacted"}');
} catch (RuntimeException) {
	$redactedRejected = true;
}
$assert($redactedRejected, 'A forged or redacted stored node must never decode into a restorable value.');
$assert(! $codec->matches('current', '{"type":"array","items":"broken"}', 'fixture'), 'Malformed expected state must fail a restore conflict check.');

$serializedObject = serialize(new ConfigOpsAdversarialWakeupProbe());
$encodedSerialized = $codec->encode($serializedObject, 'fixture_serialized');
$assert($serializedObject === $codec->decode($encodedSerialized->payload), 'Serialized-looking strings must round-trip as inert strings.');
$assert(0 === ConfigOpsAdversarialWakeupProbe::$wakeups, 'ConfigOps must never unserialize arbitrary option strings while encoding or decoding evidence.');

$resource = tmpfile();
$encodedResource = $codec->encode($resource, 'fixture_resource');
$assert(! $encodedResource->restorable && str_contains($encodedResource->payload, 'resource'), 'Resources must remain unsupported instead of becoming lossy state.');
if (is_resource($resource)) {
	fclose($resource);
}

$pointerDiff = (new NestedDiff())->compare(array('a/b' => array('x~y' => 1)), array('a/b' => array('x~y' => 2)));
$assert('/a~1b/x~0y' === ($pointerDiff[0]['path'] ?? ''), 'JSON Pointer evidence must escape slash and tilde keys without collision.');

unset($_COOKIE[IntentContext::COOKIE_NAME]);
fwrite(STDOUT, "ConfigOps adversarial checks passed ({$assertions} assertions).\n");
