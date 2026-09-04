<?php

namespace WpCalendar\Events;

use DateInterval;
use DateTimeImmutable;
use WpCalendar\Admin\Settings_Page;
use WP_Post;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers a virtual proxy post type that allows template systems and page builders
 * to query calendar events across all source post types via a single, uniform
 * `post_type` slug.
 */
class Post_Type {
	public const SLUG                   = 'wp_calendar_event';
	public const SOURCE_TYPES_QUERY_VAR = 'wp_calendar_source_types';

	private const EVENT_ENABLED_META               = Event_Config::EVENT_HAS_EVENTS_META;
	private const EVENT_SCHEDULED_START_TIME_META  = Event_Config::EVENT_SCHEDULED_START_TIME_META;
	private const EVENT_SCHEDULED_END_TIME_META    = Event_Config::EVENT_SCHEDULED_END_TIME_META;
	private const EVENT_NAME_META                  = Event_Config::EVENT_NAME_META;
	private const OCCURRENCE_FLAG_QUERY_VAR        = 'wp_calendar_expand_occurrences';
	private const OCCURRENCE_RANGE_START_QUERY_VAR = 'wp_calendar_occurrence_range_start';
	private const OCCURRENCE_RANGE_END_QUERY_VAR   = 'wp_calendar_occurrence_range_end';
	private const OCCURRENCE_OFFSET_QUERY_VAR      = 'wp_calendar_occurrence_offset';
	private const OCCURRENCE_LIMIT_QUERY_VAR       = 'wp_calendar_occurrence_limit';
	private const DEFAULT_OCCURRENCE_WINDOW        = 'P1Y';

	private Event_Query_Service $event_query_service;

	public function __construct( ?Event_Query_Service $event_query_service = null ) {
		$this->event_query_service = $event_query_service ?? new Event_Query_Service();

		add_action( 'init', array( $this, 'register_post_type' ), 5 );
		add_action( 'pre_get_posts', array( $this, 'intercept_query' ) );
		add_filter( 'the_posts', array( $this, 'expand_recurring_posts' ), 10, 2 );
		add_filter( 'get_post_metadata', array( $this, 'filter_occurrence_meta' ), 10, 4 );
		add_filter( 'wp_calendar_excluded_post_types', array( $this, 'exclude_from_settings' ) );
	}

	public function register_post_type(): void {
		register_post_type(
			self::SLUG,
			array(
				'label'               => esc_html__( 'Calendar Events', 'wp-calendar' ),
				'labels'              => array(
					'name'          => esc_html__( 'Calendar Events', 'wp-calendar' ),
					'singular_name' => esc_html__( 'Calendar Event', 'wp-calendar' ),
					'menu_name'     => esc_html__( 'Calendar Events', 'wp-calendar' ),
				),
				'description'         => esc_html__( 'A virtual query type for WordPress Calendar events. Query this type to retrieve event posts from all source post types.', 'wp-calendar' ),
				'public'              => true,
				'publicly_queryable'  => true,
				'show_ui'             => true,
				'show_in_menu'        => false,
				'show_in_admin_bar'   => false,
				'show_in_nav_menus'   => false,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'rewrite'             => false,
				'show_in_rest'        => true,
				'rest_base'           => self::SLUG,
				'query_var'           => false,
				'map_meta_cap'        => true,
				'capabilities'        => array(
					'create_posts'           => 'do_not_allow',
					'edit_posts'             => 'do_not_allow',
					'edit_published_posts'   => 'do_not_allow',
					'delete_posts'           => 'do_not_allow',
					'delete_published_posts' => 'do_not_allow',
					'publish_posts'          => 'do_not_allow',
					'read_private_posts'     => 'read',
					'read'                   => 'read',
				),
				'supports'            => array( 'title', 'custom-fields' ),
			),
		);
	}

