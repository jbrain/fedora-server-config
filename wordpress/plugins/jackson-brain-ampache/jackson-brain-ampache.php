<?php
/**
 * Plugin Name:       Jackson Brain Ampache Integration
 * Description:       Read-only WordPress blocks/shortcode showing local Ampache library stats, now playing, and recently played tracks.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Author:            Jackson Brain
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       jackson-brain-ampache
 *
 * This plugin never writes to Ampache and never lets a public page load wait on a live
 * Ampache request. See /plans/wordpress-ampache-plugin/ in the source repository for the
 * full product, architecture, security, and validation specification this code implements.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'JBA_PLUGIN_FILE', __FILE__ );
define( 'JBA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'JBA_PLUGIN_VERSION', '0.1.0' );

require JBA_PLUGIN_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( \JacksonBrain\Ampache\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \JacksonBrain\Ampache\Plugin::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \JacksonBrain\Ampache\Plugin::class, 'instance' ) );
