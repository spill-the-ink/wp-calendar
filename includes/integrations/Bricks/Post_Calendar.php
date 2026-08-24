<?php

namespace PostCalendar\Integrations\Bricks;

use PostCalendar\Events\Event_Config;
use PostCalendar\Admin\Settings_Page;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Flat, settings-driven calendar element. Mounts the React calendar app via
 * the shared `.js-post-calendar-root` root; data is fetched from the plugin's
 * REST endpoint at runtime.
 */
class Element_Post_Calendar extends \Bricks\Element {
	public $category     = 'general';
	public $name         = 'post-calendar';
	public $icon         = 'ti-calendar';
	public $css_selector = '.post-calendar-element';
	public $nestable     = false;

	private static function get_supported_query_var_keys(): array {
		return Event_Config::get_supported_query_var_keys();
	}

	private static function get_unsupported_query_control_keys(): array {
		return array(
			'objectType',
			'paged',
			'posts_per_page',
			'nopaging',
			'offset',
			'ignore_sticky_posts',
			'post_parent',
			'post_parent__in',
			'post_parent__not_in',
			'post_name__in',
			'author',
			'author_name',
			'exact',
			'sentence',
			'meta_value',
			'meta_value_num',
			'meta_compare',
			'cache_results',
			'update_post_term_cache',
			'update_post_meta_cache',
			'no_found_rows',
			'perm',
			'post_mime_type',
			'comment_count',
			'comment_status',
			'post_comment_status',
			'disable_query_merge',
			'useQueryEditor',
			'queryEditor',
			'signature',
			'user_id',
			'time',
			'no_results_template',
			'no_results_text',
			'is_live_search',
			'is_live_search_wrapper_selector',
			'disable_url_params',
			'infinite_scroll_separator',
			'infinite_scroll',
			'infinite_scroll_margin',
			'infinite_scroll_delay',
			'ajax_loader_animation',
			'ajax_loader_selector',
			'ajax_loader_color',
			'ajax_loader_scale',
			'arrayEditor',
			'pagination_enabled',
			'items_per_page',
		);
	}

	public function get_label() {
		return esc_html__( 'Post Calendar', 'post-calendar' );
	}

	public function get_keywords() {
		return array( 'calendar', 'post', 'events' );
	}

	public function set_control_groups() {
		$this->control_groups['layout'] = array(
			'title' => esc_html__( 'Layout', 'post-calendar' ),
			'tab'   => 'style',
		);

		$this->control_groups['colors'] = array(
			'title' => esc_html__( 'Colors', 'post-calendar' ),
			'tab'   => 'style',
		);
	}