	public function intercept_query( WP_Query $query ): void {
		$post_type = $query->get( 'post_type' );

		if ( is_string( $post_type ) ) {
			if ( self::SLUG !== $post_type ) {
				return;
			}
		} elseif ( is_array( $post_type ) ) {
			$post_types = array_values( array_unique( array_filter( array_map( 'sanitize_key', $post_type ) ) ) );

			if ( 1 !== count( $post_types ) || self::SLUG !== $post_types[0] ) {
				return;
			}
		} else {
			return;
		}

		$source_types = $this->resolve_source_types( $query );

		if ( empty( $source_types ) ) {
			$query->set( 'post_type', 'post' );
			$query->set( 'post__in', array( 0 ) );
			return;
		}

		$query->set( 'post_type', $source_types );
		$query->set( self::OCCURRENCE_FLAG_QUERY_VAR, true );

		$occurrence_constraints = $this->extract_occurrence_constraints( $query->get( 'meta_query' ) );
		$range_start            = $this->resolve_occurrence_range_start( $query, $occurrence_constraints['range_start'] );
		$range_end              = $this->resolve_occurrence_range_end( $query, $occurrence_constraints['range_end'], $range_start );
		$occurrence_offset      = $this->resolve_occurrence_offset( $query );
		$occurrence_limit       = $this->resolve_occurrence_limit( $query );

		$query->set( self::OCCURRENCE_RANGE_START_QUERY_VAR, $range_start ? $range_start->format( DATE_ATOM ) : '' );
		$query->set( self::OCCURRENCE_RANGE_END_QUERY_VAR, $range_end ? $range_end->format( DATE_ATOM ) : '' );
		$query->set( self::OCCURRENCE_OFFSET_QUERY_VAR, $occurrence_offset );
		$query->set( self::OCCURRENCE_LIMIT_QUERY_VAR, $occurrence_limit );

		$caller_meta = $occurrence_constraints['meta_query'];
		$meta_query  = array(
			'relation' => 'AND',
		);

		if ( ! empty( $caller_meta ) ) {
			$meta_query[] = $caller_meta;
		}

		$meta_query[] = $this->event_query_service->build_range_meta_query( $range_start, $range_end );

		$query->set( 'meta_query', $meta_query );
		$query->set( 'posts_per_page', -1 );
		$query->set( 'nopaging', true );
		$query->set( 'offset', 0 );
		$query->set( 'paged', 1 );
		$query->set( 'no_found_rows', true );
		$query->set( 'ignore_sticky_posts', true );

		if ( ! $query->get( 'orderby' ) ) {
			$query->set( 'orderby', 'meta_value' );
			$query->set( 'meta_key', self::EVENT_SCHEDULED_START_TIME_META );
			$query->set( 'order', 'ASC' );
		}

		if ( ! $query->get( 'order' ) ) {
			$query->set( 'order', 'ASC' );
		}
	}

	public function expand_recurring_posts( array $posts, WP_Query $query ): array {
		if ( ! $query->get( self::OCCURRENCE_FLAG_QUERY_VAR ) ) {
			return $posts;
		}

		/**
		 * Expand each post into its individual occurrence rows. This is where occurrence-level
		 * date filtering actually happens. Each event definition in the post is expanded into
		 * individual occurrences and filtered by the search range. Posts with multiple events
		 * may produce multiple occurrence rows, each with its own start/end/label metadata.
		 */
		$range_start = Event_Date_Parser::parse( is_string( $query->get( self::OCCURRENCE_RANGE_START_QUERY_VAR ) ) ? $query->get( self::OCCURRENCE_RANGE_START_QUERY_VAR ) : null );
		$range_end   = Event_Date_Parser::parse( is_string( $query->get( self::OCCURRENCE_RANGE_END_QUERY_VAR ) ) ? $query->get( self::OCCURRENCE_RANGE_END_QUERY_VAR ) : null );
		$occurrences = array();

		foreach ( $posts as $index => $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$events = $this->event_query_service->build_events_for_post( (int) $post->ID, $range_start, $range_end );

			foreach ( $events as $event ) {
				$occurrences[] = $this->create_occurrence_post( $post, $event, $index );
			}
		}

		$occurrences = $this->sort_occurrences( $occurrences, $query );
		$total       = count( $occurrences );
		$offset      = max( 0, (int) $query->get( self::OCCURRENCE_OFFSET_QUERY_VAR ) );
		$limit       = (int) $query->get( self::OCCURRENCE_LIMIT_QUERY_VAR );

		if ( $limit > 0 ) {
			$occurrences = array_slice( $occurrences, $offset, $limit );
		} elseif ( $offset > 0 ) {
			$occurrences = array_slice( $occurrences, $offset );
		}

		$query->found_posts   = $total;
		$query->post_count    = count( $occurrences );
		$query->posts         = $occurrences;
		$query->max_num_pages = $limit > 0 ? (int) ceil( $total / $limit ) : ( $total > 0 ? 1 : 0 );

		return $occurrences;
	}

