<?php
/**
 * Plugin Name: Media Dependency Map
 * Plugin URI: https://github.com/bilalkk9/WordPress-Media-Dependency-Map
 * Description: Inspect known Media Library dependencies and scan coverage. Development preview.
 * Version: 0.3.0
 * Requires at least: 6.6
 * Requires PHP: 7.4
 * Author: Bilal
 * Author URI: https://profiles.wordpress.org/mbilalkk/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: media-dependency-map
 * Update URI: https://github.com/bilalkk9/WordPress-Media-Dependency-Map
 *
 * @package Bilal\MediaDependencyMap
 */

namespace Bilal\MediaDependencyMap;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/load.php';
require_once __DIR__ . '/includes/class-lifecycle.php';
require_once __DIR__ . '/includes/class-plugin.php';

register_activation_hook( __FILE__, array( Lifecycle::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Lifecycle::class, 'deactivate' ) );
add_action( 'plugins_loaded', array( new Plugin(), 'register' ) );
