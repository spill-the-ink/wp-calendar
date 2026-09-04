<?php
/**
 * Fetches, caches, and maps iCal feed events to the unified schema.
 *
 * @package WpCalendar\Integrations
 */

namespace WpCalendar\Integrations\ICal;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orchestrates per-feed fetching and caching to produce unified calendar events.
 *
 * Caching uses transients with a 5-minute TTL and a lock to prevent concurrent
 * fetches, mirroring Discord_Aggregator. Failures fall back to stale cache.
 *
 * Cache keys are scoped by feed id AND expansion year so that recurring events
 * expanded for one year do not shadow a different year's data within the TTL.
 */
class Ical_Aggregator {
	private const TRANSIENT_PREFIX      = 'wp_calendar_ical_events_';
	private const TRANSIENT_LOCK_PREFIX = 'wp_calendar_ical_lock_';
	private const TTL                   = 300; // 5 minutes.
	private const LOCK_TTL              = 30;  // 30 seconds.

	/**
	 * Fetches all iCal events for enabled feeds, cached per feed+year.
	 *
	 * @param array $feeds  Feed configs from Settings_Page::get_sources().
	 * @param int   $year   Bounded recurrence expansion year.
	 * @return array  Unified event records.
	 */
	public static function get_events( array $feeds, int $year ): array {
		$enabled = array_values(
			array_filter(
				$feeds,
				static function ( $feed ): bool {
					return ! empty( $feed['enabled'] ) && ! empty( $feed['url'] );
				}
			)
		);

		if ( empty( $enabled ) ) {
			return array();
		}

		$by_feed = array();

		foreach ( $enabled as $feed ) {
			$feed_id = self::feed_id( $feed );
			$cached  = self::get_cached_events( $feed_id, $year );

			if ( null !== $cached ) {
				$by_feed[ $feed_id ] = $cached;
				continue;
			}

			$by_feed[ $feed_id ] = self::fetch_and_cache_feed( $feed, $year );
		}

		return self::merge_feeds( $by_feed );
	}

	/**
	 * Fetches events for a single feed, caches them, and returns unified records.
	 *
	 * @param array $feed  Feed config.
	 * @param int   $year  Bounded recurrence year.
	 * @return array  Unified event records (possibly empty).
	 */
	private static function fetch_and_cache_feed( array $feed, int $year ): array {
		$feed_id = self::feed_id( $feed );

		if ( self::acquire_lock( $feed_id, $year ) ) {
			try {
				$result = Ical_Api::fetch_all_feeds( array( $feed ), $year );
				$raw    = $result['events'][ $feed_id ] ?? null;

				if ( null === $raw ) {
					return self::stale_or_empty( $feed_id, $year );
				}

				$mapped = self::map_events( $raw, $feed );
				self::cache_events( $feed_id, $year, $mapped );
				return $mapped;
			} finally {
				self::release_lock( $feed_id, $year );
			}
		}

		return self::stale_or_empty( $feed_id, $year );
	}

	/**
	 * Merges per-feed unified records, de-duplicating across feeds by UID.
	 *
	 * @param array $by_feed  Map of feed_id => unified records.
	 * @return array  Merged, sorted unified records.
	 */
	private static function merge_feeds( array $by_feed ): array {
		$events = array();
		$seen   = array();

		foreach ( $by_feed as $feed_records ) {
			foreach ( $feed_records as $record ) {
				$uid = isset( $record['id'] ) ? $record['id'] : '';
				if ( '' !== $uid && isset( $seen[ $uid ] ) ) {
					continue;
				}
				$seen[ $uid ] = true;
				$events[]     = $record;
			}
		}

		usort(
			$events,
			static function ( $a, $b ): int {
				return strcmp( $a['scheduled_start_time'] ?? '', $b['scheduled_start_time'] ?? '' );
			}
		);

		return $events;
	}

	/**
	 * Maps raw parsed iCal records to the unified schema.
	 *
	 * @param array $raw_events  Parsed iCal records from Ical_Parser::parse().
	 * @param array $feed        Feed config (for source attribution).
	 * @return array  Unified event records.
	 */
	private static function map_events( array $raw_events, array $feed ): array {
		$feed_id = self::feed_id( $feed );
		$mapped  = array();

		foreach ( $raw_events as $record ) {
			$unified = self::map_single_event( $record, $feed, $feed_id );
			if ( null !== $unified ) {
				$mapped[] = $unified;
			}
		}

		return $mapped;
	}