	public function filter_occurrence_meta( $value, $object_id, $meta_key, $single ) {
		/**
		 * Intercept meta requests on occurrence loop rows to return occurrence-specific data
		 * instead of source-post data. When the loop is rendering an occurrence, the post
		 * object carries occurrence metadata as properties. This filter checks whether the
		 * current loop row is an occurrence and returns the correct value.
		 *
		 * For multi-event posts, this ensures each loop row returns its own event's label,
		 * start date, and end date, not the source post's aggregate values.
		 */
		global $post;

		if ( ! $post instanceof WP_Post ) {
			return $value;
		}

		if ( (int) $post->ID !== (int) $object_id || empty( $post->post_event_start ) ) {
			return $value;
		}

		if ( self::EVENT_SCHEDULED_START_TIME_META === $meta_key ) {
			return $single ? $post->post_event_start : array( $post->post_event_start );
		}

		if ( self::EVENT_SCHEDULED_END_TIME_META === $meta_key ) {
			return $single ? $post->post_event_end : array( $post->post_event_end );
		}

		if ( self::EVENT_NAME_META === $meta_key ) {
			$label = isset( $post->post_event_label ) ? (string) $post->post_event_label : (string) get_the_title( $post->ID );
			return $single ? $label : array( $label );
		}

		if ( self::EVENT_ENABLED_META === $meta_key ) {
			return $single ? '1' : array( '1' );
		}

		return $value;
	}

	private function resolve_source_types( WP_Query $query ): array {
		$requested_source_types = $query->get( self::SOURCE_TYPES_QUERY_VAR );

		return Settings_Page::resolve_event_source_post_types( $requested_source_types );
	}

	private function resolve_occurrence_range_start( WP_Query $query, ?DateTimeImmutable $derived_range_start ): ?DateTimeImmutable {
		$requested_range_start = $this->event_query_service->parse_request_date( $query->get( 'start' ) );

		if ( $requested_range_start ) {
			return $requested_range_start;
		}

		if ( $derived_range_start ) {
			return $derived_range_start;
		}

		return $this->now();
	}

	private function resolve_occurrence_range_end( WP_Query $query, ?DateTimeImmutable $derived_range_end, ?DateTimeImmutable $range_start ): ?DateTimeImmutable {
		$requested_range_end = $this->event_query_service->parse_request_date( $query->get( 'end' ) );

		if ( $requested_range_end ) {
			return $requested_range_end;
		}

		if ( $derived_range_end ) {
			return $derived_range_end;
		}

		$anchor = $range_start ?? $this->now();

		return $anchor->add( new DateInterval( self::DEFAULT_OCCURRENCE_WINDOW ) );
	}

	private function resolve_occurrence_offset( WP_Query $query ): int {
		$offset         = max( 0, (int) $query->get( 'offset' ) );
		$posts_per_page = $this->resolve_occurrence_limit( $query );
		$paged          = max( 1, (int) $query->get( 'paged' ) );

		if ( $posts_per_page > 0 && $paged > 1 ) {
			$offset += ( $paged - 1 ) * $posts_per_page;
		}

		return $offset;
	}

	private function resolve_occurrence_limit( WP_Query $query ): int {
		$posts_per_page = (int) $query->get( 'posts_per_page' );

		return $posts_per_page > 0 ? $posts_per_page : -1;
	}