	public function set_controls() {
		$this->controls['query'] = array(
			'tab'     => 'content',
			'label'   => esc_html__( 'Query', 'post-calendar' ),
			'type'    => 'query',
			'popup'   => true,
			'inline'  => true,
			'exclude' => self::get_unsupported_query_control_keys(),
		);

		$this->controls['queryInfo'] = array(
			'tab'     => 'content',
			'type'    => 'info',
			'content' => esc_html__( 'The query popup is limited to the subset the calendar applies: post type, include/exclude, taxonomy, author include/exclude, search, ordering, and date/meta constraints.', 'post-calendar' ),
		);

		$this->controls['defaultView'] = array(
			'tab'     => 'content',
			'label'   => esc_html__( 'Default view', 'post-calendar' ),
			'type'    => 'select',
			'options' => $this->get_view_options(),
			'default' => 'month',
		);

		$this->controls['enabledViews'] = array(
			'tab'      => 'content',
			'label'    => esc_html__( 'Enabled views', 'post-calendar' ),
			'type'     => 'select',
			'multiple' => true,
			'options'  => $this->get_view_options(),
		);

		$this->controls['showToolbar'] = array(
			'tab'     => 'content',
			'label'   => esc_html__( 'Toolbar', 'post-calendar' ),
			'type'    => 'checkbox',
			'default' => true,
		);

		$this->controls['showToolbarActions'] = array(
			'tab'      => 'content',
			'label'    => esc_html__( 'Toolbar: today / back / next', 'post-calendar' ),
			'type'     => 'checkbox',
			'default'  => true,
			'required' => array( 'showToolbar', '=', true ),
		);

		$this->controls['showToolbarLabel'] = array(
			'tab'      => 'content',
			'label'    => esc_html__( 'Toolbar: period label', 'post-calendar' ),
			'type'     => 'checkbox',
			'default'  => true,
			'required' => array( 'showToolbar', '=', true ),
		);

		$this->controls['showViewMenu'] = array(
			'tab'      => 'content',
			'label'    => esc_html__( 'Toolbar: view menu', 'post-calendar' ),
			'type'     => 'checkbox',
			'default'  => true,
			'required' => array( 'showToolbar', '=', true ),
		);

		$this->controls['agendaRangeMode'] = array(
			'tab'     => 'content',
			'label'   => esc_html__( 'Agenda range', 'post-calendar' ),
			'type'    => 'select',
			'options' => array(
				'visible-range'   => esc_html__( 'Visible range', 'post-calendar' ),
				'upcoming-window' => esc_html__( 'Upcoming window', 'post-calendar' ),
			),
			'default' => 'visible-range',
		);

		$this->controls['agendaRangeMonths'] = array(
			'tab'      => 'content',
			'label'    => esc_html__( 'Agenda window (months)', 'post-calendar' ),
			'type'     => 'number',
			'min'      => 1,
			'default'  => 3,
			'required' => array( 'agendaRangeMode', '=', 'upcoming-window' ),
		);

		$this->controls['multiWeeksBefore'] = array(
			'tab'         => 'content',
			'label'       => esc_html__( 'Two-week view: weeks before', 'post-calendar' ),
			'type'        => 'number',
			'min'         => 0,
			'default'     => 0,
			'description' => esc_html__( 'Weeks shown before the current week in the two-week view. 0 = start at the current week.', 'post-calendar' ),
		);

		$this->controls['multiWeeksAfter'] = array(
			'tab'         => 'content',
			'label'       => esc_html__( 'Two-week view: weeks after', 'post-calendar' ),
			'type'        => 'number',
			'min'         => 0,
			'default'     => 1,
			'description' => esc_html__( 'Weeks shown after the current week. Defaults (0/1) render the current and the following week; 1/1 renders three weeks.', 'post-calendar' ),
		);

		$this->controls['timelineDays'] = array(
			'tab'         => 'content',
			'label'       => esc_html__( 'Timeline view: days', 'post-calendar' ),
			'type'        => 'number',
			'min'         => 1,
			'max'         => 120,
			'default'     => 14,
			'description' => esc_html__( 'Number of consecutive days on the timeline axis, starting at the current week.', 'post-calendar' ),
		);

		$this->controls['responsiveBreakpoint'] = array(
			'tab'         => 'content',
			'label'       => esc_html__( 'Responsive breakpoint', 'post-calendar' ),
			'type'        => 'number',
			'units'       => array( 'px' ),
			'min'         => 0,
			'default'     => 640,
			'description' => esc_html__( 'Below this container width, wide time-grid views (two weeks, week, timeline) automatically render as agenda. 0 disables auto-switching.', 'post-calendar' ),
		);

		$this->controls['calendarWidth'] = array(
			'tab'    => 'style',
			'group'  => 'layout',
			'label'  => esc_html__( 'Calendar width', 'post-calendar' ),
			'type'   => 'number',
			'units'  => array( 'px', 'rem', '%' ),
			'min'    => 240,
			'inline' => true,
			'css'    => array(
				array(
					'property' => '--post-calendar-w',
					'selector' => '.post-calendar-element',
				),
			),
		);

		$this->controls['calendarHeight'] = array(
			'tab'    => 'style',
			'group'  => 'layout',
			'label'  => esc_html__( 'Calendar height', 'post-calendar' ),
			'type'   => 'number',
			'units'  => array( 'px', 'rem', 'vh' ),
			'min'    => 320,
			'inline' => true,
			'css'    => array(
				array(
					'property' => '--post-calendar-h',
					'selector' => '.post-calendar-element',
				),
			),
		);

		$this->controls['calendarRadius'] = array(
			'tab'    => 'style',
			'group'  => 'layout',
			'label'  => esc_html__( 'Surface radius', 'post-calendar' ),
			'type'   => 'number',
			'units'  => array( 'px', 'rem' ),
			'min'    => 0,
			'inline' => true,
			'css'    => array(
				array(
					'property' => '--post-calendar-radius-md',
					'selector' => '.post-calendar-element',
				),
			),
		);

		$this->controls['pillRadius'] = array(
			'tab'    => 'style',
			'group'  => 'layout',
			'label'  => esc_html__( 'Button and event radius', 'post-calendar' ),
			'type'   => 'number',
			'units'  => array( 'px', 'rem' ),
			'min'    => 0,
			'inline' => true,
			'css'    => array(
				array(
					'property' => '--post-calendar-radius-sm',
					'selector' => '.post-calendar-element',
				),
			),
		);

		$this->controls['surfaceColor'] = array(
			'tab'   => 'style',
			'group' => 'colors',
			'label' => esc_html__( 'Surface color', 'post-calendar' ),
			'type'  => 'color',
			'css'   => array(
				array(
					'property' => '--post-calendar-surface',
					'selector' => '.post-calendar-element',
				),
			),
		);

		$this->controls['surfaceMutedColor'] = array(
			'tab'   => 'style',
			'group' => 'colors',
			'label' => esc_html__( 'Muted surface color', 'post-calendar' ),
			'type'  => 'color',
			'css'   => array(
				array(
					'property' => '--post-calendar-surface-muted',
					'selector' => '.post-calendar-element',
				),
			),
		);

		$this->controls['borderColor'] = array(
			'tab'   => 'style',
			'group' => 'colors',
			'label' => esc_html__( 'Border color', 'post-calendar' ),
			'type'  => 'color',
			'css'   => array(
				array(
					'property' => '--post-calendar-border',
					'selector' => '.post-calendar-element',
				),
			),
		);

		$this->controls['textColor'] = array(
			'tab'   => 'style',
			'group' => 'colors',
			'label' => esc_html__( 'Body text color', 'post-calendar' ),
			'type'  => 'color',
			'css'   => array(
				array(
					'property' => '--post-calendar-text-default',
					'selector' => '.post-calendar-element',
				),
			),
		);

		$this->controls['mutedTextColor'] = array(
			'tab'   => 'style',
			'group' => 'colors',
			'label' => esc_html__( 'Muted text color', 'post-calendar' ),
			'type'  => 'color',
			'css'   => array(
				array(
					'property' => '--post-calendar-text-muted',
					'selector' => '.post-calendar-element',
				),
				array(
					'property' => '--post-calendar-text-subtle',
					'selector' => '.post-calendar-element',
				),
			),
		);

		$this->controls['pillColor'] = array(
			'tab'   => 'style',
			'group' => 'colors',
			'label' => esc_html__( 'Event pill color', 'post-calendar' ),
			'type'  => 'color',
			'css'   => array(
				array(
					'property' => '--post-calendar-surface-pill',
					'selector' => '.post-calendar-element',
				),
			),
		);

		$this->controls['pillTextColor'] = array(
			'tab'   => 'style',
			'group' => 'colors',
			'label' => esc_html__( 'Event pill text color', 'post-calendar' ),
			'type'  => 'color',
			'css'   => array(
				array(
					'property' => '--post-calendar-text-strong-pill',
					'selector' => '.post-calendar-element',
				),
			),
		);

		$this->controls['accentColor'] = array(
			'tab'   => 'style',
			'group' => 'colors',
			'label' => esc_html__( 'Active button color', 'post-calendar' ),
			'type'  => 'color',
			'css'   => array(
				array(
					'property' => '--post-calendar-surface-active',
					'selector' => '.post-calendar-element',
				),
			),
		);

		$this->controls['accentForegroundColor'] = array(
			'tab'   => 'style',
			'group' => 'colors',
			'label' => esc_html__( 'Active button text color', 'post-calendar' ),
			'type'  => 'color',
			'css'   => array(
				array(
					'property' => '--post-calendar-surface-active-foreground',
					'selector' => '.post-calendar-element',
				),
			),
		);
	}

