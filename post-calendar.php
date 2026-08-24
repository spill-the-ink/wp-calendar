<?php
/**
 * Plugin Name: Post Calendar
 * Plugin URI:  https://github.com/achtender/post-calendar
 * Description: Display posts as events in a calendar via Bricks or shortcode, using existing post types and the built-in Post Calendar editor or direct event meta.
 * Version:     0.5.2
 * Author:      Achtender
 * Text Domain: post-calendar
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Update URI:  https://github.com/achtender/post-calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'POST_CALENDAR_VERSION', '0.5.2' );
define( 'POST_CALENDAR_PLUGIN_FILE', __FILE__ );
define( 'POST_CALENDAR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'POST_CALENDAR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once POST_CALENDAR_PLUGIN_DIR . 'includes/autoload.php';

PostCalendar\Plugin::boot();
