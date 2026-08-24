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
		'default_seo_title',
		'default_seo_meta_desc',
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
		'wpseo_dismissed_conflicts',
		'wpseo_indexation',
		'wpseo_internallinks',
		'wpseo_license_server_version',
		'wpseo_llms_txt_content_hash',
		'wpseo_llms_txt_file_failure',
		'wpseo_onpage',
		'wpseo_recalibration_beta_mailinglist_subscription',
		'wpseo_ryte',
		'wpseo_tracking_last_request',
		'wpseo_tracking_only',
		'wpseo_upgrade_history',
		'wpseo-cleanup-current-task',
	);

	public function __construct()
	{
		$this->defineGeneralFields();
		$this->defineTitleFields();
		$this->defineSocialFields();
		$this->defineLlmFields();
	}

	public function manifest(): AdapterManifest
	{
		return new AdapterManifest(
			'yoast-seo',
			'Yoast SEO',
			'wordpress-seo/wp-seo.php',
			'>=28.1 <28.4',
			3,
			array(
				array('id' => 'capture', 'label' => 'Record Options API writes', 'level' => 'full', 'note' => 'The active 28.1, 28.2, and 28.3 Free settings groups and dynamic content-type/taxonomy families are captured; content metadata is excluded.'),
				array('id' => 'explain', 'label' => 'Map settings fields', 'level' => 'full', 'note' => 'Features, integrations, crawl cleanup, schema, identity, search appearance, social, and LLMs.txt paths have pinned semantics.'),
				array('id' => 'secrets', 'label' => 'Redact credentials', 'level' => 'full', 'note' => 'MyYoast, Semrush, Wincher, and OAuth credentials are removed before storage.'),
				array('id' => 'noise', 'label' => 'Classify runtime values', 'level' => 'full', 'note' => 'Indexing, caches, migrations, dismissals, tracking, LLMs.txt generation state, and maintenance writes are separated.'),
				array('id' => 'restore', 'label' => 'Conflict-checked undo', 'level' => 'partial', 'note' => 'Supported settings use field-level conflict checks; media and content IDs must still resolve before undo.'),
				array('id' => 'apply', 'label' => 'Cross-site apply', 'level' => 'planned', 'note' => 'Cross-site apply is not implemented; local IDs need an explicit reference-resolution contract.'),
			),
			array(
				'Site features, analyses, integrations, crawl cleanup, bot rules, IndexNow, schema, and AI controls',
				'Site identity, publisher policies, title/meta templates, breadcrumbs, archives, content types, and taxonomies',
				'Social profiles, Open Graph, every dynamic social-image ID, X / Twitter, and LLMs.txt page references',
				'Plugin-generated indexing, cache, migration, dismissal, tracking, and maintenance data',
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
		return in_array($optionName, self::RUNTIME_OPTIONS, true)
			|| 'wpseo' === $optionName
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
			return new FieldDefinition('Ignored content', 'Content analysis', 'reference', 'This item points to content on the current website; ConfigOps keeps its local identity and verifies it before undo.', 'content');
		}

		if ('wpseo_titles' === $optionName) {
			if (str_starts_with($key, 'social-image-id-')) {
				return new FieldDefinition(
					$this->dynamicLabel($key, 'social-image-id-', ' social image'),
					'Social appearance',
					'reference',
					'A media item selected for this content type or archive; ConfigOps keeps its local attachment identity.',
					'media'
				);
			}
			if (str_starts_with($key, 'social-image-url-')) {
				return new FieldDefinition($this->dynamicLabel($key, 'social-image-url-', ' social image URL'), 'Social appearance', 'environment', 'This media URL belongs to the current website.');
			}
			if (str_starts_with($key, 'social-title-')) {
				return new FieldDefinition($this->dynamicLabel($key, 'social-title-', ' social title'), 'Social appearance', 'portable', 'The social-title template for this content type or archive.');
			}
			if (str_starts_with($key, 'social-description-')) {
				return new FieldDefinition($this->dynamicLabel($key, 'social-description-', ' social description'), 'Social appearance', 'portable', 'The social-description template for this content type or archive.');
			}
			if (str_starts_with($key, 'title-')) {
				return new FieldDefinition($this->dynamicLabel($key, 'title-', ' SEO title'), 'Search appearance', 'portable', 'The SEO-title template for this content type or archive.');
			}
			if (str_starts_with($key, 'metadesc-')) {
				return new FieldDefinition($this->dynamicLabel($key, 'metadesc-', ' meta description'), 'Search appearance', 'portable', 'The meta-description template for this content type or archive.');
			}
			if (str_starts_with($key, 'noindex-')) {
				return new FieldDefinition($this->dynamicLabel($key, 'noindex-', ' search visibility'), 'Search appearance', 'environment', 'Controls whether this content type or archive should appear in search results on this website.');
			}
			if (str_starts_with($key, 'display-metabox-')) {
				return new FieldDefinition($this->dynamicLabel($key, 'display-metabox-', ' SEO controls'), 'Editor', 'portable', 'Controls whether Yoast SEO controls appear for this content type or taxonomy.');
			}
			if (str_starts_with($key, 'schema-page-type-')) {
				return new FieldDefinition($this->dynamicLabel($key, 'schema-page-type-', ' schema page type'), 'Schema', 'portable', 'The default Schema.org page type for this content type.');
			}
			if (str_starts_with($key, 'schema-article-type-')) {
				return new FieldDefinition($this->dynamicLabel($key, 'schema-article-type-', ' schema article type'), 'Schema', 'portable', 'The default Schema.org article type for this content type.');
			}
			if (str_starts_with($key, 'bctitle-ptarchive-')) {
				return new FieldDefinition($this->dynamicLabel($key, 'bctitle-ptarchive-', ' archive breadcrumb title'), 'Breadcrumbs', 'portable', 'The breadcrumb label for this post-type archive.');
			}
			if (str_starts_with($key, 'post_types-') || str_starts_with($key, 'taxonomy-')) {
				return new FieldDefinition($this->humanize($key), 'Content relationships', 'portable', 'A pinned Yoast relationship between a content type and its primary taxonomy.');
			}

			return new FieldDefinition($this->humanize($key), 'Search appearance', 'unknown', 'This path is outside the tested Yoast SEO Free 28.1–28.3 field contract. ConfigOps keeps it visible but does not guess during undo.');
		}
		if ('wpseo_social' === $optionName) {
			$root = (string) ($parts[0] ?? '');
			if ('other_social_urls' === $root) {
				return new FieldDefinition('Other social profile', 'Social profiles', 'environment', 'A public profile URL associated with this website or organization.');
			}

			return new FieldDefinition($this->humanize($key), 'Social sharing', 'unknown', 'This path is outside the tested Yoast SEO Free 28.1–28.3 social field contract.');
		}
		if ('wpseo_llmstxt' === $optionName) {
			$root = (string) ($parts[0] ?? '');
			if (in_array($root, array('about_us_page', 'contact_page', 'terms_page', 'privacy_policy_page', 'shop_page', 'other_included_pages'), true)) {
				return new FieldDefinition('Included page', 'AI discovery', 'reference', 'A page included in this website’s LLMs.txt file; ConfigOps keeps its local content identity.', 'content');
			}

			return new FieldDefinition($this->humanize($key), 'AI discovery', 'unknown', 'This path is outside the tested Yoast SEO Free 28.1–28.3 LLMs.txt field contract.');
		}

		return new FieldDefinition($this->humanize($key), 'Yoast features', 'unknown', 'This path is outside the tested Yoast SEO Free 28.1–28.3 field contract. ConfigOps keeps it visible but does not guess during undo.');
	}

	private function defineGeneralFields(): void
	{
		$this->defineFields(
			'wpseo',
			array(
				'/disableadvanced_meta' => array('Restrict advanced settings for authors', 'Access', 'portable', 'Limits advanced indexing and canonical controls to users with the Yoast capability.'),
				'/enable_headless_rest_endpoints' => array('REST API endpoint', 'Site features', 'portable', 'Makes Yoast SEO metadata available through REST responses.'),
				'/content_analysis_active' => array('Readability analysis', 'Analysis', 'portable', 'Runs Yoast’s readability analysis in supported editors.'),
				'/keyword_analysis_active' => array('SEO analysis', 'Analysis', 'portable', 'Runs Yoast’s keyphrase and SEO analysis in supported editors.'),
				'/inclusive_language_analysis_active' => array('Inclusive language analysis', 'Analysis', 'portable', 'Runs inclusive-language checks when the site language is supported.'),
				'/enable_admin_bar_menu' => array('Admin bar menu', 'Site features', 'portable', 'Shows Yoast SEO shortcuts in the WordPress toolbar.'),
				'/enable_cornerstone_content' => array('Cornerstone content', 'Analysis', 'portable', 'Enables cornerstone-content marking and analysis.'),
				'/enable_xml_sitemap' => array('XML sitemaps', 'Discovery', 'portable', 'Publishes XML sitemaps for search engines.'),
				'/enable_text_link_counter' => array('Text link counter', 'Analysis', 'portable', 'Counts internal links for the SEO overview.'),
				'/enable_index_now' => array('IndexNow', 'Discovery', 'environment', 'Notifies compatible search engines when public content changes on this website.'),
				'/enable_ai_generator' => array('AI title and description generator', 'AI features', 'portable', 'Makes Yoast’s AI-assisted title and description generator available in the editor.'),
				'/enable_enhanced_slack_sharing' => array('Slack sharing', 'Social sharing', 'portable', 'Adds enhanced metadata when this website’s URLs are shared in Slack.'),
				'/enable_metabox_insights' => array('Content insights', 'Analysis', 'portable', 'Shows Yoast content-insight data in supported editors.'),
				'/enable_link_suggestions' => array('Internal link suggestions', 'Analysis', 'portable', 'Enables Yoast’s internal-link suggestion feature where available.'),
				'/enable_task_list' => array('SEO task list', 'Site features', 'portable', 'Shows the Yoast task list for site-optimization work.'),
				'/enable_schema' => array('Schema Framework', 'Schema', 'portable', 'Outputs Yoast’s Schema.org graph on the public website.'),
				'/enable_schema_aggregation_endpoint' => array('Schema aggregation endpoint', 'Schema', 'portable', 'Exposes the site-wide Schema graph through Yoast’s aggregation endpoint.'),
				'/enable_llms_txt' => array('LLMs.txt', 'AI discovery', 'environment', 'Generates an LLMs.txt file from content selected on this website.'),
				'/semrush_integration_active' => array('Semrush integration', 'Integrations', 'portable', 'Enables Semrush related-keyphrase data in the Yoast editor.'),
				'/semrush_country_code' => array('Semrush country database', 'Integrations', 'portable', 'Selects the regional Semrush keyword database.'),
				'/algolia_integration_active' => array('Algolia integration', 'Integrations', 'portable', 'Enables Yoast’s Algolia integration when the companion service is available.'),
				'/wincher_integration_active' => array('Wincher integration', 'Integrations', 'portable', 'Enables Wincher ranking data in Yoast SEO.'),
				'/wincher_automatically_add_keyphrases' => array('Automatically track keyphrases in Wincher', 'Integrations', 'portable', 'Adds new focus keyphrases to the connected Wincher account.'),
				'/index_now_key' => array('IndexNow site key', 'Connected services', 'environment', 'A verification key generated for this website’s IndexNow endpoint.'),
				'/baiduverify' => array('Baidu verification', 'Site verification', 'environment', 'A verification value issued for this website.'),
				'/googleverify' => array('Google verification', 'Site verification', 'environment', 'A verification value issued for this website.'),
				'/msverify' => array('Bing verification', 'Site verification', 'environment', 'A verification value issued for this website.'),
				'/yandexverify' => array('Yandex verification', 'Site verification', 'environment', 'A verification value issued for this website.'),
				'/ahrefsverify' => array('Ahrefs verification', 'Site verification', 'environment', 'A verification value issued for this website.'),
				'/site_type' => array('Website type', 'Site identity', 'environment', 'Describes the kind of website Yoast is configuring.'),
				'/has_multiple_authors' => array('Multiple authors', 'Site identity', 'environment', 'Tells Yoast whether multiple people publish content on this website.'),
				'/environment_type' => array('Website environment', 'Site identity', 'environment', 'Tells Yoast whether the site is production, staging, development, or local.'),
				'/wincher_website_id' => array('Wincher website', 'Connected services', 'environment', 'Identifies this website inside the connected Wincher account.'),
				'/remove_feed_global' => array('Remove global feed', 'Crawl cleanup', 'portable', 'Removes the site-wide RSS feed.'),
				'/remove_feed_global_comments' => array('Remove global comment feed', 'Crawl cleanup', 'portable', 'Removes the site-wide comment feed.'),
				'/remove_feed_post_comments' => array('Remove post comment feeds', 'Crawl cleanup', 'portable', 'Removes comment feeds for individual posts.'),
				'/remove_feed_authors' => array('Remove author feeds', 'Crawl cleanup', 'portable', 'Removes feeds for author archives.'),
				'/remove_feed_categories' => array('Remove category feeds', 'Crawl cleanup', 'portable', 'Removes feeds for category archives.'),
				'/remove_feed_tags' => array('Remove tag feeds', 'Crawl cleanup', 'portable', 'Removes feeds for tag archives.'),
				'/remove_feed_custom_taxonomies' => array('Remove custom taxonomy feeds', 'Crawl cleanup', 'portable', 'Removes feeds for public custom taxonomies.'),
				'/remove_feed_post_types' => array('Remove custom post-type feeds', 'Crawl cleanup', 'portable', 'Removes feeds for public custom post types.'),
				'/remove_feed_search' => array('Remove search-result feeds', 'Crawl cleanup', 'portable', 'Removes feeds for internal search results.'),
				'/remove_atom_rdf_feeds' => array('Remove Atom and RDF feed links', 'Crawl cleanup', 'portable', 'Removes legacy Atom and RDF feed discovery links.'),
				'/remove_shortlinks' => array('Remove shortlinks', 'Crawl cleanup', 'portable', 'Removes WordPress shortlink output from page headers.'),
				'/remove_rest_api_links' => array('Remove REST API links', 'Crawl cleanup', 'portable', 'Removes REST API discovery links from page headers.'),
				'/remove_rsd_wlw_links' => array('Remove RSD and WLW links', 'Crawl cleanup', 'portable', 'Removes legacy remote-publishing discovery links.'),
				'/remove_oembed_links' => array('Remove oEmbed links', 'Crawl cleanup', 'portable', 'Removes oEmbed discovery links from page headers.'),
				'/remove_generator' => array('Remove generator metadata', 'Crawl cleanup', 'portable', 'Removes the WordPress generator version tag.'),
				'/remove_emoji_scripts' => array('Remove emoji scripts', 'Crawl cleanup', 'portable', 'Removes WordPress emoji scripts and styles from the public site.'),
				'/remove_powered_by_header' => array('Remove X-Powered-By header', 'Crawl cleanup', 'portable', 'Removes the X-Powered-By response header when possible.'),
				'/remove_pingback_header' => array('Remove pingback header', 'Crawl cleanup', 'portable', 'Removes the X-Pingback response header.'),
				'/clean_campaign_tracking_urls' => array('Clean campaign-tracking URLs', 'Crawl cleanup', 'portable', 'Redirects known campaign parameters to a canonical clean URL.'),
				'/clean_permalinks' => array('Clean unrecognized URL parameters', 'Crawl cleanup', 'environment', 'Redirects unrecognized query parameters and must be checked against this website’s integrations.'),
				'/clean_permalinks_extra_variables' => array('Allowed extra URL parameters', 'Crawl cleanup', 'environment', 'Keeps website-specific query parameters when permalink cleanup is active.'),
				'/search_cleanup' => array('Clean internal search URLs', 'Crawl cleanup', 'portable', 'Applies Yoast’s internal-search crawl cleanup rules.'),
				'/search_cleanup_emoji' => array('Block emoji searches', 'Crawl cleanup', 'portable', 'Prevents internal search URLs made only from emoji.'),
				'/search_cleanup_patterns' => array('Block search-pattern spam', 'Crawl cleanup', 'portable', 'Prevents common internal-search spam patterns.'),
				'/search_character_limit' => array('Search-term length limit', 'Crawl cleanup', 'portable', 'Limits the length of internal search terms exposed to crawlers.'),
				'/deny_search_crawling' => array('Block internal search results', 'Crawl controls', 'portable', 'Adds a robots rule that blocks crawling internal search results.'),
				'/deny_wp_json_crawling' => array('Block WordPress JSON endpoints', 'Crawl controls', 'portable', 'Adds a robots rule for WordPress JSON API paths.'),
				'/deny_adsbot_crawling' => array('Block AdsBot', 'AI and bot controls', 'portable', 'Adds a robots rule that blocks Google AdsBot.'),
				'/deny_ccbot_crawling' => array('Block CCBot', 'AI and bot controls', 'portable', 'Adds a robots rule that blocks Common Crawl’s CCBot.'),
				'/deny_google_extended_crawling' => array('Block Google-Extended', 'AI and bot controls', 'portable', 'Adds a robots rule that blocks Google-Extended.'),
				'/deny_gptbot_crawling' => array('Block GPTBot', 'AI and bot controls', 'portable', 'Adds a robots rule that blocks GPTBot.'),
				'/redirect_search_pretty_urls' => array('Redirect pretty search URLs', 'Crawl cleanup', 'portable', 'Redirects pretty internal-search paths to Yoast’s preferred search URL format.'),
			)
		);
	}

	private function defineTitleFields(): void
	{
		$this->defineFields(
			'wpseo_titles',
			array(
				'/forcerewritetitle' => array('Force title rewriting', 'Search appearance', 'environment', 'Uses output buffering to rewrite document titles when the theme does not support WordPress title tags correctly.'),
				'/separator' => array('Title separator', 'Search appearance', 'portable', 'The symbol between parts of Yoast title templates.'),
				'/title-home-wpseo' => array('Homepage SEO title', 'Homepage appearance', 'environment', 'The title template shown for this website’s homepage.'),
				'/title-author-wpseo' => array('Author archive SEO title', 'Archive appearance', 'portable', 'The title template for author archives.'),
				'/title-archive-wpseo' => array('Date archive SEO title', 'Archive appearance', 'portable', 'The title template for date archives.'),
				'/title-search-wpseo' => array('Search-results SEO title', 'Archive appearance', 'portable', 'The title template for internal search results.'),
				'/title-404-wpseo' => array('404 page title', 'Archive appearance', 'portable', 'The title template for not-found pages.'),
				'/metadesc-home-wpseo' => array('Homepage meta description', 'Homepage appearance', 'environment', 'The description search engines may show for this website’s homepage.'),
				'/metadesc-author-wpseo' => array('Author archive meta description', 'Archive appearance', 'portable', 'The meta-description template for author archives.'),
				'/metadesc-archive-wpseo' => array('Date archive meta description', 'Archive appearance', 'portable', 'The meta-description template for date archives.'),
				'/rssbefore' => array('Content before RSS posts', 'RSS', 'portable', 'Text and variables inserted before each post in RSS feeds.'),
				'/rssafter' => array('Content after RSS posts', 'RSS', 'portable', 'Text and variables inserted after each post in RSS feeds.'),
				'/noindex-author-wpseo' => array('Author archive search visibility', 'Archive appearance', 'environment', 'Controls whether author archives should appear in search results.'),
				'/noindex-author-noposts-wpseo' => array('Empty author archive visibility', 'Archive appearance', 'environment', 'Controls indexing when an author has no published posts.'),
				'/noindex-archive-wpseo' => array('Date archive search visibility', 'Archive appearance', 'environment', 'Controls whether date archives should appear in search results.'),
				'/disable-author' => array('Author archives', 'Archive appearance', 'environment', 'Disables author archive pages and redirects them when enabled.'),
				'/disable-date' => array('Date archives', 'Archive appearance', 'environment', 'Disables date archive pages and redirects them when enabled.'),
				'/disable-post_format' => array('Post-format archives', 'Archive appearance', 'environment', 'Disables post-format archive pages.'),
				'/disable-attachment' => array('Attachment-page redirects', 'Media', 'portable', 'Redirects attachment pages to their media files or parent content.'),
				'/breadcrumbs-enable' => array('Breadcrumbs', 'Breadcrumbs', 'portable', 'Adds a breadcrumb trail when the theme supports Yoast breadcrumbs.'),
				'/breadcrumbs-sep' => array('Breadcrumb separator', 'Breadcrumbs', 'portable', 'The symbol between breadcrumb items.'),
				'/breadcrumbs-home' => array('Homepage breadcrumb label', 'Breadcrumbs', 'environment', 'The label used for this website’s homepage.'),
				'/breadcrumbs-prefix' => array('Breadcrumb prefix', 'Breadcrumbs', 'portable', 'Text shown before a breadcrumb trail.'),
				'/breadcrumbs-archiveprefix' => array('Archive breadcrumb prefix', 'Breadcrumbs', 'portable', 'Text shown before archive breadcrumb titles.'),
				'/breadcrumbs-searchprefix' => array('Search breadcrumb prefix', 'Breadcrumbs', 'portable', 'Text shown before search-result breadcrumb titles.'),
				'/breadcrumbs-404crumb' => array('404 breadcrumb label', 'Breadcrumbs', 'portable', 'The breadcrumb label for not-found pages.'),
				'/breadcrumbs-display-blog-page' => array('Show blog page in breadcrumbs', 'Breadcrumbs', 'portable', 'Includes the posts page in breadcrumb trails for posts.'),
				'/breadcrumbs-boldlast' => array('Emphasize final breadcrumb', 'Breadcrumbs', 'portable', 'Adds emphasis to the current-page breadcrumb.'),
				'/website_name' => array('Website name', 'Site identity', 'environment', 'The public name Yoast describes to search engines.'),
				'/alternate_website_name' => array('Alternative website name', 'Site identity', 'environment', 'An optional shorter or alternative public name.'),
				'/company_or_person' => array('Site represents', 'Site identity', 'environment', 'Declares whether this website represents an organization or a person.'),
				'/company_name' => array('Organization name', 'Site identity', 'environment', 'The organization represented by this website.'),
				'/company_alternate_name' => array('Alternative organization name', 'Site identity', 'environment', 'An alternative public name for the organization.'),
				'/person_name' => array('Person name', 'Site identity', 'environment', 'The person represented by this website.'),
				'/company_or_person_user_id' => array('Represented WordPress user', 'Site identity', 'reference', 'A user on this website; ConfigOps keeps only bounded local identity.', 'user'),
				'/company_logo' => array('Organization logo URL', 'Site identity', 'environment', 'The media URL that represents this organization on this website.'),
				'/company_logo_id' => array('Organization logo', 'Site identity', 'reference', 'A media item on this website; ConfigOps keeps its local attachment identity.', 'media'),
				'/person_logo' => array('Person logo URL', 'Site identity', 'environment', 'The media URL that represents this person on this website.'),
				'/person_logo_id' => array('Person logo', 'Site identity', 'reference', 'A media item on this website; ConfigOps keeps its local attachment identity.', 'media'),
				'/company_logo_meta' => array('Organization logo cache', 'Plugin housekeeping', 'runtime', 'Yoast generated image metadata that is not an editable setting.'),
				'/person_logo_meta' => array('Person logo cache', 'Plugin housekeeping', 'runtime', 'Yoast generated image metadata that is not an editable setting.'),
				'/stripcategorybase' => array('Category URL prefix', 'Permalinks', 'portable', 'Controls whether category archive URLs keep the category prefix.'),
				'/open_graph_frontpage_title' => array('Homepage social title', 'Homepage appearance', 'environment', 'The title used when this website’s homepage is shared.'),
				'/open_graph_frontpage_desc' => array('Homepage social description', 'Homepage appearance', 'environment', 'The description used when this website’s homepage is shared.'),
				'/open_graph_frontpage_image' => array('Homepage social image URL', 'Homepage appearance', 'environment', 'The media URL used when this website’s homepage is shared.'),
				'/open_graph_frontpage_image_id' => array('Homepage social image', 'Homepage appearance', 'reference', 'A media item selected for homepage sharing; ConfigOps keeps its local attachment identity.', 'media'),
				'/social-title-author-wpseo' => array('Author archive social title', 'Social appearance', 'portable', 'The social-title template for author archives.'),
				'/social-title-archive-wpseo' => array('Date archive social title', 'Social appearance', 'portable', 'The social-title template for date archives.'),
				'/social-description-author-wpseo' => array('Author archive social description', 'Social appearance', 'portable', 'The social-description template for author archives.'),
				'/social-description-archive-wpseo' => array('Date archive social description', 'Social appearance', 'portable', 'The social-description template for date archives.'),
				'/social-image-url-author-wpseo' => array('Author archive social image URL', 'Social appearance', 'environment', 'The media URL used when an author archive is shared.'),
				'/social-image-url-archive-wpseo' => array('Date archive social image URL', 'Social appearance', 'environment', 'The media URL used when a date archive is shared.'),
				'/social-image-id-author-wpseo' => array('Author archive social image', 'Social appearance', 'reference', 'A media item selected for author-archive sharing.', 'media'),
				'/social-image-id-archive-wpseo' => array('Date archive social image', 'Social appearance', 'reference', 'A media item selected for date-archive sharing.', 'media'),
				'/publishing_principles_id' => array('Publishing principles page', 'Publisher policies', 'reference', 'A page on this website describing its publishing principles.', 'content'),
				'/ownership_funding_info_id' => array('Ownership and funding page', 'Publisher policies', 'reference', 'A page on this website describing ownership and funding.', 'content'),
				'/actionable_feedback_policy_id' => array('Feedback policy page', 'Publisher policies', 'reference', 'A page on this website describing how feedback is handled.', 'content'),
				'/corrections_policy_id' => array('Corrections policy page', 'Publisher policies', 'reference', 'A page on this website describing its corrections policy.', 'content'),
				'/ethics_policy_id' => array('Ethics policy page', 'Publisher policies', 'reference', 'A page on this website describing its ethics policy.', 'content'),
				'/diversity_policy_id' => array('Diversity policy page', 'Publisher policies', 'reference', 'A page on this website describing its diversity policy.', 'content'),
				'/diversity_staffing_report_id' => array('Diversity staffing report', 'Publisher policies', 'reference', 'A page on this website containing its diversity staffing report.', 'content'),
				'/org-description' => array('Organization description', 'Organization details', 'environment', 'A public description of the organization represented by this website.'),
				'/org-email' => array('Organization email', 'Organization details', 'environment', 'The public contact email for this organization.'),
				'/org-phone' => array('Organization phone', 'Organization details', 'environment', 'The public contact phone number for this organization.'),
				'/org-legal-name' => array('Organization legal name', 'Organization details', 'environment', 'The registered legal name of the organization.'),
				'/org-founding-date' => array('Organization founding date', 'Organization details', 'environment', 'The organization’s founding date.'),
				'/org-number-employees' => array('Organization employee count', 'Organization details', 'environment', 'The organization’s employee-count range.'),
				'/org-vat-id' => array('VAT ID', 'Organization identifiers', 'environment', 'The organization’s VAT identifier.'),
				'/org-tax-id' => array('Tax ID', 'Organization identifiers', 'environment', 'The organization’s tax identifier.'),
				'/org-iso' => array('ISO 6523 identifier', 'Organization identifiers', 'environment', 'The organization’s ISO 6523 identifier.'),
				'/org-duns' => array('DUNS number', 'Organization identifiers', 'environment', 'The organization’s Dun & Bradstreet identifier.'),
				'/org-leicode' => array('LEI code', 'Organization identifiers', 'environment', 'The organization’s Legal Entity Identifier.'),
				'/org-naics' => array('NAICS code', 'Organization identifiers', 'environment', 'The organization’s NAICS industry code.'),
			)
		);
	}

	private function defineSocialFields(): void
	{
		$this->defineFields(
			'wpseo_social',
			array(
				'/opengraph' => array('Open Graph metadata', 'Social sharing', 'portable', 'Adds metadata used when pages are shared on social networks.'),
				'/twitter' => array('X / Twitter metadata', 'Social sharing', 'portable', 'Adds metadata used when pages are shared on X or Twitter.'),
				'/twitter_card_type' => array('X / Twitter card type', 'Social sharing', 'portable', 'Controls the social preview layout.'),
				'/og_default_image' => array('Default social image URL', 'Social sharing', 'environment', 'Fallback media URL used for social previews.'),
				'/og_default_image_id' => array('Default social image', 'Social sharing', 'reference', 'A fallback media item on this website; ConfigOps keeps its local attachment identity.', 'media'),
				'/og_frontpage_title' => array('Homepage Open Graph title', 'Homepage sharing', 'environment', 'The Open Graph title used for this website’s homepage.'),
				'/og_frontpage_desc' => array('Homepage Open Graph description', 'Homepage sharing', 'environment', 'The Open Graph description used for this website’s homepage.'),
				'/og_frontpage_image' => array('Homepage Open Graph image URL', 'Homepage sharing', 'environment', 'The media URL used when this website’s homepage is shared.'),
				'/og_frontpage_image_id' => array('Homepage Open Graph image', 'Homepage sharing', 'reference', 'A media item selected for homepage sharing; ConfigOps keeps its local attachment identity.', 'media'),
				'/facebook_site' => array('Facebook page', 'Social profiles', 'environment', 'The Facebook profile associated with this website.'),
				'/instagram_url' => array('Instagram profile', 'Social profiles', 'environment', 'The Instagram profile associated with this website.'),
				'/linkedin_url' => array('LinkedIn profile', 'Social profiles', 'environment', 'The LinkedIn profile associated with this website.'),
				'/myspace_url' => array('Myspace profile', 'Social profiles', 'environment', 'The Myspace profile associated with this website.'),
				'/pinterest_url' => array('Pinterest profile', 'Social profiles', 'environment', 'The Pinterest profile associated with this website.'),
				'/pinterestverify' => array('Pinterest verification', 'Site verification', 'environment', 'A Pinterest verification value issued for this website.'),
				'/twitter_site' => array('X / Twitter username', 'Social profiles', 'environment', 'The X or Twitter account associated with this website.'),
				'/youtube_url' => array('YouTube channel', 'Social profiles', 'environment', 'The YouTube channel associated with this website.'),
				'/wikipedia_url' => array('Wikipedia page', 'Social profiles', 'environment', 'The Wikipedia page associated with this website or organization.'),
				'/mastodon_url' => array('Mastodon profile', 'Social profiles', 'environment', 'The Mastodon profile associated with this website.'),
				'/other_social_urls' => array('Other social profiles', 'Social profiles', 'environment', 'Additional public profiles associated with this website or organization.'),
			)
		);
	}

	private function defineLlmFields(): void
	{
		$this->defineFields(
			'wpseo_llmstxt',
			array(
				'/llms_txt_selection_mode' => array('LLMs.txt page selection', 'AI discovery', 'portable', 'Controls whether pages are selected automatically or manually.'),
				'/about_us_page' => array('About page', 'AI discovery', 'reference', 'A page included in this website’s LLMs.txt file.', 'content'),
				'/contact_page' => array('Contact page', 'AI discovery', 'reference', 'A page included in this website’s LLMs.txt file.', 'content'),
				'/terms_page' => array('Terms page', 'AI discovery', 'reference', 'A page included in this website’s LLMs.txt file.', 'content'),
				'/privacy_policy_page' => array('Privacy policy page', 'AI discovery', 'reference', 'A page included in this website’s LLMs.txt file.', 'content'),
				'/shop_page' => array('Shop page', 'AI discovery', 'reference', 'A page included in this website’s LLMs.txt file.', 'content'),
				'/other_included_pages' => array('Other included pages', 'AI discovery', 'reference', 'Additional pages included in this website’s LLMs.txt file.', 'content'),
			)
		);
	}

	private function dynamicLabel(string $key, string $prefix, string $suffix): string
	{
		$target = substr($key, strlen($prefix));
		$target = preg_replace('/^(?:ptarchive|tax)-/', '', $target) ?? $target;

		return $this->humanize($target) . $suffix;
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
