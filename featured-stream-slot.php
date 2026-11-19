<?php
/**
 * Plugin Name:       Featured Stream Slot
 * Plugin URI:        https://github.com/christianc24/featured-stream-slot
 * Description:       A sticky, draggable, resizable live stream player (Twitch, YouTube, Kick, Vimeo) that floats on every page, autoplays muted, and shows your own message when the stream is offline.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            christianc24
 * Author URI:        https://github.com/christianc24
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       featured-stream-slot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SSP_VERSION', '1.2.0' );
define( 'SSP_FILE', __FILE__ );
define( 'SSP_URL', plugin_dir_url( __FILE__ ) );

require_once __DIR__ . '/includes/class-ssp-plugin.php';

SSP_Plugin::instance();
