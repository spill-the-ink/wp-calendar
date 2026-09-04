<?php

namespace WpCalendar\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-time migration for the "Post Calendar" -> "WordPress Calendar" rename.
 *
 * Copies legacy `_post_*` post meta keys onto their `_wp_calendar_*`
 * replacements and legacy `post_calendar_*` options/transients onto their
 * `wp_calendar_*` replacements so existing installs keep their data after the
 * stable identifiers changed to align with the wp-manifest naming conventions.
 *
 * The migration is idempotent: it only copies when the destination key is empty,
 * so it is safe to run multiple times and never overwrites newer data.
 */
class Schema_Migration {
	private const VERSION_OPTION  = 'wp_calendar_schema_version';
	private const CURRENT_VERSION = 1;

	/**
	 * Map of legacy meta keys to their canonical replacements.
	 *
	 * @var array<string,string>
	 */
	private const META_KEY_MAP = array(
		'_post_events'               => Event_Config::EVENTS_META,
		'_post_has_events'           => Event_Config::EVENT_HAS_EVENTS_META,
		'_post_events_range_start'   => Event_Config::EVENT_RANGE_START_META,
		'_post_events_range_end'     => Event_Config::EVENT_RANGE_END_META,
		'_post_event_start'          => Event_Config::EVENT_SCHEDULED_START_TIME_META,
		'_post_event_end'            => Event_Config::EVENT_SCHEDULED_END_TIME_META,
		'_post_event_label'          => Event_Config::EVENT_NAME_META,
	);

	/**
	 * Map of legacy option/transient names (keys) to their replacements
	 * (values).
	 *
	 * @var array<string,string>
	 */
	private const STORAGE_KEY_MAP = array(
		'post_calendar_post_types'        => 'wp_calendar_post_types',
		'post_calendar_labels'            => 'wp_calendar_labels',
		'post_calendar_sources'           => 'wp_calendar_sources',
		'post_calendar_discord'           => 'wp_calendar_discord',
		'post_calendar_meta_keys_version' => 'wp_calendar_meta_keys_version',
		'post_calendar_github_update'     => 'wp_calendar_github_update',
	);

	public static function maybe_run(): void {
		$stored_version = get_option( self::VERSION_OPTION, 0 );

		if ( self::CURRENT_VERSION <= $stored_version ) {
			return;
		}

		self::migrate_post_meta();
		self::migrate_options();
		self::migrate_transients();

		update_option( self::VERSION_OPTION, self::CURRENT_VERSION );
	}

	/**
	 * Copy legacy `_post_*` meta keys onto their `_wp_calendar_*` replacements
	 * for every post that still holds legacy keys.
	 */
	private static function migrate_post_meta(): void {
		$exists_clauses = array();

		foreach ( array_keys( self::META_KEY_MAP ) as $legacy_key ) {
			$exists_clauses[] = array(
				'key'     => $legacy_key,
				'compare' => 'EXISTS',
			);
		}

		$query = new \WP_Query(
			array(
				'post_type'              => 'any',
				'post_status'            => 'any',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
				'meta_query'             => array_merge(
					array( 'relation' => 'OR' ),
					$exists_clauses
				),
			)
		);

		foreach ( $query->posts as $post_id ) {
			$post_id = (int) $post_id;

			foreach ( self::META_KEY_MAP as $legacy_key => $new_key ) {
				if ( metadata_exists( 'post', $post_id, $new_key ) ) {
					continue;
				}

				$value = get_post_meta( $post_id, $legacy_key, true );

				if ( '' === $value ) {
					continue;
				}

				update_post_meta( $post_id, $new_key, $value );
			}
		}
	}

	/**
	 * Copy legacy `post_calendar_*` options onto their `wp_calendar_*`
	 * replacements.
	 */
	private static function migrate_options(): void {
		foreach ( self::STORAGE_KEY_MAP as $legacy_key => $new_key ) {
			if ( false !== get_option( $new_key, false ) ) {
				continue;
			}

			$value = get_option( $legacy_key, null );

			if ( null === $value ) {
				continue;
			}

			update_option( $new_key, $value, false );
		}
	}

	/**
	 * Copy legacy `post_calendar_*` transients onto their `wp_calendar_*`
	 * replacements (discovering prefixed cache keys without SQL).
	 */
	private static function migrate_transients(): void {
		$replacements = array(
			'post_calendar_github_update'    => 'wp_calendar_github_update',
			'post_calendar_discord_events_'  => 'wp_calendar_discord_events_',
			'post_calendar_discord_lock_'    => 'wp_calendar_discord_lock_',
			'post_calendar_ical_events_'     => 'wp_calendar_ical_events_',
			'post_calendar_ical_lock_'       => 'wp_calendar_ical_lock_',
			'post_calendar_exp_'             => 'wp_calendar_exp_',
		);

		foreach ( $replacements as $legacy_prefix => $new_prefix ) {
			foreach ( self::find_transients( $legacy_prefix ) as $legacy_transient ) {
				$new_transient = $new_prefix . substr( $legacy_transient, strlen( $legacy_prefix ) );

				if ( false !== get_transient( $new_transient ) ) {
					continue;
				}

				$value = get_transient( $legacy_transient );

				if ( false === $value ) {
					continue;
				}

				set_transient( $new_transient, $value );
			}
		}
	}

	/**
	 * Enumerate stored transients whose name starts with the given prefix.
	 *
	 * Transients are stored as `_transient_<name>` rows in the options table.
	 *
	 * @param string $prefix
	 * @return string[]
	 */
	private static function find_transients( string $prefix ): array {
		global $wpdb;

		$results = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . $prefix ) . '%'
			)
		);

		$transients = array();

		foreach ( $results as $option_name ) {
			$transients[] = (string) substr( $option_name, strlen( '_transient_' ) );
		}

		return $transients;
	}
}
