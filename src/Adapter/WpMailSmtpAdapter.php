<?php
/**
 * Capture adapter for WP Mail SMTP Lite.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Adapter;

final class WpMailSmtpAdapter extends AbstractOptionAdapter
{
	private const MAIN_OPTION = 'wp_mail_smtp';

	/** @var list<string> */
	private const RUNTIME_OPTIONS = array(
		'wp_mail_smtp_activated',
		'wp_mail_smtp_activated_time',
		'wp_mail_smtp_activation_prevent_redirect',
		'wp_mail_smtp_initial_version',
		'wp_mail_smtp_source',
		'wp_mail_smtp_summary_report_email_last_sent_week',
		'wp_mail_smtp_version',
	);

	public function __construct()
	{
		$this->define(self::MAIN_OPTION, '/mail/from_email', 'Sender email', 'Message identity', 'environment', 'The address recipients see. It often differs by website or environment.');
		$this->define(self::MAIN_OPTION, '/mail/from_name', 'Sender name', 'Message identity', 'portable', 'The name recipients see next to the sender address.');
		$this->define(self::MAIN_OPTION, '/mail/mailer', 'Delivery method', 'Connection', 'environment', 'Selects PHP mail, SMTP, or a provider API.');
		$this->define(self::MAIN_OPTION, '/mail/return_path', 'Match return path', 'Message identity', 'portable', 'Sends delivery failures back to the sender address.');
		$this->define(self::MAIN_OPTION, '/mail/from_email_force', 'Use this sender email everywhere', 'Message identity', 'portable', 'Prevents other plugins from replacing the sender email.');
		$this->define(self::MAIN_OPTION, '/mail/from_name_force', 'Use this sender name everywhere', 'Message identity', 'portable', 'Prevents other plugins from replacing the sender name.');
		$this->define(self::MAIN_OPTION, '/smtp/host', 'SMTP server', 'SMTP connection', 'environment', 'The mail server hostname for this website.');
		$this->define(self::MAIN_OPTION, '/smtp/port', 'SMTP port', 'SMTP connection', 'environment', 'The port used to connect to the mail server.');
		$this->define(self::MAIN_OPTION, '/smtp/encryption', 'Connection encryption', 'SMTP connection', 'portable', 'The TLS or SSL mode used by the connection.');
		$this->define(self::MAIN_OPTION, '/smtp/autotls', 'Automatic TLS', 'SMTP connection', 'portable', 'Lets the mail library enable TLS when the server supports it.');
		$this->define(self::MAIN_OPTION, '/smtp/auth', 'SMTP authentication', 'SMTP connection', 'portable', 'Controls whether the server requires a username and password.');
		$this->define(self::MAIN_OPTION, '/smtp/user', 'SMTP username', 'SMTP connection', 'environment', 'The account name used for this website’s mail server.');
		$this->define(self::MAIN_OPTION, '/smtp/pass', 'SMTP password', 'SMTP connection', 'secret', 'A credential. ConfigOps never stores its clear value.');
		$this->define(self::MAIN_OPTION, '/license/key', 'License key', 'License', 'secret', 'A plugin credential. ConfigOps never stores its clear value.');
	}

	public function manifest(): AdapterManifest
	{
		return new AdapterManifest(
			'wp-mail-smtp',
			'WP Mail SMTP',
			'wp-mail-smtp/wp_mail_smtp.php',
			'>=4.9.0 <5.0.0',
			1,
			array(
				array('id' => 'capture', 'label' => 'Find changes', 'level' => 'full', 'note' => 'All Lite settings stored in the main option are captured.'),
				array('id' => 'explain', 'label' => 'Explain fields', 'level' => 'full', 'note' => 'Core Lite mail and SMTP fields have plain-language names.'),
				array('id' => 'secrets', 'label' => 'Hide secrets', 'level' => 'full', 'note' => 'Passwords, tokens, and provider keys are removed before storage.'),
				array('id' => 'noise', 'label' => 'Separate technical noise', 'level' => 'full', 'note' => 'Version, activation, and report timestamps are separated.'),
				array('id' => 'restore', 'label' => 'Undo safely', 'level' => 'partial', 'note' => 'Conflict-checked restore works unless the changed option contains a redacted secret.'),
				array('id' => 'apply', 'label' => 'Apply to another site', 'level' => 'planned', 'note' => 'Release Packs are the next product iteration, not a hidden promise.'),
			),
			array(
				'All settings in WP Mail SMTP Lite’s main settings record',
				'Mailer selection, sender identity, SMTP connection, and Lite provider credentials',
				'Plugin-generated activation, version, and report state',
			),
			array(
				'Pro-only mailers are redacted conservatively but are not part of the tested field map.',
				'A changed secret cannot be reconstructed from capture history.',
				'Sending a test email is not yet a verification contract.',
			),
			'https://github.com/awesomemotive/WP-Mail-SMTP'
		);
	}

	public function ownsOption(string $optionName): bool
	{
		return self::MAIN_OPTION === $optionName || str_starts_with($optionName, 'wp_mail_smtp_');
	}

	public function analyze(string $optionName, array $changes): AdapterAnalysis
	{
		if (in_array($optionName, array('wp_mail_smtp_mail_key', 'wp_mail_smtp_connect', 'wp_mail_smtp_connect_token'), true)) {
			return new AdapterAnalysis('secret', 'WP Mail SMTP stores this local credential outside its portable settings.', false);
		}
		if (
			in_array($optionName, self::RUNTIME_OPTIONS, true)
			|| 1 === preg_match('/(_migration_|_db_(version|error)$|_counter$|_stat$|_stats$|_notifications$|_test_email$)/', $optionName)
		) {
			return new AdapterAnalysis('derived', 'WP Mail SMTP generated this activation, version, or reporting state.', false);
		}
		if (self::MAIN_OPTION !== $optionName) {
			return new AdapterAnalysis('unknown', 'WP Mail SMTP owns this option, but it is outside the tested Lite settings contract.');
		}

		return $this->analyzeFields($optionName, $changes);
	}

	public function field(string $optionName, string $jsonPointer): ?FieldDefinition
	{
		if (self::MAIN_OPTION !== $optionName) {
			return null;
		}

		$field = parent::field($optionName, $jsonPointer);
		if (null === $field || 'unknown' !== $field->kind) {
			return $field;
		}

		$parts = $this->pointerParts($jsonPointer);
		$root  = (string) ($parts[0] ?? '');
		$key   = (string) ($parts[array_key_last($parts)] ?? '');
		if ($this->pathMatchesSecret($parts)) {
			return new FieldDefinition($this->humanize($key), $this->humanize($root) . ' connection', 'secret', 'A provider credential. ConfigOps never stores its clear value.');
		}

		$kind = in_array($root, array('mail', 'general'), true) ? 'portable' : 'environment';

		return new FieldDefinition($this->humanize($key), $this->humanize($root) . ' settings', $kind, 'A WP Mail SMTP setting recognized within the tested Lite option.');
	}

	public function isSensitive(string $optionName, array $path): bool
	{
		if (in_array($optionName, array('wp_mail_smtp_mail_key', 'wp_mail_smtp_connect', 'wp_mail_smtp_connect_token'), true)) {
			return true;
		}

		return self::MAIN_OPTION === $optionName
			&& ($this->pathMatchesSecret($path) || array('license', 'key') === $path);
	}
}
