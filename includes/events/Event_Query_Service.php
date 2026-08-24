<?php

namespace PostCalendar\Events;

use DateInterval;
use DateTimeImmutable;
use PostCalendar\Admin\Settings_Page;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Event_Query_Service {
	public const EVENTS_META                     = Event_Config::EVENTS_META;
	public const EVENT_HAS_EVENTS_META           = Event_Config::EVENT_HAS_EVENTS_META;
	public const EVENT_RANGE_START_META          = Event_Config::EVENT_RANGE_START_META;
	public const EVENT_RANGE_END_META            = Event_Config::EVENT_RANGE_END_META;
	public const EVENT_SCHEDULED_START_TIME_META = Event_Config::EVENT_SCHEDULED_START_TIME_META;
	public const EVENT_SCHEDULED_END_TIME_META   = Event_Config::EVENT_SCHEDULED_END_TIME_META;
	public const EVENT_NAME_META                 = Event_Config::EVENT_NAME_META;

	private const DEFAULT_EXPANSION_WINDOW = 'P1Y';
	public const REPEAT_NONE               = 'none';
	public const REPEAT_WEEKLY             = 'weekly';
	public const REPEAT_MONTHLY            = 'monthly';
	public const REPEAT_YEARLY             = 'yearly';
	private const MAX_OCCURRENCES          = 500;

	/**
	 * Cache lifetime for per-post occurrence expansion.
	 */
	public const EXPANSION_CACHE_TTL    = 300;
	private const EXPANSION_CACHE_GROUP = 'post_calendar';
	private const TRANSIENT_PREFIX      = 'post_calendar_exp_';

	/**
	 * Cap on the serialized size of persisted occurrences. Sites without an
	 * external object cache persist expansion results to the options table via
	 * transients, so we avoid writing oversized rows.
	 */
	private const MAX_PERSISTED_BYTES = 262144;

	/**
	 * @var array<string, array> Per-request memo of expanded occurrences.
	 */
	private $expansion_memo = array();

	public function build_range_meta_query( ?DateTimeImmutable $range_start, ?DateTimeImmutable $range_end ): array {
		/**
		 * Build a post-level meta query to find source posts that have events within the
		 * specified occurrence range. This queries the POST-LEVEL aggregate summary keys,
		 * not individual occurrence dates. The coarse filter identifies candidate posts;
		 * occurrence-level filtering happens during expand_recurring_posts().
		 *
		 * For posts with multiple event definitions, this checks if ANY event in the post
		 * overlaps the requested range. Individual occurrences are then filtered separately.
		 *
		 * @link https://www.w3.org/TR/NOTE-datetime Overlapping interval algorithm
		 */
		$range_meta_query = array(
			array(
				'key'   => self::EVENT_HAS_EVENTS_META,
				'value' => '1',
			),
			array(
				'key'     => self::EVENT_RANGE_START_META,
				'compare' => 'EXISTS',
			),
			array(
				'key'     => self::EVENT_RANGE_START_META,
				'value'   => '',
				'compare' => '!=',
			),
		);

		if ( $range_end ) {
			$range_meta_query[] = array(
				'key'     => self::EVENT_RANGE_START_META,
				'value'   => $this->format_request_date_for_meta( $range_end ),
				'compare' => '<=',
				'type'    => 'DATETIME',
			);
		}

		if ( $range_start ) {
			$formatted_start    = $this->format_request_date_for_meta( $range_start );
			$range_meta_query[] = array(
				'relation' => 'OR',
				array(
					'key'     => self::EVENT_RANGE_END_META,
					'value'   => $formatted_start,
					'compare' => '>=',
					'type'    => 'DATETIME',
				),
				array(
					'relation' => 'OR',
					array(
						'key'     => self::EVENT_RANGE_END_META,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'   => self::EVENT_RANGE_END_META,
						'value' => '',
					),
				),
			);
		}

		return $range_meta_query;
	}

	public function build_events_for_posts( array $posts, ?DateTimeImmutable $range_start, ?DateTimeImmutable $range_end ): array {
		$post_ids = array();

		foreach ( $posts as $post ) {
			$post_id = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : (int) $post;

			if ( $post_id > 0 ) {
				$post_ids[] = $post_id;
			}
		}

		$this->prime_post_lookups( $post_ids );

		$events = array();

		foreach ( $post_ids as $post_id ) {
			$events = array_merge( $events, $this->build_events_for_post( $post_id, $range_start, $range_end ) );
		}

		usort(
			$events,
			static function ( array $left, array $right ): int {
				$start_comparison = strcmp( (string) $left['scheduled_start_time'], (string) $right['scheduled_start_time'] );

				if ( 0 !== $start_comparison ) {
					return $start_comparison;
				}

				return strcmp( (string) $left['name'], (string) $right['name'] );
			}
		);

		return $events;
	}

	public function build_events_for_post( int $post_id, ?DateTimeImmutable $range_start, ?DateTimeImmutable $range_end ): array {
		$cache_key = $this->build_expansion_cache_key( $post_id, $range_start, $range_end );

		if ( isset( $this->expansion_memo[ $cache_key ] ) ) {
			return $this->expansion_memo[ $cache_key ];
		}

		$cached = $this->cache_get( $cache_key );

		if ( is_array( $cached ) ) {
			$this->expansion_memo[ $cache_key ] = $cached;

			return $cached;
		}

		$events = $this->expand_post_events( $post_id, $range_start, $range_end );

		$this->expansion_memo[ $cache_key ] = $events;
		$this->cache_set( $cache_key, $events );

		return $events;
	}

	/**
	 * Read an expansion result. Backed by the in-memory wp_cache layer, with a
	 * transient fallback for hosts that do not have a persistent object cache.
	 */
	private function cache_get( string $key ) {
		$cached = wp_cache_get( $key, self::EXPANSION_CACHE_GROUP );

		if ( is_array( $cached ) || ! $this->should_use_persistent_fallback() ) {
			return $cached;
		}

		return get_transient( $this->get_transient_key( $key ) );
	}

	/**
	 * Write an expansion result to the in-memory wp_cache layer and, when no
	 * persistent object cache is available, to a transient fallback.
	 */
	private function cache_set( string $key, array $events ): void {
		wp_cache_set( $key, $events, self::EXPANSION_CACHE_GROUP, self::EXPANSION_CACHE_TTL );

		if ( $this->should_use_persistent_fallback() && $this->is_cacheable_size( $events ) ) {
			set_transient( $this->get_transient_key( $key ), $events, self::EXPANSION_CACHE_TTL );
		}
	}

	/**
	 * Sites with an external object cache already persist across requests, so
	 * the transient fallback is only needed when WordPress reports no external
	 * object cache.
	 */
	private function should_use_persistent_fallback(): bool {
		return ! wp_using_ext_object_cache();
	}

	private function get_transient_key( string $key ): string {
		return self::TRANSIENT_PREFIX . md5( $key );
	}

	private function is_cacheable_size( array $events ): bool {
		$size = strlen( (string) wp_json_encode( $events ) );

		return $size > 0 && $size <= self::MAX_PERSISTED_BYTES;
	}

	private function expand_post_events( int $post_id, ?DateTimeImmutable $range_start, ?DateTimeImmutable $range_end ): array {
		$events      = array();
		$definitions = $this->get_event_definitions_for_post( $post_id );

		foreach ( $definitions as $definition ) {
			if ( self::REPEAT_NONE === $definition['frequency'] ) {
				$events = array_merge( $events, $this->build_single_event_set( $definition, $definition['scheduled_start_time'], $definition['scheduled_end_time'], $range_start, $range_end ) );
				continue;
			}

			switch ( $definition['frequency'] ) {
				case self::REPEAT_WEEKLY:
					$events = array_merge( $events, $this->build_weekly_events( $definition, $range_start, $range_end ) );
					break;

				case self::REPEAT_MONTHLY:
					$events = array_merge( $events, $this->build_monthly_events( $definition, $range_start, $range_end ) );
					break;

				case self::REPEAT_YEARLY:
					$events = array_merge( $events, $this->build_yearly_events( $definition, $range_start, $range_end ) );
					break;

				default:
					$events = array_merge( $events, $this->build_single_event_set( $definition, $definition['scheduled_start_time'], $definition['scheduled_end_time'], $range_start, $range_end ) );
			}
		}

		return $events;
	}

	/**
	 * Cache key for occurrence expansion. Includes a hash of the normalized
	 * definitions so any definition edit invalidates automatically.
	 */
	private function build_expansion_cache_key( int $post_id, ?DateTimeImmutable $range_start, ?DateTimeImmutable $range_end ): string {
		$post      = get_post( $post_id );
		$modified  = $post ? $post->post_modified_gmt : '';
		$rows_hash = md5( (string) wp_json_encode( $this->get_event_rows( $post_id ) ) );

		return sprintf(
			'expand_%d_%s_%s_%s_%s',
			$post_id,
			$modified,
			$rows_hash,
			$range_start ? $range_start->format( 'YmdHis' ) : 'open',
			$range_end ? $range_end->format( 'YmdHis' ) : 'open'
		);
	}

	/**
	 * Prime the WordPress object caches (post meta + object terms) for all
	 * source posts up front so that the per-post lookups in
	 * get_event_definitions_for_post() resolve from cache instead of issuing a
	 * separate DB query per post. When WP_Query has already primed these (e.g.
	 * default cache_results), the calls below are cheap no-ops.
	 *
	 * @param int[] $post_ids Post IDs to prime.
	 */
	private function prime_post_lookups( array $post_ids ): void {
		if ( empty( $post_ids ) ) {
			return;
		}

		$unique_ids = array_values( array_unique( $post_ids ) );

		update_meta_cache( 'post', $unique_ids );

		$objects = array();

		foreach ( $unique_ids as $post_id ) {
			$post = get_post( $post_id );

			if ( $post instanceof \WP_Post && ! empty( $post->post_type ) ) {
				$objects[ $post->post_type ][] = $post_id;
			}
		}

		foreach ( $objects as $post_type => $ids ) {
			update_object_term_cache( $ids, $post_type );
		}
	}

	public function get_event_definitions_for_post( int $post_id ): array {
		$rows = $this->get_event_rows( $post_id );

		if ( empty( $rows ) ) {
			return array();
		}

		$base_definition = array(
			'post_id'     => $post_id,
			'name'        => get_the_title( $post_id ),
			'url'         => get_permalink( $post_id ),
			'post_type'   => get_post_type( $post_id ),
			'description' => $this->get_event_excerpt( $post_id ),
			'tags'        => $this->get_event_terms( $post_id ),
		);
		$definitions     = array();

		foreach ( array_values( $rows ) as $event_index => $row ) {
			$definition = $this->normalize_event_definition( $base_definition, is_array( $row ) ? $row : array(), $event_index );

			if ( null === $definition ) {
				continue;
			}

			$definitions[] = $definition;
		}

		return $definitions;
	}

	public function parse_request_date( ?string $value ): ?DateTimeImmutable {
		return Event_Date_Parser::parse( $value );
	}

	public function resolve_post_types( ?string $post_types ): array {
		$available_types = Settings_Page::get_event_source_post_types();

		if ( ! $post_types ) {
			return $available_types;
		}

		$resolved = Settings_Page::resolve_event_source_post_types( $post_types );

		return $resolved ? $resolved : $available_types;
	}

	private function build_single_event_set( array $definition, DateTimeImmutable $occurrence_start, DateTimeImmutable $occurrence_end, ?DateTimeImmutable $range_start, ?DateTimeImmutable $range_end ): array {
		if ( ! $this->event_overlaps_range( $occurrence_start, $occurrence_end, $range_start, $range_end ) ) {
			return array();
		}

		return array(
			$this->format_occurrence_event( $definition, $occurrence_start, $occurrence_end ),
		);
	}

	private function build_weekly_events( array $definition, ?DateTimeImmutable $range_start, ?DateTimeImmutable $range_end ): array {
		$events          = array();
		$duration        = $definition['scheduled_end_time']->getTimestamp() - $definition['scheduled_start_time']->getTimestamp();
		$search_end      = $this->resolve_expansion_end( $definition, $range_start, $range_end );
		$base_week_start = $this->get_week_start( $definition['scheduled_start_time'] );
		$weekday_codes   = $definition['by_weekday'];
		$start_at        = $this->resolve_weekly_start_index( $definition['scheduled_start_time'], $range_start, $definition['interval'] );

		for ( $occurrence_count = 0, $week_index = $start_at; $occurrence_count < self::MAX_OCCURRENCES; $week_index += $definition['interval'] ) {
			$week_start = $base_week_start->modify( '+' . ( $week_index * 7 ) . ' days' );

			foreach ( $weekday_codes as $weekday_code ) {
				$occurrence_start = $this->create_weekday_occurrence( $definition['scheduled_start_time'], $week_start, $weekday_code );

				if ( $occurrence_start < $definition['scheduled_start_time'] ) {
					continue;
				}

				if ( $definition['recurrence_end'] && $occurrence_start > $definition['recurrence_end'] ) {
					return $events;
				}

				if ( $occurrence_start > $search_end ) {
					return $events;
				}

				$occurrence_end = $occurrence_start->modify( sprintf( '+%d seconds', max( 0, $duration ) ) );

				if ( $this->event_overlaps_range( $occurrence_start, $occurrence_end, $range_start, $range_end ) ) {
					$events[] = $this->format_occurrence_event( $definition, $occurrence_start, $occurrence_end );
				}

				++$occurrence_count;
			}
		}

		return $events;
	}

	private function resolve_weekly_start_index( DateTimeImmutable $base_start, ?DateTimeImmutable $range_start, int $interval ): int {
		if ( ! $range_start || $range_start <= $base_start ) {
			return 0;
		}

		$base_week_start  = $this->get_week_start( $base_start );
		$range_week_start = $this->get_week_start( $range_start );
		$days_difference  = (int) floor( ( $range_week_start->getTimestamp() - $base_week_start->getTimestamp() ) / 86400 );
		$weeks_difference = max( 0, intdiv( max( 0, $days_difference ), 7 ) );

		return intdiv( $weeks_difference, $interval ) * $interval;
	}

	private function build_monthly_events( array $definition, ?DateTimeImmutable $range_start, ?DateTimeImmutable $range_end ): array {
		$events     = array();
		$duration   = $definition['scheduled_end_time']->getTimestamp() - $definition['scheduled_start_time']->getTimestamp();
		$search_end = $this->resolve_expansion_end( $definition, $range_start, $range_end );
		$start_at   = $this->resolve_monthly_start_index( $definition['scheduled_start_time'], $range_start, $definition['interval'] );

		for ( $occurrence_count = 0, $month_index = $start_at; $occurrence_count < self::MAX_OCCURRENCES; $month_index += $definition['interval'], ++$occurrence_count ) {
			$occurrence_start = $this->create_monthly_occurrence( $definition['scheduled_start_time'], $month_index );

			if ( $definition['recurrence_end'] && $occurrence_start > $definition['recurrence_end'] ) {
				break;
			}

			if ( $occurrence_start > $search_end ) {
				break;
			}

			$occurrence_end = $occurrence_start->modify( sprintf( '+%d seconds', max( 0, $duration ) ) );

			if ( $this->event_overlaps_range( $occurrence_start, $occurrence_end, $range_start, $range_end ) ) {
				$events[] = $this->format_occurrence_event( $definition, $occurrence_start, $occurrence_end );
			}
		}

		return $events;
	}

	private function build_yearly_events( array $definition, ?DateTimeImmutable $range_start, ?DateTimeImmutable $range_end ): array {
		$events     = array();
		$duration   = $definition['scheduled_end_time']->getTimestamp() - $definition['scheduled_start_time']->getTimestamp();
		$search_end = $this->resolve_expansion_end( $definition, $range_start, $range_end );
		$start_at   = $this->resolve_yearly_start_index( $definition['scheduled_start_time'], $range_start, $definition['interval'] );

		for ( $occurrence_count = 0, $year_index = $start_at; $occurrence_count < self::MAX_OCCURRENCES; $year_index += $definition['interval'], ++$occurrence_count ) {
			$occurrence_start = $this->create_yearly_occurrence( $definition['scheduled_start_time'], $year_index );

			if ( $definition['recurrence_end'] && $occurrence_start > $definition['recurrence_end'] ) {
				break;
			}

			if ( $occurrence_start > $search_end ) {
				break;
			}

			$occurrence_end = $occurrence_start->modify( sprintf( '+%d seconds', max( 0, $duration ) ) );

			if ( $this->event_overlaps_range( $occurrence_start, $occurrence_end, $range_start, $range_end ) ) {
				$events[] = $this->format_occurrence_event( $definition, $occurrence_start, $occurrence_end );
			}
		}

		return $events;
	}

	private function normalize_event_definition( array $base_definition, array $row, int $event_index ): ?array {
		$start = Event_Date_Parser::parse( $this->get_row_value( $row, 'scheduled_start_time' ) ?? $this->get_row_value( $row, 'start' ) );

		if ( ! $start ) {
			return null;
		}

		$end = Event_Date_Parser::parse( $this->get_row_value( $row, 'scheduled_end_time' ) ?? $this->get_row_value( $row, 'end' ) );

		if ( ! $end || $end < $start ) {
			$end = $start;
		}

		$repeat  = $this->normalize_repeat_value( $this->get_row_value( $row, 'frequency' ) ?? $this->get_row_value( $row, 'repeat' ) );
		$name_id = $this->get_row_value( $row, 'name_id' ) ?? $this->get_row_value( $row, 'label_id' );

		return array(
			'post_id'              => $base_definition['post_id'],
			'event_index'          => $event_index,
			'definition_id'        => $base_definition['post_id'] . ':' . $event_index,
			'name'                 => $this->normalize_event_title( $this->get_row_value( $row, 'name' ) ?? $this->get_row_value( $row, 'label' ), $base_definition['name'] ),
			'scheduled_start_time' => $start,
			'scheduled_end_time'   => $end,
			'all_day'              => $this->normalize_boolean( $this->get_row_value( $row, 'all_day' ) ),
			'url'                  => $base_definition['url'],
			'post_type'            => $base_definition['post_type'],
			'description'          => $base_definition['description'],
			'tags'                 => $base_definition['tags'],
			'name_id'              => $name_id ? sanitize_key( (string) $name_id ) : '',
			'frequency'            => $repeat,
			'interval'             => max( 1, absint( $this->get_row_value( $row, 'interval' ) ?? $this->get_row_value( $row, 'repeat_interval' ) ) ),
			'recurrence_end'       => Event_Date_Parser::parse( $this->get_row_value( $row, 'recurrence_end' ) ?? $this->get_row_value( $row, 'repeat_until' ) ),
			'by_weekday'           => $this->normalize_repeat_byday( $this->get_row_value( $row, 'by_weekday' ) ?? $this->get_row_value( $row, 'repeat_byday' ), $start ),
			'location'             => (string) ( $this->get_row_value( $row, 'location' ) ?? '' ),
			'location_url'         => (string) ( $this->get_row_value( $row, 'location_url' ) ?? '' ),
		);
	}

	private function format_occurrence_event( array $definition, DateTimeImmutable $occurrence_start, DateTimeImmutable $occurrence_end ): array {
		$label = null;
		if ( ! empty( $definition['name_id'] ) ) {
			$label_data = Settings_Page::get_label_by_id( $definition['name_id'] );
			if ( $label_data ) {
				$label = array(
					'id'    => $label_data['id'],
					'name'  => $label_data['name'],
					'color' => $label_data['color'],
				);
			}
		}

		return array(
			'id'                   => $this->build_occurrence_id( (int) $definition['post_id'], (int) $definition['event_index'], $occurrence_start ),
			'name'                 => $definition['name'],
			'scheduled_start_time' => $occurrence_start->format( DATE_ATOM ),
			'scheduled_end_time'   => $occurrence_end->format( DATE_ATOM ),
			'allDay'               => $definition['all_day'],
			'url'                  => $definition['url'],
			'description'          => $definition['description'],
			'tags'                 => $definition['tags'],
			'label'                => $label,
			'location'             => ! empty( $definition['location'] ) ? $definition['location'] : null,
			'location_url'         => ! empty( $definition['location_url'] ) ? $definition['location_url'] : null,
			'source'               => array(
				'type' => 'wp',
				'id'   => $definition['post_type'],
				'name' => get_post_type_object( $definition['post_type'] )->labels->singular_name ?? $definition['post_type'],
			),
			'postId'               => $definition['post_id'],
			'postType'             => $definition['post_type'],
			'eventIndex'           => (int) $definition['event_index'],
		);
	}

	private function build_occurrence_id( int $post_id, int $event_index, DateTimeImmutable $occurrence_start ): string {
		return $post_id . ':' . $event_index . ':' . $occurrence_start->format( 'Y-m-d\TH:i:sP' );
	}

	private function normalize_repeat_value( $value ): string {
		$repeat = is_string( $value ) ? sanitize_key( $value ) : self::REPEAT_NONE;

		if ( in_array( $repeat, array( self::REPEAT_WEEKLY, self::REPEAT_MONTHLY, self::REPEAT_YEARLY ), true ) ) {
			return $repeat;
		}

		return self::REPEAT_NONE;
	}

	private function normalize_repeat_byday( $value, DateTimeImmutable $start ): array {
		$weekday_codes = array();

		if ( is_array( $value ) ) {
			$weekday_codes = $value;
		} elseif ( is_string( $value ) && '' !== trim( $value ) ) {
			$weekday_codes = array_map( 'trim', explode( ',', $value ) );
		}

		$weekday_codes = array_values(
			array_filter(
				array_unique(
					array_map(
						static function ( $weekday_code ): string {
							return strtoupper( sanitize_key( (string) $weekday_code ) );
						},
						$weekday_codes,
					),
				),
			),
		);

		if ( empty( $weekday_codes ) ) {
			$weekday_codes[] = $this->get_weekday_code( $start );
		}

		usort(
			$weekday_codes,
			array( $this, 'compare_weekday_codes' ),
		);

		return $weekday_codes;
	}

	private function compare_weekday_codes( string $left, string $right ): int {
		return $this->get_weekday_number_from_code( $left ) <=> $this->get_weekday_number_from_code( $right );
	}

	private function get_weekday_code( DateTimeImmutable $date ): string {
		$weekday_map = array(
			1 => 'MO',
			2 => 'TU',
			3 => 'WE',
			4 => 'TH',
			5 => 'FR',
			6 => 'SA',
			7 => 'SU',
		);

		return $weekday_map[ (int) $date->format( 'N' ) ] ?? 'MO';
	}

	private function get_weekday_number_from_code( string $weekday_code ): int {
		$weekday_map = array(
			'MO' => 1,
			'TU' => 2,
			'WE' => 3,
			'TH' => 4,
			'FR' => 5,
			'SA' => 6,
			'SU' => 7,
		);

		return $weekday_map[ $weekday_code ] ?? 1;
	}

	private function resolve_expansion_end( array $definition, ?DateTimeImmutable $range_start, ?DateTimeImmutable $range_end ): DateTimeImmutable {
		$expansion_end = $range_end;

		if ( ! $expansion_end ) {
			$anchor        = $range_start ?? $definition['scheduled_start_time'];
			$expansion_end = $anchor->add( new DateInterval( self::DEFAULT_EXPANSION_WINDOW ) );
		}

		if ( $definition['recurrence_end'] && $definition['recurrence_end'] < $expansion_end ) {
			return $definition['recurrence_end'];
		}

		return $expansion_end;
	}

	private function resolve_monthly_start_index( DateTimeImmutable $base_start, ?DateTimeImmutable $range_start, int $interval ): int {
		if ( ! $range_start || $range_start <= $base_start ) {
			return 0;
		}

		$month_difference = ( ( (int) $range_start->format( 'Y' ) - (int) $base_start->format( 'Y' ) ) * 12 ) + ( (int) $range_start->format( 'n' ) - (int) $base_start->format( 'n' ) );

		return max( 0, intdiv( max( 0, $month_difference ), $interval ) * $interval );
	}

	private function resolve_yearly_start_index( DateTimeImmutable $base_start, ?DateTimeImmutable $range_start, int $interval ): int {
		if ( ! $range_start || $range_start <= $base_start ) {
			return 0;
		}

		$year_difference = (int) $range_start->format( 'Y' ) - (int) $base_start->format( 'Y' );

		return max( 0, intdiv( max( 0, $year_difference ), $interval ) * $interval );
	}

	private function get_week_start( DateTimeImmutable $date ): DateTimeImmutable {
		$days_from_monday = (int) $date->format( 'N' ) - 1;

		return $date->modify( sprintf( '-%d days', $days_from_monday ) );
	}

	private function create_weekday_occurrence( DateTimeImmutable $base_start, DateTimeImmutable $week_start, string $weekday_code ): DateTimeImmutable {
		$day_offset = $this->get_weekday_number_from_code( $weekday_code ) - 1;
		$time       = $base_start->format( 'H:i:s' );

		return $week_start
			->modify( '+' . $day_offset . ' days' )
			->setTime( (int) substr( $time, 0, 2 ), (int) substr( $time, 3, 2 ), (int) substr( $time, 6, 2 ) );
	}

	private function create_monthly_occurrence( DateTimeImmutable $base_start, int $month_index ): DateTimeImmutable {
		$base_month_index = ( (int) $base_start->format( 'Y' ) * 12 ) + ( (int) $base_start->format( 'n' ) - 1 ) + $month_index;
		$year             = intdiv( $base_month_index, 12 );
		$month            = ( $base_month_index % 12 ) + 1;
		$day              = min( (int) $base_start->format( 'j' ), cal_days_in_month( CAL_GREGORIAN, $month, $year ) );

		return $base_start->setDate( $year, $month, $day );
	}

	private function create_yearly_occurrence( DateTimeImmutable $base_start, int $year_index ): DateTimeImmutable {
		$year  = (int) $base_start->format( 'Y' ) + $year_index;
		$month = (int) $base_start->format( 'n' );
		$day   = min( (int) $base_start->format( 'j' ), cal_days_in_month( CAL_GREGORIAN, $month, $year ) );

		return $base_start->setDate( $year, $month, $day );
	}

	private function event_overlaps_range( DateTimeImmutable $event_start, DateTimeImmutable $event_end, ?DateTimeImmutable $range_start, ?DateTimeImmutable $range_end ): bool {
		if ( $range_start && $event_end < $range_start ) {
			return false;
		}

		if ( $range_end && $event_start > $range_end ) {
			return false;
		}

		return true;
	}

	private function format_request_date_for_meta( DateTimeImmutable $date ): string {
		return $date->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' );
	}

	private function get_event_excerpt( int $post_id ): string {
		$excerpt = get_the_excerpt( $post_id );

		if ( ! is_string( $excerpt ) ) {
			return '';
		}

		return wp_strip_all_tags( $excerpt );
	}

	private function get_event_terms( int $post_id ): array {
		$taxonomy_names = get_object_taxonomies( get_post_type( $post_id ), 'names' );
		$labels         = array();

		foreach ( $taxonomy_names as $taxonomy_name ) {
			if ( 'post_format' === $taxonomy_name ) {
				continue;
			}

			$terms = wp_get_post_terms(
				$post_id,
				$taxonomy_name,
				array(
					'fields' => 'names',
				),
			);

			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term_name ) {
				if ( ! in_array( $term_name, $labels, true ) ) {
					$labels[] = $term_name;
				}

				if ( count( $labels ) >= 3 ) {
					return $labels;
				}
			}
		}

		return $labels;
	}

	private function get_event_rows( int $post_id ): array {
		$stored_rows = get_post_meta( $post_id, self::EVENTS_META, true );

		if ( is_array( $stored_rows ) ) {
			return $stored_rows;
		}

		return array();
	}

	private function get_row_value( array $row, string $key ) {
		return array_key_exists( $key, $row ) ? $row[ $key ] : null;
	}

	private function normalize_boolean( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			return 1 === (int) $value;
		}

		if ( is_string( $value ) ) {
			return in_array( strtolower( $value ), array( '1', 'true', 'yes', 'on' ), true );
		}

		return false;
	}

	private function normalize_event_title( $value, string $fallback ): string {
		if ( is_string( $value ) ) {
			$label = trim( $value );

			if ( '' !== $label ) {
				return $label;
			}
		}

		return $fallback;
	}
}
