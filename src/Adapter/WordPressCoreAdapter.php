<?php
/**
 * Capture adapter for the WordPress single-site settings contract.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Adapter;

final class WordPressCoreAdapter extends AbstractOptionAdapter implements OptionValueNormalizer
{
	/** @var array<string, true> */
	private array $ownedOptions = array();

	/** @var list<string> */
	private const HIGH_RISK_OPTIONS = array('home', 'siteurl');

	public function __construct()
	{
		$this->defineGeneralSettings();
		$this->defineWritingSettings();
		$this->defineReadingSettings();
		$this->defineDiscussionSettings();
		$this->defineMediaSettings();
		$this->definePermalinkSettings();
	}

	public function manifest(): AdapterManifest
	{
		return new AdapterManifest(
			'wordpress-core',
			'WordPress Core',
			'wp-includes/version.php',
			'>=7.0 <7.2',
			1,
			array(
				array('id' => 'capture', 'label' => 'Record Options API writes', 'level' => 'full', 'note' => 'The standard single-site General, Writing, Reading, Discussion, Media, and Permalink options are captured.'),
				array('id' => 'explain', 'label' => 'Map settings fields', 'level' => 'full', 'note' => 'Common WordPress options receive stable names and portable, per-website, or local-reference semantics.'),
				array('id' => 'secrets', 'label' => 'Redact credentials', 'level' => 'full', 'note' => 'The mapped Core settings contain no credentials; ConfigOps still applies its conservative global secret detector.'),
				array('id' => 'noise', 'label' => 'Classify runtime values', 'level' => 'full', 'note' => 'Core caches, update state, rewrite rules, cron, locks, and migration markers remain outside the settings decision set.'),
				array('id' => 'restore', 'label' => 'Conflict-checked undo', 'level' => 'partial', 'note' => 'Ordinary settings use conflict-checked undo. Site URLs are review-only, and referenced media or pages must still exist.'),
				array('id' => 'apply', 'label' => 'Cross-site apply', 'level' => 'planned', 'note' => 'Per-website values and local object references must be resolved before a Core setting can move between sites.'),
			),
			array(
				'General identity, locale, registration, date, time, and privacy-page settings',
				'Writing defaults, front-page and feed behavior, discussion policy, and avatars',
				'Image sizes, upload organization, permalink structure, category base, and tag base',
				'Site icons, site logos, front pages, posts pages, and privacy pages as bounded local references',
			),
			array(
				'Multisite network settings are not included.',
				'Theme-specific settings other than WordPress logo references are not mapped.',
				'Site and WordPress address changes are visible but deliberately unavailable for automatic undo.',
				'Category IDs, custom roles, paths, URLs, and administrator email addresses must be checked per website.',
			),
			'https://github.com/WordPress/wordpress-develop',
			'wordpress'
		);
	}

	public function ownsOption(string $optionName): bool
	{
		return isset($this->ownedOptions[$optionName]);
	}

	public function analyze(string $optionName, array $changes): AdapterAnalysis
	{
		if (! $this->ownsOption($optionName)) {
			return new AdapterAnalysis('unknown', 'This option is outside the tested WordPress Core settings contract.', false);
		}
		if (in_array($optionName, self::HIGH_RISK_OPTIONS, true)) {
			return new AdapterAnalysis(
				'environment',
				'This address controls how WordPress reaches or publishes the website. ConfigOps records it for review but does not change it automatically.',
				false
			);
		}

		return $this->analyzeFields($optionName, $changes);
	}

	public function field(string $optionName, string $jsonPointer): ?FieldDefinition
	{
		if (! $this->ownsOption($optionName)) {
			return null;
		}

		return parent::field($optionName, $jsonPointer);
	}

	public function isSensitive(string $optionName, array $path): bool
	{
		unset($optionName, $path);

		return false;
	}

	public function normalizeOptionValue(string $optionName, mixed $value): mixed
	{
		if (! $this->ownsOption($optionName) || is_string($value) || ! is_scalar($value)) {
			return $value;
		}
		if (is_bool($value)) {
			return $value ? '1' : '';
		}

		return (string) $value;
	}

	private function defineGeneralSettings(): void
	{
		$this->defineOption('blogname', 'Site title', 'Site identity', 'portable', 'The public name of the website.');
		$this->defineOption('blogdescription', 'Site tagline', 'Site identity', 'portable', 'The short description shown by themes and feeds.');
		$this->defineOption('home', 'Site address', 'Website addresses', 'environment', 'The public address visitors use for this website.');
		$this->defineOption('siteurl', 'WordPress address', 'Website addresses', 'environment', 'The address of the WordPress installation.');
		$this->defineOption('admin_email', 'Administration email', 'Site administration', 'environment', 'Receives important website administration notices and should be checked per environment.');
		$this->defineOption('users_can_register', 'Anyone can register', 'Membership', 'portable', 'Controls whether visitors may create accounts.');
		$this->defineOption('default_role', 'New user default role', 'Membership', 'environment', 'The role assigned to new users; the same role slug must exist on this website.');
		$this->defineOption('timezone_string', 'Time zone', 'Locale and time', 'environment', 'The named time zone used for dates and scheduled events on this website.');
		$this->defineOption('gmt_offset', 'UTC offset', 'Locale and time', 'environment', 'The fixed UTC offset used when no named time zone is selected.');
		$this->defineOption('date_format', 'Date format', 'Locale and time', 'portable', 'The format WordPress uses when it displays dates.');
		$this->defineOption('time_format', 'Time format', 'Locale and time', 'portable', 'The format WordPress uses when it displays times.');
		$this->defineOption('start_of_week', 'Week starts on', 'Locale and time', 'portable', 'The first day shown in WordPress calendars.');
		$this->defineOption('WPLANG', 'Site language', 'Locale and time', 'portable', 'The language used by the WordPress administration and public translations.');
		$this->defineOption('site_icon', 'Site icon', 'Site identity', 'reference', 'The image WordPress uses for browser tabs and app icons.', 'media');
		$this->defineOption('site_logo', 'Site logo', 'Site identity', 'reference', 'The logo selected for this website.', 'media');
		$this->defineOption('wp_page_for_privacy_policy', 'Privacy policy page', 'Privacy', 'reference', 'The page WordPress identifies as this website\'s privacy policy.', 'content');
	}

	private function defineWritingSettings(): void
	{
		$this->defineOption('use_smilies', 'Convert emoticons', 'Formatting', 'portable', 'Converts text emoticons into graphic emoji while displaying content.');
		$this->defineOption('use_balanceTags', 'Correct invalid markup', 'Formatting', 'portable', 'Lets WordPress correct invalid nested XHTML automatically.');
		$this->defineOption('default_category', 'Default post category', 'Publishing defaults', 'environment', 'The local category assigned when a post has no category; its term ID belongs to this website.');
		$this->defineOption('default_email_category', 'Default email category', 'Publishing defaults', 'environment', 'The local category used by legacy post-by-email publishing.');
		$this->defineOption('default_post_format', 'Default post format', 'Publishing defaults', 'portable', 'The post format selected for new posts by default.');
		$this->defineOption('ping_sites', 'Update services', 'Publishing services', 'portable', 'The update-service endpoints WordPress notifies after publishing.');
	}

	private function defineReadingSettings(): void
	{
		$this->defineOption('show_on_front', 'Homepage displays', 'Homepage', 'portable', 'Chooses between recent posts and a selected static page for the homepage.');
		$this->defineOption('page_on_front', 'Homepage', 'Homepage', 'reference', 'The local page selected as the website homepage.', 'content');
		$this->defineOption('page_for_posts', 'Posts page', 'Homepage', 'reference', 'The local page selected to display the posts index.', 'content');
		$this->defineOption('posts_per_page', 'Posts per page', 'Content lists', 'portable', 'The maximum number of posts shown on blog and archive pages.');
		$this->defineOption('posts_per_rss', 'Feed item count', 'Feeds', 'portable', 'The maximum number of recent items included in a feed.');
		$this->defineOption('rss_use_excerpt', 'Feed content', 'Feeds', 'portable', 'Chooses whether feeds contain full text or an excerpt.');
		$this->defineOption('blog_charset', 'Page encoding', 'Publishing format', 'environment', 'The character encoding emitted by WordPress for this website.');
		$this->defineOption('blog_public', 'Search engine visibility', 'Discoverability', 'environment', 'Requests that search engines index or avoid this website; staging and production commonly differ.');
	}

	private function defineDiscussionSettings(): void
	{
		$this->defineOption('default_pingback_flag', 'Notify linked blogs', 'Default article settings', 'portable', 'Attempts to notify blogs linked from newly published posts.');
		$this->defineOption('default_ping_status', 'Allow link notifications', 'Default article settings', 'portable', 'Allows pingbacks and trackbacks on new posts by default.');
		$this->defineOption('default_comment_status', 'Allow comments', 'Default article settings', 'portable', 'Allows comments on new posts by default.');
		$this->defineOption('require_name_email', 'Require author name and email', 'Comment authors', 'portable', 'Requires commenters to provide a name and email address.');
		$this->defineOption('comment_registration', 'Require account for comments', 'Comment authors', 'portable', 'Allows comments only from signed-in users.');
		$this->defineOption('close_comments_for_old_posts', 'Close comments on old posts', 'Comment lifecycle', 'portable', 'Closes comments automatically after a configured number of days.');
		$this->defineOption('close_comments_days_old', 'Days before comments close', 'Comment lifecycle', 'portable', 'The age at which WordPress closes comments automatically.');
		$this->defineOption('show_comments_cookies_opt_in', 'Comment cookie consent', 'Comment authors', 'portable', 'Shows the opt-in for saving commenter details in cookies.');
		$this->defineOption('thread_comments', 'Threaded comments', 'Comment display', 'portable', 'Enables nested replies in comment discussions.');
		$this->defineOption('thread_comments_depth', 'Comment nesting depth', 'Comment display', 'portable', 'The maximum depth allowed for threaded replies.');
		$this->defineOption('page_comments', 'Paginate comments', 'Comment display', 'portable', 'Splits comments across multiple pages.');
		$this->defineOption('comments_per_page', 'Comments per page', 'Comment display', 'portable', 'The number of top-level comments shown on each page.');
		$this->defineOption('default_comments_page', 'Default comment page', 'Comment display', 'portable', 'Chooses whether the first or last comment page appears initially.');
		$this->defineOption('comment_order', 'Comment order', 'Comment display', 'portable', 'Chooses whether older or newer comments appear first.');
		$this->defineOption('comments_notify', 'Email post authors', 'Notifications', 'portable', 'Emails a post author when someone comments.');
		$this->defineOption('moderation_notify', 'Email comment moderators', 'Notifications', 'portable', 'Emails administrators when a comment awaits moderation.');
		$this->defineOption('comment_moderation', 'Manual comment approval', 'Moderation', 'portable', 'Holds every comment for manual approval.');
		$this->defineOption('comment_previously_approved', 'Previously approved authors', 'Moderation', 'portable', 'Lets previously approved comment authors bypass moderation.');
		$this->defineOption('comment_whitelist', 'Previously approved authors', 'Moderation', 'portable', 'Legacy storage for the previously approved author rule.');
		$this->defineOption('comment_max_links', 'Comment link limit', 'Moderation', 'portable', 'Holds comments that contain at least this many links.');
		$this->defineOption('moderation_keys', 'Moderation terms', 'Moderation', 'portable', 'The words, authors, email addresses, URLs, and IP patterns that send a comment to moderation.');
		$this->defineOption('disallowed_keys', 'Disallowed comment terms', 'Moderation', 'portable', 'The words, authors, email addresses, URLs, and IP patterns that send a comment to Trash.');
		$this->defineOption('blacklist_keys', 'Disallowed comment terms', 'Moderation', 'portable', 'Legacy storage for disallowed comment terms.');
		$this->defineOption('show_avatars', 'Show avatars', 'Avatars', 'portable', 'Displays avatars beside comments and user profiles.');
		$this->defineOption('avatar_rating', 'Maximum avatar rating', 'Avatars', 'portable', 'Limits displayed avatars to the selected content rating.');
		$this->defineOption('avatar_default', 'Default avatar', 'Avatars', 'environment', 'The fallback avatar may contain a URL or service choice that should be checked per website.');
	}

	private function defineMediaSettings(): void
	{
		$this->defineOption('thumbnail_size_w', 'Thumbnail width', 'Thumbnail size', 'portable', 'The maximum width generated for thumbnail images.');
		$this->defineOption('thumbnail_size_h', 'Thumbnail height', 'Thumbnail size', 'portable', 'The maximum height generated for thumbnail images.');
		$this->defineOption('thumbnail_crop', 'Crop thumbnails', 'Thumbnail size', 'portable', 'Crops thumbnails to exact dimensions instead of scaling proportionally.');
		$this->defineOption('medium_size_w', 'Medium image width', 'Medium size', 'portable', 'The maximum width generated for medium images.');
		$this->defineOption('medium_size_h', 'Medium image height', 'Medium size', 'portable', 'The maximum height generated for medium images.');
		$this->defineOption('medium_large_size_w', 'Medium-large image width', 'Medium-large size', 'portable', 'The maximum width generated for medium-large images.');
		$this->defineOption('medium_large_size_h', 'Medium-large image height', 'Medium-large size', 'portable', 'The maximum height generated for medium-large images.');
		$this->defineOption('large_size_w', 'Large image width', 'Large size', 'portable', 'The maximum width generated for large images.');
		$this->defineOption('large_size_h', 'Large image height', 'Large size', 'portable', 'The maximum height generated for large images.');
		$this->defineOption('uploads_use_yearmonth_folders', 'Organize uploads by date', 'Upload organization', 'portable', 'Stores new uploads in year and month folders.');
		$this->defineOption('upload_path', 'Upload path', 'Upload location', 'environment', 'A custom filesystem path belongs to the current hosting environment.');
		$this->defineOption('upload_url_path', 'Upload URL', 'Upload location', 'environment', 'A custom upload URL belongs to the current website.');
	}

	private function definePermalinkSettings(): void
	{
		$this->defineOption('permalink_structure', 'Permalink structure', 'Permalinks', 'portable', 'The URL pattern WordPress uses for posts.');
		$this->defineOption('category_base', 'Category base', 'Permalinks', 'portable', 'The optional URL prefix used for category archives.');
		$this->defineOption('tag_base', 'Tag base', 'Permalinks', 'portable', 'The optional URL prefix used for tag archives.');
	}

	private function defineOption(
		string $optionName,
		string $label,
		string $group,
		string $kind,
		string $explanation,
		?string $referenceType = null
	): void {
		$this->ownedOptions[$optionName] = true;
		$this->define($optionName, '/', $label, $group, $kind, $explanation, $referenceType);
	}
}
