<?php
/**
 * Minimal RFC 5545 (iCalendar) parser tuned for event feeds.
 *
 * @package PostCalendar\Integrations
 */

namespace PostCalendar\Integrations\ICal;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parses VCALENDAR/VEVENT blocks commonly emitted by Google Calendar, Apple
 * Calendar, and Outlook, and expands a bounded subset of recurrence rules so a
 * repeating event can show up on the calendar without full RFC 5545 support.
 *
 * Deliberately dependency-free: the plugin ships no runtime Composer packages,
 * mirroring the zero-dependency approach of the Discord integration.
 */
class Ical_Parser {

	/**
	 * Parses an ICS document and returns normalized VEVENT records.
	 *
	 * @param string      $ics           Raw ICS content.
	 * @param int|null    $range_year    Bounded recurrence window (year) to expand into.
	 * @param string|null $recurrence_id A stable recurring-event identifier used for
	 *                                   recurring records; derived from SUMMARY when not given.
	 * @return array  Array of normalized records, each shaped for Ical_Aggregator.
	 */
	public static function parse( string $ics, ?int $range_year = null, ?string $recurrence_id = null ): array {
		$lines = self::unfold( $ics );

		$events = array();
		$ocur   = null;
		foreach ( $lines as $line ) {
			if ( 'BEGIN:VEVENT' === $line ) {
				$ocur = array();
			} elseif ( 'END:VEVENT' === $line && is_array( $ocur ) ) {
				$records = self::build_vevent( $ocur, $range_year, $recurrence_id );
				if ( ! empty( $records ) ) {
					$events = array_merge( $events, $records );
				}
				$ocur = null;
			} elseif ( is_array( $ocur ) ) {
				$ocur = self::merge_line( $ocur, $line );
			}
		}

		return $events;
	}

	/**
	 * Unfolds CRLF line folding (RFC 5545 3.1). A continuation line starts with
	 * one space or tab; it is removed and the remainder appended.
	 *
	 * @param string $ics Raw ICS content.
	 * @return string[] Unfolded lines.
	 */
	public static function unfold( string $ics ): array {
		$ics     = str_replace( array( "\r\n", "\r" ), "\n", $ics );
		$lines   = explode( "\n", $ics );
		$output  = array();
		$current = '';

		foreach ( $lines as $line ) {
			if ( '' !== $line && ( "\t" === $line[0] || ' ' === $line[0] ) ) {
				$current .= substr( $line, 1 );
				continue;
			}

			if ( '' !== $current ) {
				$output[] = $current;
			}
			$current = $line;
		}

		if ( '' !== $current ) {
			$output[] = $current;
		}

		return array_filter(
			$output,
			static function ( $line ): bool {
				return '' !== trim( $line );
			}
		);
	}

	/**
	 * Merges a single content line into the accumulated VEVENT array.
	 *
	 * @param array  $ocur Accumulated VEVENT properties.
	 * @param string $line Raw content line.
	 * @return array Updated VEVENT properties.
	 */
	private static function merge_line( array $ocur, string $line ): array {
		$colon = strpos( $line, ':' );
		if ( false === $colon ) {
			return $ocur;
		}

		$name_value = substr( $line, 0, $colon );
		$value      = substr( $line, $colon + 1 );

		$semicolons = explode( ';', $name_value );
		$name       = strtoupper( array_shift( $semicolons ) );

		$params = array();
		foreach ( $semicolons as $param ) {
			$eq = strpos( $param, '=' );
			if ( false === $eq ) {
				continue;
			}
			$key = strtoupper( trim( substr( $param, 0, $eq ) ) );
			if ( '' === $key ) {
				continue;
			}
			$params[ $key ] = trim( substr( $param, $eq + 1 ) );
		}

		switch ( $name ) {
			case 'DTSTART':
			case 'DTEND':
			case 'DTSTAMP':
			case 'EXDATE':
				$ocur[ $name ] = array(
					'value'  => $value,
					'params' => $params,
				);
				break;
			case 'RRULE':
				$ocur['RRULE'] = $value;
				break;
			default:
				$ocur[ $name ] = $value;
				break;
		}

		return $ocur;
	}

