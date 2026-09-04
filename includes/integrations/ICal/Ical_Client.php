<?php
/**
 * Remote iCal (.ics) feed fetching and SSRF-safe validation.
 *
 * @package WpCalendar\Integrations
 */

namespace WpCalendar\Integrations\ICal;

use WpCalendar\Admin\Settings_Page;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches and validates remote iCalendar (.ics) feed content.
 *
 * Reuses the settings layer's SSRF guard (Settings_Page::is_safe_url) before
 * any network request, and normalizes errors so a single bad feed cannot abort
 * the whole aggregation pass.
 */
class Ical_Client {

	/**
	 * Fetches a single iCal feed and returns its raw ICS content.
	 *
	 * @param string $url  Feed URL (already sanitized on save).
	 * @return string  Raw ICS body, or empty string on failure.
	 */
	public static function fetch_feed( string $url ): string {
		$url = trim( $url );
		if ( '' === $url || ! Settings_Page::is_safe_url( $url ) ) {
			return '';
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 3,
				'headers'     => array(
					'Accept' => 'text/calendar, application/ics;q=0.9, */*;q=0.1',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code && 304 !== $code ) {
			return '';
		}

		$body = wp_remote_retrieve_body( $response );
		return is_string( $body ) ? $body : '';
	}

	/**
	 * Detects whether a feed body looks like ICS content.
	 *
	 * @param string $body Raw body.
	 * @return bool True when the body looks like an iCalendar document.
	 */
	public static function looks_like_ical( string $body ): bool {
		return stripos( $body, 'BEGIN:VCALENDAR' ) !== false;
	}
}
