<?php
/**
 * Capture adapter for Yoast SEO Free.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Adapter;

final class YoastSeoAdapter extends AbstractOptionAdapter implements DatabaseWriteAwareAdapter
{
	/** @var list<string> */
	private const CONFIG_OPTIONS = array('wpseo', 'wpseo_titles', 'wpseo_social', 'wpseo_llmstxt');

	/** @var list<string> */
	private const RUNTIME_ROOTS = array(
		'tracking',
		'toggled_tracking',
		'license_server_version',
		'ms_defaults_set',
		'ignore_search_engines_discouraged_notice',
		'version',
		'previous_version',
		'first_activated_on',
		'indexing_first_time',
		'indexing_started',
		'indexing_reason',
		'indexables_indexing_completed',
		'link_counting_completed',
		'last_known_no_unindexed',
		'last_known_no_unindexed_posts',
		'last_known_no_unindexed_terms',
		'configuration_finished_steps',
		'configuration_finished',
		'ryte_indexability',
		'show_onboarding_notice',
		'ai_enabled_pre_default',
		'import_cursors',
		'workouts_data',
		'dismiss_configuration_workout_notice',
		'dismiss_premium_deactivated_notice',
		'importing_completed',
		'first_time_install',
		'should_redirect_after_install_free',
		'activation_redirect_timestamp_free',
		'indexables_page_reading_list',
		'indexables_overview_state',
		'last_known_public_post_types',
		'last_known_public_taxonomies',
		'new_post_types',
		'new_taxonomies',
		'show_new_content_type_notification',
		'site_kit_configuration_permanently_dismissed',
		'site_kit_connected',
		'site_kit_tracking_setup_widget_loaded',
		'site_kit_tracking_first_interaction_stage',
		'site_kit_tracking_last_interaction_stage',
		'site_kit_tracking_setup_widget_temporarily_dismissed',
		'site_kit_tracking_setup_widget_permanently_dismissed',
		'google_site_kit_feature_enabled',
		'ai_free_sparks_started_on',
		'last_updated_on',
		'first_activated_by',
		'schema_aggregation_endpoint_enabled_on',
		'home_url',
		'permalink_structure',
		'dynamic_permalinks',
		'category_base_url',
		'tag_base_url',
		'custom_taxonomy_slugs',
	);

	/** @var list<string> */
	private const ENVIRONMENT_ROOTS = array(
		'index_now_key',
		'baiduverify',
		'googleverify',
		'msverify',
		'yandexverify',
		'ahrefsverify',
		'site_type',
		'has_multiple_authors',
		'environment_type',
		'wincher_website_id',
	);

	/** @var list<string> */
	private const REFERENCE_ROOTS = array(
		'least_readability_ignore_list',
		'least_seo_score_ignore_list',
		'most_linked_ignore_list',
		'least_linked_ignore_list',
	);

	/** @var list<string> */
	private const RUNTIME_OPTIONS = array(
		'wpseo_indexation',
		'wpseo_internallinks',
		'wpseo_license_server_version',
		'wpseo_onpage',
		'wpseo_recalibration_beta_mailinglist_subscription',
		'wpseo_ryte',
		'wpseo_tracking_only',
	);

	public function __construct()
	{
		$this->define('wpseo', '/enable_headless_rest_endpoints', 'Headless REST endpoints', 'Features', 'portable', 'Makes Yoast SEO metadata available through REST responses.');
		$this->define('wpseo', '/enable_admin_bar_menu', 'SEO menu in the toolbar', 'Features', 'portable', 'Shows Yoast SEO shortcuts in the WordPress toolbar.');
		$this->define('wpseo', '/enable_cornerstone_content', 'Cornerstone content', 'Features', 'portable', 'Enables cornerstone content analysis.');
		$this->define('wpseo', '/enable_text_link_counter', 'Text link counter', 'Features', 'portable', 'Counts internal links for the SEO overview.');
		$this->define('wpseo', '/enable_xml_sitemap', 'XML sitemaps', 'Site features', 'portable', 'Publishes XML sitemaps for search engines.');
		$this->define('wpseo', '/enable_index_now', 'IndexNow', 'Site features', 'environment', 'Notifies compatible search engines when public content changes.');
		$this->define('wpseo', '/environment_type', 'Website environment', 'Site identity', 'environment', 'Tells Yoast whether the site is production, staging, or development.');
		$this->define('wpseo_titles', '/company_name', 'Organization name', 'Site identity', 'environment', 'The organization represented by this website.');
		$this->define('wpseo_titles', '/company_logo', 'Organization logo URL', 'Site identity', 'environment', 'The media URL that represents this organization on this website.');
		$this->define('wpseo_titles', '/company_logo_id', 'Organization logo', 'Site identity', 'reference', 'A media item on this website; raw attachment IDs do not travel safely.');
		$this->define('wpseo_titles', '/person_logo', 'Person logo URL', 'Site identity', 'environment', 'The media URL that represents this person on this website.');
		$this->define('wpseo_titles', '/person_logo_id', 'Person logo', 'Site identity', 'reference', 'A media item on this website; raw attachment IDs do not travel safely.');
		$this->define('wpseo_titles', '/company_logo_meta', 'Organization logo cache', 'Plugin housekeeping', 'runtime', 'Yoast removed generated image metadata that is not editable on this settings screen.');
		$this->define('wpseo_titles', '/person_logo_meta', 'Person logo cache', 'Plugin housekeeping', 'runtime', 'Yoast removed generated image metadata that is not editable on this settings screen.');
		$this->define('wpseo_titles', '/website_name', 'Website name', 'Site identity', 'environment', 'The public name Yoast describes to search engines.');
		$this->define('wpseo_titles', '/alternate_website_name', 'Alternative website name', 'Site identity', 'environment', 'An optional shorter or alternative public name.');

		$this->define('wpseo_titles', '/breadcrumbs-enable', 'Breadcrumbs', 'Breadcrumbs', 'portable', 'Adds a breadcrumb trail when the theme supports it.');
		$this->define('wpseo_titles', '/breadcrumbs-sep', 'Breadcrumb separator', 'Breadcrumbs', 'portable', 'The symbol between breadcrumb items.');
		$this->define('wpseo_titles', '/breadcrumbs-home', 'Homepage breadcrumb label', 'Breadcrumbs', 'environment', 'The label used for the website homepage.');
		$this->define('wpseo_titles', '/separator', 'Title separator', 'Search appearance', 'portable', 'The symbol between title template parts.');
		$this->define('wpseo_titles', '/title-home-wpseo', 'Homepage SEO title', 'Search appearance', 'environment', 'The title template shown for the homepage.');
		$this->define('wpseo_titles', '/metadesc-home-wpseo', 'Homepage meta description', 'Search appearance', 'environment', 'The description search engines may show for the homepage.');

		$this->define('wpseo_social', '/opengraph', 'Open Graph metadata', 'Social sharing', 'portable', 'Adds metadata used when pages are shared on social networks.');
		$this->define('wpseo_social', '/twitter', 'X / Twitter metadata', 'Social sharing', 'portable', 'Adds metadata used when pages are shared on X or Twitter.');
		$this->define('wpseo_social', '/twitter_card_type', 'X / Twitter card type', 'Social sharing', 'portable', 'Controls the social preview layout.');
		$this->define('wpseo_social', '/og_default_image', 'Default social image', 'Social sharing', 'environment', 'Fallback image URL used for social previews.');
		$this->define('wpseo_social', '/og_default_image_id', 'Default social image', 'Social sharing', 'reference', 'A media item on this website; raw attachment IDs do not travel safely.');
		$this->define('wpseo_social', '/facebook_site', 'Facebook page', 'Social profiles', 'environment', 'The Facebook profile associated with this website.');
		$this->define('wpseo_social', '/instagram_url', 'Instagram profile', 'Social profiles', 'environment', 'The Instagram profile associated with this website.');
		$this->define('wpseo_social', '/linkedin_url', 'LinkedIn profile', 'Social profiles', 'environment', 'The LinkedIn profile associated with this website.');
		$this->define('wpseo_social', '/pinterest_url', 'Pinterest profile', 'Social profiles', 'environment', 'The Pinterest profile associated with this website.');
		$this->define('wpseo_social', '/pinterestverify', 'Pinterest verification', 'Connected services', 'environment', 'A verification value issued for this website.');
		$this->define('wpseo_social', '/youtube_url', 'YouTube channel', 'Social profiles', 'environment', 'The YouTube channel associated with this website.');

		$this->define('wpseo_llmstxt', '/llms_txt_selection_mode', 'LLMs.txt page selection', 'AI discovery', 'portable', 'Controls whether pages are selected automatically or manually.');
		$this->define('wpseo_llmstxt', '/about_us_page', 'About page', 'AI discovery', 'reference', 'A page on this website; raw page IDs do not travel safely.');
		$this->define('wpseo_llmstxt', '/contact_page', 'Contact page', 'AI discovery', 'reference', 'A page on this website; raw page IDs do not travel safely.');
		$this->define('wpseo_llmstxt', '/terms_page', 'Terms page', 'AI discovery', 'reference', 'A page on this website; raw page IDs do not travel safely.');
		$this->define('wpseo_llmstxt', '/privacy_policy_page', 'Privacy policy page', 'AI discovery', 'reference', 'A page on this website; raw page IDs do not travel safely.');
		$this->define('wpseo_llmstxt', '/shop_page', 'Shop page', 'AI discovery', 'reference', 'A page on this website; raw page IDs do not travel safely.');
		$this->define('wpseo_llmstxt', '/other_included_pages', 'Other included pages', 'AI discovery', 'reference', 'Pages on this website; raw page IDs do not travel safely.');
	}

	public function manifest(): AdapterManifest
	{
		return new AdapterManifest(
			'yoast-seo',
			'Yoast SEO',
			'wordpress-seo/wp-seo.php',
			'=28.2',
			2,
			array(
				array('id' => 'capture', 'label' => 'Find changes', 'level' => 'full', 'note' => 'Core Free settings options are captured; content metadata is explicitly excluded.'),
				array('id' => 'explain', 'label' => 'Explain fields', 'level' => 'partial', 'note' => 'Core feature, search appearance, social, and LLMs.txt fields are named; dynamic content-type fields use clear generated labels.'),
				array('id' => 'secrets', 'label' => 'Hide secrets', 'level' => 'full', 'note' => 'MyYoast, Semrush, Wincher, and OAuth credentials are removed before storage.'),
				array('id' => 'noise', 'label' => 'Separate technical noise', 'level' => 'full', 'note' => 'Indexing progress, migrations, tracking, and maintenance state are separated.'),
				array('id' => 'restore', 'label' => 'Undo safely', 'level' => 'partial', 'note' => 'Supported settings use field-level conflict checks while credentials, content metadata, and multisite data stay untouched.'),
				array('id' => 'apply', 'label' => 'Apply to another site', 'level' => 'planned', 'note' => 'IDs require semantic references before Release Packs can apply them elsewhere.'),
			),
			array(
				'Features, crawl, and search appearance settings',
				'Title templates, breadcrumbs, and indexing rules',
				'Social profiles, Open Graph, X / Twitter, and LLMs.txt',
				'Plugin-generated indexing, migration, tracking, and maintenance data',
			),
			array(
				'Taxonomy metadata is excluded.',
				'Multisite settings are excluded.',
				'Yoast Premium settings are not mapped.',
			),
			'https://github.com/Yoast/wordpress-seo'
		);
	}

	public function ownsOption(string $optionName): bool
	{
		return 'wpseo' === $optionName
			|| str_starts_with($optionName, 'wpseo_')
			|| str_starts_with($optionName, 'yoast_migrations_')
			|| str_starts_with($optionName, 'yst_sm_');
	}

	public function analyze(string $optionName, array $changes): AdapterAnalysis
	{
		if ('wpseo_ms' === $optionName) {
			return new AdapterAnalysis('unsupported', 'Yoast network settings belong to Multisite, which ConfigOps does not support yet.', false);
		}
		if ('wpseo_taxonomy_meta' === $optionName) {
			return new AdapterAnalysis('unsupported', 'Yoast taxonomy metadata is content, not site configuration.', false);
		}
		if (
			in_array($optionName, self::RUNTIME_OPTIONS, true)
			|| str_starts_with($optionName, 'yoast_migrations_')
			|| str_starts_with($optionName, 'yst_sm_')
			|| str_starts_with($optionName, 'wpseo_dismiss_')
		) {
			return new AdapterAnalysis('derived', 'Yoast generated this tracking, migration, or maintenance state.', false);
		}
		if (str_starts_with($optionName, 'wpseo_myyoast_')) {
			return new AdapterAnalysis('secret', 'Yoast stores a site credential in this local option. ConfigOps never persists its value.', false);
		}
		if (! in_array($optionName, self::CONFIG_OPTIONS, true)) {
			return new AdapterAnalysis('unknown', 'Yoast owns this option, but it is outside the tested Free configuration contract.');
		}

		return $this->analyzeFields($optionName, $changes);
	}

	public function field(string $optionName, string $jsonPointer): ?FieldDefinition
	{
		if (! in_array($optionName, self::CONFIG_OPTIONS, true)) {
			return null;
		}

		$field = parent::field($optionName, $jsonPointer);
		if (null === $field || 'unknown' !== $field->kind) {
			return $field;
		}

		$parts = $this->pointerParts($jsonPointer);
		$key   = (string) ($parts[array_key_last($parts)] ?? '');
		if ('wpseo' === $optionName && in_array((string) ($parts[0] ?? ''), self::RUNTIME_ROOTS, true)) {
			return new FieldDefinition($this->humanize($key), 'Plugin maintenance', 'runtime', 'Yoast generated this value while indexing, upgrading, or configuring itself.');
		}
		if ($this->pathMatchesSecret($parts) || in_array((string) ($parts[0] ?? ''), array('semrush_tokens', 'wincher_tokens', 'myyoast-oauth'), true)) {
			return new FieldDefinition($this->humanize($key), 'Connected services', 'secret', 'A connected-service credential. ConfigOps never stores its clear value.');
		}
		if ('wpseo' === $optionName && in_array((string) ($parts[0] ?? ''), self::ENVIRONMENT_ROOTS, true)) {
			return new FieldDefinition($this->humanize($key), 'Website identity', 'environment', 'This value describes or verifies the current website and should be checked per environment.');
		}
		if ('wpseo' === $optionName && in_array((string) ($parts[0] ?? ''), self::REFERENCE_ROOTS, true)) {
			return new FieldDefinition($this->humanize($key), 'Content analysis', 'reference', 'This value points to content on the current website and needs a semantic resolver before reuse.');
		}

		if ('wpseo_titles' === $optionName) {
			if (1 === preg_match('/(?:^|[-_])(id|page)$/', $key) || str_contains($key, 'logo')) {
				return new FieldDefinition($this->humanize($key), 'Search appearance', 'reference', 'This value points to content or media on this website.');
			}
			if (str_contains($key, 'image') || str_contains($key, 'logo')) {
				return new FieldDefinition($this->humanize($key), 'Search appearance', 'environment', 'This media URL belongs to the current website.');
			}
			$kind = str_contains($key, 'noindex') ? 'environment' : 'portable';

			return new FieldDefinition($this->humanize($key), 'Search appearance', $kind, 'A Yoast search appearance setting for a content type or archive.');
		}
		if ('wpseo_social' === $optionName) {
			$root = (string) ($parts[0] ?? '');
			$kind = str_ends_with($key, '_id')
				? 'reference'
				: (str_contains($key, 'url') || str_contains($key, 'site') || 'other_social_urls' === $root ? 'environment' : 'portable');

			return new FieldDefinition($this->humanize($key), 'Social sharing', $kind, 'A Yoast social profile or sharing setting.');
		}
		if ('wpseo_llmstxt' === $optionName) {
			$root = (string) ($parts[0] ?? '');
			$kind = str_contains($key, 'page') || in_array($root, array('about_us_page', 'contact_page', 'terms_page', 'privacy_policy_page', 'shop_page', 'other_included_pages'), true)
				? 'reference'
				: 'portable';

			return new FieldDefinition($this->humanize($key), 'AI discovery', $kind, 'A Yoast LLMs.txt selection setting.');
		}

		return new FieldDefinition($this->humanize($key), 'Yoast features', 'portable', 'A recognized setting in the Yoast Free general configuration option.');
	}

	public function isSensitive(string $optionName, array $path): bool
	{
		if (str_starts_with($optionName, 'wpseo_myyoast_')) {
			return true;
		}
		if ('wpseo' !== $optionName) {
			return false;
		}

		$root = (string) ($path[0] ?? '');

		return in_array($root, array('semrush_tokens', 'wincher_tokens', 'myyoast-oauth'), true)
			|| $this->pathMatchesSecret($path);
	}

	public function isKnownNonConfigurationWrite(string $table, array $source): bool
	{
		return 'wordpress-seo' === $source['component']
			&& 1 === preg_match('/(?:^|_)yoast_(?:indexable|indexable_hierarchy|migrations|primary_term|seo_links)$/', $table);
	}
}
