<?php

namespace WpCalendar\Integrations\Discord;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches, caches, and maps Discord guild scheduled events to the unified schema.
 *
 * Caching uses transients with a 5-minute TTL and a lock to prevent concurrent fetches.
 */
class Discord_Aggregator {
	private const TRANSIENT_PREFIX      = 'wp_calendar_discord_events_';
	private const TRANSIENT_LOCK_PREFIX = 'wp_calendar_discord_lock_';
	private const TTL                   = 300; // 5 minutes.
	private const LOCK_TTL              = 30; // 30 seconds.

	/**
	 * Fetches all Discord events for enabled guilds, cached per guild.
	 *
	 * Returns an array of unified event records ready for the REST response.
	 *
	 * @param array $guilds  Guild configs from Settings_Page::get_sources().
	 * @return array  Unified event records.
	 */
	public static function get_events( array $guilds ): array {
		$enabled_guilds = array_filter(
			$guilds,
			static function ( $guild ): bool {
				return ! empty( $guild['enabled'] ) && ! empty( $guild['guild_id'] );
			}
		);

		if ( empty( $enabled_guilds ) ) {
			return array();
		}

		$all_events = array();

		foreach ( $enabled_guilds as $guild ) {
			$guild_id   = (string) $guild['guild_id'];
			$guild_name = $guild['name'] ?? '';
			$cached     = self::get_cached_events( $guild_id );

			if ( null !== $cached ) {
				$all_events = array_merge( $all_events, $cached );
				continue;
			}

			$fetched    = self::fetch_and_cache_guild( $guild_id, $guild_name );
			$all_events = array_merge( $all_events, $fetched );
		}

		usort(
			$all_events,
			static function ( $a, $b ): int {
				return strcmp( $a['scheduled_start_time'] ?? '', $b['scheduled_start_time'] ?? '' );
			}
		);

		return $all_events;
	}

	/**
	 * Fetches events for a single guild, caches them, and returns unified records.
	 */
	private static function fetch_and_cache_guild( string $guild_id, string $guild_name ): array {
		if ( self::acquire_lock( $guild_id ) ) {
			try {
				$raw_events = Discord_API::fetch_all_guild_events(
					array(
						array(
							'guild_id' => $guild_id,
							'name'     => $guild_name,
							'enabled'  => true,
						),
					)
				);

				$events = $raw_events['events'][ $guild_id ] ?? null;

				if ( null === $events ) {
					// Failed — serve stale if available.
					$stale = self::get_stale_events( $guild_id );
					if ( null !== $stale ) {
						return $stale;
					}
					return array();
				}

				$filtered = Discord_API::filter_active_events( $events );
				$mapped   = self::map_events( $filtered, $guild_id, $guild_name );
				self::cache_events( $guild_id, $mapped );

				return $mapped;
			} finally {
				self::release_lock( $guild_id );
			}
		}

		// Could not acquire lock — serve stale.
		$stale = self::get_stale_events( $guild_id );
		return null !== $stale ? $stale : array();
	}

	/**
	 * Maps raw Discord scheduled events to the unified schema.
	 *
	 * @param array  $raw_events  Raw Discord event objects.
	 * @param string $guild_id    Guild ID.
	 * @param string $guild_name  Guild name.
	 * @return array  Unified event records.
	 */
	private static function map_events( array $raw_events, string $guild_id, string $guild_name ): array {
		$mapped = array();

		foreach ( $raw_events as $event ) {
			$record = self::map_single_event( $event, $guild_id, $guild_name );
			if ( null !== $record ) {
				$mapped[] = $record;
			}
		}

		return $mapped;
	}

	/**
	 * Maps a single Discord scheduled event to the unified schema.
	 *
	 * @param array  $event       Raw Discord event object.
	 * @param string $guild_id    Guild ID.
	 * @param string $guild_name  Guild name.
	 * @return array|null  Unified event record, or null if invalid.
	 */
	private static function map_single_event( array $event, string $guild_id, string $guild_name ): ?array {
		if ( empty( $event['id'] ) || empty( $event['name'] ) ) {
			return null;
		}

		$start_time = $event['scheduled_start_time'] ?? '';
		$end_time   = $event['scheduled_end_time'] ?? '';

		if ( '' === $start_time ) {
			return null;
		}

		// Parse location from entity_metadata.
		$location     = null;
		$location_url = null;
		$metadata     = $event['entity_metadata'] ?? array();

		if ( ! empty( $metadata['location'] ) ) {
			$loc = (string) $metadata['location'];
			if ( self::is_url( $loc ) ) {
				$location_url = $loc;
			} else {
				$location = $loc;
			}
		}

		// Determine if all-day (no end time or same-day start/end with midnight times).
		$all_day = false;
		if ( '' === $end_time ) {
			$all_day  = true;
			$end_time = $start_time;
		}

		// Build URL to the Discord event.
		$url = sprintf(
			'https://discord.com/events/%s/%s',
			rawurlencode( $guild_id ),
			rawurlencode( $event['id'] )
		);

		return array(
			'id'                   => 'discord:' . $guild_id . ':' . $event['id'],
			'name'                 => $event['name'],
			'scheduled_start_time' => $start_time,
			'scheduled_end_time'   => $end_time,
			'allDay'               => $all_day,
			'url'                  => $url,
			'description'          => $event['description'] ?? '',
			'tags'                 => array(),
			'label'                => null,
			'location'             => $location,
			'location_url'         => $location_url,
			'source'               => array(
				'type' => 'discord',
				'id'   => $guild_id,
				'name' => $guild_name,
			),
			'postId'               => null,
			'postType'             => null,
			'eventIndex'           => null,
		);
	}

	/**
	 * Checks if a string looks like a URL.
	 */
	private static function is_url( string $value ): bool {
		return preg_match( '#^https?://#i', $value ) === 1;
	}

	/*
	------------------------------------------------------------------ */
	/*
		Caching                                                            */
	/* ------------------------------------------------------------------ */

	private static function get_cache_key( string $guild_id ): string {
		return self::TRANSIENT_PREFIX . $guild_id;
	}

	private static function get_lock_key( string $guild_id ): string {
		return self::TRANSIENT_LOCK_PREFIX . $guild_id;
	}

	/**
	 * Returns cached events for a guild, or null if not cached / expired.
	 */
	private static function get_cached_events( string $guild_id ): ?array {
		$cached = get_transient( self::get_cache_key( $guild_id ) );
		return is_array( $cached ) ? $cached : null;
	}

	/**
	 * Returns stale (expired) cache for a guild, used as fallback on fetch failure.
	 */
	private static function get_stale_events( string $guild_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::get_cache_key( $guild_id ) )
		);

		if ( null === $value ) {
			return null;
		}

		$decoded = maybe_unserialize( $value );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Caches events for a guild.
	 */
	private static function cache_events( string $guild_id, array $events ): void {
		set_transient( self::get_cache_key( $guild_id ), $events, self::TTL );
	}

	/**
	 * Acquires a fetch lock for a guild.
	 */
	private static function acquire_lock( string $guild_id ): bool {
		$key      = self::get_lock_key( $guild_id );
		$existing = get_transient( $key );

		if ( false !== $existing ) {
			return false;
		}

		set_transient( $key, 1, self::LOCK_TTL );
		return true;
	}

	/**
	 * Releases the fetch lock for a guild.
	 */
	private static function release_lock( string $guild_id ): void {
		delete_transient( self::get_lock_key( $guild_id ) );
	}
}