	private function extract_occurrence_constraints( $meta_query ): array {
		/**
		 * Extract occurrence-level date filters from a meta_query and convert them to
		 * a post-level search window. This is necessary because:
		 *
		 * 1. Users query on _wp_calendar_event_start and _wp_calendar_event_end (occurrence keys)
		 * 2. These keys don't exist on the source post; they only appear on occurrence clones
		 * 3. We must intercept these filters, remove them from the post meta_query,
		 *    and convert them to post-level summary range bounds
		 * 4. The occurrence-level filtering happens later in expand_recurring_posts()
		 *
		 * For multi-event posts, this ensures each event's date is checked against the
		 * user's requested range, not the aggregate post range.
		 */
		if ( ! is_array( $meta_query ) ) {
			return array(
				'meta_query'  => array(),
				'range_start' => null,
				'range_end'   => null,
			);
		}

		$cleaned_meta = array();

		if ( isset( $meta_query['relation'] ) ) {
			$cleaned_meta['relation'] = $meta_query['relation'];
		}

		$range_start = null;
		$range_end   = null;

		foreach ( $meta_query as $key => $clause ) {
			if ( 'relation' === $key ) {
				continue;
			}

			if ( ! is_array( $clause ) ) {
				$cleaned_meta[ $key ] = $clause;
				continue;
			}

			if ( $this->is_meta_clause( $clause ) ) {
				$constraint = $this->extract_range_from_clause( $clause );

				if ( $constraint['handled'] ) {
					$range_start = $this->merge_range_start( $range_start, $constraint['range_start'] );
					$range_end   = $this->merge_range_end( $range_end, $constraint['range_end'] );
					continue;
				}
			}

			$nested = $this->extract_occurrence_constraints( $clause );

			$range_start = $this->merge_range_start( $range_start, $nested['range_start'] );
			$range_end   = $this->merge_range_end( $range_end, $nested['range_end'] );

			if ( $this->has_meta_clauses( $nested['meta_query'] ) ) {
				$cleaned_meta[ $key ] = $nested['meta_query'];
			}
		}

		return array(
			'meta_query'  => $this->has_meta_clauses( $cleaned_meta ) ? $cleaned_meta : array(),
			'range_start' => $range_start,
			'range_end'   => $range_end,
		);
	}

	private function is_meta_clause( array $clause ): bool {
		return isset( $clause['key'] ) && is_string( $clause['key'] );
	}

	private function extract_range_from_clause( array $clause ): array {
		/**
		 * Extract a date range constraint from a meta_query clause that references
		 * occurrence date keys. Only _wp_calendar_event_start and _wp_calendar_event_end are converted
		 * to search window bounds. All other meta keys are left as-is for post-level filtering.
		 *
		 * Occurrence date filters must NOT be passed to the post-level WP_Query because
		 * they don't exist as persistent post meta. Instead, they define the search window
		 * used to find candidate posts and later applied during occurrence expansion.
		 */
		$key = $clause['key'] ?? '';

		if ( ! in_array( $key, array( self::EVENT_SCHEDULED_START_TIME_META, self::EVENT_SCHEDULED_END_TIME_META ), true ) ) {
			return array(
				'handled'     => false,
				'range_start' => null,
				'range_end'   => null,
			);
		}

		$compare = strtoupper( is_string( $clause['compare'] ?? '' ) ? $clause['compare'] : '=' );
		$value   = $clause['value'] ?? null;

		switch ( $compare ) {
			case '>':
			case '>=':
				return array(
					'handled'     => true,
					'range_start' => $this->parse_range_value( $value ),
					'range_end'   => null,
				);

			case '<':
			case '<=':
				return array(
					'handled'     => true,
					'range_start' => null,
					'range_end'   => $this->parse_range_value( $value ),
				);

			case '=':
				$date = $this->parse_range_value( $value );

				return array(
					'handled'     => true,
					'range_start' => $date,
					'range_end'   => $date,
				);

			case 'BETWEEN':
				$start_value = is_array( $value ) && isset( $value[0] ) ? $value[0] : null;
				$end_value   = is_array( $value ) && isset( $value[1] ) ? $value[1] : null;

				return array(
					'handled'     => true,
					'range_start' => $this->parse_range_value( $start_value ),
					'range_end'   => $this->parse_range_value( $end_value ),
				);

			default:
				return array(
					'handled'     => false,
					'range_start' => null,
					'range_end'   => null,
				);
		}
	}

