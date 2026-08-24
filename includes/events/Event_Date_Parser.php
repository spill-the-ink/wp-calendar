<?php

namespace PostCalendar\Events;

use DateTimeImmutable;
use DateTimeZone;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Event_Date_Parser {
	public static function parse( ?string $value ): ?DateTimeImmutable {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}

		$timezone = self::get_timezone();
		$date     = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value, $timezone );

		if ( false !== $date ) {
			return $date;
		}

		try {
			return new DateTimeImmutable( $value, $timezone );
		} catch ( \Exception $exception ) {
			return null;
		}
	}

	public static function parse_editor_input( $value, bool $is_end = false, bool $all_day = false ): ?DateTimeImmutable {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}

		$normalized_value = str_replace( 'T', ' ', trim( $value ) );

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $normalized_value ) ) {
			$normalized_value .= $is_end && $all_day ? ' 23:59:59' : ' 00:00:00';
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $normalized_value ) ) {
			$normalized_value .= $is_end && $all_day ? ':59' : ':00';
		}

		return self::parse( $normalized_value );
	}

	private static function get_timezone(): DateTimeZone {
		$timezone = wp_timezone();

		if ( $timezone instanceof DateTimeZone ) {
			return $timezone;
		}

		$tz_string = wp_timezone_string();

		return new DateTimeZone( '' !== $tz_string ? $tz_string : 'UTC' );
	}
}