	/**
	 * Builds a normalized record from an accumulated VEVENT property set.
	 *
	 * @param array       $ocur          Accumulated VEVENT properties.
	 * @param int|null    $range_year    Bounded recurrence window (year) to expand into.
	 * @param string|null $recurrence_id A stable recurring-event identifier.
	 * @return array|null Normalized record, or null if the event is unusable.
	 */
	private static function build_vevent( array $ocur, ?int $range_year, ?string $recurrence_id ): ?array {
		$uid     = isset( $ocur['UID'] ) ? trim( (string) $ocur['UID'] ) : '';
		$summary = isset( $ocur['SUMMARY'] ) ? self::unescape( (string) $ocur['SUMMARY'] ) : '';
		$start   = self::parse_datetime( $ocur['DTSTART'] ?? null );
		$end_raw = $ocur['DTEND'] ?? $ocur['DUE'] ?? null;

		if ( null === $start ) {
			return null;
		}

		$end     = null;
		$all_day = false;
		if ( null !== $end_raw ) {
			$end = self::parse_datetime( $end_raw );
		}

		if ( null === $end ) {
			// Single timed event: one hour, or all-day when the start is date-only.
			if ( self::is_date_only( $ocur['DTSTART'] ?? null ) ) {
				$end     = clone $start;
				$all_day = true;
			} else {
				$end     = ( clone $start )->modify( '+1 hour' );
				$all_day = false;
			}
		} else {
			// All-day runs from a date-only DTSTART to DTEND (exclusive).
			$all_day = self::is_date_only( $ocur['DTSTART'] ?? null );
			if ( $all_day && $end->format( 'H:i' ) === '00:00' ) {
				// Represent the last inclusive day so the calendar shows the correct date.
				$end = $end->modify( '-1 second' );
			}
		}

		if ( $all_day ) {
			$start = $start->setTime( 0, 0, 0 );
			$end   = $end->setTime( 0, 0, 0 );
		}

		$description = isset( $ocur['DESCRIPTION'] ) ? self::unescape( (string) $ocur['DESCRIPTION'] ) : '';
		$location    = isset( $ocur['LOCATION'] ) ? self::unescape( (string) $ocur['LOCATION'] ) : '';

		// Bounded recurrence expansion.
		$recurrence = null !== $recurrence_id
			? $recurrence_id
			: ( '' !== $summary ? $summary : $uid );

		$records = array();
		if ( ! empty( $ocur['RRULE'] ) ) {
			$occurrences = self::expand_recurrence( $ocur['RRULE'], $start, $end, $range_year );
			if ( empty( $occurrences ) ) {
				return null;
			}
			foreach ( $occurrences as $occ ) {
				$records[] = self::make_record(
					$uid,
					$summary,
					$occ['start'],
					$occ['end'],
					$all_day,
					$description,
					$location,
					$recurrence
				);
			}
		} else {
			$records[] = self::make_record(
				$uid,
				$summary,
				$start,
				$end,
				$all_day,
				$description,
				$location,
				$recurrence
			);
		}

		return $records;
	}

	/**
	 * Wraps a parsed event into a normalized record array.
	 *
	 * @param string    $uid          Event UID.
	 * @param string    $summary      Event title.
	 * @param \DateTime $start        Start date/time.
	 * @param \DateTime $end          End date/time.
	 * @param bool      $all_day      True when the event is all-day.
	 * @param string    $description  Event description.
	 * @param string    $location     Event location.
	 * @param string    $recurrence   Stable identifier for recurring events.
	 * @return array Normalized record.
	 */
	private static function make_record(
		string $uid,
		string $summary,
		\DateTime $start,
		\DateTime $end,
		bool $all_day,
		string $description,
		string $location,
		string $recurrence
	): array {
		return array(
			'uid'                  => $uid,
			'name'                 => '' !== $summary ? $summary : '(Untitled)',
			'scheduled_start_time' => $start->format( 'c' ),
			'scheduled_end_time'   => $end->format( 'c' ),
			'allDay'               => $all_day,
			'description'          => $description,
			'location'             => '' !== $location ? $location : null,
			'eventIndex'           => $recurrence,
			'raw'                  => null,
		);
	}

