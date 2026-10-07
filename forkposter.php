<?php
/**
 * Plugin Name:       Forkposter
 * Description:       Fork a published post into a new version while keeping the original live, clearly labeled as an earlier version.
 * Version:           1.1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Brad Salomons
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       forkposter
 */

defined( 'ABSPATH' ) || exit;

define( 'FORKPOSTER_VERSION', '1.1.0' );
define( 'FORKPOSTER_FILE', __FILE__ );
define( 'FORKPOSTER_DIR', plugin_dir_path( __FILE__ ) );
define( 'FORKPOSTER_URL', plugin_dir_url( __FILE__ ) );

require_once FORKPOSTER_DIR . 'includes/settings.php';
require_once FORKPOSTER_DIR . 'includes/model.php';
require_once FORKPOSTER_DIR . 'includes/lifecycle.php';
require_once FORKPOSTER_DIR . 'includes/fork-action.php';
require_once FORKPOSTER_DIR . 'includes/admin.php';
require_once FORKPOSTER_DIR . 'includes/dashboard.php';
require_once FORKPOSTER_DIR . 'includes/display.php';
require_once FORKPOSTER_DIR . 'includes/feed.php';
require_once FORKPOSTER_DIR . 'includes/seo.php';
