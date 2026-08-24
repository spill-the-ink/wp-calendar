<?php

namespace PostCalendar\Rest;

use DateTimeImmutable;
use PostCalendar\Admin\Settings_Page;
use PostCalendar\Events\Event_Config;
use PostCalendar\Events\Event_Query_Service;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Rest_Controller {
	public const REST_NAMESPACE       = 'post-calendar/v1';
	public const REST_ROUTE           = '/events';
	public const CONFIG_ROUTE         = '/config';
	public const DISCORD_GUILDS_ROUTE = '/discord/guilds';

	private const DEFAULT_PER_PAGE = 1000;

	/**
	 * @var Event_Query_Service
	 */
	private $event_query_service;

	public function __construct( ?Event_Query_Service $event_query_service = null ) {
		$this->event_query_service = $event_query_service ?? new Event_Query_Service();

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_events' ),
					'permission_callback' => '__return_true',
					'args'                => $this->get_collection_params(),
				),
			),
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::CONFIG_ROUTE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_config' ),
					'permission_callback' => '__return_true',
				),
			),
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::DISCORD_GUILDS_ROUTE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_discord_guilds' ),
					'permission_callback' => array( $this, 'require_manage_options' ),
				),
			),
		);
	}

	/**
	 * Permission callback for admin-only REST routes.
	 */
	public function require_manage_options(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Fetches the Discord bot guild list on-demand.
	 *
	 * This is called lazily from the settings client (rather than on every page
	 * render) to avoid a slow, blocking Discord API request blocking the admin.
	 *
	 * Persists the refreshed, merged guild list so subsequent page renders have a
	 * current list without another API round-trip.
	 *
	 * @param WP_REST_Request $request  Current request.
	 * @return WP_REST_Response Array of guild records.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $request is required by the WP REST API callback signature.
	public function get_discord_guilds( WP_REST_Request $request ): WP_REST_Response {
		$token = \PostCalendar\Integrations\Discord\Discord_Client::get_bot_token();
		if ( '' === $token ) {
			return new WP_REST_Response(
				array(
					'error'  => 'discord-not-configured',
					'guilds' => array(),
				),
				403
			);
		}

		$guilds = Settings_Page::refresh_discord_guilds();

		return new WP_REST_Response(
			array(
				'guilds' => $guilds,
			)
		);
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $_request is required by the WP REST API callback signature.
	public function get_config( WP_REST_Request $_request ): WP_REST_Response {
		$labels    = Settings_Page::get_labels();
		$label_map = array();
		foreach ( $labels as $label ) {
			$label_map[ $label['id'] ] = array(
				'name'  => $label['name'],
				'color' => $label['color'],
			);
		}

		return new WP_REST_Response(
			array(
				'labels' => $label_map,
			)
		);
	}

	public function get_collection_params(): array {
		return array(
			'post_types'  => array(
				'description'       => __( 'Restrict the collection to a comma-separated list of source post types.', 'post-calendar' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'query_vars'  => array(
				'description'       => __( 'Restrict the collection with a JSON-encoded subset of Bricks query vars.', 'post-calendar' ),
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_query_vars_param' ),
			),
			'search'      => array(
				'description'       => __( 'Limit results to events whose source post matches the search string.', 'post-calendar' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'tax_filters' => array(
				'description'       => __( 'JSON object mapping taxonomy slugs to term slug arrays, e.g. {"category":["news"]}. Clauses are combined with AND.', 'post-calendar' ),
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_tax_filters_param' ),
			),
			'start'       => array(
				'description'       => __( 'Limit results to events that overlap the supplied ISO 8601 start date.', 'post-calendar' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'end'         => array(
				'description'       => __( 'Limit results to events that overlap the supplied ISO 8601 end date.', 'post-calendar' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'per_page'    => array(
				'description'       => __( 'Limit the number of source posts evaluated for the response.', 'post-calendar' ),
				'type'              => 'integer',
				'default'           => self::DEFAULT_PER_PAGE,
				'minimum'           => 1,
				'maximum'           => self::DEFAULT_PER_PAGE,
				'sanitize_callback' => 'absint',
			),
		);
	}

	public function get_events( WP_REST_Request $request ): WP_REST_Response {
		$source_types = $this->event_query_service->resolve_post_types( $request->get_param( 'post_types' ) );
		$query_vars   = $this->parse_query_vars_param( $request->get_param( 'query_vars' ) );
		$range_start  = $this->event_query_service->parse_request_date( $request->get_param( 'start' ) );
		$range_end    = $this->event_query_service->parse_request_date( $request->get_param( 'end' ) );
		$search       = (string) $request->get_param( 'search' );
		$tax_filters  = $this->parse_tax_filters_param( $request->get_param( 'tax_filters' ) );
		$source_types = $this->merge_source_types_from_query_vars( $source_types, $query_vars );

		if ( empty( $source_types ) ) {
			return new WP_REST_Response( array() );
		}

		$args = $this->merge_supported_query_vars(
			array(
				'post_type'                  => $source_types,
				'post_status'                => 'publish',
				'posts_per_page'             => $this->resolve_posts_per_page( $request ),
				'no_found_rows'              => true,
				'update_post_term_cache'     => false,
				'ignore_sticky_posts'        => true,
				'post_calendar_source_types' => $source_types,
			),
			$query_vars,
		);

		$args['meta_query'] = $this->merge_meta_query_constraints(
			$args['meta_query'] ?? array(),
			$this->build_rest_meta_constraints( $range_start, $range_end ),
		);

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		$args = $this->apply_tax_filters( $args, $tax_filters, $args['tax_query'] ?? array() );

		if ( ! $request->has_param( 'orderby' ) && empty( $query_vars['orderby'] ) ) {
			$args['orderby']   = 'meta_value';
			$args['meta_key']  = Event_Query_Service::EVENT_RANGE_START_META;
			$args['meta_type'] = 'DATETIME';
			$args['order']     = 'ASC';
		}

		$query  = new WP_Query( $args );
		$events = $this->event_query_service->build_events_for_posts( $query->posts, $range_start, $range_end );

		// Merge Discord guild events if any guilds are enabled.
		$discord_events = $this->fetch_discord_events( $range_start, $range_end );
		if ( ! empty( $discord_events ) ) {
			$events = array_merge( $events, $discord_events );
		}

		// Merge iCal feed events if any feeds are enabled.
		$ical_events = $this->fetch_ical_events( $range_start, $range_end );
		if ( ! empty( $ical_events ) ) {
			$events = array_merge( $events, $ical_events );
		}

		if ( ! empty( $discord_events ) || ! empty( $ical_events ) ) {
			usort(
				$events,
				static function ( $a, $b ): int {
					$cmp = strcmp( $a['scheduled_start_time'] ?? '', $b['scheduled_start_time'] ?? '' );
					return 0 !== $cmp ? $cmp : strcmp( $a['name'] ?? '', $b['name'] ?? '' );
				}
			);
		}

		$response = new WP_REST_Response( $events );

		$this->add_response_cache_headers( $response, $request );

		return $response;
	}

	/**
	 * Adds short-lived cache headers to the events response so anonymous
	 * visitors can be served by page/edge caches without re-running the full
	 * expansion pipeline on every request. Authenticated requests (identified
	 * by the WP REST nonce) are never cached.
	 *
	 * @param WP_REST_Response $response Response to add headers to.
	 * @param WP_REST_Request  $request  Current request.
	 */
	private function add_response_cache_headers( WP_REST_Response $response, WP_REST_Request $request ): void {
		if ( $this->is_authenticated_request( $request ) ) {
			$response->header( 'Cache-Control', 'no-store' );
			return;
		}

		$response->header( 'Cache-Control', 'public, max-age=60' );
		$response->header( 'Vary', 'Accept-Encoding' );
	}

	/**
	 * Detects an authenticated request via the WP REST nonce, which the
	 * calendar runtime sends with every fetch (see Assets::get_runtime_config).
	 */
	private function is_authenticated_request( WP_REST_Request $request ): bool {
		return ! empty( $request->get_header( 'X-WP-Nonce' ) );
	}

	/**
	 * Fetches Discord guild events if Discord is connected and any guilds are enabled.
	 *
	 * @param DateTimeImmutable|null $range_start  Range start for filtering.
	 * @param DateTimeImmutable|null $range_end    Range end for filtering.
	 * @return array  Discord events in unified schema.
	 */
	private function fetch_discord_events( ?DateTimeImmutable $range_start, ?DateTimeImmutable $range_end ): array {
		$token = \PostCalendar\Integrations\Discord\Discord_Client::get_bot_token();
		if ( '' === $token ) {
			return array();
		}

		$sources = Settings_Page::get_sources();
		$guilds  = $sources['discord']['guilds'] ?? array();
		$events  = \PostCalendar\Integrations\Discord\Discord_Aggregator::get_events( $guilds );

		if ( empty( $events ) ) {
			return array();
		}

		return $this->filter_events_by_range( $events, $range_start, $range_end );
	}

	/**
	 * Fetches iCal feed events if any feeds are enabled, filtered by date range.
	 *
	 * @param DateTimeImmutable|null $range_start  Range start for filtering.
	 * @param DateTimeImmutable|null $range_end    Range end for filtering.
	 * @return array  iCal events in unified schema.
	 */
	private function fetch_ical_events( ?DateTimeImmutable $range_start, ?DateTimeImmutable $range_end ): array {
		$sources = Settings_Page::get_sources();
		$feeds   = $sources['ical_feeds'] ?? array();
		$year    = $this->resolve_ical_expansion_year( $range_start, $range_end );
		$events  = \PostCalendar\Integrations\ICal\Ical_Aggregator::get_events( $feeds, $year );

		if ( empty( $events ) ) {
			return array();
		}

		return $this->filter_events_by_range( $events, $range_start, $range_end );
	}

	/**
	 * Filters unified event records to those overlapping a date range.
	 *
	 * Shared by the Discord and iCal fetch paths. Events without a start time
	 * are dropped; a null bound means "unbounded" on that side.
	 *
	 * @param array                  $events      Unified event records.
	 * @param DateTimeImmutable|null $range_start Range start, or null for unbounded.
	 * @param DateTimeImmutable|null $range_end   Range end, or null for unbounded.
	 * @return array  Events overlapping the range.
	 */
	private function filter_events_by_range( array $events, ?DateTimeImmutable $range_start, ?DateTimeImmutable $range_end ): array {
		if ( null === $range_start && null === $range_end ) {
			return $events;
		}

		$filtered = array_filter(
			$events,
			static function ( $event ) use ( $range_start, $range_end ): bool {
				$start = $event['scheduled_start_time'] ?? '';
				$end   = $event['scheduled_end_time'] ?? '';

				if ( '' === $start ) {
					return false;
				}

				if ( null !== $range_start ) {
					$event_end = '' !== $end ? $end : $start;
					if ( $event_end < $range_start->format( 'c' ) ) {
						return false;
					}
				}

				if ( null !== $range_end ) {
					if ( $start > $range_end->format( 'c' ) ) {
						return false;
					}
				}

				return true;
			}
		);

		return array_values( $filtered );
	}

	/**
	 * Resolves the recurrence expansion year from the requested date range.
	 *
	 * Recurring iCal events are expanded into a single bounded year window so a
	 * feed cannot emit an unbounded stream. The range is preferred so next-year
	 * navigations show recurrences; otherwise the current calendar year is used.
	 *
	 * @param DateTimeImmutable|null $range_start  Range start for filtering.
	 * @param DateTimeImmutable|null $range_end    Range end for filtering.
	 * @return int  Target year for recurrence expansion.
	 */
	private function resolve_ical_expansion_year( ?DateTimeImmutable $range_start, ?DateTimeImmutable $range_end ): int {
		if ( null !== $range_start ) {
			return (int) $range_start->format( 'Y' );
		}
		if ( null !== $range_end ) {
			return (int) $range_end->format( 'Y' );
		}
		return (int) gmdate( 'Y' );
	}

	public function sanitize_query_vars_param( $value ): string {
		return is_string( $value ) ? wp_unslash( $value ) : '';
	}

	public function sanitize_tax_filters_param( $value ): string {
		return is_string( $value ) ? wp_unslash( $value ) : '';
	}

	/**
	 * Parse the tax_filters JSON param into [taxonomy => [term, ...]].
	 */
	private function parse_tax_filters_param( $value ): array {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return array();
		}

		$decoded = json_decode( $value, true );

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$parsed = array();

		foreach ( $decoded as $taxonomy => $terms ) {
			$taxonomy = sanitize_key( (string) $taxonomy );

			if ( '' === $taxonomy ) {
				continue;
			}

			$terms = is_array( $terms ) ? $terms : array_filter( array_map( 'trim', explode( ',', (string) $terms ) ) );
			$terms = array_values( array_unique( array_filter( array_map( 'sanitize_title', $terms ) ) ) );

			if ( empty( $terms ) ) {
				continue;
			}

			$parsed[ $taxonomy ] = array_slice( $terms, 0, 50 );
		}

		return $parsed;
	}

	/**
	 * Merge taxonomy filter clauses with any query-var-provided tax_query.
	 */
	private function apply_tax_filters( array $args, array $tax_filters, $existing_tax_query ): array {
		if ( empty( $tax_filters ) && empty( $existing_tax_query ) ) {
			return $args;
		}

		$clauses = array();

		if ( ! empty( $existing_tax_query ) && is_array( $existing_tax_query ) ) {
			$clauses[] = $existing_tax_query;
		}

		foreach ( $tax_filters as $taxonomy => $terms ) {
			$clauses[] = array(
				'taxonomy'         => $taxonomy,
				'field'            => 'slug',
				'terms'            => $terms,
				'include_children' => false,
				'operator'         => 'IN',
			);
		}

		if ( count( $clauses ) === 1 ) {
			$args['tax_query'] = $clauses[0];

			return $args;
		}

		$args['tax_query'] = array_merge(
			array( 'relation' => 'AND' ),
			$clauses
		);

		return $args;
	}

	private function build_rest_meta_constraints( ?DateTimeImmutable $range_start, ?DateTimeImmutable $range_end ): array {
		return $this->event_query_service->build_range_meta_query( $range_start, $range_end );
	}

	private function merge_meta_query_constraints( $existing_meta_query, array $range_meta_query ): array {
		$meta_query = array(
			'relation' => 'AND',
		);

		if ( ! empty( $existing_meta_query ) ) {
			$meta_query[] = $existing_meta_query;
		}

		foreach ( $range_meta_query as $constraint ) {
			$meta_query[] = $constraint;
		}

		return $meta_query;
	}

	private function parse_query_vars_param( $value ): array {
		if ( ! is_string( $value ) || '' === $value ) {
			return array();
		}

		$decoded = json_decode( $value, true );

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$sanitized = array();

		foreach ( Event_Config::get_supported_query_var_keys() as $key ) {
			if ( ! array_key_exists( $key, $decoded ) ) {
				continue;
			}

			$sanitized[ $key ] = $this->sanitize_query_var_value( $decoded[ $key ] );
		}

		return $sanitized;
	}

	private function sanitize_query_var_value( $value ) {
		if ( is_array( $value ) ) {
			$sanitized = array();

			foreach ( $value as $key => $item ) {
				$sanitized_key               = is_string( $key ) ? sanitize_key( $key ) : $key;
				$sanitized[ $sanitized_key ] = $this->sanitize_query_var_value( $item );
			}

			return $sanitized;
		}

		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			return sanitize_text_field( $value );
		}

		return null;
	}

	private function merge_source_types_from_query_vars( array $source_types, array &$query_vars ): array {
		if ( empty( $query_vars['post_type'] ) ) {
			return $source_types;
		}

		$query_source_types = $this->event_query_service->resolve_post_types( is_array( $query_vars['post_type'] ) ? implode( ',', $query_vars['post_type'] ) : (string) $query_vars['post_type'] );
		unset( $query_vars['post_type'] );

		if ( empty( $query_source_types ) ) {
			return array();
		}

		$intersected = array_values( array_intersect( $source_types, $query_source_types ) );

		return ! empty( $intersected ) ? $intersected : array( '__post_calendar_no_results__' );
	}

	private function merge_supported_query_vars( array $args, array $query_vars ): array {
		if ( empty( $query_vars ) ) {
			return $args;
		}

		foreach ( Event_Config::get_supported_query_var_list_keys() as $key ) {
			if ( empty( $query_vars[ $key ] ) || ! is_array( $query_vars[ $key ] ) ) {
				continue;
			}

			$args[ $key ] = array_values( array_filter( array_map( 'absint', $query_vars[ $key ] ) ) );
		}

		foreach ( Event_Config::get_supported_query_var_direct_keys() as $key ) {
			if ( ! array_key_exists( $key, $query_vars ) || '' === $query_vars[ $key ] || null === $query_vars[ $key ] ) {
				continue;
			}

			$args[ $key ] = $query_vars[ $key ];
		}

		foreach ( array( 'tax_query', 'meta_query', 'date_query' ) as $key ) {
			if ( empty( $query_vars[ $key ] ) || ! is_array( $query_vars[ $key ] ) ) {
				continue;
			}

			$args[ $key ] = $query_vars[ $key ];
		}

		return $args;
	}

	private function resolve_posts_per_page( WP_REST_Request $request ): int {
		$per_page = absint( $request->get_param( 'per_page' ) );

		if ( $per_page > 0 ) {
			return min( $per_page, self::DEFAULT_PER_PAGE );
		}

		return self::DEFAULT_PER_PAGE;
	}
}