	/**
	 * Maps a single parsed iCal record to the unified schema.
	 *
	 * @param array  $record   Parsed iCal record.
	 * @param array  $feed     Feed config.
	 * @param string $feed_id  Stable feed identifier.
	 * @return array|null  Unified event record, or null if invalid.
	 */
	private static function map_single_event( array $record, array $feed, string $feed_id ): ?array {
		$start = $record['scheduled_start_time'] ?? '';
		$end   = $record['scheduled_end_time'] ?? '';

		if ( '' === $start ) {
			return null;
		}

		$name     = isset( $record['name'] ) && '' !== (string) $record['name'] ? (string) $record['name'] : '(No title)';
		$all_day  = ! empty( $record['allDay'] );
		$location = isset( $record['location'] ) ? (string) $record['location'] : '';

		// Stable, occurrence-distinct id: feed id + source UID + start timestamp.
		$parsed = strtotime( (string) $start );
		$stamp  = false !== $parsed ? (int) $parsed : 0;
		$id     = 'ical:' . $feed_id . ':' . preg_replace( '/[^a-zA-Z0-9\-_.]/', '', (string) $record['uid'] ) . ':' . $stamp;

		return array(
			'id'                   => $id,
			'name'                 => $name,
			'scheduled_start_time' => $start,
			'scheduled_end_time'   => $end,
			'allDay'               => $all_day,
			'url'                  => null,
			'description'          => isset( $record['description'] ) ? (string) $record['description'] : '',
			'tags'                 => array(),
			'label'                => null,
			'location'             => '' !== $location ? $location : null,
			'location_url'         => null,
			'source'               => array(
				'type' => 'ical',
				'id'   => $feed_id,
				'name' => isset( $feed['name'] ) ? (string) $feed['name'] : 'iCal',
			),
			'postId'               => null,
			'postType'             => null,
		);
	}

	/**
	 * Resolves a stable feed identifier from its config.
	 *
	 * @param array $feed  Feed config.
	 * @return string  Stable feed identifier.
	 */
	private static function feed_id( array $feed ): string {
		return Ical_Api::feed_id( $feed );
	}

	/**
	 * Returns stale (expired) cache for a feed+year, or empty when none.
	 *
	 * @param string $feed_id  Feed identifier.
	 * @param int    $year     Expansion year.
	 * @return array  Unified records, possibly empty.
	 */
	private static function stale_or_empty( string $feed_id, int $year ): array {
		$stale = self::get_stale_events( $feed_id, $year );
		return null !== $stale ? $stale : array();
	}

	/*
	 * Caching.
	 */

	/**
	 * Builds the transient key for a feed's cached events.
	 *
	 * @param string $feed_id  Feed identifier.
	 * @param int    $year     Expansion year.
	 * @return string  Transient key.
	 */
	private static function get_cache_key( string $feed_id, int $year ): string {
		return self::TRANSIENT_PREFIX . $feed_id . '_y' . $year;
	}

	/**
	 * Builds the transient key for a feed's fetch lock.
	 *
	 * @param string $feed_id  Feed identifier.
	 * @param int    $year     Expansion year.
	 * @return string  Transient key.
	 */
	private static function get_lock_key( string $feed_id, int $year ): string {
		return self::TRANSIENT_LOCK_PREFIX . $feed_id . '_y' . $year;
	}

	/**
	 * Returns cached events for a feed+year, or null if not cached / expired.
	 *
	 * @param string $feed_id  Feed identifier.
	 * @param int    $year     Expansion year.
	 * @return array|null  Cached events, or null.
	 */
	private static function get_cached_events( string $feed_id, int $year ): ?array {
		$cached = get_transient( self::get_cache_key( $feed_id, $year ) );
		return is_array( $cached ) ? $cached : null;
	}

	/**
	 * Returns stale (expired) events for a feed+year, used as fallback on fetch failure.
	 *
	 * @param string $feed_id  Feed identifier.
	 * @param int    $year     Expansion year.
	 * @return array|null  Stale events, or null.
	 */
	private static function get_stale_events( string $feed_id, int $year ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::get_cache_key( $feed_id, $year ) )
		);

		$decoded = maybe_unserialize( $value );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Caches events for a feed+year.
	 *
	 * @param string $feed_id  Feed identifier.
	 * @param int    $year     Expansion year.
	 * @param array  $events   Unified event records.
	 */
	private static function cache_events( string $feed_id, int $year, array $events ): void {
		set_transient( self::get_cache_key( $feed_id, $year ), $events, self::TTL );
	}

	/**
	 * Acquires a fetch lock for a feed+year.
	 *
	 * @param string $feed_id  Feed identifier.
	 * @param int    $year     Expansion year.
	 * @return bool  True when the lock was acquired.
	 */
	private static function acquire_lock( string $feed_id, int $year ): bool {
		$key      = self::get_lock_key( $feed_id, $year );
		$existing = get_transient( $key );

		if ( false !== $existing ) {
			return false;
		}

		set_transient( $key, 1, self::LOCK_TTL );
		return true;
	}

	/**
	 * Releases the fetch lock for a feed+year.
	 *
	 * @param string $feed_id  Feed identifier.
	 * @param int    $year     Expansion year.
	 */
	private static function release_lock( string $feed_id, int $year ): void {
		delete_transient( self::get_lock_key( $feed_id, $year ) );
	}
}
