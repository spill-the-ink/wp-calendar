<?php

namespace WpCalendar;

use WpCalendar\Rest\Rest_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Assets {
	private const SCRIPT_HANDLE          = 'wp-calendar-app';
	private const STYLE_HANDLE           = 'wp-calendar-app';
	private const ADMIN_SCRIPT_HANDLE    = 'wp-calendar-admin';
	private const ADMIN_STYLE_HANDLE     = 'wp-calendar-admin';
	private const SETTINGS_SCRIPT_HANDLE = 'wp-calendar-settings';
	private const SETTINGS_STYLE_HANDLE  = 'wp-calendar-settings';

	public function has_built_assets(): bool {
		return file_exists( WP_CALENDAR_PLUGIN_DIR . 'dist/wp-calendar.js' );
	}

	public function has_admin_built_assets(): bool {
		return file_exists( WP_CALENDAR_PLUGIN_DIR . 'dist/wp-calendar-admin.js' );
	}

	public function has_settings_built_assets(): bool {
		return file_exists( WP_CALENDAR_PLUGIN_DIR . 'dist/wp-calendar-settings.js' );
	}

	public function enqueue_calendar_assets(): void {
		if ( ! $this->has_built_assets() ) {
			return;
		}

		$version = $this->get_asset_version( 'wp-calendar.js' );
		$style   = $this->get_style_asset_path( 'wp-calendar.css', 'style.css' );

		if ( $style ) {
			wp_enqueue_style(
				self::STYLE_HANDLE,
				WP_CALENDAR_PLUGIN_URL . 'dist/' . $style,
				array(),
				$version,
			);
		}

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			WP_CALENDAR_PLUGIN_URL . 'dist/wp-calendar.js',
			array(),
			$version,
			true,
		);

		wp_localize_script( self::SCRIPT_HANDLE, 'WpCalendarRuntime', $this->get_runtime_config() );
	}

	public function enqueue_admin_editor_assets( array $config ): void {
		if ( ! $this->has_admin_built_assets() ) {
			return;
		}

		$version = $this->get_asset_version( 'wp-calendar-admin.js' );
		$style   = $this->get_style_asset_path( 'wp-calendar-admin.css' );

		if ( $style ) {
			wp_enqueue_style(
				self::ADMIN_STYLE_HANDLE,
				WP_CALENDAR_PLUGIN_URL . 'dist/' . $style,
				array(),
				$version,
			);
		}

		wp_enqueue_script(
			self::ADMIN_SCRIPT_HANDLE,
			WP_CALENDAR_PLUGIN_URL . 'dist/wp-calendar-admin.js',
			array( 'wp-element' ),
			$version,
			true,
		);

		wp_localize_script( self::ADMIN_SCRIPT_HANDLE, 'WpCalendarAdmin', $config );
	}

	public function enqueue_settings_assets( array $config ): void {
		if ( ! $this->has_settings_built_assets() ) {
			return;
		}

		$version = $this->get_asset_version( 'wp-calendar-settings.js' );
		$style   = $this->get_style_asset_path( 'wp-calendar-settings.css', 'wp-calendar-admin.css' );

		if ( $style ) {
			wp_enqueue_style(
				self::SETTINGS_STYLE_HANDLE,
				WP_CALENDAR_PLUGIN_URL . 'dist/' . $style,
				array(),
				$version,
			);
		}

		wp_enqueue_script(
			self::SETTINGS_SCRIPT_HANDLE,
			WP_CALENDAR_PLUGIN_URL . 'dist/wp-calendar-settings.js',
			array( 'wp-element' ),
			$version,
			true,
		);

		wp_localize_script( self::SETTINGS_SCRIPT_HANDLE, 'WpCalendarSettings', $config );
	}

	private function get_asset_version( string $script_name ): string {
		$script_path = WP_CALENDAR_PLUGIN_DIR . 'dist/' . $script_name;

		if ( file_exists( $script_path ) ) {
			return (string) filemtime( $script_path );
		}

		return WP_CALENDAR_VERSION;
	}

	private function get_style_asset_path( string ...$candidates ): ?string {
		foreach ( $candidates as $candidate ) {
			if ( file_exists( WP_CALENDAR_PLUGIN_DIR . 'dist/' . $candidate ) ) {
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
			'allDay'                 => esc_html__( 'All-day', 'wp-calendar' ),
			'agenda'                 => esc_html__( 'Agenda', 'wp-calendar' ),
			'back'                   => esc_html__( 'Back', 'wp-calendar' ),
			'calendarViews'          => esc_html__( 'Calendar views', 'wp-calendar' ),
			'configParseError'       => esc_html__( 'Unable to parse the calendar configuration.', 'wp-calendar' ),
			'date'                   => esc_html__( 'Date', 'wp-calendar' ),
			'day'                    => esc_html__( 'Day', 'wp-calendar' ),
			'event'                  => esc_html__( 'Event', 'wp-calendar' ),
			'loadError'              => esc_html__( 'Unable to load calendar events right now.', 'wp-calendar' ),
			'missingApiUrl'          => esc_html__( 'The calendar API URL is missing.', 'wp-calendar' ),
			'month'                  => esc_html__( 'Month', 'wp-calendar' ),
			'next'                   => esc_html__( 'Next', 'wp-calendar' ),
			'noEvents'               => esc_html__( 'No events to display.', 'wp-calendar' ),
			'showMore'               => esc_html__( 'more', 'wp-calendar' ),
			/* translators: 1: hidden event count, 2: localized month label. */
			'showMoreEventsForMonth' => esc_html__( 'Show %1$s more events for %2$s', 'wp-calendar' ),
			'time'                   => esc_html__( 'Time', 'wp-calendar' ),
			'today'                  => esc_html__( 'Today', 'wp-calendar' ),
			'twoweeks'               => esc_html__( 'Two weeks', 'wp-calendar' ),
			'week'                   => esc_html__( 'Week', 'wp-calendar' ),
			'year'                   => esc_html__( 'Year', 'wp-calendar' ),
		);
	}
}
