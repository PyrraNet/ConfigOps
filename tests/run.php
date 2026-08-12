<?php
/**
 * Dependency-free unit checks for pure ConfigOps services.
 *
 * Run through WordPress Playground so local PHP is not required.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Autoload.php';

use ConfigOps\Capture\ValueCodec;
use ConfigOps\Capture\SensitiveValueDetector;
use ConfigOps\Diff\NestedDiff;
use ConfigOps\Adapter\WpMailSmtpAdapter;
use ConfigOps\Adapter\YoastSeoAdapter;
use ConfigOps\Adapter\AdapterRegistry;
use ConfigOps\Adapter\AdapterAnalysis;
use ConfigOps\Adapter\AdapterManifest;
use ConfigOps\Adapter\ConfigAdapter;
use ConfigOps\Adapter\FieldDefinition;
use ConfigOps\Capture\HeuristicSensitiveValueDetector;
use ConfigOps\Noise\NoiseClassifier;

$assertions = 0;

$assert = static function (bool $condition, string $message) use (&$assertions): void {
	++$assertions;
	if (! $condition) {
		throw new RuntimeException($message);
	}
};

$diff = new NestedDiff();

$changes = $diff->compare(
	array('mail' => array('enabled' => '1', 'retry' => 3)),
	array('mail' => array('retry' => 4, 'enabled' => '1', 'return_path' => true))
);

$assert(2 === count($changes), 'Nested diff should emit only the two changed paths.');
$assert('/mail/retry' === $changes[0]['path'], 'Associative paths should be stable and sorted.');
$assert('/mail/return_path' === $changes[1]['path'], 'Nested additions should use JSON Pointer paths.');
$assert(array() === $diff->compare(array('b' => 2, 'a' => 1), array('a' => 1, 'b' => 2)), 'Associative key order must not create noise.');

$typedKeyChanges = $diff->compare(array(1 => 'integer key'), array('01' => 'string key'));
$assert(2 === count($typedKeyChanges), 'Distinct integer and numeric-looking string keys must not collapse into one diff path.');

$listChanges = $diff->compare(array('first', 'second'), array('first', 'changed', 'third'));
$assert('/1' === $listChanges[0]['path'] && '/2' === $listChanges[1]['path'], 'List order and numeric paths must be retained.');

$wideBefore = array_fill(0, 1100, 0);
$wideAfter = array_fill(0, 1100, 1);
$boundedDiff = $diff->compare($wideBefore, $wideAfter);
$assert(1001 === count($boundedDiff), 'Diff output should stop at 1,000 concrete changes plus one truncation marker.');
$assert('truncated' === $boundedDiff[1000]['op'], 'A bounded diff must disclose truncation explicitly.');

$codec   = new ValueCodec();
$source  = array(2 => 'two', '02' => 'string-key', 'enabled' => true, 'ratio' => 1.0);
$encoded = $codec->encode($source, 'fixture_settings');
$decoded = $codec->decode($encoded->payload);

$assert($source === $decoded, 'The value codec must preserve scalar and array-key types.');
$assert($encoded->restorable, 'Ordinary nested arrays should be restorable.');
$assert(
	$codec->matches(array('enabled' => true, 'retry' => 3), $codec->encode(array('retry' => 3, 'enabled' => true))->payload, 'fixture_settings'),
	'Conflict checks should ignore associative key order.'
);
$assert(
	! $codec->matches(array('second', 'first'), $codec->encode(array('first', 'second'))->payload, 'fixture_settings'),
	'Conflict checks must retain list order.'
);
$assert(
	! $codec->matches(1, $codec->encode('1')->payload, 'fixture_settings'),
	'Conflict checks must retain scalar types.'
);

$object = new stdClass();
$object->enabled = true;
$object->retries = 3;
$encodedObject = $codec->encode($object, 'fixture_object');
$decodedObject = $codec->decode($encodedObject->payload);
$assert($decodedObject instanceof stdClass, 'Plain stdClass values should retain their safe object type.');
$assert($decodedObject == $object, 'Plain stdClass properties should round-trip without PHP unserialize.');

$secret = $codec->encode(
	array(
		'host'         => 'smtp.example.test',
		'smtpPassword' => 'correct horse battery staple',
	),
	'fixture_mail'
);
$assert($secret->redacted, 'Camel-case secret fields must be detected.');
$assert(! $secret->restorable, 'A redacted option must never be presented as restorable.');
$assert(! str_contains($secret->payload, 'correct horse'), 'Secret material must not reach the stored payload.');
$assert(str_contains($secret->payload, 'redacted'), 'The payload should retain an explicit redaction marker.');

$opaqueJsonSecret = '{"transport":{"host":"smtp.example.test","password":"do-not-persist"}}';
$opaqueJson = $codec->encode($opaqueJsonSecret, 'fixture_opaque_blob');
$assert($opaqueJson->redacted && ! $opaqueJson->restorable, 'Secrets nested in an opaque JSON string must redact the complete string.');
$assert(! str_contains($opaqueJson->payload, 'do-not-persist'), 'Opaque JSON secret plaintext must never reach the stored payload.');

$ordinaryJson = $codec->encode('{"transport":{"host":"smtp.example.test","port":587}}', 'fixture_opaque_blob');
$assert($ordinaryJson->restorable && ! $ordinaryJson->redacted, 'A JSON settings string without credential fields should remain usable evidence.');

$malformedJsonSecret = $codec->encode('{"password":"still-secret",}', 'fixture_opaque_blob');
$assert($malformedJsonSecret->redacted && ! str_contains($malformedJsonSecret->payload, 'still-secret'), 'Malformed JSON must not bypass recognizable secret-key redaction.');

$xmlSecret = $codec->encode('<transport><password>xml-secret</password></transport>', 'fixture_opaque_blob');
$assert($xmlSecret->redacted && ! str_contains($xmlSecret->payload, 'xml-secret'), 'Credentials embedded in XML-like settings strings must be redacted.');

$credentialDsn = $codec->encode('smtp://mailer:do-not-persist@smtp.example.test:587', 'fixture_connection');
$assert($credentialDsn->redacted && ! str_contains($credentialDsn->payload, 'do-not-persist'), 'Credentials embedded in a connection URI must be redacted.');

$privateKey = $codec->encode("-----BEGIN PRIVATE KEY-----\ndo-not-persist\n-----END PRIVATE KEY-----", 'fixture_certificate');
$assert($privateKey->redacted && ! str_contains($privateKey->payload, 'do-not-persist'), 'PEM private keys must be redacted even under an unfamiliar option name.');

$publicUrl = $codec->encode('https://example.test/callback?mode=active', 'fixture_callback');
$assert($publicUrl->restorable && ! $publicUrl->redacted, 'Ordinary URLs must not be mistaken for credential-bearing connection strings.');

$tooDeepJson = str_repeat('{"level":', 34) . '"safe-looking"' . str_repeat('}', 34);
$tooDeepEncoded = $codec->encode($tooDeepJson, 'fixture_opaque_blob');
$assert($tooDeepEncoded->redacted, 'A structured string deeper than the inspection budget must fail closed instead of leaking an unvisited tail.');

$malformedPayloadRejected = false;
try {
	$codec->decode('{"type":"array","items":"not-an-array"}');
} catch (RuntimeException) {
	$malformedPayloadRejected = true;
}
$assert($malformedPayloadRejected, 'Malformed persisted value nodes must never be interpreted as restorable state.');

mt_srand(20260812);
$randomValue = static function (int $depth = 0) use (&$randomValue): mixed {
	if ($depth >= 4 || 0 === mt_rand(0, 3)) {
		return match (mt_rand(0, 5)) {
			0 => null,
			1 => (bool) mt_rand(0, 1),
			2 => mt_rand(-100000, 100000),
			3 => mt_rand(-10000, 10000) / 10,
			4 => 'value-' . mt_rand(0, 100000),
			default => '',
		};
	}

	$items = array();
	$count = mt_rand(0, 5);
	$list = (bool) mt_rand(0, 1);
	for ($index = 0; $index < $count; ++$index) {
		$key = $list ? $index : 'field_' . $index;
		$items[$key] = $randomValue($depth + 1);
	}

	return $items;
};

for ($fuzzCase = 0; $fuzzCase < 250; ++$fuzzCase) {
	$value = $randomValue();
	$fuzzEncoded = $codec->encode($value, 'fixture_fuzz');
	$assert($fuzzEncoded->restorable, "Deterministic codec fuzz case {$fuzzCase} should remain within the supported type boundary.");
	$assert($value === $codec->decode($fuzzEncoded->payload), "Deterministic codec fuzz case {$fuzzCase} should round-trip exactly.");
	$assert(array() === $diff->compare($fuzzEncoded->display, $codec->encode($value, 'fixture_fuzz')->display), "Deterministic diff fuzz case {$fuzzCase} should be stable.");
}

foreach (array('password', 'apiKey', 'client_secret', 'authorization', 'refresh-token', 'credentials') as $secretKey) {
	$secretMarker = 'fuzz-secret-' . $secretKey;
	$fuzzSecret = $codec->encode((string) json_encode(array('nested' => array($secretKey => $secretMarker))), 'fixture_fuzz_blob');
	$assert($fuzzSecret->redacted, "Opaque secret key {$secretKey} should redact under fuzz coverage.");
	$assert(! str_contains($fuzzSecret->payload, $secretMarker), "Opaque secret key {$secretKey} must leave no plaintext marker.");
}

$adapterDetector = new class implements SensitiveValueDetector {
	public function isSensitive(string $optionName, array $path): bool
	{
		unset($optionName);

		return 'vendor_credential' === ($path[array_key_last($path)] ?? null);
	}
};
$adapterCodec = new ValueCodec($adapterDetector);
$adapterSecret = $adapterCodec->encode(array('vendor_credential' => 'adapter-owned-secret'), 'vendor_settings');
$assert($adapterSecret->redacted, 'Adapters should be able to extend secret semantics without changing the codec.');
$assert(! str_contains($adapterSecret->payload, 'adapter-owned-secret'), 'Adapter-declared secrets must follow the same storage boundary.');
$adapterJsonSecret = $adapterCodec->encode('{"nested":{"vendor_credential":"adapter-json-secret"}}', 'vendor_settings');
$assert($adapterJsonSecret->redacted, 'Adapter-owned secret paths must also protect opaque JSON settings strings.');
$assert(! str_contains($adapterJsonSecret->payload, 'adapter-json-secret'), 'Adapter-owned JSON secrets must never enter storage.');

$nonFinite = $codec->encode(INF, 'fixture_ratio');
$assert(! $nonFinite->restorable, 'Non-finite floats must fail closed.');

$oversizedTree = $codec->encode(range(1, 20001), 'fixture_large_tree');
$assert(! $oversizedTree->restorable, 'Structurally excessive values must stop at a bounded node count.');
$assert(str_contains($oversizedTree->payload, '20,000-node'), 'Node-limit failures should remain explicit in storage.');

$mailAdapter = new WpMailSmtpAdapter();
$mailSender = $mailAdapter->analyze('wp_mail_smtp', array(array('path' => '/mail/from_email')));
$assert('environment' === $mailSender->classification, 'WP Mail SMTP sender addresses should be marked per-website.');
$assert('Sender email' === $mailAdapter->field('wp_mail_smtp', '/mail/from_email')?->label, 'WP Mail SMTP should expose plain-language field names.');
$assert($mailAdapter->isSensitive('wp_mail_smtp', array('smtp', 'pass')), 'WP Mail SMTP passwords should use adapter-owned secret semantics.');
$assert($mailAdapter->isSensitive('wp_mail_smtp', array('sendgrid', 'api_key')), 'WP Mail SMTP provider keys should be redacted even without a one-off field map.');
$assert($mailAdapter->isSensitive('wp_mail_smtp', array('license', 'key')), 'WP Mail SMTP license keys should be redacted despite their generic nested key name.');
$assert($mailAdapter->isSensitive('wp_mail_smtp_connect', array()), 'WP Mail SMTP Connect handoff values should be treated as local secrets.');
$mailRuntime = $mailAdapter->analyze('wp_mail_smtp_version', array(array('path' => '/')));
$assert('derived' === $mailRuntime->classification && ! $mailRuntime->allowsGenericRestore, 'WP Mail SMTP version state should stay outside rollback.');
$mailSecretOnly = $mailAdapter->analyze('wp_mail_smtp', array(array('path' => '/', 'redacted' => true)));
$assert('secret' === $mailSecretOnly->classification && ! $mailSecretOnly->allowsGenericRestore, 'A secret-only WP Mail SMTP change should not fall back to an unknown root path.');
$assert(6 === count($mailAdapter->manifest()->capabilities), 'WP Mail SMTP support should disclose every current product capability.');

$yoastAdapter = new YoastSeoAdapter();
$yoastNoIndex = $yoastAdapter->analyze('wpseo_titles', array(array('path' => '/noindex-author-wpseo')));
$assert('environment' === $yoastNoIndex->classification, 'Yoast noindex rules should be checked per environment.');
$assert('reference' === $yoastAdapter->field('wpseo_llmstxt', '/contact_page')?->kind, 'Yoast page IDs should be semantic-reference candidates.');
$assert('reference' === $yoastAdapter->field('wpseo_llmstxt', '/other_included_pages/0')?->kind, 'Yoast page-reference lists should retain their meaning at nested diff paths.');
$assert('LLMs.txt page selection' === $yoastAdapter->field('wpseo_llmstxt', '/llms_txt_selection_mode')?->label, 'Yoast LLMs.txt fields should match the exact 28.2 option schema.');
$assert($yoastAdapter->isSensitive('wpseo', array('myyoast-oauth', 'config', 'secret')), 'Yoast connected-service credentials should be redacted by full path.');
$yoastRuntime = $yoastAdapter->analyze('wpseo', array(array('path' => '/indexing_started')));
$assert('derived' === $yoastRuntime->classification, 'Yoast indexing progress should be separated from intentional settings.');
$yoastDashboardRuntime = $yoastAdapter->analyze('wpseo', array(array('path' => '/indexables_overview_state')));
$assert('derived' === $yoastDashboardRuntime->classification, 'Yoast dashboard and indexing state should not masquerade as reusable settings.');
$yoastVerification = $yoastAdapter->analyze('wpseo', array(array('path' => '/googleverify')));
$assert('environment' === $yoastVerification->classification, 'Yoast site-verification values should be checked per website.');
$assert('reference' === $yoastAdapter->field('wpseo', '/least_linked_ignore_list/0')?->kind, 'Yoast content ignore lists should retain their reference meaning at nested paths.');
$yoastContent = $yoastAdapter->analyze('wpseo_taxonomy_meta', array(array('path' => '/category/1')));
$assert('unsupported' === $yoastContent->classification && ! $yoastContent->allowsGenericRestore, 'Yoast taxonomy content must not masquerade as portable configuration.');
$yoastMultisite = $yoastAdapter->analyze('wpseo_ms', array(array('path' => '/access')));
$assert('unsupported' === $yoastMultisite->classification, 'The Yoast adapter should make the Multisite boundary explicit.');
$assert(6 === count($yoastAdapter->manifest()->capabilities), 'Yoast support should disclose every current product capability.');

$noise = new NoiseClassifier();
$commentMigrationLock = $noise->classify('update_comment_type.lock');
$commentMigrationFinished = $noise->classify('finished_updating_comment_type');
$assert('derived' === $commentMigrationLock['classification'], 'A WordPress comment-type migration lock must not appear as a user setting.');
$assert('derived' === $commentMigrationFinished['classification'], 'WordPress comment-type migration completion state must stay in the technical filter.');

$registry = new AdapterRegistry(array($mailAdapter, $yoastAdapter), new NoiseClassifier(), new HeuristicSensitiveValueDetector());
$assert(null !== $registry->field('wp-mail-smtp', 2, 'wp_mail_smtp', '/smtp/host'), 'The current adapter schema should enrich matching historical evidence.');
$assert(null === $registry->field('wp-mail-smtp', 1, 'wp_mail_smtp', '/smtp/host'), 'Field-aware adapter changes must not reinterpret captures stored under the previous schema.');
$assert(null === $registry->field('wp-mail-smtp', 99, 'wp_mail_smtp', '/smtp/host'), 'A newer adapter must not reinterpret evidence captured under another schema.');
$duplicateRegistry = new AdapterRegistry(array($mailAdapter, $mailAdapter), new NoiseClassifier(), new HeuristicSensitiveValueDetector());
$assert(1 === count($duplicateRegistry->supportPayload()), 'Duplicate adapter IDs should not replace or duplicate the trusted registration.');

$competingAdapter = new class implements ConfigAdapter {
	public function manifest(): AdapterManifest
	{
		return new AdapterManifest('fixture-competitor', 'Fixture competitor', 'fixture/plugin.php', '>=1.0 <2.0', 1, array(), array(), array(), 'https://example.test');
	}

	public function ownsOption(string $optionName): bool
	{
		return 'wp_mail_smtp' === $optionName;
	}

	public function analyze(string $optionName, array $changes): AdapterAnalysis
	{
		unset($optionName, $changes);

		return new AdapterAnalysis('portable', 'Fixture claim.');
	}

	public function field(string $optionName, string $jsonPointer): ?FieldDefinition
	{
		unset($optionName, $jsonPointer);

		return null;
	}

	public function isSensitive(string $optionName, array $path): bool
	{
		unset($optionName);

		return array('fixture-secret') === $path;
	}
};
$ambiguousRegistry = new AdapterRegistry(array($mailAdapter, $competingAdapter), new NoiseClassifier(), new HeuristicSensitiveValueDetector());
$ambiguous = $ambiguousRegistry->analyze('wp_mail_smtp', array(array('path' => '/mail/from_name')));
$assert('unknown' === $ambiguous['classification'] && ! $ambiguous['allows_restore'], 'Ambiguous adapter ownership must fail closed instead of depending on registration order.');
$assert($ambiguousRegistry->isSensitive('wp_mail_smtp', array('fixture-secret')), 'Ambiguous ownership must still union every adapter’s secret protection.');

$throwingSecretAdapter = new class implements ConfigAdapter {
	public function manifest(): AdapterManifest
	{
		return new AdapterManifest('fixture-throwing', 'Fixture throwing', 'fixture/plugin.php', '>=1.0 <2.0', 1, array(), array(), array(), 'https://example.test');
	}

	public function ownsOption(string $optionName): bool
	{
		return 'fixture_throwing_option' === $optionName;
	}

	public function analyze(string $optionName, array $changes): AdapterAnalysis
	{
		unset($optionName, $changes);

		return new AdapterAnalysis('unknown', 'Fixture.');
	}

	public function field(string $optionName, string $jsonPointer): ?FieldDefinition
	{
		unset($optionName, $jsonPointer);

		return null;
	}

	public function isSensitive(string $optionName, array $path): bool
	{
		unset($optionName, $path);

		throw new RuntimeException('Broken community adapter.');
	}
};
$throwingRegistry = new AdapterRegistry(array($throwingSecretAdapter), new NoiseClassifier(), new HeuristicSensitiveValueDetector());
$assert($throwingRegistry->isSensitive('fixture_throwing_option', array('unknown')), 'A failing adapter secret check should redact the option instead of exposing or crashing it.');

fwrite(STDOUT, "ConfigOps unit checks passed ({$assertions} assertions).\n");
