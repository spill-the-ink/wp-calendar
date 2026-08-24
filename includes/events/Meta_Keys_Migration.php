<?php

namespace PostCalendar\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-time migration: renames serialized _post_events array keys from
 * WordPress-style to Discord-aligned naming conventions.
 *
 * Old keys: label, label_id, start, end, repeat, repeat_interval, repeat_byday, repeat_until
 * New keys: name, name_id, scheduled_start_time, scheduled_end_time, frequency, interval, by_weekday, recurrence_end
 */
class Meta_Keys_Migration {
	private const VERSION_OPTION  = 'post_calendar_meta_keys_version';
	private const CURRENT_VERSION = 1;

	private const KEY_MAP = array(
		'label'           => 'name',
		'label_id'        => 'name_id',
		'start'           => 'scheduled_start_time',
		'end'             => 'scheduled_end_time',
		'repeat'          => 'frequency',
		'repeat_interval' => 'interval',
		'repeat_byday'    => 'by_weekday',
		'repeat_until'    => 'recurrence_end',
	);

	public static function maybe_run(): void {
		$stored_version = get_option( self::VERSION_OPTION, 0 );

		if ( self::CURRENT_VERSION <= $stored_version ) {
			return;
		}

		self::migrate_all_posts();
		update_option( self::VERSION_OPTION, self::CURRENT_VERSION );
	}

	private static function migrate_all_posts(): void {
		$query = new \WP_Query(
			array(
				'post_type'              => 'any',
				'post_status'            => 'any',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
				'meta_query'             => array(
					array(
						'key'     => Event_Config::EVENT_HAS_EVENTS_META,
						'value'   => '1',
						'compare' => '=',
					),
				),
			)
		);

		foreach ( $query->posts as $post_id ) {
			$stored_rows = get_post_meta( (int) $post_id, Event_Config::EVENTS_META, true );

			if ( ! is_array( $stored_rows ) || empty( $stored_rows ) ) {
				continue;
			}

			$migrated = array_map( array( self::class, 'migrate_row' ), $stored_rows );
			update_post_meta( (int) $post_id, Event_Config::EVENTS_META, $migrated );
		}
	}

	private static function migrate_row( $row ) {
		if ( ! is_array( $row ) ) {
			return $row;
		}

		$migrated = array();

		foreach ( $row as $key => $value ) {
			$new_key              = self::KEY_MAP[ $key ] ?? $key;
			$migrated[ $new_key ] = $value;
		}

		return $migrated;
	}
}
