<?php
/**
 * Minimal server shell for independently hydrated ConfigOps admin islands.
 *
 * @package ConfigOps
 *
 * @var array<string, mixed> $bootstrap Initial state; prevents a first-render request waterfall.
 * @var string $view Current server-routed workspace view.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

$bootstrapJson = wp_json_encode(
	$bootstrap,
	JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);

?>

<div class="wrap configops-shell">
	<header class="configops-appbar">
		<div class="configops-app-identity">
			<img
				class="configops-wordmark"
				src="<?php echo esc_url(CONFIGOPS_URL . 'assets/brand/configops-wordmark-light.svg'); ?>"
				alt="<?php esc_attr_e('ConfigOps', 'configops'); ?>"
				width="144"
				height="32"
			>
			<span class="configops-app-divider" aria-hidden="true"></span>
			<h1><?php echo 'support' === $view ? esc_html__('Plugin support', 'configops') : esc_html__('Change review', 'configops'); ?></h1>
		</div>
		<nav class="configops-app-nav" aria-label="<?php esc_attr_e('ConfigOps sections', 'configops'); ?>">
			<a class="<?php echo esc_attr('review' === $view ? 'is-current' : ''); ?>" <?php if ('review' === $view) : ?>aria-current="page"<?php endif; ?> href="<?php echo esc_url(admin_url('admin.php?page=configops')); ?>"><?php esc_html_e('Changes', 'configops'); ?></a>
			<a class="<?php echo esc_attr('support' === $view ? 'is-current' : ''); ?>" <?php if ('support' === $view) : ?>aria-current="page"<?php endif; ?> href="<?php echo esc_url(admin_url('admin.php?page=configops&view=support')); ?>"><?php esc_html_e('Plugin support', 'configops'); ?></a>
		</nav>
	</header>

	<?php if ('support' === $view) : ?>
		<main id="configops-support-island" class="configops-support configops-island" aria-live="polite" aria-busy="true">
			<div class="configops-island-placeholder configops-island-placeholder--review">
				<span></span><span></span><span></span>
			</div>
		</main>
	<?php else : ?>
		<div id="configops-capture-island" class="configops-island" aria-live="polite" aria-busy="true">
			<div class="configops-island-placeholder configops-island-placeholder--controls">
				<span></span><span></span>
			</div>
		</div>

		<div class="configops-workspace">
			<aside id="configops-sessions-island" class="configops-session-rail configops-island" aria-label="<?php esc_attr_e('Capture sessions', 'configops'); ?>" aria-busy="true">
				<div class="configops-island-placeholder configops-island-placeholder--sessions">
					<span></span><span></span><span></span>
				</div>
			</aside>

			<main id="configops-review-island" class="configops-review configops-island" aria-live="polite" aria-busy="true">
				<div class="configops-island-placeholder configops-island-placeholder--review">
					<span></span><span></span><span></span>
				</div>
			</main>
		</div>
	<?php endif; ?>

	<noscript>
		<section class="configops-no-script">
			<h2><?php esc_html_e('JavaScript is required for the ConfigOps interface.', 'configops'); ?></h2>
			<p><?php esc_html_e('Capture remains server-authoritative; the interactive review and support views load as small React islands.', 'configops'); ?></p>
			<?php if ('review' === $view && null !== ($bootstrap['active'] ?? null)) : ?>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<input type="hidden" name="action" value="configops_stop_capture">
					<?php wp_nonce_field('configops_stop_capture'); ?>
					<button class="button button-primary" type="submit"><?php esc_html_e('Stop active capture', 'configops'); ?></button>
				</form>
			<?php endif; ?>
		</section>
	</noscript>

	<script id="configops-bootstrap" type="application/json"><?php echo is_string($bootstrapJson) ? $bootstrapJson : '{}'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON_HEX flags prevent script termination. ?></script>
</div>
