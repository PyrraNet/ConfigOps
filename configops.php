<?php
/**
 * Plugin Name:       ConfigOps – Undo Settings Changes
 * Plugin URI:        https://configops.pyrra.net/
 * Description:       See what a settings save changed and reverse only the values that are still safe to restore.
 * Version:           0.4.0
 * Requires at least: 7.0
 * Requires PHP:      8.2
 * Author:            pyrra
 * Author URI:        https://configops.pyrra.net/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       configops
 *
 * @package ConfigOps
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

define('CONFIGOPS_VERSION', '0.4.0');
define('CONFIGOPS_FILE', __FILE__);
define('CONFIGOPS_PATH', __DIR__);
define('CONFIGOPS_URL', plugin_dir_url(__FILE__));

require_once CONFIGOPS_PATH . '/src/Autoload.php';

register_activation_hook(CONFIGOPS_FILE, array(\ConfigOps\Plugin::class, 'activate'));
register_deactivation_hook(CONFIGOPS_FILE, array(\ConfigOps\Plugin::class, 'deactivate'));

add_action(
	'plugins_loaded',
	static function (): void {
		\ConfigOps\Plugin::boot();
	}
);
