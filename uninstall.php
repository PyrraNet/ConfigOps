<?php
/**
 * Remove ConfigOps capture evidence and installation state.
 *
 * @package ConfigOps
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

require_once __DIR__ . '/src/Autoload.php';

\ConfigOps\Uninstall::run();
