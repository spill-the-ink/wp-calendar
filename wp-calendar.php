<?php
/**
 * Plugin Name:       WordPress Calendar
 * Plugin URI:        https://github.com/spill-the-ink/wp-plugins
 * Description:       Display posts as events in a calendar via Bricks or shortcode, using existing post types and the built-in WordPress Calendar editor or direct event meta.
 * Version:           0.5.2
 * Requires at least: 6.0
 * Requires           PHP: 7.4
 * Author:            spill-the.ink
 * Author URI:        https://spill-the.ink/?wp-plugins=wp-calendar
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-calendar
 * Domain Path:       /languages
 * Update URI:        https://github.com/spill-the-ink/wp-plugins
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WP_CALENDAR_VERSION', '0.5.2' );
define( 'WP_CALENDAR_PLUGIN_FILE', __FILE__ );
define( 'WP_CALENDAR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_CALENDAR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once WP_CALENDAR_PLUGIN_DIR . 'includes/autoload.php';

WpCalendar\Plugin::boot();
