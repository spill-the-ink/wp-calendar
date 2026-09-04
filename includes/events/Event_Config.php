<?php

namespace WpCalendar\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Event_Config {
	public const EVENTS_META                     = '_wp_calendar_events';
	public const EVENT_HAS_EVENTS_META           = '_wp_calendar_has_events';
	public const EVENT_RANGE_START_META          = '_wp_calendar_events_range_start';
	public const EVENT_RANGE_END_META            = '_wp_calendar_events_range_end';
	public const EVENT_SCHEDULED_START_TIME_META = '_wp_calendar_event_start';
	public const EVENT_SCHEDULED_END_TIME_META   = '_wp_calendar_event_end';
	public const EVENT_NAME_META                 = '_wp_calendar_event_label';

	private const SUPPORTED_QUERY_VAR_KEYS = array(
		'post_type',
		'post__in',
		'post__not_in',
		'author__in',
		'author__not_in',
		'tax_query',
		'meta_query',
		'date_query',
		'orderby',
		'order',
		'meta_key',
		'meta_type',
		's',
	);

	private const SUPPORTED_QUERY_VAR_LIST_KEYS = array(
		'post__in',
		'post__not_in',
		'author__in',
		'author__not_in',
	);

	private const SUPPORTED_QUERY_VAR_DIRECT_KEYS = array(
		'orderby',
		'order',
		'meta_key',
		'meta_type',
		's',
	);

	public static function get_supported_query_var_keys(): array {
		return self::SUPPORTED_QUERY_VAR_KEYS;
	}

	public static function get_supported_query_var_list_keys(): array {
		return self::SUPPORTED_QUERY_VAR_LIST_KEYS;
	}

	public static function get_supported_query_var_direct_keys(): array {
		return self::SUPPORTED_QUERY_VAR_DIRECT_KEYS;
	}
}
