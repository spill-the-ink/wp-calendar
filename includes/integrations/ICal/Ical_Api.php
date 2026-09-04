<?php
/**
 * Fetches and parses events from configured iCal feeds.
 *
 * @package WpCalendar\Integrations
 */

namespace WpCalendar\Integrations\ICal;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Higher-level iCal API wrapper for fetching and parsing multiple feeds.
 *
 * Mirrors Discord_API: performs the targeted fetch/parse (with no caching) and
 * returns per-feed results plus an error map so failures are isolated.
 */
class Ical_Api {

	/**
	 * Fetches and parses all enabled iCal feeds.
	 *
	 * @param array $feeds  Feed configs from Settings_Page::get_sources().
	 * @param int   $year   Bounded recurrence window (year) to expand recurring events into.
	 * @return array{events: array<string, array|null>, errors: array<string, string>}
	 */
	public static function fetch_all_feeds( array $feeds, int $year ): array {
		$results = array(
			'events' => array(),
			'errors' => array(),
		);

		foreach ( $feeds as $feed ) {
			if ( empty( $feed['enabled'] ) || empty( $feed['url'] ) ) {
				continue;
			}

			$feed_name = isset( $feed['name'] ) ? (string) $feed['name'] : '';
			$feed_id   = self::feed_id( $feed );

			$body = Ical_Client::fetch_feed( (string) $feed['url'] );
			if ( '' === $body || ! Ical_Client::looks_like_ical( $body ) ) {
				$results['errors'][ $feed_id ] = sprintf(
					'Failed to fetch or parse iCal feed %s.',
					'' !== $feed_name ? $feed_name : $feed_id
				);
				$results['events'][ $feed_id ] = null;
				continue;
			}

			$parsed                        = Ical_Parser::parse( $body, $year, $feed_id );
			$results['events'][ $feed_id ] = $parsed;
		}

		return $results;
	}

	/**
	 * Resolves a stable feed identifier from its config.
	 *
	 * Shared with Ical_Aggregator so both derive identical keys for the same feed.
	 *
	 * @param array $feed  Feed config.
	 * @return string  Stable feed identifier.
	 */
	public static function feed_id( array $feed ): string {
		$id   = $feed['id'] ?? '';
		$name = $feed['name'] ?? '';
		if ( '' !== (string) $id ) {
			return (string) $id;
		}
		if ( '' !== (string) $name ) {
			return sanitize_title( (string) $name );
		}
		return md5( (string) $feed['url'] );
	}
}
