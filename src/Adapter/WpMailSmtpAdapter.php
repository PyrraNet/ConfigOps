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

	/** @var array<string, string> */
	private const PROVIDER_NAMES = array(
		'amazonses'    => 'Amazon SES',
		'elasticemail' => 'Elastic Email',
		'gmail'        => 'Gmail',
		'mailersend'   => 'MailerSend',
		'mailgun'      => 'Mailgun',
		'mailjet'      => 'Mailjet',
		'mandrill'     => 'Mandrill',
		'outlook'      => 'Microsoft 365 / Outlook',
		'pepipost'     => 'Pepipost SMTP',
		'pepipostapi'  => 'Pepipost API',
		'postmark'     => 'Postmark',
		'resend'       => 'Resend',
		'sendgrid'     => 'SendGrid',
		'sendinblue'   => 'Brevo',
		'sendlayer'    => 'SendLayer',
		'smtp2go'      => 'SMTP2GO',
		'smtpcom'      => 'SMTP.com',
		'sparkpost'    => 'SparkPost',
		'zoho'         => 'Zoho Mail',
	);

	/** @var list<string> */
	private const RUNTIME_OPTIONS = array(
		'wp_mail_smtp_activated',
		'wp_mail_smtp_activated_time',
		'wp_mail_smtp_activation_prevent_redirect',
		'wp_mail_smtp_initial_version',
		'wp_mail_smtp_source',
		'wp_mail_smtp_debug',
		'wp_mail_smtp_email_sending_debug',
		'wp_mail_smtp_review_notice',
		'wp_mail_smtp_sendlayer_quick_connect',
		'wp_mail_smtp_summary_report_email_last_sent_week',
		'wp_mail_smtp_version',
	);

	public function __construct()
	{
		$this->defineFields(
			self::MAIN_OPTION,
			array(
				'/mail/from_email' => array('Sender email', 'Message identity', 'environment', 'The address recipients see. It often differs by website or environment.'),
				'/mail/from_name' => array('Sender name', 'Message identity', 'portable', 'The name recipients see next to the sender address.'),
				'/mail/mailer' => array('Delivery method', 'Connection', 'environment', 'Selects PHP mail, SMTP, or a provider API.'),
				'/mail/return_path' => array('Match return path', 'Message identity', 'portable', 'Sends delivery failures back to the sender address.'),
				'/mail/from_email_force' => array('Use this sender email everywhere', 'Message identity', 'portable', 'Prevents other plugins from replacing the sender email.'),
				'/mail/from_name_force' => array('Use this sender name everywhere', 'Message identity', 'portable', 'Prevents other plugins from replacing the sender name.'),
				'/smtp/host' => array('SMTP server', 'SMTP connection', 'environment', 'The mail server hostname for this website.'),
				'/smtp/port' => array('SMTP port', 'SMTP connection', 'environment', 'The port used to connect to the mail server.'),
				'/smtp/encryption' => array('Connection encryption', 'SMTP connection', 'portable', 'The TLS or SSL mode used by the connection.'),
				'/smtp/autotls' => array('Automatic TLS', 'SMTP connection', 'portable', 'Lets the mail library enable TLS when the server supports it.'),
				'/smtp/auth' => array('SMTP authentication', 'SMTP connection', 'portable', 'Controls whether the server requires a username and password.'),
				'/smtp/user' => array('SMTP username', 'SMTP connection', 'environment', 'The account name used for this website’s mail server.'),
				'/smtp/pass' => array('SMTP password', 'SMTP connection', 'secret', 'A credential. ConfigOps never stores its clear value.'),
				'/general/do_not_send' => array('Stop all outgoing email', 'Delivery policy', 'environment', 'Blocks email sent through wp_mail() on this website, except WP Mail SMTP test messages.'),
				'/general/am_notifications_hidden' => array('Hide plugin announcements', 'Admin experience', 'portable', 'Hides WP Mail SMTP announcements and update details.'),
				'/general/email_delivery_errors_hidden' => array('Hide delivery-error warnings', 'Admin experience', 'environment', 'Hides admin warnings when this website cannot deliver email.'),
				'/general/dashboard_widget_hidden' => array('Hide dashboard widget', 'Admin experience', 'portable', 'Hides the WP Mail SMTP dashboard widget.'),
				'/general/usage-tracking-enabled' => array('Allow usage tracking', 'Privacy', 'environment', 'Controls whether WP Mail SMTP sends usage telemetry from this website.'),
				'/general/summary_report_email_disabled' => array('Disable weekly email summaries', 'Reports', 'portable', 'Stops WP Mail SMTP from sending its weekly summary email.'),
				'/general/optimize_email_sending_enabled' => array('Optimize email sending', 'Delivery policy', 'environment', 'Queues wp_mail() delivery asynchronously, which can delay messages.'),
				'/general/uninstall' => array('Delete WP Mail SMTP data on uninstall', 'Data lifecycle', 'environment', 'Removes the plugin’s settings and data when WP Mail SMTP is deleted.'),
				'/license/key' => array('License key', 'License', 'secret', 'A plugin credential. ConfigOps never stores its clear value.'),
			)
		);

		$this->defineProviderFields();
	}

	public function manifest(): AdapterManifest
	{
		return new AdapterManifest(
			'wp-mail-smtp',
			'WP Mail SMTP',
			'wp-mail-smtp/wp_mail_smtp.php',
			'=4.9.0',
			3,
			array(
				array('id' => 'capture', 'label' => 'Find changes', 'level' => 'full', 'note' => 'The exact 4.9.0 Lite option schema is captured, including every bundled mailer and Misc setting.'),
				array('id' => 'explain', 'label' => 'Explain fields', 'level' => 'full', 'note' => 'Sender, SMTP, provider, privacy, delivery-policy, report, and lifecycle fields have exact names and boundaries.'),
				array('id' => 'secrets', 'label' => 'Hide secrets', 'level' => 'full', 'note' => 'Passwords, API keys, OAuth secrets, access-key IDs, signed account links, and license keys are removed before storage.'),
				array('id' => 'noise', 'label' => 'Separate technical noise', 'level' => 'full', 'note' => 'Version, activation, debug, migration, counters, notices, scheduler, and provider-usage state are separated.'),
				array('id' => 'restore', 'label' => 'Undo safely', 'level' => 'partial', 'note' => 'Supported non-secret fields use conflict checks and can be undone without reading or replacing a stored credential.'),
				array('id' => 'apply', 'label' => 'Apply to another site', 'level' => 'planned', 'note' => 'Release Packs are the next product iteration, not a hidden promise.'),
			),
			array(
				'Sender identity, delivery method, SMTP, and every bundled Lite mailer',
				'Provider domains, regions, streams, channels, OAuth mode, and account-plan choices',
				'Misc privacy, reporting, admin-display, async-delivery, and uninstall settings',
				'Plugin-generated activation, debug, migration, notice, counter, scheduler, and provider-usage data',
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
			$group = isset(self::PROVIDER_NAMES[$root]) ? self::PROVIDER_NAMES[$root] . ' connection' : $this->humanize($root) . ' settings';

			return new FieldDefinition($this->humanize($key), $group, 'secret', 'An unrecognized credential-shaped field. ConfigOps removes its clear value and does not guess how to restore it.');
		}

		$group = isset(self::PROVIDER_NAMES[$root])
			? self::PROVIDER_NAMES[$root] . ' connection'
			: $this->humanize($root) . ' settings';

		return new FieldDefinition($this->humanize($key), $group, 'unknown', 'This path is not part of the pinned WP Mail SMTP Lite 4.9.0 field contract. ConfigOps keeps it visible but does not guess during undo.');
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
		$selectedMailer = $this->selectedMailer($changes);
		if (
			1 === count($parts)
			&& isset(self::PROVIDER_NAMES[$root])
			&& '' !== $selectedMailer
			&& $root !== $selectedMailer
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
			&& (
				$this->pathMatchesSecret($path)
				|| array('license', 'key') === $path
				|| array('amazonses', 'client_id') === $path
				|| array('sendlayer', 'free_upgrade_url') === $path
			);
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

	private function defineProviderFields(): void
	{
		/** @var array<string, array<string, array{0: string, 1: string, 2: string}>> $providers */
		$providers = array(
			'amazonses' => array(
				'client_id' => array('Access key ID', 'secret', 'An Amazon SES credential identifier. ConfigOps removes it together with the secret access key.'),
				'client_secret' => array('Secret access key', 'secret', 'An Amazon SES credential. ConfigOps never stores its clear value.'),
				'region' => array('AWS region', 'environment', 'The AWS region that owns this website’s SES account and sending identity.'),
			),
			'elasticemail' => array(
				'api_key' => array('API key', 'secret', 'An Elastic Email credential. ConfigOps never stores its clear value.'),
			),
			'gmail' => array(
				'one_click_setup_enabled' => array('One-Click Setup', 'environment', 'Selects the OAuth connection flow used by this website.'),
				'client_id' => array('Google client ID', 'environment', 'Identifies the Google OAuth application used by this website.'),
				'client_secret' => array('Google client secret', 'secret', 'A Google OAuth credential. ConfigOps never stores its clear value.'),
			),
			'mailersend' => array(
				'api_key' => array('API token', 'secret', 'A MailerSend credential. ConfigOps never stores its clear value.'),
				'has_pro_plan' => array('Paid MailerSend plan', 'environment', 'Records which account plan controls this connection’s available sending features.'),
			),
			'mailgun' => array(
				'api_key' => array('Private API key', 'secret', 'A Mailgun credential. ConfigOps never stores its clear value.'),
				'domain' => array('Sending domain', 'environment', 'The Mailgun domain authorized to send mail for this website.'),
				'region' => array('Mailgun region', 'environment', 'Selects the US or EU Mailgun API endpoint for this account.'),
			),
			'mailjet' => array(
				'api_key' => array('API key', 'secret', 'A Mailjet credential. ConfigOps never stores its clear value.'),
				'secret_key' => array('Secret key', 'secret', 'A Mailjet credential. ConfigOps never stores its clear value.'),
			),
			'mandrill' => array(
				'api_key' => array('API key', 'secret', 'A Mandrill credential. ConfigOps never stores its clear value.'),
			),
			'outlook' => array(
				'one_click_setup_enabled' => array('One-Click Setup', 'environment', 'Selects the Microsoft OAuth connection flow used by this website.'),
				'client_id' => array('Application ID', 'environment', 'Identifies the Microsoft OAuth application used by this website.'),
				'client_secret' => array('Application password', 'secret', 'A Microsoft OAuth credential. ConfigOps never stores its clear value.'),
			),
			'pepipost' => array(
				'host' => array('SMTP server', 'environment', 'The Pepipost SMTP hostname used by this website.'),
				'port' => array('SMTP port', 'environment', 'The port used for this Pepipost SMTP connection.'),
				'encryption' => array('Connection encryption', 'portable', 'The TLS or SSL mode used by Pepipost SMTP.'),
				'auth' => array('SMTP authentication', 'portable', 'Controls whether Pepipost SMTP requires credentials.'),
				'user' => array('SMTP username', 'environment', 'The account name used by this website’s Pepipost SMTP connection.'),
				'pass' => array('SMTP password', 'secret', 'A Pepipost credential. ConfigOps never stores its clear value.'),
			),
			'pepipostapi' => array(
				'api_key' => array('API key', 'secret', 'A Pepipost credential. ConfigOps never stores its clear value.'),
			),
			'postmark' => array(
				'server_api_token' => array('Server API token', 'secret', 'A Postmark credential. ConfigOps never stores its clear value.'),
				'message_stream' => array('Message stream', 'environment', 'The Postmark stream that receives mail from this website.'),
			),
			'resend' => array(
				'api_key' => array('API key', 'secret', 'A Resend credential. ConfigOps never stores its clear value.'),
			),
			'sendgrid' => array(
				'api_key' => array('API key', 'secret', 'A SendGrid credential. ConfigOps never stores its clear value.'),
				'domain' => array('Sending domain', 'environment', 'The SendGrid domain authorized to send mail for this website.'),
			),
			'sendinblue' => array(
				'api_key' => array('API key', 'secret', 'A Brevo credential. ConfigOps never stores its clear value.'),
				'domain' => array('Sending domain', 'environment', 'The Brevo domain authorized to send mail for this website.'),
			),
			'sendlayer' => array(
				'api_key' => array('API key', 'secret', 'A SendLayer credential. ConfigOps never stores its clear value.'),
				'quick_connect' => array('Quick Connect', 'environment', 'Records that this website uses SendLayer’s account connection flow.'),
				'is_shared_domain' => array('Shared sending domain', 'environment', 'Records whether SendLayer assigned this website a shared sending domain.'),
				'sender_domain' => array('Sending domain', 'environment', 'The SendLayer domain used in this website’s sender address.'),
				'free_upgrade_url' => array('Account upgrade link', 'secret', 'A signed account-specific URL. ConfigOps removes it before storage.'),
			),
			'smtp2go' => array(
				'api_key' => array('API key', 'secret', 'An SMTP2GO credential. ConfigOps never stores its clear value.'),
			),
			'smtpcom' => array(
				'api_key' => array('API key', 'secret', 'An SMTP.com credential. ConfigOps never stores its clear value.'),
				'channel' => array('Sender channel', 'environment', 'The SMTP.com channel assigned to mail from this website.'),
			),
			'sparkpost' => array(
				'api_key' => array('API key', 'secret', 'A SparkPost credential. ConfigOps never stores its clear value.'),
				'region' => array('SparkPost region', 'environment', 'Selects the US or EU SparkPost API endpoint for this account.'),
			),
			'zoho' => array(
				'domain' => array('Zoho data center', 'environment', 'Selects the Zoho account domain and regional API endpoint.'),
				'client_id' => array('Zoho client ID', 'environment', 'Identifies the Zoho OAuth application used by this website.'),
				'client_secret' => array('Zoho client secret', 'secret', 'A Zoho OAuth credential. ConfigOps never stores its clear value.'),
			),
		);

		foreach ($providers as $root => $fields) {
			foreach ($fields as $key => $definition) {
				$this->define(
					self::MAIN_OPTION,
					'/' . $root . '/' . $key,
					$definition[0],
					self::PROVIDER_NAMES[$root] . ' connection',
					$definition[1],
					$definition[2]
				);
			}
		}
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
