<?php

namespace PostCalendar\Integrations\Bricks;

use PostCalendar\Events\Event_Config;
use PostCalendar\Events\Event_Date_Parser;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dynamic_Data_Tags {

	public function __construct() {
		add_filter( 'bricks/dynamic_tags_list', array( $this, 'register_tags' ) );
		add_filter( 'bricks/dynamic_data/render_tag', array( $this, 'render_tag' ), 20, 3 );
		add_filter( 'bricks/dynamic_data/render_content', array( $this, 'render_content' ), 20, 3 );
		add_filter( 'bricks/frontend/render_data', array( $this, 'render_content' ), 20, 2 );
	}

	/**
	 * Register Post Calendar dynamic data tags in Bricks builder
	 *
	 * Syntax examples:
	 * - {post_event_start}
	 * - {post_event_start:Y-m-d}
	 * - {post_event_start:F j, Y \a\t g:i A}
	 * - {post_event_end}
	 * - {post_event_end:Y-m-d}
	 * - {post_event_label}
	 * - {post_has_events}
	 * - {post_events_range_start}
	 * - {post_events_range_start:Y-m-d}
	 * - {post_events_range_end}
	 * - {post_events_range_end:Y-m-d}
	 *
	 * @param array $tags Existing dynamic tags list.
	 * @return array Updated tags list.
	 */
	public function register_tags( $tags ) {
		if ( ! is_array( $tags ) ) {
			return $tags;
		}

		$tags[] = array(
			'name'  => '{post_event_start}',
			'label' => 'Event Start',
			'group' => 'Post Calendar',
		);

		$tags[] = array(
			'name'  => '{post_event_end}',
			'label' => 'Event End',
			'group' => 'Post Calendar',
		);

		$tags[] = array(
			'name'  => '{post_event_label}',
			'label' => 'Event Label',
			'group' => 'Post Calendar',
		);

		$tags[] = array(
			'name'  => '{post_has_events}',
			'label' => 'Has Events',
			'group' => 'Post Calendar',
		);

		$tags[] = array(
			'name'  => '{post_events_range_start}',
			'label' => 'Events Range Start',
			'group' => 'Post Calendar',
		);

		$tags[] = array(
			'name'  => '{post_events_range_end}',
			'label' => 'Events Range End',
			'group' => 'Post Calendar',
		);

		// Occurrence event tags (inside an events loop).
		$event_tags = array(
			'{pc_event_title}'   => 'Event Title',
			'{pc_event_start}'   => 'Event Start',
			'{pc_event_end}'     => 'Event End',
			'{pc_event_url}'     => 'Event URL',
			'{pc_event_all_day}' => 'Event All Day',
		);

		foreach ( $event_tags as $name => $label ) {
			$tags[] = array(
				'name'  => $name,
				'label' => $label,
				'group' => 'Post Calendar',
			);
		}

		// Window tags (active events query scope).
		$window_tags = array(
			'{pc_window_start}'        => 'Events Window Start',
			'{pc_window_end}'          => 'Events Window End',
			'{pc_url:cal_view:agenda}' => 'Calendar State URL',
		);

		foreach ( $window_tags as $name => $label ) {
			$tags[] = array(
				'name'  => $name,
				'label' => $label,
				'group' => 'Post Calendar',
			);
		}

		return $tags;
	}

	/**
	 * Render individual dynamic tags
	 *
	 * @param mixed  $tag The tag to render (may include arguments like "post_event_start:Y-m-d").
	 * @param object $post The post object.
	 * @param string $_context The context ('text', 'html', etc.) — unused, required by Bricks API.
	 * @return mixed The rendered value or original tag if not recognized.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $_context is required by the Bricks render_tag API.
	public function render_tag( $tag, $post, $_context = 'text' ) {
		if ( ! is_string( $tag ) ) {
			return $tag;
		}

		// Clean tag name (remove outer braces if present).
		$clean_tag = str_replace( array( '{', '}' ), '', $tag );

		// Calendar tags are context-based, not post-based: resolve before the post check.
		if ( strpos( $clean_tag, 'pc_' ) === 0 ) {
			return $this->get_pc_tag_value( $clean_tag );
		}

		// Get post ID.
		$post_id = is_object( $post ) ? $post->ID : $post;

		if ( ! $post_id ) {
			return $tag;
		}

		// Handle tags with format arguments (e.g., "post_event_start:Y-m-d").
		if ( strpos( $clean_tag, 'post_event_start:' ) === 0 ) {
			$format = str_replace( 'post_event_start:', '', $clean_tag );
			return $this->get_formatted_meta_date( $post_id, Event_Config::EVENT_SCHEDULED_START_TIME_META, $format );
		}

		if ( strpos( $clean_tag, 'post_event_end:' ) === 0 ) {
			$format = str_replace( 'post_event_end:', '', $clean_tag );
			return $this->get_formatted_meta_date( $post_id, Event_Config::EVENT_SCHEDULED_END_TIME_META, $format );
		}

		if ( strpos( $clean_tag, 'post_events_range_start:' ) === 0 ) {
			$format = str_replace( 'post_events_range_start:', '', $clean_tag );
			return $this->get_formatted_meta_date( $post_id, Event_Config::EVENT_RANGE_START_META, $format );
		}

		if ( strpos( $clean_tag, 'post_events_range_end:' ) === 0 ) {
			$format = str_replace( 'post_events_range_end:', '', $clean_tag );
			return $this->get_formatted_meta_date( $post_id, Event_Config::EVENT_RANGE_END_META, $format );
		}

		// Handle simple tags without arguments.
		if ( 'post_event_start' === $clean_tag ) {
			return $this->get_meta_value( $post_id, Event_Config::EVENT_SCHEDULED_START_TIME_META );
		}

		if ( 'post_event_end' === $clean_tag ) {
			return $this->get_meta_value( $post_id, Event_Config::EVENT_SCHEDULED_END_TIME_META );
		}

		if ( 'post_event_label' === $clean_tag ) {
			return $this->get_meta_value( $post_id, Event_Config::EVENT_NAME_META );
		}

		if ( 'post_has_events' === $clean_tag ) {
			return $this->get_meta_value( $post_id, Event_Config::EVENT_HAS_EVENTS_META );
		}

		if ( 'post_events_range_start' === $clean_tag ) {
			return $this->get_meta_value( $post_id, Event_Config::EVENT_RANGE_START_META );
		}

		if ( 'post_events_range_end' === $clean_tag ) {
			return $this->get_meta_value( $post_id, Event_Config::EVENT_RANGE_END_META );
		}

		return $tag;
	}

	/**
	 * Render tags within content strings (frontend rendering)
	 *
	 * @param string $content The content potentially containing dynamic tags.
	 * @param object $post The post object.
	 * @param string $_context The context ('text', 'html', etc.) — unused, required by Bricks API.
	 * @return string The content with tags replaced.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $_context is required by the Bricks render_content API.
	public function render_content( $content, $post, $_context = 'text' ) {
		if ( ! is_string( $content ) ) {
			return $content;
		}

		// Calendar tags are context-based: no post required.
		if ( strpos( $content, '{pc_' ) !== false ) {
			$content = preg_replace_callback(
				'/\{(pc_[a-z_]+(?::[^}]+)?)\}/',
				function ( $matches ) {
					return (string) $this->get_pc_tag_value( $matches[1] );
				},
				$content
			);
		}

		$post_id = is_object( $post ) ? $post->ID : (int) $post;

		if ( ! $post_id ) {
			return $content;
		}

		// Check if content contains any Post Calendar tags.
		if ( strpos( $content, '{post_' ) === false ) {
			return $content;
		}

		// Handle formatted date tags: {post_event_start:format}, {post_event_end:format}, etc.
		$content = $this->replace_formatted_date_tags( $content, $post_id, 'post_event_start', Event_Config::EVENT_SCHEDULED_START_TIME_META );
		$content = $this->replace_formatted_date_tags( $content, $post_id, 'post_event_end', Event_Config::EVENT_SCHEDULED_END_TIME_META );
		$content = $this->replace_formatted_date_tags( $content, $post_id, 'post_events_range_start', Event_Config::EVENT_RANGE_START_META );
		$content = $this->replace_formatted_date_tags( $content, $post_id, 'post_events_range_end', Event_Config::EVENT_RANGE_END_META );

		// Handle simple tags.
		$simple_tags = array(
			'post_event_start'        => Event_Config::EVENT_SCHEDULED_START_TIME_META,
			'post_event_end'          => Event_Config::EVENT_SCHEDULED_END_TIME_META,
			'post_event_label'        => Event_Config::EVENT_NAME_META,
			'post_has_events'         => Event_Config::EVENT_HAS_EVENTS_META,
			'post_events_range_start' => Event_Config::EVENT_RANGE_START_META,
			'post_events_range_end'   => Event_Config::EVENT_RANGE_END_META,
		);

		foreach ( $simple_tags as $tag_name => $meta_key ) {
			$tag = '{' . $tag_name . '}';
			if ( strpos( $content, $tag ) !== false ) {
				$value   = $this->get_meta_value( $post_id, $meta_key );
				$content = str_replace( $tag, $value, $content );
			}
		}

		return $content;
	}

	/**
	 * Replace formatted date tags in content
	 *
	 * @param string $content The content to search.
	 * @param int    $post_id The post ID.
	 * @param string $tag_name The tag name (without braces).
	 * @param string $meta_key The meta key to fetch.
	 * @return string The content with tags replaced.
	 */
	private function replace_formatted_date_tags( $content, $post_id, $tag_name, $meta_key ) {
		$pattern = '/\{' . preg_quote( $tag_name, '/' ) . ':([^}]+)\}/';

		if ( ! preg_match_all( $pattern, $content, $matches ) ) {
			return $content;
		}

		foreach ( $matches[1] as $key => $format ) {
			$tag     = $matches[0][ $key ];
			$value   = $this->get_formatted_meta_date( $post_id, $meta_key, $format );
			$content = str_replace( $tag, $value, $content );
		}

		return $content;
	}

	/**
	 * Get a simple meta value
	 *
	 * @param int    $post_id The post ID.
	 * @param string $meta_key The meta key.
	 * @return string The meta value or empty string if not found.
	 */
	private function get_meta_value( $post_id, $meta_key ) {
		$value = get_post_meta( $post_id, $meta_key, true );
		return is_string( $value ) ? $value : (string) ( $value ?? '' );
	}

	/**
	 * Get a formatted date from meta
	 *
	 * @param int    $post_id The post ID.
	 * @param string $meta_key The meta key containing the date.
	 * @param string $format PHP date format string.
	 * @return string The formatted date or empty string if not found/invalid.
	 */
	private function get_formatted_meta_date( $post_id, $meta_key, $format = 'Y-m-d H:i:s' ) {
		$value = get_post_meta( $post_id, $meta_key, true );

		if ( empty( $value ) ) {
			return '';
		}

		try {
			$date = new \DateTime( $value );
			return $date->format( $format );
		} catch ( \Exception $e ) {
			// Return the raw value if parsing fails.
			return $value;
		}
	}

	/**
	 * Resolve a context-based {pc_*} tag against the active calendar scope.
	 *
	 * Supported syntax: {pc_event_start:g:i A}, {pc_window_start:F j}, {pc_url:cal_anchor:2026-09-01}.
	 *
	 * @param string $clean_tag Tag name without braces, possibly with a :format suffix.
	 * @return string Rendered value, or empty string when no calendar context exists.
	 */
	private function get_pc_tag_value( string $clean_tag ): string {
		list( $name, $format ) = $this->split_pc_tag( $clean_tag );

		switch ( true ) {
			case 'pc_event_title' === $name:
			case 'pc_event_start' === $name:
			case 'pc_event_end' === $name:
			case 'pc_event_url' === $name:
			case 'pc_event_all_day' === $name:
				return $this->render_event_tag( $name, $format );

			case 'pc_window_start' === $name:
			case 'pc_window_end' === $name:
				return $this->render_window_tag( $name, $format );

			case 'pc_url' === $name:
				return $this->render_state_url( $format );
		}

		return '';
	}

	/**
	 * Build the current URL with calendar params changed.
	 *
	 * Format: {pc_url:param:value} e.g. {pc_url:cal_anchor:2026-09-01}.
	 * Use an empty value to remove a param: {pc_url:cal_anchor:}.
	 */
	private function render_state_url( ?string $args ): string {
		if ( ! is_string( $args ) || false === strpos( $args, ':' ) ) {
			return '';
		}

		list( $param, $value ) = explode( ':', $args, 2 );

		return ( new Calendar_Request_Params() )->build_url( array( $param => '' !== $value ? sanitize_text_field( $value ) : null ) );
	}

	private function render_event_tag( string $name, ?string $format ): string {
		$event = Calendar_Context::get_active_event();

		if ( ! is_array( $event ) ) {
			return '';
		}

		switch ( $name ) {
			case 'pc_event_title':
				return (string) ( $event['title'] ?? '' );

			case 'pc_event_start':
				return $this->format_context_date( $event['start'] ?? null, $format );

			case 'pc_event_end':
				return $this->format_context_date( $event['end'] ?? null, $format );

			case 'pc_event_url':
				return (string) ( $event['url'] ?? '' );

			case 'pc_event_all_day':
				return empty( $event['allDay'] ) ? '' : '1';
		}

		return '';
	}

	private function render_window_tag( string $name, ?string $format ): string {
		$window = Calendar_Context::get_active_window();

		if ( ! is_array( $window ) ) {
			return '';
		}

		switch ( $name ) {
			case 'pc_window_start':
				return $this->format_context_date( $window['start'] ?? null, $format );

			case 'pc_window_end':
				return $this->format_context_date( $window['end'] ?? null, $format );
		}

		return '';
	}

	/**
	 * @return array{0: string, 1: string|null}
	 */
	private function split_pc_tag( string $clean_tag ): array {
		$colon_position = strpos( $clean_tag, ':' );

		if ( false === $colon_position ) {
			return array( $clean_tag, null );
		}

		return array(
			substr( $clean_tag, 0, $colon_position ),
			substr( $clean_tag, $colon_position + 1 ),
		);
	}

	private function format_context_date( $value, ?string $format ): string {
		if ( empty( $value ) ) {
			return '';
		}

		try {
			$date = Event_Date_Parser::parse( (string) $value );

			if ( ! $date ) {
				return (string) $value;
			}

			return $date->format( $format ?? 'Y-m-d H:i:s' );
		} catch ( \Exception $exception ) {
			return (string) $value;
		}
	}
}