	private function parse_range_value( $value ): ?DateTimeImmutable {
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		return $this->event_query_service->parse_request_date( (string) $value );
	}

	private function merge_range_start( ?DateTimeImmutable $current, ?DateTimeImmutable $candidate ): ?DateTimeImmutable {
		if ( ! $candidate ) {
			return $current;
		}

		if ( ! $current || $candidate > $current ) {
			return $candidate;
		}

		return $current;
	}

	private function merge_range_end( ?DateTimeImmutable $current, ?DateTimeImmutable $candidate ): ?DateTimeImmutable {
		if ( ! $candidate ) {
			return $current;
		}

		if ( ! $current || $candidate < $current ) {
			return $candidate;
		}

		return $current;
	}

	private function has_meta_clauses( array $meta_query ): bool {
		foreach ( $meta_query as $key => $clause ) {
			if ( 'relation' === $key ) {
				continue;
			}

			return true;
		}

		return false;
	}

	private function create_occurrence_post( WP_Post $post, array $event, int $source_index ): WP_Post {
		/**
		 * Create an occurrence clone: a synthetic WP_Post object that represents a single
		 * event occurrence with its own metadata. The clone carries the source post's
		 * content (title, excerpt, permalink, taxonomy) but with occurrence-specific
		 * dates and label. When the loop accesses post meta, filter_occurrence_meta()
		 * intercepts the request and returns occurrence values, not source values.
		 */
		$occurrence_start = $this->event_query_service->parse_request_date( $event['scheduled_start_time'] ?? null );
		$occurrence_end   = $this->event_query_service->parse_request_date( $event['scheduled_end_time'] ?? null );
		$occurrence       = clone $post;

		if ( $occurrence_start ) {
			$occurrence->post_date     = $occurrence_start->format( 'Y-m-d H:i:s' );
			$occurrence->post_date_gmt = $occurrence_start->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		}

		$occurrence->post_event_id               = (string) ( $event['id'] ?? $post->ID );
		$occurrence->post_event_start            = $occurrence_start ? $occurrence_start->format( 'Y-m-d H:i:s' ) : '';
		$occurrence->post_event_end              = $occurrence_end ? $occurrence_end->format( 'Y-m-d H:i:s' ) : $occurrence->post_event_start;
		$occurrence->post_event_source_id        = (int) $post->ID;
		$occurrence->post_event_occurrence_index = $source_index;
		$occurrence->post_event_source_index     = isset( $event['eventIndex'] ) ? (int) $event['eventIndex'] : 0;
		$occurrence->post_event_label            = isset( $event['name'] ) ? (string) $event['name'] : $post->post_title;

		return $occurrence;
	}

	private function sort_occurrences( array $occurrences, WP_Query $query ): array {
		if ( ! $this->should_sort_by_occurrence_start( $query ) ) {
			return $occurrences;
		}

		$order = 'DESC' === strtoupper( (string) $query->get( 'order' ) ) ? 'DESC' : 'ASC';

		usort(
			$occurrences,
			static function ( WP_Post $left, WP_Post $right ) use ( $order ): int {
				$comparison = strcmp( (string) $left->post_event_start, (string) $right->post_event_start );

				if ( 0 === $comparison ) {
					$comparison = strcmp( (string) $left->post_title, (string) $right->post_title );
				}

				return 'DESC' === $order ? -$comparison : $comparison;
			}
		);

		return $occurrences;
	}

	private function should_sort_by_occurrence_start( WP_Query $query ): bool {
		$orderby  = $query->get( 'orderby' );
		$meta_key = (string) $query->get( 'meta_key' );

		if ( empty( $orderby ) || 'date' === $orderby ) {
			return true;
		}

		if ( 'meta_value' === $orderby || 'meta_value_num' === $orderby ) {
			return self::EVENT_SCHEDULED_START_TIME_META === $meta_key || empty( $meta_key );
		}

		return false;
	}

	private function now(): DateTimeImmutable {
		return new DateTimeImmutable( 'now', wp_timezone() );
	}

	public function exclude_from_settings( array $excluded ): array {
		$excluded[] = self::SLUG;
		return $excluded;
	}
}