	/**
	 * Expands an RRULE into concrete occurrences within a bounded window.
	 *
	 * Supported: FREQ=WEEKLY (with COUNT/UNTIL), FREQ=DAILY, FREQ=MONTHLY,
	 * FREQ=YEARLY. Only INTERVAL is honored; BY* rules are ignored. A hard cap
	 * prevents runaway expansions.
	 *
	 * @param string    $rrule      RRULE value (without the "RRULE:" prefix).
	 * @param \DateTime $start      First occurrence start.
	 * @param \DateTime $end        First occurrence end.
	 * @param int|null  $range_year Window year to expand within.
	 * @return array  List of [ 'start' => DateTime, 'end' => DateTime ].
	 */
	private static function expand_recurrence( string $rrule, \DateTime $start, \DateTime $end, ?int $range_year ): array {
		$parts = array();
		foreach ( explode( ';', $rrule ) as $kv ) {
			$eq = strpos( $kv, '=' );
			if ( false === $eq ) {
				continue;
			}
			// Favour the last occurrence of a duplicated key (spec-safe).
			$parts[ strtoupper( substr( $kv, 0, $eq ) ) ] = strtoupper( substr( $kv, $eq + 1 ) );
		}

		$freq     = $parts['FREQ'] ?? '';
		$interval = max( 1, (int) ( $parts['INTERVAL'] ?? 1 ) );
		$until    = isset( $parts['UNTIL'] ) ? self::parse_rrule_datetime( $parts['UNTIL'] ) : null;
		$count    = isset( $parts['COUNT'] ) ? max( 1, (int) $parts['COUNT'] ) : null;

		$dur         = max( 0, (int) $end->getTimestamp() - (int) $start->getTimestamp() );
		$supported   = in_array( $freq, array( 'WEEKLY', 'DAILY', 'MONTHLY', 'YEARLY' ), true );
		$max_occ     = 500;
		$horizontal  = new \DateTime( $start->format( 'Y-m-d H:i:s' ), $start->getTimezone() );
		$occurrences = array();
		$occ_count   = 0;

		while ( $occ_count < $max_occ ) {
			// Fast-forward occurrences before the bounded window so far-past
			// recurring events are reached without stepping one interval at a time.
			if ( null !== $range_year && $supported ) {
				$window_start = new \DateTime( sprintf( '%d-01-01 00:00:00', $range_year ) );
				if ( $horizontal < $window_start ) {
					self::fast_forward( $horizontal, $window_start, $freq, $interval );
					continue;
				}
			}

			$occurrences[] = array(
				'start' => new \DateTime( $horizontal->format( 'c' ), $horizontal->getTimezone() ),
				'end'   => ( clone $horizontal )->setTimestamp( $horizontal->getTimestamp() + $dur ),
			);
			++$occ_count;

			if ( null !== $count && $occ_count >= $count ) {
				break;
			}
			if ( null !== $until && $horizontal > $until ) {
				break;
			}

			self::advance_step( $horizontal, $freq, $interval );
		}

		return $occurrences;
	}

	/**
	 * Advances a DateTime by one recurrence interval.
	 *
	 * @param \DateTime $horizontal DateTime to advance in place.
	 * @param string    $freq       Recurrence frequency.
	 * @param int       $interval   Recurrence interval.
	 */
	private static function advance_step( \DateTime $horizontal, string $freq, int $interval ): void {
		switch ( $freq ) {
			case 'DAILY':
				$horizontal->modify( sprintf( '+%d days', $interval ) );
				break;
			case 'WEEKLY':
				$horizontal->modify( sprintf( '+%d weeks', $interval ) );
				break;
			case 'MONTHLY':
				$horizontal->modify( sprintf( '+%d months', $interval ) );
				break;
			case 'YEARLY':
				$horizontal->modify( sprintf( '+%d years', $interval ) );
				break;
		}
	}

	/**
	 * Advances a DateTime to (or just past) a target window start in one step.
	 *
	 * @param \DateTime $horizontal   DateTime to advance in place.
	 * @param \DateTime $window_start Target window start.
	 * @param string    $freq         Recurrence frequency.
	 * @param int       $interval     Recurrence interval.
	 */
	private static function fast_forward( \DateTime $horizontal, \DateTime $window_start, string $freq, int $interval ): void {
		$target = $window_start->getTimestamp();

		switch ( $freq ) {
			case 'DAILY':
				$steps = (int) floor( ( $target - $horizontal->getTimestamp() ) / ( 86400 * $interval ) );
				$add   = max( 1, $steps );
				$horizontal->modify( sprintf( '+%d days', $add * $interval ) );
				break;
			case 'WEEKLY':
				$steps = (int) floor( ( $target - $horizontal->getTimestamp() ) / ( 604800 * $interval ) );
				$add   = max( 1, $steps );
				$horizontal->modify( sprintf( '+%d weeks', $add * $interval ) );
				break;
			case 'MONTHLY':
				$step   = new \DateTime( $horizontal->format( 'c' ), $horizontal->getTimezone() );
				$months = 0;
				while ( $step < $window_start && $months < 1200 ) {
					$step->modify( sprintf( '+%d months', $interval ) );
					++$months;
				}
				$horizontal->setTimestamp( $step->getTimestamp() );
				break;
			case 'YEARLY':
				$diff = (int) floor( ( $target - $horizontal->getTimestamp() ) / ( 31536000 * $interval ) );
				$add  = max( 1, $diff );
				$horizontal->modify( sprintf( '+%d years', $add * $interval ) );
				break;
		}
	}

