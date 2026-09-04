<?php

namespace WpCalendar\Admin;

use WpCalendar\Assets;
use WpCalendar\Events\Event_Date_Parser;
use WpCalendar\Events\Event_Query_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin_Editor {
	private const META_BOX_ID      = 'wp-calendar-events';
	private const NONCE_ACTION     = 'wp_calendar_save_events';
	private const NONCE_NAME       = 'wp_calendar_events_nonce';
	private const ROWS_FIELD_NAME  = 'wp_calendar_events';
	private const INPUT_FIELD_NAME = 'wp_calendar_events_json';
	private const ROOT_CLASS       = 'js-wp-calendar-admin-root';

	private Assets $assets;

	public function __construct( ?Assets $assets = null ) {
		$this->assets = $assets ?? new Assets();

		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'save_post', array( $this, 'save_events' ), 10, 2 );
	}

	public function register_meta_boxes(): void {
		foreach ( Settings_Page::get_allowed_post_types() as $post_type ) {
			add_meta_box(
				self::META_BOX_ID,
				esc_html__( 'Calendar', 'wp-calendar' ),
				array( $this, 'render_meta_box' ),
				$post_type,
				'normal',
				'default',
			);
		}
	}

	public function render_meta_box( $post ): void {
		$post_id      = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : 0;
		$current_rows = $this->get_editor_rows( $post_id );

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		?>
		<div class="wp-calendar-admin-root <?php echo esc_attr( self::ROOT_CLASS ); ?>"></div>
		<noscript>
			<p><?php echo esc_html__( 'Calendar event editing requires JavaScript in the post editor.', 'wp-calendar' ); ?>
			</p>
		</noscript>
		<?php
	}

	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || ! in_array( $screen->post_type, Settings_Page::get_allowed_post_types(), true ) ) {
			return;
		}

		$this->assets->enqueue_admin_editor_assets( $this->get_editor_config( $this->resolve_current_post_id() ) );
	}

	public function save_events( int $post_id, $post ): void {
		if ( ! is_object( $post ) || empty( $post->post_type ) ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! in_array( $post->post_type, Settings_Page::get_allowed_post_types(), true ) ) {
			return;
		}

		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$submitted_rows = $this->get_submitted_rows();
		$sanitized_rows = $this->sanitize_rows( is_array( $submitted_rows ) ? $submitted_rows : array() );

		if ( empty( $sanitized_rows ) ) {
			delete_post_meta( $post_id, Event_Query_Service::EVENTS_META );
			return;
		}

		update_post_meta( $post_id, Event_Query_Service::EVENTS_META, array_values( $sanitized_rows ) );
	}

	private function get_editor_config( int $post_id ): array {
		return array(
			'currentEvents' => $this->get_editor_rows( $post_id ),
			'fieldName'     => self::ROWS_FIELD_NAME,
			'strings'       => $this->get_editor_strings(),
		);
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in caller save_events().
	private function get_submitted_rows(): array {
		if ( isset( $_POST[ self::ROWS_FIELD_NAME ] ) && is_array( $_POST[ self::ROWS_FIELD_NAME ] ) ) {
			return wp_unslash( $_POST[ self::ROWS_FIELD_NAME ] );
		}

		if ( isset( $_POST[ self::INPUT_FIELD_NAME ] ) ) {
			$payload = wp_unslash( $_POST[ self::INPUT_FIELD_NAME ] );

			if ( is_string( $payload ) ) {
				$decoded = json_decode( $payload, true );

				if ( is_array( $decoded ) ) {
					return $decoded;
				}
			}
		}

		return array();
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

	private function get_editor_rows( int $post_id ): array {
		if ( $post_id <= 0 ) {
			return array();
		}

		$stored_rows = get_post_meta( $post_id, Event_Query_Service::EVENTS_META, true );

		if ( is_array( $stored_rows ) ) {
			return $this->sanitize_rows( $stored_rows );
		}

		return array();
	}

	private function sanitize_rows( array $rows ): array {
		$sanitized_rows = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$sanitized_row = $this->sanitize_row( $row );

			if ( null === $sanitized_row ) {
				continue;
			}

			$sanitized_rows[] = $sanitized_row;
		}

		return $sanitized_rows;
	}

	private function sanitize_row( array $row ): ?array {
		$all_day = ! empty( $row['all_day'] );
		$start   = Event_Date_Parser::parse_editor_input( $row['scheduled_start_time'] ?? $row['start'] ?? '', false, $all_day );

		if ( ! $start ) {
			return null;
		}

		$end = Event_Date_Parser::parse_editor_input( $row['scheduled_end_time'] ?? $row['end'] ?? '', true, $all_day );

		if ( ! $end || $end < $start ) {
			$end = $all_day ? $start->setTime( 23, 59, 59 ) : $start;
		}

		$repeat          = $this->normalize_repeat_value( $row['frequency'] ?? $row['repeat'] ?? Event_Query_Service::REPEAT_NONE );
		$repeat_interval = max( 1, absint( $row['interval'] ?? $row['repeat_interval'] ?? 1 ) );
		$repeat_until    = Event_Query_Service::REPEAT_NONE === $repeat ? null : Event_Date_Parser::parse_editor_input( $row['recurrence_end'] ?? $row['repeat_until'] ?? '', true, $all_day );

		$name_id = isset( $row['name_id'] ) ? sanitize_key( (string) $row['name_id'] ) : ( isset( $row['label_id'] ) ? sanitize_key( (string) $row['label_id'] ) : '' );

		return array(
			'name'                 => sanitize_text_field( (string) ( $row['name'] ?? $row['label'] ?? '' ) ),
			'name_id'              => $name_id,
			'all_day'              => $all_day,
			'scheduled_start_time' => $start->format( 'Y-m-d H:i:s' ),
			'scheduled_end_time'   => $end->format( 'Y-m-d H:i:s' ),
			'frequency'            => $repeat,
			'interval'             => $repeat_interval,
			'by_weekday'           => Event_Query_Service::REPEAT_WEEKLY === $repeat ? $this->normalize_repeat_byday( $row['by_weekday'] ?? $row['repeat_byday'] ?? array() ) : array(),
			'recurrence_end'       => $repeat_until ? $repeat_until->format( 'Y-m-d H:i:s' ) : '',
			'location'             => sanitize_text_field( (string) ( $row['location'] ?? '' ) ),
			'location_url'         => esc_url_raw( (string) ( $row['location_url'] ?? '' ) ),
		);
	}

	private function normalize_repeat_value( $value ): string {
		$repeat = is_string( $value ) ? sanitize_key( $value ) : Event_Query_Service::REPEAT_NONE;

		if ( in_array( $repeat, array( Event_Query_Service::REPEAT_WEEKLY, Event_Query_Service::REPEAT_MONTHLY, Event_Query_Service::REPEAT_YEARLY ), true ) ) {
			return $repeat;
		}

		return Event_Query_Service::REPEAT_NONE;
	}

	private function normalize_repeat_byday( $value ): array {
		$weekday_codes = array();

		if ( is_array( $value ) ) {
			$weekday_codes = $value;
		} elseif ( is_string( $value ) && '' !== trim( $value ) ) {
			$weekday_codes = array_map( 'trim', explode( ',', $value ) );
		}

		$allowed = array( 'MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU' );

		return array_values(
			array_filter(
				array_unique(
					array_map(
						static function ( $weekday_code ): string {
							return strtoupper( sanitize_key( (string) $weekday_code ) );
						},
						$weekday_codes,
					),
				),
				static function ( string $weekday_code ) use ( $allowed ): bool {
					return in_array( $weekday_code, $allowed, true );
				}
			),
		);
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only post ID lookup for admin editor; nonce not applicable.
	private function resolve_current_post_id(): int {
		if ( isset( $_GET['post'] ) ) {
			return absint( wp_unslash( $_GET['post'] ) );
		}

		return 0;
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	private function get_editor_strings(): array {
		$labels        = Settings_Page::get_labels();
		$label_options = array();
		foreach ( $labels as $label ) {
			$label_options[] = array(
				'id'    => $label['id'],
				'name'  => $label['name'],
				'color' => $label['color'],
			);
		}

		return array(
			'addEvent'           => esc_html__( 'Add event', 'wp-calendar' ),
			'allDay'             => esc_html__( 'All-day event', 'wp-calendar' ),
			'endDate'            => esc_html__( 'End date', 'wp-calendar' ),
			'eventLabel'         => esc_html__( 'Event label', 'wp-calendar' ),
			'eventLabelHelp'     => esc_html__( 'Leave empty to use the post title.', 'wp-calendar' ),
			'eventNumber'        => esc_html__( 'Event', 'wp-calendar' ),
			'eventRepeat'        => esc_html__( 'Event frequency', 'wp-calendar' ),
			'eventsIntro'        => esc_html__( 'Add one or more event rows to make this post appear in the calendar.', 'wp-calendar' ),
			'monthly'            => esc_html__( 'Monthly', 'wp-calendar' ),
			'noEvents'           => esc_html__( 'No event rows yet.', 'wp-calendar' ),
			'removeEvent'        => esc_html__( 'Remove event', 'wp-calendar' ),
			'repeatInterval'     => esc_html__( 'Repeat interval', 'wp-calendar' ),
			'repeatIntervalHelp' => esc_html__( 'For example, every 2 weeks.', 'wp-calendar' ),
			'repeatOn'           => esc_html__( 'Repeat on', 'wp-calendar' ),
			'repeatUntil'        => esc_html__( 'Repeat until', 'wp-calendar' ),
			'startDate'          => esc_html__( 'Start date', 'wp-calendar' ),
			'weekly'             => esc_html__( 'Weekly', 'wp-calendar' ),
			'yearly'             => esc_html__( 'Yearly', 'wp-calendar' ),
			'doesNotRepeat'      => esc_html__( 'Does not repeat', 'wp-calendar' ),
			'monday'             => esc_html__( 'Monday', 'wp-calendar' ),
			'tuesday'            => esc_html__( 'Tuesday', 'wp-calendar' ),
			'wednesday'          => esc_html__( 'Wednesday', 'wp-calendar' ),
			'thursday'           => esc_html__( 'Thursday', 'wp-calendar' ),
			'friday'             => esc_html__( 'Friday', 'wp-calendar' ),
			'saturday'           => esc_html__( 'Saturday', 'wp-calendar' ),
			'sunday'             => esc_html__( 'Sunday', 'wp-calendar' ),
			'eventCategory'      => esc_html__( 'Label', 'wp-calendar' ),
			'eventCategoryHelp'  => esc_html__( 'Assign a label to this event for color-coded grouping in calendar views.', 'wp-calendar' ),
			'labelNone'          => esc_html__( 'No label', 'wp-calendar' ),
			'labels'             => $label_options,
		);
	}
}
