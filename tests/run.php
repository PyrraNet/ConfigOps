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

$adapterDetector = new class implements SensitiveValueDetector {
	public function isSensitiveKey(string $key): bool
	{
		return 'vendor_credential' === $key;
	}
};
$adapterCodec = new ValueCodec($adapterDetector);
$adapterSecret = $adapterCodec->encode(array('vendor_credential' => 'adapter-owned-secret'), 'vendor_settings');
$assert($adapterSecret->redacted, 'Adapters should be able to extend secret semantics without changing the codec.');
$assert(! str_contains($adapterSecret->payload, 'adapter-owned-secret'), 'Adapter-declared secrets must follow the same storage boundary.');

$nonFinite = $codec->encode(INF, 'fixture_ratio');
$assert(! $nonFinite->restorable, 'Non-finite floats must fail closed.');

$oversizedTree = $codec->encode(range(1, 20001), 'fixture_large_tree');
$assert(! $oversizedTree->restorable, 'Structurally excessive values must stop at a bounded node count.');
$assert(str_contains($oversizedTree->payload, '20,000-node'), 'Node-limit failures should remain explicit in storage.');

fwrite(STDOUT, "ConfigOps unit checks passed ({$assertions} assertions).\n");
