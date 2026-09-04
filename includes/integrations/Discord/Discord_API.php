<?php

namespace WpCalendar\Integrations\Discord;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Higher-level Discord API wrapper for fetching scheduled events.
 *
 * Handles token validation, multi-guild fetching, and error normalization.
 */
class Discord_API {
	/**
	 * Fetches scheduled events for all enabled guilds.
	 *
	 * Returns an associative array keyed by guild_id, with each value being
	 * an array of raw Discord scheduled event objects, or null on failure.
	 *
	 * @param array $guilds  Array of guild configs from Settings_Page::get_sources().
	 * @return array{events: array<string, array|null>, errors: array<string, string>}
	 */
	public static function fetch_all_guild_events( array $guilds ): array {
		$token = Discord_Client::get_bot_token();
		if ( '' === $token ) {
			return array(
				'events' => array(),
				'errors' => array( '_auth' => 'Discord Bot token is not configured.' ),
			);
		}

		$results = array(
			'events' => array(),
			'errors' => array(),
		);

		foreach ( $guilds as $guild ) {
			if ( empty( $guild['enabled'] ) || empty( $guild['guild_id'] ) ) {
				continue;
			}

			$guild_id = (string) $guild['guild_id'];
			$events   = Discord_Client::get_guild_scheduled_events( $token, $guild_id, true );

			if ( null === $events ) {
				$results['errors'][ $guild_id ] = sprintf(
					'Failed to fetch events for guild %s.',
					$guild['name'] ?? $guild_id
				);
				$results['events'][ $guild_id ] = null;
				continue;
			}

			$results['events'][ $guild_id ] = $events;
		}

		return $results;
	}

	/**
	 * Filters scheduled events to only those in SCHEDULED or ACTIVE status.
	 *
	 * Discord statuses:
	 * 1 = SCHEDULED, 2 = ACTIVE, 3 = COMPLETED, 4 = CANCELED
	 *
	 * @param array $events  Raw Discord scheduled event objects.
	 * @return array  Filtered events.
	 */
	public static function filter_active_events( array $events ): array {
		return array_filter(
			$events,
			static function ( $event ): bool {
				$status = $event['status'] ?? 0;
				return in_array( $status, array( 1, 2 ), true );
			}
		);
	}
}
