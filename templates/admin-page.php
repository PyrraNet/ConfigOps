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

$configopsBootstrapJson = wp_json_encode(
	$bootstrap,
	JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
$configopsIsNetwork = 'network' === $view;
$configopsPageUrl = $configopsIsNetwork ? network_admin_url('admin.php') : admin_url('admin.php');
$configopsSiteUrl = get_admin_url(get_current_blog_id(), 'admin.php?page=configops');

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
			<h1>
				<?php
				echo esc_html(
					match ($view) {
						'support' => __('Plugin support', 'configops'),
						'network' => __('Network changes', 'configops'),
						default => __('Review changes', 'configops'),
					}
				);
				?>
			</h1>
		</div>
		<nav class="configops-app-nav" aria-label="<?php esc_attr_e('ConfigOps sections', 'configops'); ?>">
			<?php if ($configopsIsNetwork) : ?>
				<a class="is-current" aria-current="page" href="<?php echo esc_url(add_query_arg('page', 'configops', $configopsPageUrl)); ?>"><?php esc_html_e('Network evidence', 'configops'); ?></a>
				<a href="<?php echo esc_url($configopsSiteUrl); ?>"><?php esc_html_e('Current site', 'configops'); ?></a>
			<?php else : ?>
				<a class="<?php echo esc_attr('review' === $view ? 'is-current' : ''); ?>" <?php if ('review' === $view) : ?>aria-current="page"<?php endif; ?> href="<?php echo esc_url(admin_url('admin.php?page=configops')); ?>"><?php esc_html_e('Changes', 'configops'); ?></a>
				<a class="<?php echo esc_attr('support' === $view ? 'is-current' : ''); ?>" <?php if ('support' === $view) : ?>aria-current="page"<?php endif; ?> href="<?php echo esc_url(admin_url('admin.php?page=configops&view=support')); ?>"><?php esc_html_e('Plugin support', 'configops'); ?></a>
			<?php endif; ?>
		</nav>
	</header>

	<?php if ($configopsIsNetwork) : ?>
		<section class="configops-network-scope" aria-labelledby="configops-network-scope-title">
			<div>
				<span><?php esc_html_e('NETWORK SCOPE', 'configops'); ?></span>
				<h2 id="configops-network-scope-title"><?php echo esc_html((string) ($bootstrap['scope']['name'] ?? __('WordPress network', 'configops'))); ?></h2>
				<p><?php esc_html_e('Changes shown here belong to the network itself, not to an individual site.', 'configops'); ?></p>
			</div>
			<dl>
				<div><dt><?php esc_html_e('Network', 'configops'); ?></dt><dd>#<?php echo esc_html((string) ($bootstrap['scope']['networkId'] ?? 0)); ?></dd></div>
				<div><dt><?php esc_html_e('Sites', 'configops'); ?></dt><dd><?php echo esc_html((string) ($bootstrap['scope']['siteCount'] ?? 0)); ?></dd></div>
				<div><dt><?php esc_html_e('Mode', 'configops'); ?></dt><dd><?php esc_html_e('Add/update undo', 'configops'); ?></dd></div>
			</dl>
		</section>
	<?php endif; ?>

	<?php if ('support' === $view) : ?>
		<main id="configops-support-island" class="configops-support configops-island" aria-live="polite" aria-busy="true">
			<div class="configops-island-placeholder configops-island-placeholder--review">
				<span></span><span></span><span></span>
			</div>
		</main>
	<?php else : ?>
		<?php if (! $configopsIsNetwork) : ?>
		<div id="configops-capture-island" class="configops-island" aria-live="polite" aria-busy="true">
			<div class="configops-island-placeholder configops-island-placeholder--controls">
				<span></span><span></span>
			</div>
		</div>
		<?php endif; ?>

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
			<h2><?php esc_html_e('Enable JavaScript to use ConfigOps.', 'configops'); ?></h2>
			<?php if ('review' === $view && null !== ($bootstrap['active'] ?? null)) : ?>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<input type="hidden" name="action" value="configops_stop_capture">
					<?php wp_nonce_field('configops_stop_capture'); ?>
					<button class="button button-primary" type="submit"><?php esc_html_e('Stop active capture', 'configops'); ?></button>
				</form>
			<?php endif; ?>
		</section>
	</noscript>

	<script id="configops-bootstrap" type="application/json"><?php echo is_string($configopsBootstrapJson) ? $configopsBootstrapJson : '{}'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON_HEX flags prevent script termination. ?></script>
</div>
