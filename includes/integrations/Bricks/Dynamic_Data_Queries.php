<?php

namespace WpCalendar\Integrations\Bricks;

// use WpCalendar\Events\Event_Config;

use WpCalendar\Events\Event_Query_Service;
use WpCalendar\Events\Event_Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dynamic_Data_Queries {
	private const QUERY_WP_CALENDAR_EVENTS = 'wp_calendar_events';

	/**
	 * Maximum number of window pages exposed to Bricks' pagination element.
	 */
	public const MAX_WINDOW_PAGES = 25;

	private $query_options = null;

	/**
	 * @var Event_Query_Service
	 */
	private $event_query_service;

	/**
	 * @var Calendar_Request_Params
	 */
	private $request_params;

	public function __construct( ?Event_Query_Service $event_query_service = null ) {
		$this->query_options = array(
			self::QUERY_WP_CALENDAR_EVENTS => array(
				'label'     => 'Calendar Events',
				'get_posts' => array( $this, 'get_wp_calendar_events' ),
			),
		);

		$this->event_query_service = $event_query_service ?? new Event_Query_Service();
		$this->request_params      = new Calendar_Request_Params();

		add_filter( 'bricks/setup/control_options', array( $this, 'register_queries' ) );
		add_filter( 'bricks/query/loop_object', array( $this, 'query_loop_object' ), 10, 3 );
		add_filter( 'bricks/query/run', array( $this, 'query_run' ), 10, 2 );

		// Pagination element relies on max_num_pages (@see Bricks\Query::run()).
		add_filter( 'bricks/query/result_max_num_pages', array( $this, 'result_max_num_pages' ), 10, 2 );

		// Pagination element hard-codes supported object types; opt in via the documented filters (@since 2.2).
		add_filter( 'bricks/pagination/custom_logic', array( $this, 'pagination_custom_logic' ), 10, 2 );
		add_filter( 'bricks/pagination/current_page', array( $this, 'pagination_current_page' ), 10, 2 );
		add_filter( 'bricks/pagination/total_pages', array( $this, 'pagination_total_pages' ), 10, 2 );
	}

	public function register_queries( $control_options ) {
		if ( ! is_array( $control_options ) ) {
			return $control_options;
		}

		foreach ( $this->query_options as $type => $option ) {
			$control_options['queryTypes'][ $type ] = esc_html( $option['label'] );
		}

		return $control_options;
	}

	private function is_events_object_type( string $object_type ): bool {
		return self::QUERY_WP_CALENDAR_EVENTS === $object_type;
	}

	private function is_events_query_settings( $query_settings ): bool {
		return is_array( $query_settings )
			&& $this->is_events_object_type( (string) ( $query_settings['query']['objectType'] ?? '' ) );
	}

	public function query_loop_object( $loop_object, $loop_key, $query_obj ) {
		if ( ! is_object( $query_obj ) || ! $this->is_events_object_type( (string) ( $query_obj->object_type ?? '' ) ) ) {
			return $loop_object;
		}

		if ( is_array( $loop_object ) && ! empty( $loop_object['postId'] ) ) {
			Calendar_Context::set_active_event( $loop_object );

			global $post;
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Intentional WordPress setup_postdata pattern.
			$post = get_post( $loop_object['postId'] );
			setup_postdata( $post );

			$loop_object = $post;
		}

		return $loop_object;
	}

	public function query_run( $results, $query_obj ) {
		if ( ! is_object( $query_obj ) || ! isset( $this->query_options[ $query_obj->object_type ] ) ) {
			return $results;
		}

		return call_user_func( $this->query_options[ $query_obj->object_type ]['get_posts'], $query_obj );
	}

	public function result_max_num_pages( $max_num_pages, $query_obj ) {
		if ( is_object( $query_obj ) && $this->is_events_object_type( (string) ( $query_obj->object_type ?? '' ) ) ) {
			return self::MAX_WINDOW_PAGES;
		}

		return $max_num_pages;
	}

	public function pagination_custom_logic( $custom_logic, $query_settings ) {
		return $this->is_events_query_settings( $query_settings ) ? true : $custom_logic;
	}

	public function pagination_current_page( $current_page, $query_settings ) {
		if ( ! $this->is_events_query_settings( $query_settings ) ) {
			return $current_page;
		}

		return \Bricks\Query::get_paged_query_var( array() );
	}

	public function pagination_total_pages( $total_pages, $query_settings ) {
		if ( ! $this->is_events_query_settings( $query_settings ) ) {
			return $total_pages;
		}

		return self::MAX_WINDOW_PAGES;
	}

	/**
	 * The single events loop: occurrences overlapping the resolved window.
	 *
	 * Range resolution: explicit start/end params > anchor + span > service
	 * default. Pagination offsets the window by its own span.
	 */
	private function get_wp_calendar_events( $query_obj = null ) {
		list( $range_start, $range_end ) = $this->resolve_range( $query_obj );

		$source_types = $this->event_query_service->resolve_post_types( false );
		$filter_vars  = $this->get_filter_query_vars( $query_obj );
		$events       = $this->query_events( $source_types, $range_start, $range_end, $filter_vars );

		Calendar_Context::set_active_window(
			array(
				'start'      => $range_start ? $range_start->format( DATE_ATOM ) : '',
				'end'        => $range_end ? $range_end->format( DATE_ATOM ) : '',
				'totalPages' => self::MAX_WINDOW_PAGES,
			)
		);

		return $events;
	}

	/**
	 * @return array{0: \DateTimeImmutable|null, 1: \DateTimeImmutable|null}
	 */
	private function resolve_range( $query_obj ): array {
		list( $start, $end ) = array_values( $this->request_params->get_range() );

		if ( ! $start && ! $end ) {
			return array( null, null );
		}

		$page = $this->get_paged( $query_obj );

		if ( $page <= 1 ) {
			return array( $start, $end );
		}

		// Offset an anchored window by its own span per page.
		$params = $this->request_params->get_request_params();
		$anchor = $params[ Calendar_Request_Params::PARAM_ANCHOR ] ?? null;

		if ( empty( $anchor ) || ! $start instanceof \DateTimeImmutable ) {
			return array( $start, $end );
		}

		$span_count = isset( $params[ Calendar_Request_Params::PARAM_SPAN ] ) && is_numeric( $params[ Calendar_Request_Params::PARAM_SPAN ] )
			? max( 1, absint( $params[ Calendar_Request_Params::PARAM_SPAN ] ) )
			: Calendar_Request_Params::DEFAULT_SPAN_COUNT;
		$offset     = ( $page - 1 ) * $span_count;
		unset( $span_count );

		$start = $this->advance_one_span( $start, $params[ Calendar_Request_Params::PARAM_UNIT ] ?? '', $offset );
		$end   = $this->advance_one_span( $end, $params[ Calendar_Request_Params::PARAM_UNIT ] ?? '', $offset );

		return array( $start, $end );
	}

	private function advance_one_span( ?\DateTimeImmutable $date, string $unit, int $multiples = 1 ): ?\DateTimeImmutable {
		if ( null === $date ) {
			return null;
		}

		$unit = in_array( sanitize_key( $unit ), Calendar_Request_Params::SPAN_UNITS, true ) ? sanitize_key( $unit ) : Calendar_Request_Params::DEFAULT_SPAN_UNIT;
		$days = $multiples;

		switch ( $unit ) {
			case 'years':
				$interval = sprintf( '+%d years', $multiples );
				break;

			case 'months':
				$interval = sprintf( '+%d months', $multiples );
				break;

			case 'weeks':
				$interval = sprintf( '+%d weeks', $multiples );
				break;

			default:
				$interval = sprintf( '+%d days', $days );
		}

		try {
			return $date->modify( $interval );
		} catch ( \Exception $exception ) {
			return $date;
		}
	}

	private function get_paged( $query_obj ): int {
		// Custom object types don't go through Bricks' per-type paged resolution; use its public resolver.
		if ( class_exists( '\Bricks\Query' ) && method_exists( '\Bricks\Query', 'get_paged_query_var' ) ) {
			return max( 1, (int) \Bricks\Query::get_paged_query_var( array() ) );
		}

		if ( is_object( $query_obj ) && isset( $query_obj->query_vars['paged'] ) ) {
			return max( 1, (int) $query_obj->query_vars['paged'] );
		}

		return 1;
	}

	/**
	 * Read Bricks query-filter vars bound to a query element.
	 *
	 * Bricks only applies filter vars to post/term/user queries; custom queries
	 * must pull them from Query_Filters themselves.
	 */
	private function get_filter_query_vars( $query_obj ): array {
		if (
			! is_object( $query_obj )
			|| empty( $query_obj->element_id )
			|| ! class_exists( '\Bricks\Query_Filters' )
			|| ! method_exists( '\Bricks\Query_Filters', 'generate_query_vars_from_active_filters' )
		) {
			return array();
		}

		$vars = \Bricks\Query_Filters::generate_query_vars_from_active_filters( (string) $query_obj->element_id );

		return is_array( $vars ) ? $vars : array();
	}

	private function query_events( array $source_types, ?\DateTimeImmutable $range_start, ?\DateTimeImmutable $range_end, array $filter_vars = array() ): array {
		if ( empty( $source_types ) ) {
			return array();
		}

		$args = array(
			'post_type'                  => $source_types,
			'post_status'                => 'publish',
			'posts_per_page'             => Calendar_Request_Params::MAX_SOURCE_POSTS,
			'no_found_rows'              => true,
			'ignore_sticky_posts'        => true,
			'wp_calendar_source_types' => $source_types,
			'orderby'                    => 'meta_value',
			'meta_key'                   => Event_Config::EVENT_RANGE_START_META,
			'meta_type'                  => 'DATETIME',
			'order'                      => 'ASC',
		);

		$args['meta_query'] = $this->event_query_service->build_range_meta_query( $range_start, $range_end );

		// Merge supported Bricks query-filter vars (taxonomy filters, search, ...).
		if ( ! empty( $filter_vars['tax_query'] ) && is_array( $filter_vars['tax_query'] ) ) {
			$args['tax_query'] = array_merge(
				array( 'relation' => 'AND' ),
				array( $filter_vars['tax_query'] )
			);
		}

		foreach ( array( 's', 'post__in', 'post__not_in' ) as $supported_var ) {
			if ( ! empty( $filter_vars[ $supported_var ] ) ) {
				$args[ $supported_var ] = $filter_vars[ $supported_var ];
			}
		}

		if ( ! empty( $filter_vars['meta_query'] ) && is_array( $filter_vars['meta_query'] ) ) {
			$args['meta_query'] = array_merge(
				array( 'relation' => 'AND' ),
				array( $args['meta_query'], $filter_vars['meta_query'] )
			);
		}

		$query = new \WP_Query( $args );

		return $this->event_query_service->build_events_for_posts( $query->posts, $range_start, $range_end );
	}
}
