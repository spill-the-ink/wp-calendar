<?php

namespace PostCalendar;

use PostCalendar\Rest\Rest_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Assets {
	private const SCRIPT_HANDLE          = 'post-calendar-app';
	private const STYLE_HANDLE           = 'post-calendar-app';
	private const ADMIN_SCRIPT_HANDLE    = 'post-calendar-admin';
	private const ADMIN_STYLE_HANDLE     = 'post-calendar-admin';
	private const SETTINGS_SCRIPT_HANDLE = 'post-calendar-settings';
	private const SETTINGS_STYLE_HANDLE  = 'post-calendar-settings';

	public function has_built_assets(): bool {
		return file_exists( POST_CALENDAR_PLUGIN_DIR . 'dist/post-calendar.js' );
	}

	public function has_admin_built_assets(): bool {
		return file_exists( POST_CALENDAR_PLUGIN_DIR . 'dist/post-calendar-admin.js' );
	}

	public function has_settings_built_assets(): bool {
		return file_exists( POST_CALENDAR_PLUGIN_DIR . 'dist/post-calendar-settings.js' );
	}

	public function enqueue_calendar_assets(): void {
		if ( ! $this->has_built_assets() ) {
			return;
		}

		$version = $this->get_asset_version( 'post-calendar.js' );
		$style   = $this->get_style_asset_path( 'post-calendar.css', 'style.css' );

		if ( $style ) {
			wp_enqueue_style(
				self::STYLE_HANDLE,
				POST_CALENDAR_PLUGIN_URL . 'dist/' . $style,
				array(),
				$version,
			);
		}

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			POST_CALENDAR_PLUGIN_URL . 'dist/post-calendar.js',
			array(),
			$version,
			true,
		);

		wp_localize_script( self::SCRIPT_HANDLE, 'PostCalendarRuntime', $this->get_runtime_config() );
	}

	public function enqueue_admin_editor_assets( array $config ): void {
		if ( ! $this->has_admin_built_assets() ) {
			return;
		}

		$version = $this->get_asset_version( 'post-calendar-admin.js' );
		$style   = $this->get_style_asset_path( 'post-calendar-admin.css' );

		if ( $style ) {
			wp_enqueue_style(
				self::ADMIN_STYLE_HANDLE,
				POST_CALENDAR_PLUGIN_URL . 'dist/' . $style,
				array(),
				$version,
			);
		}

		wp_enqueue_script(
			self::ADMIN_SCRIPT_HANDLE,
			POST_CALENDAR_PLUGIN_URL . 'dist/post-calendar-admin.js',
			array( 'wp-element' ),
			$version,
			true,
		);

		wp_localize_script( self::ADMIN_SCRIPT_HANDLE, 'PostCalendarAdmin', $config );
	}

	public function enqueue_settings_assets( array $config ): void {
		if ( ! $this->has_settings_built_assets() ) {
			return;
		}

		$version = $this->get_asset_version( 'post-calendar-settings.js' );
		$style   = $this->get_style_asset_path( 'post-calendar-settings.css', 'post-calendar-admin.css' );

		if ( $style ) {
			wp_enqueue_style(
				self::SETTINGS_STYLE_HANDLE,
				POST_CALENDAR_PLUGIN_URL . 'dist/' . $style,
				array(),
				$version,
			);
		}

		wp_enqueue_script(
			self::SETTINGS_SCRIPT_HANDLE,
			POST_CALENDAR_PLUGIN_URL . 'dist/post-calendar-settings.js',
			array( 'wp-element' ),
			$version,
			true,
		);

		wp_localize_script( self::SETTINGS_SCRIPT_HANDLE, 'PostCalendarSettings', $config );
	}

	private function get_asset_version( string $script_name ): string {
		$script_path = POST_CALENDAR_PLUGIN_DIR . 'dist/' . $script_name;

		if ( file_exists( $script_path ) ) {
			return (string) filemtime( $script_path );
		}

		return POST_CALENDAR_VERSION;
	}

	private function get_style_asset_path( string ...$candidates ): ?string {
		foreach ( $candidates as $candidate ) {
			if ( file_exists( POST_CALENDAR_PLUGIN_DIR . 'dist/' . $candidate ) ) {
				return $candidate;
			}
		}

		return null;
	}

	private function get_runtime_config(): array {
		return array(
			'restUrl'   => esc_url_raw( rest_url( Rest_Controller::REST_NAMESPACE . Rest_Controller::REST_ROUTE ) ),
			'restNonce' => wp_create_nonce( 'wp_rest' ),
			'locale'    => determine_locale(),
			'strings'   => $this->get_runtime_strings(),
		);
	}

	private function get_runtime_strings(): array {
		return array(
			'allDay'                 => esc_html__( 'All-day', 'post-calendar' ),
			'agenda'                 => esc_html__( 'Agenda', 'post-calendar' ),
			'back'                   => esc_html__( 'Back', 'post-calendar' ),
			'calendarViews'          => esc_html__( 'Calendar views', 'post-calendar' ),
			'configParseError'       => esc_html__( 'Unable to parse the calendar configuration.', 'post-calendar' ),
			'date'                   => esc_html__( 'Date', 'post-calendar' ),
			'day'                    => esc_html__( 'Day', 'post-calendar' ),
			'event'                  => esc_html__( 'Event', 'post-calendar' ),
			'loadError'              => esc_html__( 'Unable to load calendar events right now.', 'post-calendar' ),
			'missingApiUrl'          => esc_html__( 'The calendar API URL is missing.', 'post-calendar' ),
			'month'                  => esc_html__( 'Month', 'post-calendar' ),
			'next'                   => esc_html__( 'Next', 'post-calendar' ),
			'noEvents'               => esc_html__( 'No events to display.', 'post-calendar' ),
			'showMore'               => esc_html__( 'more', 'post-calendar' ),
			/* translators: 1: hidden event count, 2: localized month label. */
			'showMoreEventsForMonth' => esc_html__( 'Show %1$s more events for %2$s', 'post-calendar' ),
			'time'                   => esc_html__( 'Time', 'post-calendar' ),
			'today'                  => esc_html__( 'Today', 'post-calendar' ),
			'twoweeks'               => esc_html__( 'Two weeks', 'post-calendar' ),
			'week'                   => esc_html__( 'Week', 'post-calendar' ),
			'year'                   => esc_html__( 'Year', 'post-calendar' ),
		);
	}
}
