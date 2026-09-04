<?php

namespace WpCalendar\Integrations\Bricks;

use DateTimeImmutable;
use WpCalendar\Events\Event_Date_Parser;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads whitelisted runtime request params that override event-list query
 * settings. Mirrors the REST contract: explicit start/end win over
 * anchor+span defaults.
 */
class Calendar_Request_Params {
	public const PARAM_START  = 'start';
	public const PARAM_END    = 'end';
	public const PARAM_ANCHOR = 'cal_anchor';
	public const PARAM_SPAN   = 'cal_span';
	public const PARAM_UNIT   = 'cal_unit';

	public const SPAN_UNITS = array( 'years', 'months', 'weeks', 'days' );

	public const DEFAULT_SPAN_COUNT = 1;
	public const DEFAULT_SPAN_UNIT  = 'months';

	/**
	 * Maximum number of source posts evaluated per query.
	 */
	public const MAX_SOURCE_POSTS = 1000;

	/**
	 * Current request params: normal GET plus Bricks' AJAX-forwarded URL params.
	 */
	public function get_request_params(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query param access for calendar routing; no form processing.
		$params = $_GET;

		if ( class_exists( '\Bricks\Api' ) && isset( \Bricks\Api::$request_data['urlParams'] ) && is_array( \Bricks\Api::$request_data['urlParams'] ) ) {
			$params = array_merge( $params, \Bricks\Api::$request_data['urlParams'] );
		}

		return $params;
	}

	/**
	 * Resolve the effective [start, end] range.
	 *
	 * Priority: explicit start/end > anchor + span > null (service default).
	 *
	 * @return array{start: DateTimeImmutable|null, end: DateTimeImmutable|null}
	 */
	public function get_range( ?array $source = null ): array {
		$source = $source ?? $this->get_request_params();

		$start = isset( $source[ self::PARAM_START ] ) && is_string( $source[ self::PARAM_START ] )
			? Event_Date_Parser::parse( sanitize_text_field( $source[ self::PARAM_START ] ) )
			: null;
		$end   = isset( $source[ self::PARAM_END ] ) && is_string( $source[ self::PARAM_END ] )
			? Event_Date_Parser::parse( sanitize_text_field( $source[ self::PARAM_END ] ) )
			: null;

		if ( $start || $end ) {
			return array(
				'start' => $start,
				'end'   => $end,
			);
		}

		$anchor = isset( $source[ self::PARAM_ANCHOR ] ) && is_string( $source[ self::PARAM_ANCHOR ] )
			? Event_Date_Parser::parse( sanitize_text_field( $source[ self::PARAM_ANCHOR ] ) )
			: null;

		if ( ! $anchor ) {
			return array(
				'start' => null,
				'end'   => null,
			);
		}

		$anchor     = $anchor->setTime( 0, 0, 0 );
		$span_count = isset( $source[ self::PARAM_SPAN ] ) && is_numeric( $source[ self::PARAM_SPAN ] )
			? max( 1, absint( $source[ self::PARAM_SPAN ] ) )
			: self::DEFAULT_SPAN_COUNT;
		$span_unit  = isset( $source[ self::PARAM_UNIT ] ) && is_string( $source[ self::PARAM_UNIT ] ) && in_array( sanitize_key( $source[ self::PARAM_UNIT ] ), self::SPAN_UNITS, true )
			? sanitize_key( $source[ self::PARAM_UNIT ] )
			: self::DEFAULT_SPAN_UNIT;

		return array(
			'start' => $anchor,
			'end'   => $this->add_span( $anchor, $span_count, $span_unit ),
		);
	}

	private function add_span( DateTimeImmutable $date, int $count, string $unit ): DateTimeImmutable {
		switch ( $unit ) {
			case 'years':
				return $this->add_months_safe( $date, 12 * $count );

			case 'months':
				return $this->add_months_safe( $date, $count );

			case 'weeks':
				return $date->modify( sprintf( '+%d days', 7 * $count ) );

			default:
				return $date->modify( sprintf( '+%d days', $count ) );
		}
	}

	private function add_months_safe( DateTimeImmutable $date, int $months ): DateTimeImmutable {
		$total_month_index = ( ( (int) $date->format( 'Y' ) ) * 12 ) + ( (int) $date->format( 'n' ) - 1 ) + $months;
		$year              = intdiv( $total_month_index, 12 );
		$month             = ( $total_month_index % 12 ) + 1;
		$day               = min( (int) $date->format( 'j' ), cal_days_in_month( CAL_GREGORIAN, $month, $year ) );

		return $date->setDate( $year, $month, $day );
	}

	/**
	 * Build the current URL with param changes applied (paged reset).
	 *
	 * @param array $changes Param name => value; null removes the param.
	 */
	public function build_url( array $changes ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query param access for URL building; no form processing.
		$params = $_GET;

		unset( $params['paged'] );

		foreach ( $changes as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( null === $value || '' === $value ) {
				unset( $params[ $key ] );
				continue;
			}

			$params[ $key ] = (string) $value;
		}

		$path  = strtok( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) ), '?' );
		$query = http_build_query( $params );

		return esc_url( esc_url_raw( home_url( $query ? $path . '?' . $query : $path ) ) );
	}
}
