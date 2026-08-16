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
			'ConfigOps automatically stores configuration-change evidence from authorized administrative settings requests and from explicitly named Change Sessions in this website’s database. This can include option names and values, nested differences, bounded identity for referenced media or content such as a filename or post title, the acting WordPress user ID, the admin screen and request path, and a relative source file and line number. A short-lived, same-site browser cookie may carry the names and visible labels of admin fields the operator changed so ConfigOps can correlate likely intent with the saved option; it does not contain those field values. Probable credentials are removed before the evidence is stored, but other configuration values and reference labels can contain personal data entered into the website.',
			'configops'
		) . '</p>';
		$content .= '<p>' . esc_html__(
			'ConfigOps does not send this evidence to pyrra or another external service. Users with the ConfigOps viewing capability can review it. While ConfigOps is active, completed and interrupted captures are deleted after 30 days by default; site developers can change that retention period with the configops_retention_days filter. Uninstalling ConfigOps removes its capture history and installation data.',
			'configops'
		) . '</p>';

		wp_add_privacy_policy_content(
			esc_html__('ConfigOps', 'configops'),
			wp_kses_post($content)
		);
	}
}
