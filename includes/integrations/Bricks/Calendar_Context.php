<?php

namespace PostCalendar\Integrations\Bricks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tracks render-time state of the events query so dynamic tags can read
 * occurrence and window data without post-meta round-trips.
 *
 * Bricks renders loops depth-first, so "last resolved" is always "current".
 */
class Calendar_Context {
	/**
	 * @var array|null
	 */
	private static $active_window = null;

	/**
	 * @var array|null
	 */
	private static $active_event = null;

	/**
	 * Store the resolved window bounds of the running events query
	 * (start/end DATE_ATOM strings).
	 */
	public static function set_active_window( ?array $window ): void {
		self::$active_window = $window;
	}

	public static function get_active_window(): ?array {
		return self::$active_window;
	}

	/**
	 * Track the occurrence event currently being rendered so event tags can
	 * read occurrence data even after the loop object becomes a WP_Post.
	 */
	public static function set_active_event( ?array $event ): void {
		self::$active_event = $event;
	}

	public static function get_active_event(): ?array {
		return self::$active_event;
	}
}
