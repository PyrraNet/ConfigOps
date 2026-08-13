<?php
/**
 * Capture adapter for WP Mail SMTP Lite.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Adapter;

final class WpMailSmtpAdapter extends AbstractOptionAdapter implements ChangeAwareAdapter, DatabaseWriteAwareAdapter
{
	private const MAIN_OPTION = 'wp_mail_smtp';

	/** @var list<string> */
	private const PROVIDER_ROOTS = array(
		'elasticemail',
		'gmail',
		'mailersend',
		'mailgun',
		'mailjet',
		'mandrill',
		'postmark',
		'resend',
		'sendgrid',
		'sendinblue',
		'sendlayer',
		'smtp2go',
		'smtpcom',
		'sparkpost',
	);

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
			'=4.9.0',
			2,
			array(
				array('id' => 'capture', 'label' => 'Find changes', 'level' => 'full', 'note' => 'All Lite settings stored in the main option are captured.'),
				array('id' => 'explain', 'label' => 'Explain fields', 'level' => 'full', 'note' => 'Core Lite mail and SMTP fields have plain-language names.'),
				array('id' => 'secrets', 'label' => 'Hide secrets', 'level' => 'full', 'note' => 'Passwords, tokens, and provider keys are removed before storage.'),
				array('id' => 'noise', 'label' => 'Separate technical noise', 'level' => 'full', 'note' => 'Version, activation, and report timestamps are separated.'),
				array('id' => 'restore', 'label' => 'Undo safely', 'level' => 'partial', 'note' => 'Supported non-secret fields use conflict checks and can be undone without reading or replacing a stored credential.'),
				array('id' => 'apply', 'label' => 'Apply to another site', 'level' => 'planned', 'note' => 'Release Packs are the next product iteration, not a hidden promise.'),
			),
			array(
				'WP Mail SMTP Lite settings',
				'Mailer, sender, and SMTP settings',
				'Plugin-generated activation, version, and report data',
			),
			array(
				'WP Mail SMTP Pro settings are not mapped.',
				'Changed passwords and API keys cannot be restored.',
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
		if ('wp_mail_smtp_mail_key' === $optionName) {
			return new AdapterAnalysis('derived', 'WP Mail SMTP created a local encryption key while saving credentials. It is protected and hidden from normal review.', false);
		}
		if (in_array($optionName, array('wp_mail_smtp_connect', 'wp_mail_smtp_connect_token'), true)) {
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

	public function fieldForChange(
		string $optionName,
		string $jsonPointer,
		array $change,
		array $changes
	): ?FieldDefinition {
		if (self::MAIN_OPTION !== $optionName) {
			return $this->field($optionName, $jsonPointer);
		}

		$parts = $this->pointerParts($jsonPointer);
		$root  = (string) ($parts[0] ?? '');
		if (
			1 === count($parts)
			&& in_array($root, self::PROVIDER_ROOTS, true)
			&& $root !== $this->selectedMailer($changes)
			&& $this->containsOnlyProviderDefaults($change['after'] ?? null)
		) {
			return new FieldDefinition(
				sprintf('%s defaults', $this->humanize($root)),
				'Plugin housekeeping',
				'runtime',
				'WP Mail SMTP initialized an unused mail provider with empty defaults while saving. This was not a setting you chose.'
			);
		}

		return $this->field($optionName, $jsonPointer);
	}

	public function isSensitive(string $optionName, array $path): bool
	{
		if (in_array($optionName, array('wp_mail_smtp_mail_key', 'wp_mail_smtp_connect', 'wp_mail_smtp_connect_token'), true)) {
			return true;
		}

		return self::MAIN_OPTION === $optionName
			&& ($this->pathMatchesSecret($path) || array('license', 'key') === $path);
	}

	public function isKnownNonConfigurationWrite(string $table, array $source): bool
	{
		$file = str_replace('\\', '/', $source['file']);

		return 'wp-mail-smtp' === $source['component']
			&& (
				str_contains($table, 'actionscheduler_')
				|| str_contains($file, '/action-scheduler/')
			);
	}

	/**
	 * @param list<array<string, mixed>> $changes Nested diff entries.
	 */
	private function selectedMailer(array $changes): string
	{
		foreach ($changes as $change) {
			if ('/mail/mailer' === ($change['path'] ?? '') && is_string($change['after'] ?? null)) {
				return $change['after'];
			}
		}

		return '';
	}

	private function containsOnlyProviderDefaults(mixed $value): bool
	{
		if (is_array($value)) {
			foreach ($value as $item) {
				if (! $this->containsOnlyProviderDefaults($item)) {
					return false;
				}
			}

			return true;
		}

		return null === $value
			|| false === $value
			|| '' === $value
			|| 'US' === $value
			|| '••••••••' === $value;
	}
}