	/**
	 * Parses an RRULE UNTIL value into a DateTime.
	 *
	 * @param string $value UNTIL value.
	 * @return \DateTime|null Parsed date, or null when unrecognized.
	 */
	private static function parse_rrule_datetime( string $value ): ?\DateTime {
		$cleaned = trim( $value );
		if ( '' === $cleaned ) {
			return null;
		}
		try {
			if ( preg_match( '/^\d{8}T\d{6}$/', $cleaned ) === 1 ) {
				return \DateTime::createFromFormat( '!Ymd\THis', $cleaned, new \DateTimeZone( 'UTC' ) );
			}
			if ( preg_match( '/^\d{8}$/', $cleaned ) === 1 ) {
				return \DateTime::createFromFormat( '!Ymd', $cleaned, new \DateTimeZone( 'UTC' ) );
			}
			return new \DateTime( $cleaned );
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * Parses an DTSTART/DTEND/DTSTAMP/EXDATE value.
	 *
	 * @param array|null $prop Property array ['value' => string, 'params' => array], or null.
	 * @return \DateTime|null Parsed date, or null when unavailable.
	 */
	private static function parse_datetime( ?array $prop ): ?\DateTime {
		if ( null === $prop ) {
			return null;
		}

		$value  = trim( (string) $prop['value'] );
		$params = $prop['params'] ?? array();

		try {
			// Date-only (all-day).
			if ( preg_match( '/^\d{8}$/', $value ) === 1 ) {
				return \DateTime::createFromFormat( '!Ymd', $value, new \DateTimeZone( 'UTC' ) );
			}

			// UTC (Z suffix).
			if ( preg_match( '/^\d{8}T\d{6}Z$/i', $value ) === 1 ) {
				return \DateTime::createFromFormat( '!Ymd\THis\Z', $value, new \DateTimeZone( 'UTC' ) );
			}

			// Basic local (no timezone) with TZID.
			if ( preg_match( '/^\d{8}T\d{6}$/', $value ) === 1 ) {
				if ( ! empty( $params['TZID'] ) && self::valid_timezone( (string) $params['TZID'] ) ) {
					return \DateTime::createFromFormat( '!Ymd\THis', $value, new \DateTimeZone( $params['TZID'] ) );
				}
				return \DateTime::createFromFormat( '!Ymd\THis', $value, new \DateTimeZone( 'UTC' ) );
			}

			// Extended or float fallback.
			return new \DateTime( $value );
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * Determines whether a raw DTSTART/DTEND value is date-only (all-day).
	 *
	 * @param array|null $prop Property array, or null.
	 * @return bool True when date-only.
	 */
	private static function is_date_only( ?array $prop ): bool {
		if ( null === $prop ) {
			return false;
		}
		$value = trim( (string) $prop['value'] );
		return preg_match( '/^\d{8}$/', $value ) === 1;
	}

	/**
	 * Unescapes iCalendar text escaping (\n, \\, \;, \,).
	 *
	 * @param string $value Raw escaped value.
	 * @return string Unescaped value.
	 */
	public static function unescape( string $value ): string {
		$value = str_replace( '\\\\', "\x00", $value );
		$value = str_replace( '\\n', "\n", $value );
		$value = str_replace( '\\N', "\n", $value );
		$value = str_replace( '\\;', ';', $value );
		$value = str_replace( '\\,', ',', $value );
		$value = str_replace( "\x00", '\\', $value );
		return $value;
	}

	/**
	 * Determines whether a value is a valid PHP timezone identifier.
	 *
	 * @param string $tzid Timezone identifier.
	 * @return bool True when valid.
	 */
	public static function valid_timezone( string $tzid ): bool {
		return in_array( $tzid, \DateTimeZone::listIdentifiers(), true );
	}
}