	private function get_view_options(): array {
		return array(
			'month'    => esc_html__( 'Month', 'post-calendar' ),
			'twoweeks' => esc_html__( 'Two weeks', 'post-calendar' ),
			'week'     => esc_html__( 'Week', 'post-calendar' ),
			'timeline' => esc_html__( 'Timeline', 'post-calendar' ),
			'day'      => esc_html__( 'Day', 'post-calendar' ),
			'agenda'   => esc_html__( 'Agenda', 'post-calendar' ),
			'year'     => esc_html__( 'Year', 'post-calendar' ),
		);
	}

	private function sanitize_view_list( $value ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}

		$allowed = array_keys( $this->get_view_options() );
		$valid   = array_values( array_intersect( $allowed, array_map( 'sanitize_key', $value ) ) );

		return empty( $valid ) ? null : $valid;
	}

	public function render() {
		$plugin = \PostCalendar\Plugin::instance();

		if ( ! $plugin ) {
			return;
		}

		$assets = $plugin->assets();

		if ( ! $assets->has_built_assets() ) {
			echo '<div class="post-calendar-element-placeholder">' . esc_html__( 'The calendar frontend assets are missing. Run the plugin build before using this element.', 'post-calendar' ) . '</div>';
			return;
		}

		$assets->enqueue_calendar_assets();

		$settings = $this->settings;

		$config = array(
			'defaultView'          => sanitize_key( $settings['defaultView'] ?? 'month' ),
			'enabledViews'         => $this->sanitize_view_list( $settings['enabledViews'] ?? null ),
			'showToolbar'          => ! isset( $settings['showToolbar'] ) || ! empty( $settings['showToolbar'] ),
			'showToolbarActions'   => ! isset( $settings['showToolbarActions'] ) || ! empty( $settings['showToolbarActions'] ),
			'showToolbarLabel'     => ! isset( $settings['showToolbarLabel'] ) || ! empty( $settings['showToolbarLabel'] ),
			'showViewMenu'         => ! isset( $settings['showViewMenu'] ) || ! empty( $settings['showViewMenu'] ),
			'agendaRangeMode'      => sanitize_key( $settings['agendaRangeMode'] ?? 'visible-range' ),
			'agendaRangeMonths'    => max( 1, absint( $settings['agendaRangeMonths'] ?? 3 ) ),
			'multiWeeksBefore'     => isset( $settings['multiWeeksBefore'] ) ? max( 0, absint( $settings['multiWeeksBefore'] ) ) : null,
			'multiWeeksAfter'      => isset( $settings['multiWeeksAfter'] ) ? max( 0, absint( $settings['multiWeeksAfter'] ) ) : null,
			'timelineDays'         => isset( $settings['timelineDays'] ) ? min( 120, max( 1, absint( $settings['timelineDays'] ) ) ) : null,
			'responsiveBreakpoint' => isset( $settings['responsiveBreakpoint'] ) ? absint( $settings['responsiveBreakpoint'] ) : null,
		);

		$query_vars = $this->parse_supported_query_vars( $settings['query'] ?? array() );

		if ( ! empty( $query_vars ) ) {
			$config['queryVars'] = $query_vars;
		}

		$config = array_filter(
			$config,
			static function ( $value ) {
				return null !== $value && '' !== $value && array() !== $value;
			}
		);

		$this->set_attribute( '_root', 'class', 'post-calendar-element' );

		// Inject label CSS variables
		$label_css = Settings_Page::get_label_colors_css();
		if ( $label_css ) {
			printf( '<style>.post-calendar-element{%s}</style>', esc_attr( $label_css ) );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bricks render_attributes() returns pre-built HTML attributes.
		echo '<div ' . $this->render_attributes( '_root' ) . '>';
		printf(
			'<div class="js-post-calendar-root" data-config="%s"><div class="post-calendar-element-placeholder">%s</div></div>',
			esc_attr( wp_json_encode( $config ) ),
			esc_html__( 'Loading calendar…', 'post-calendar' )
		);
		echo '</div>';
	}

	public static function render_builder() {
		?>
		<script type="text/x-template" id="tmpl-bricks-element-post-calendar">
													<component :is="tag" class="post-calendar-element"></component>
												</script>
		<?php
	}

	private function parse_supported_query_vars( $query_settings ): array {
		if ( ! is_array( $query_settings ) || empty( $query_settings ) || ! class_exists( '\\Bricks\\Query' ) ) {
			return array();
		}

		$settings   = array(
			'query' => $query_settings,
		);
		$query_vars = \Bricks\Query::prepare_query_vars_from_settings( $settings, $this->id, $this->name, true );

		if ( ! is_array( $query_vars ) || empty( $query_vars ) ) {
			return array();
		}

		$normalized = array();

		foreach ( self::get_supported_query_var_keys() as $key ) {
			if ( ! array_key_exists( $key, $query_vars ) ) {
				continue;
			}

			$normalized[ $key ] = $this->sanitize_query_var_value( $query_vars[ $key ] );
		}

		return array_filter(
			$normalized,
			static function ( $value ) {
				return ! ( is_array( $value ) && empty( $value ) ) && '' !== $value && null !== $value;
			}
		);
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
}
