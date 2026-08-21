<?php
/**
 * Site-local lifecycle for single-site and network-active installations.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

namespace ConfigOps\Multisite;

use ConfigOps\Access\CapabilityManager;
use ConfigOps\Database\CaptureRepository;
use ConfigOps\Database\Schema;
use ConfigOps\Maintenance\HistoryRetention;
use RuntimeException;
use Throwable;
use WP_Site;
use wpdb;

final readonly class SiteLifecycle
{
	private SiteContextRunner $sites;

	public function __construct(private wpdb $database)
	{
		$this->sites = new SiteContextRunner();
	}

	public function register(): void
	{
		if (! is_multisite()) {
			return;
		}

		add_action('wp_initialize_site', array($this, 'initializeSite'), 200, 2);
		add_action('wp_uninitialize_site', array($this, 'uninitializeSite'), 200, 1);
		add_filter('wpmu_drop_tables', array($this, 'includeLegacyTables'), 10, 2);
	}

	public function activate(bool $networkWide = false): void
	{
		if (! $networkWide || ! is_multisite()) {
			$this->provisionCurrentSite();

			return;
		}

		$networkId = (int) get_current_network_id();
		if ($networkId <= 0) {
			throw new RuntimeException('ConfigOps cannot activate without a valid WordPress network.');
		}
		foreach ($this->sites->siteIds($networkId) as $siteId) {
			$this->sites->run($siteId, fn (): null => $this->provisionCurrentSite(), $networkId);
		}
	}

	public function deactivate(bool $networkWide = false): void
	{
		if (! $networkWide || ! is_multisite()) {
			try {
				$this->deactivateCurrentSite();
			} catch (Throwable $error) {
				$this->report(
					'configops_deactivation_error',
					$error,
					function_exists('get_current_network_id') ? max(0, (int) get_current_network_id()) : 0,
					max(1, (int) get_current_blog_id())
				);
			}

			return;
		}

		$networkId = (int) get_current_network_id();
		if ($networkId <= 0) {
			$this->report(
				'configops_deactivation_error',
				new RuntimeException('ConfigOps cannot deactivate without a valid WordPress network.'),
				0,
				(int) get_current_blog_id()
			);

			return;
		}
		foreach ($this->sites->siteIds($networkId) as $siteId) {
			try {
				$this->sites->run($siteId, fn (): null => $this->deactivateCurrentSite(), $networkId);
			} catch (Throwable $error) {
				$this->report('configops_deactivation_error', $error, $networkId, $siteId);
			}
		}

		try {
			(new CaptureRepository($this->database, new NetworkScope($networkId)))->interruptOpen('plugin_deactivated');
		} catch (Throwable $error) {
			$this->report('configops_deactivation_error', $error, $networkId, 0);
		}
	}

	/**
	 * Provision a site created while ConfigOps is active for its network.
	 *
	 * @param array<string, mixed> $args WordPress site-initialization arguments.
	 */
	public function initializeSite(WP_Site $site, array $args = array()): void
	{
		unset($args);

		$networkId = (int) ($site->site_id ?? 0);
		$siteId    = (int) ($site->blog_id ?? 0);
		if ($networkId <= 0 || $siteId <= 0) {
			return;
		}
		if (! $this->isNetworkActive($networkId)) {
			return;
		}

		try {
			$this->sites->run($siteId, fn (): null => $this->provisionCurrentSite(), $networkId);
		} catch (Throwable $error) {
			// Site creation must survive plugin provisioning failure. A normal request
			// to the new site retries the same schema and capability installation.
			$this->report('configops_site_provisioning_error', $error, $networkId, $siteId);
		}
	}

	public function uninitializeSite(WP_Site $site): void
	{
		$networkId = (int) ($site->site_id ?? 0);
		$siteId    = (int) ($site->blog_id ?? 0);
		if ($networkId <= 0 || $siteId <= 0) {
			return;
		}

		try {
			$this->deleteSharedEvidence($siteId);
		} catch (Throwable $error) {
			// ConfigOps cleanup must not replace WordPress's site-deletion result.
			$this->report('configops_site_cleanup_error', $error, $networkId, $siteId);
		}
	}

	/**
	 * Add retained pre-v10 per-site tables to WordPress's normal site cleanup.
	 *
	 * @param list<string> $tables Tables WordPress plans to drop.
	 * @return list<string>
	 */
	public function includeLegacyTables(array $tables, int $siteId): array
	{
		if ($siteId <= 0) {
			return $tables;
		}

		$prefix     = (string) $this->database->get_blog_prefix($siteId);
		$basePrefix = (string) ($this->database->base_prefix ?: $this->database->prefix);
		if ('' === $prefix || $prefix === $basePrefix) {
			return $tables;
		}

		foreach ($this->tableSuffixes() as $suffix) {
			$tables[] = $prefix . $suffix;
		}

		return array_values(array_unique($tables));
	}

	private function provisionCurrentSite(): null
	{
		(new Schema($this->database))->install();
		(new CapabilityManager())->install();
		HistoryRetention::schedule();

		return null;
	}

	private function deactivateCurrentSite(): null
	{
		$error = null;
		try {
			(new CaptureRepository($this->database))->interruptOpen('plugin_deactivated');
		} catch (Throwable $caught) {
			$error = $caught;
		}

		HistoryRetention::unschedule();
		if ($error) {
			throw $error;
		}

		return null;
	}

	private function isNetworkActive(int $networkId): bool
	{
		$plugins = get_network_option($networkId, 'active_sitewide_plugins', array());

		return is_array($plugins) && isset($plugins[plugin_basename(CONFIGOPS_FILE)]);
	}

	private function deleteSharedEvidence(int $siteId): void
	{
		$prefix = (string) ($this->database->base_prefix ?: $this->database->prefix);
		foreach ($this->tableSuffixes() as $suffix) {
			$table = '`' . str_replace('`', '``', $prefix . $suffix) . '`';
			$deleted = $this->database->query(
				$this->database->prepare(
					"DELETE FROM {$table} WHERE blog_id = %d",
					$siteId
				)
			);
			if (false === $deleted) {
				throw new RuntimeException('ConfigOps could not remove evidence for a deleted WordPress site.');
			}
		}
	}

	/**
	 * Child tables precede their owning capture sessions.
	 *
	 * @return list<string>
	 */
	private function tableSuffixes(): array
	{
		return array(
			'configops_restore_runs',
			'configops_write_signals',
			'configops_mutations',
			'configops_capture_sessions',
		);
	}

	private function report(string $hook, Throwable $error, int $networkId, int $siteId): void
	{
		try {
			$context = array('network_id' => $networkId, 'site_id' => $siteId);
			switch ($hook) {
				case 'configops_deactivation_error':
					do_action('configops_deactivation_error', $error, $context);
					break;
				case 'configops_site_provisioning_error':
					do_action('configops_site_provisioning_error', $error, $context);
					break;
				case 'configops_site_cleanup_error':
					do_action('configops_site_cleanup_error', $error, $context);
					break;
			}
		} catch (Throwable) {
			// Lifecycle diagnostics must never replace the host operation.
		}
	}
}
