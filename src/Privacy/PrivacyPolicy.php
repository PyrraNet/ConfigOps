<?php
/**
 * Suggested site privacy-policy text for locally stored capture evidence.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Privacy;

final class PrivacyPolicy
{
	public function register(): void
	{
		add_action('admin_init', array($this, 'addPolicyContent'));
	}

	public function addPolicyContent(): void
	{
		if (! function_exists('wp_add_privacy_policy_content')) {
			return;
		}

		$content = '<p>' . esc_html__(
			'While a capture is active, ConfigOps stores configuration-change evidence in this website’s database. This can include option names and values, nested differences, the acting WordPress user ID, the admin screen and request path, and a relative source file and line number. Probable credentials are removed before the evidence is stored, but other configuration values can contain personal data entered into a setting.',
			'configops'
		) . '</p>';
		$content .= '<p>' . esc_html__(
			'ConfigOps does not send this evidence to pyrra or another external service. Users with the ConfigOps viewing capability can review it. Completed and interrupted captures are deleted after 30 days by default; site developers can change that retention period with the configops_retention_days filter.',
			'configops'
		) . '</p>';

		wp_add_privacy_policy_content(
			esc_html__('ConfigOps', 'configops'),
			wp_kses_post($content)
		);
	}
}
