<?php
/**
 * Plugin Name: ConfigOps Hostile Fixture
 * Description: Deliberately awkward settings behavior for ConfigOps trust tests.
 * Version: 2.0.0
 * Requires PHP: 8.2
 *
 * @package ConfigOpsHostileFixture
 */

declare(strict_types=1);

namespace ConfigOpsHostileFixture;

final class SettingsFixture
{
	public const ENABLED_OPTION = 'cofx_enabled';
	public const SETTINGS_OPTION = 'cofx_settings';
	public const CREDENTIALS_OPTION = 'cofx_credentials';
	public const OBSOLETE_OPTION = 'cofx_obsolete';
	public const LAST_CHECKED_OPTION = 'cofx_last_checked';
	public const SCHEMA_OPTION = 'cofx_schema';
	public const DIRECT_OPTION = 'cofx_direct_write';
	public const AJAX_OPTION = 'cofx_ajax_state';
	public const COALESCE_OPTION = 'cofx_coalesce_state';
	public const REGISTERED_OPTION = 'cofx_registered_settings';
	public const TRANSIENT = 'cofx_connection_cache';
	public const SECRET = 'fixture-secret-must-never-persist';

	public static function seed(): void
	{
		update_option(self::ENABLED_OPTION, false, false);
		update_option(
			self::SETTINGS_OPTION,
			array(
				'mail'    => array('enabled' => false, 'retry' => 3),
				'content' => array('contact_page_id' => 0),
			),
			false
		);
		update_option(
			self::CREDENTIALS_OPTION,
			array('host' => 'smtp.example.test', 'username' => 'mailer'),
			false
		);
		update_option(self::OBSOLETE_OPTION, 'legacy', false);
		update_option(
			self::SCHEMA_OPTION,
			array(
				'schema' => 1,
				'mailer' => array('sender' => 'hello@example.test'),
			),
			false
		);
		update_option(self::DIRECT_OPTION, 'before', false);
		update_option(self::AJAX_OPTION, 'idle', false);
		update_option(self::COALESCE_OPTION, 'baseline', false);
		update_option(self::REGISTERED_OPTION, array('mail' => array('retry' => 2)), false);
		delete_option(self::LAST_CHECKED_OPTION);
		delete_transient(self::TRANSIENT);
	}

	public static function saveSettings(int $contactPageId): void
	{
		update_option(self::ENABLED_OPTION, true, false);
		update_option(
			self::SETTINGS_OPTION,
			array(
				'content' => array('contact_page_id' => $contactPageId),
				'mail'    => array('retry' => 5, 'enabled' => true),
			),
			false
		);
		update_option(
			self::CREDENTIALS_OPTION,
			array(
				'host'     => 'smtp.example.test',
				'username' => 'mailer',
				'password' => self::SECRET,
			),
			false
		);
		delete_option(self::OBSOLETE_OPTION);

		self::recalculateRuntimeState();
	}

	public static function migrateToVersionTwo(): void
	{
		update_option(
			self::SCHEMA_OPTION,
			array(
				'schema'   => 2,
				'mail'     => array('from' => 'hello@example.test'),
				'features' => array('connection_test' => true),
			),
			false
		);
	}

	public static function writeDirectly(string $value): void
	{
		global $wpdb;

		$wpdb->update(
			$wpdb->options,
			array('option_value' => $value),
			array('option_name' => self::DIRECT_OPTION),
			array('%s'),
			array('%s')
		);
		wp_cache_delete(self::DIRECT_OPTION, 'options');
		wp_cache_delete('alloptions', 'options');
	}

	public static function handleAjaxSave(): void
	{
		update_option(self::AJAX_OPTION, 'saved-via-ajax', false);
	}

	public static function writeCoalesceState(string $value): void
	{
		update_option(self::COALESCE_OPTION, $value, false);
	}

	public static function registerSettings(): void
	{
		register_setting(
			'cofx_fixture',
			self::SETTINGS_OPTION,
			array(
				'type'              => 'array',
				'label'             => 'Fixture direct settings',
				'sanitize_callback' => array(self::class, 'sanitizeRegisteredSettings'),
			)
		);
		register_setting(
			'cofx_fixture',
			self::REGISTERED_OPTION,
			array(
				'type'              => 'array',
				'label'             => 'Fixture registered settings',
				'sanitize_callback' => array(self::class, 'sanitizeRegisteredSettings'),
			)
		);
	}

	public static function sanitizeRegisteredSettings(mixed $value): array
	{
		return is_array($value) ? $value : array();
	}

	public static function cleanup(): void
	{
		foreach (
			array(
				self::ENABLED_OPTION,
				self::SETTINGS_OPTION,
				self::CREDENTIALS_OPTION,
				self::OBSOLETE_OPTION,
				self::LAST_CHECKED_OPTION,
				self::SCHEMA_OPTION,
				self::DIRECT_OPTION,
				self::AJAX_OPTION,
				self::COALESCE_OPTION,
				self::REGISTERED_OPTION,
			) as $option
		) {
			delete_option($option);
		}
		delete_transient(self::TRANSIENT);
	}

	private static function recalculateRuntimeState(): void
	{
		update_option(self::LAST_CHECKED_OPTION, 1700000000, false);
		set_transient(
			self::TRANSIENT,
			array('status' => 'connected', 'fingerprint' => 'derived-only'),
			MINUTE_IN_SECONDS
		);
	}
}

add_action('wp_ajax_configops_fixture_save', array(SettingsFixture::class, 'handleAjaxSave'));
