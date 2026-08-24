<?php

namespace PostCalendar\Admin;

use PostCalendar\Events\Event_Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings_Page {
	private const PAGE_SLUG                = 'post-calendar';
	private const OPTION_NAME              = 'post_calendar_post_types';
	private const LABELS_OPTION_NAME       = 'post_calendar_labels';
	public const SOURCES_OPTION_NAME       = 'post_calendar_sources';
	private const REMOVE_EVENTS_ACTION     = 'post_calendar_remove_events';
	private const REMOVE_EVENTS_NONCE      = 'post_calendar_remove_events_nonce';
	private const REMOVE_EVENTS_POST_FIELD = 'post_calendar_remove_post_type';
	private const NOTICE_POST_TYPE_ARG     = 'post_calendar_post_type';
	private const NOTICE_REMOVED_ARG       = 'post_calendar_removed';
	private const EVENT_ENABLED_META       = Event_Config::EVENT_HAS_EVENTS_META;
	private const EVENTS_META              = Event_Config::EVENTS_META;
	private const EVENT_RANGE_START_META   = Event_Config::EVENT_RANGE_START_META;
	private const EVENT_RANGE_END_META     = Event_Config::EVENT_RANGE_END_META;
	private const EXCLUDED_POST_TYPES      = array(
		'acf-field-group',
		'acf-post-type',
		'acf-taxonomy',
		'acf-ui-options-page',
		'bricks_fonts',
		'bricks_template',
		// Internal virtual type - must never appear as a selectable event source.
		'post_calendar_event',
	);

	private const DEFAULT_LABEL_COLORS = array(
		'#8fc6ff',
		'#ffb366',
		'#a8e6a0',
		'#e0a8ff',
		'#ff8fab',
	);

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_' . self::REMOVE_EVENTS_ACTION, array( $this, 'handle_remove_events_action' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function register_settings(): void {
		register_setting(
			'post_calendar',
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_post_types' ),
				'default'           => self::get_default_post_types(),
			)
		);

		register_setting(
			'post_calendar',
			self::LABELS_OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_labels' ),
				'default'           => array(),
			)
		);

		register_setting(
			'post_calendar',
			self::SOURCES_OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_sources' ),
				'default'           => self::get_default_sources(),
			)
		);
	}

	public function register_menu(): void {
		add_options_page(
			esc_html__( 'Post Calendar', 'post-calendar' ),
			esc_html__( 'Post Calendar', 'post-calendar' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->maybe_add_action_notice();

		$post_types          = self::get_selectable_post_types();
		$selected_post_types = self::get_allowed_post_types();
		$event_counts        = self::get_event_counts_for_post_types( array_keys( $post_types ) );
		$labels              = self::get_labels();
		$sources             = self::get_sources();

		// Discord state. Guilds are intentionally NOT fetched from the API here:
		// that is a slow, blocking network call that hangs the settings page. The
		// saved guild list is rendered as a starting point, and the client refreshes
		// it lazily (and only when the Discord section is used) via the /discord/guilds
		// REST endpoint.
		$discord_guilds     = $sources['discord']['guilds'] ?? array();
		$discord_configured = \PostCalendar\Integrations\Discord\Discord_Client::is_configured();

		// Compute overall statistics.
		$total_wp_events      = array_sum( $event_counts );
		$total_ical_feeds     = count( $sources['ical_feeds'] ?? array() );
		$total_discord_guilds = count(
			array_filter(
				$discord_guilds,
				static function ( $guild ): bool {
					return ! empty( $guild['enabled'] );
				}
			)
		);

		// Enqueue the React settings app.
		$assets = new \PostCalendar\Assets();
		if ( $assets->has_settings_built_assets() ) {
			$settings_post_types = array();
			foreach ( $post_types as $pt ) {
				$settings_post_types[] = array(
					'name'         => $pt->name,
					'label'        => $pt->label,
					'singularName' => '' !== $pt->labels->singular_name ? $pt->labels->singular_name : $pt->label,
					'eventCount'   => $event_counts[ $pt->name ] ?? 0,
					'enabled'      => in_array( $pt->name, $selected_post_types, true ),
				);
			}

			$assets->enqueue_settings_assets(
				array(
					'postTypes'           => $settings_post_types,
					'icalFeeds'           => $sources['ical_feeds'] ?? array(),
					'sourcesOptionName'   => self::SOURCES_OPTION_NAME,
					'postTypesOptionName' => self::OPTION_NAME,
					'discordConfigured'   => $discord_configured,
					'discordGuilds'       => $discord_guilds,
					'restUrl'             => esc_url_raw( rest_url( \PostCalendar\Rest\Rest_Controller::REST_NAMESPACE ) ),
					'restNonce'           => wp_create_nonce( 'wp_rest' ),
					'discordGuildsRoute'  => \PostCalendar\Rest\Rest_Controller::DISCORD_GUILDS_ROUTE,
					'statistics'          => array(
						'totalWpEvents'      => $total_wp_events,
						'totalIcalFeeds'     => $total_ical_feeds,
						'totalDiscordGuilds' => $total_discord_guilds,
					),
					'strings'             => $this->get_settings_strings(),
				)
			);
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Post Calendar', 'post-calendar' ); ?></h1>

			<?php settings_errors( 'post_calendar' ); ?>

			<form action="options.php" method="post">
				<?php settings_fields( 'post_calendar' ); ?>

				<?php if ( $assets->has_settings_built_assets() ) : ?>
					<div class="js-post-calendar-settings-root"></div>
				<?php else : ?>
					<noscript>
						<p><?php echo esc_html__( 'JavaScript is required to manage Post Calendar settings.', 'post-calendar' ); ?></p>
					</noscript>
				<?php endif; ?>

				<?php submit_button( $this->get_settings_strings()['save'] ?? __( 'Save Changes', 'post-calendar' ) ); ?>
			</form>
		</div>
		<?php
	}

	public function handle_remove_events_action(): void {
		if ( ! isset( $_POST[ self::REMOVE_EVENTS_POST_FIELD ] ) ) {
			$this->redirect_with_notice( 'invalid-post-type' );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to manage Post Calendar settings.', 'post-calendar' ),
				esc_html__( 'Forbidden', 'post-calendar' ),
				array(
					'response' => 403,
				)
			);
		}

		check_admin_referer( self::REMOVE_EVENTS_ACTION, self::REMOVE_EVENTS_NONCE );

		$post_type  = sanitize_key( wp_unslash( $_POST[ self::REMOVE_EVENTS_POST_FIELD ] ) );
		$post_types = self::get_selectable_post_types();

		if ( ! isset( $post_types[ $post_type ] ) ) {
			$this->redirect_with_notice( 'invalid-post-type' );
		}

		$removed_count = $this->clear_event_meta_for_post_type( $post_type );

		$this->redirect_with_notice(
			'events-cleared',
			array(
				self::NOTICE_REMOVED_ARG   => $removed_count,
				self::NOTICE_POST_TYPE_ARG => $post_type,
			)
		);
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only GET parameters for admin notice display; no form processing.
	private function maybe_add_action_notice(): void {
		if ( ! isset( $_GET['post_calendar_notice'] ) ) {
			return;
		}

		$notice     = sanitize_key( wp_unslash( $_GET['post_calendar_notice'] ) );
		$post_type  = isset( $_GET[ self::NOTICE_POST_TYPE_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::NOTICE_POST_TYPE_ARG ] ) ) : '';
		$post_types = self::get_selectable_post_types();
		$label      = isset( $post_types[ $post_type ] ) ? ( '' !== $post_types[ $post_type ]->labels->singular_name ? $post_types[ $post_type ]->labels->singular_name : $post_types[ $post_type ]->label ) : $post_type;

		if ( 'events-cleared' === $notice ) {
			$removed = isset( $_GET[ self::NOTICE_REMOVED_ARG ] ) ? absint( wp_unslash( $_GET[ self::NOTICE_REMOVED_ARG ] ) ) : 0;

			add_settings_error(
				'post_calendar',
				'post_calendar_events_cleared',
				sprintf(
					/* translators: 1: number of posts cleared, 2: post type label. */
					esc_html__( 'Removed all event data from %1$d posts in %2$s.', 'post-calendar' ),
					$removed,
					$label
				),
				'updated'
			);

			return;
		}

		if ( 'invalid-post-type' === $notice ) {
			add_settings_error(
				'post_calendar',
				'post_calendar_invalid_post_type',
				esc_html__( 'That post type cannot be managed from Post Calendar.', 'post-calendar' ),
				'error'
			);
		}
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	/**
	 * Normalises an array or a comma-separated string into a clean list of post-type slugs.
	 * Each slug is run through sanitize_key; empty results are dropped.
	 */
	public static function sanitize_slug_list( $input ): array {
		if ( is_array( $input ) ) {
			return array_values( array_filter( array_map( 'sanitize_key', $input ) ) );
		}

		if ( ! is_string( $input ) || '' === trim( $input ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map( 'sanitize_key', array_map( 'trim', explode( ',', $input ) ) )
			)
		);
	}

	public function sanitize_post_types( $post_types ): array {
		$available_types = array_keys( self::get_selectable_post_types() );

		return array_values( array_intersect( $available_types, self::sanitize_slug_list( $post_types ) ) );
	}

	public function sanitize_labels( $labels ): array {
		if ( ! is_array( $labels ) ) {
			return array();
		}

		$sanitized = array();
		foreach ( $labels as $label ) {
			if ( ! is_array( $label ) ) {
				continue;
			}

			$id    = isset( $label['id'] ) ? sanitize_key( (string) $label['id'] ) : '';
			$name  = isset( $label['name'] ) ? sanitize_text_field( (string) $label['name'] ) : '';
			$color = isset( $label['color'] ) ? sanitize_hex_color( (string) $label['color'] ) : '';

			if ( '' === $id || '' === $name ) {
				continue;
			}

			if ( ! $color ) {
				$color = self::DEFAULT_LABEL_COLORS[ count( $sanitized ) % count( self::DEFAULT_LABEL_COLORS ) ];
			}

			$sanitized[] = array(
				'id'    => $id,
				'name'  => $name,
				'color' => $color,
			);
		}

		return $sanitized;
	}

	public function sanitize_sources( $sources ): array {
		if ( ! is_array( $sources ) ) {
			return self::get_default_sources();
		}

		$sanitized = array(
			'ical_feeds' => array(),
			'discord'    => array(
				'guilds' => array(),
			),
		);

		if ( isset( $sources['ical_feeds'] ) && is_array( $sources['ical_feeds'] ) ) {
			foreach ( $sources['ical_feeds'] as $feed ) {
				if ( ! is_array( $feed ) ) {
					continue;
				}

				$id   = isset( $feed['id'] ) ? sanitize_key( (string) $feed['id'] ) : '';
				$name = isset( $feed['name'] ) ? sanitize_text_field( (string) $feed['name'] ) : '';
				$url  = isset( $feed['url'] ) ? esc_url_raw( (string) $feed['url'] ) : '';

				if ( '' === $id || '' === $name || '' === $url ) {
					continue;
				}

				if ( ! self::is_safe_url( $url ) ) {
					continue;
				}

				$sanitized['ical_feeds'][] = array(
					'id'      => $id,
					'name'    => $name,
					'url'     => $url,
					'color'   => isset( $feed['color'] ) ? sanitize_hex_color( (string) $feed['color'] ) : '',
					'enabled' => ! empty( $feed['enabled'] ),
				);
			}
		}

		// Build the enabled-state map from submitted guilds.
		$submitted_enabled = array();
		if ( isset( $sources['discord'] ) && is_array( $sources['discord'] ) ) {
			$discord          = $sources['discord'];
			$submitted_guilds = array();

			if ( isset( $discord['guilds'] ) && is_array( $discord['guilds'] ) ) {
				foreach ( $discord['guilds'] as $guild ) {
					if ( ! is_array( $guild ) ) {
						break;
					}

					$guild_id   = isset( $guild['guild_id'] ) ? sanitize_key( (string) $guild['guild_id'] ) : '';
					$guild_name = isset( $guild['name'] ) ? sanitize_text_field( (string) $guild['name'] ) : '';

					if ( '' === $guild_id ) {
						continue;
					}

					$submitted_guilds[] = array(
						'guild_id' => $guild_id,
						'name'     => $guild_name,
						'enabled'  => ! empty( $guild['enabled'] ),
					);
				}

				// Handle keyed format: discord[guilds][12345] = {guild_id, enabled}.
				if ( empty( $submitted_guilds ) ) {
					foreach ( $discord['guilds'] as $key => $guild ) {
						if ( ! is_array( $guild ) ) {
							continue;
						}
						$guild_id = isset( $guild['guild_id'] ) ? sanitize_key( (string) $guild['guild_id'] ) : sanitize_key( (string) $key );
						if ( '' === $guild_id ) {
							continue;
						}
						$submitted_guilds[] = array(
							'guild_id' => $guild_id,
							'name'     => isset( $guild['name'] ) ? sanitize_text_field( (string) $guild['name'] ) : '',
							'enabled'  => ! empty( $guild['enabled'] ),
						);
					}
				}
			}

			// Persist the submitted Discord guilds verbatim. No Discord API call is
			// made here: guild discovery happens lazily via the GET /discord/guilds
			// REST endpoint (opened from the client's add-source menu), so saving
			// the settings page never blocks on the network.
			$sanitized['discord']['guilds'] = $submitted_guilds;
		}

		return $sanitized;
	}

	public static function get_sources(): array {
		$saved = get_option( self::SOURCES_OPTION_NAME, array() );

		if ( ! is_array( $saved ) ) {
			return self::get_default_sources();
		}

		return wp_parse_args( $saved, self::get_default_sources() );
	}

	public static function get_default_sources(): array {
		return array(
			'ical_feeds' => array(),
			'discord'    => array(
				'guilds' => array(),
			),
		);
	}

	/**
	 * Refreshes the Discord guild list from the API and saves it.
	 *
	 * Merges with existing guilds to preserve enabled state.
	 * Optionally accepts an enabled-state map to override the stored values.
	 *
	 * @param array $enabled_map  Optional. Map of guild_id => enabled (bool).
	 * @return array  The refreshed guild list.
	 */
	public static function refresh_discord_guilds( array $enabled_map = array() ): array {
		$token = \PostCalendar\Integrations\Discord\Discord_Client::get_bot_token();
		if ( '' === $token ) {
			return array();
		}

		$api_guilds = \PostCalendar\Integrations\Discord\Discord_Client::get_bot_guilds( $token );
		if ( ! is_array( $api_guilds ) ) {
			return array();
		}

		// Load existing guilds to preserve enabled state.
		$sources         = self::get_sources();
		$existing_guilds = array();
		foreach ( $sources['discord']['guilds'] as $eg ) {
			if ( ! empty( $eg['guild_id'] ) ) {
				$existing_guilds[ $eg['guild_id'] ] = $eg;
			}
		}

		// Build fresh list from API response.
		$refreshed = array();
		foreach ( $api_guilds as $api_guild ) {
			$gid = isset( $api_guild['id'] ) ? sanitize_key( (string) $api_guild['id'] ) : '';
			if ( '' === $gid ) {
				continue;
			}

			// Priority: explicit enabled_map > existing stored state > default false.
			if ( isset( $enabled_map[ $gid ] ) ) {
				$enabled = (bool) $enabled_map[ $gid ];
			} else {
				$enabled = ! empty( $existing_guilds[ $gid ]['enabled'] );
			}

			$refreshed[] = array(
				'guild_id' => $gid,
				'name'     => isset( $api_guild['name'] ) ? sanitize_text_field( (string) $api_guild['name'] ) : '',
				'enabled'  => $enabled,
			);
		}

		// Save the refreshed list.
		$sources['discord']['guilds'] = $refreshed;
		update_option( self::SOURCES_OPTION_NAME, $sources, false );

		return $refreshed;
	}

	public static function get_labels(): array {
		$saved = get_option( self::LABELS_OPTION_NAME, array() );

		if ( ! is_array( $saved ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$saved,
				static function ( $label ) {
					return is_array( $label ) && isset( $label['id'], $label['name'], $label['color'] );
				}
			)
		);
	}

	public static function get_label_by_id( string $id ): ?array {
		foreach ( self::get_labels() as $label ) {
			if ( $label['id'] === $id ) {
				return $label;
			}
		}

		return null;
	}

	public static function get_label_colors_css(): string {
		$labels = self::get_labels();
		if ( empty( $labels ) ) {
			return '';
		}

		$css = '';
		foreach ( $labels as $label ) {
			$css_var = '--post-calendar-label-' . sanitize_html_class( $label['id'] );
			$css    .= "$css_var:" . esc_attr( $label['color'] ) . ';';
		}

		return $css;
	}

	public static function get_allowed_post_types(): array {
		$available_types = array_keys( self::get_selectable_post_types() );
		$saved_types     = get_option( self::OPTION_NAME, null );

		if ( null === $saved_types ) {
			return self::get_default_post_types();
		}

		return array_values( array_intersect( $available_types, self::sanitize_slug_list( $saved_types ) ) );
	}

	public static function get_event_source_post_types(): array {
		$selectable_types = array_keys( self::get_selectable_post_types() );
		$source_types     = apply_filters( 'post_calendar_event_source_post_types', $selectable_types );

		if ( ! is_array( $source_types ) ) {
			return array();
		}

		return array_values( array_intersect( $selectable_types, self::sanitize_slug_list( $source_types ) ) );
	}

	public static function resolve_event_source_post_types( $requested_post_types ): array {
		$source_types = self::get_event_source_post_types();

		if ( empty( $source_types ) ) {
			return array();
		}

		$requested = self::sanitize_slug_list( $requested_post_types );

		if ( empty( $requested ) ) {
			return $source_types;
		}

		return array_values( array_intersect( $source_types, $requested ) );
	}

	public static function get_selectable_post_types(): array {
		$post_types = get_post_types(
			array(
				'show_ui' => true,
			),
			'objects'
		);

		$selectable_post_types = array_filter(
			$post_types,
			static function ( $post_type ): bool {
				return self::is_selectable_post_type( $post_type );
			}
		);

		uksort(
			$selectable_post_types,
			static function ( string $left, string $right ): int {
				$order = array(
					'post' => 0,
					'page' => 1,
				);

				$left_order  = $order[ $left ] ?? 2;
				$right_order = $order[ $right ] ?? 2;

				if ( $left_order === $right_order ) {
					return strcmp( $left, $right );
				}

				return $left_order <=> $right_order;
			}
		);

		return $selectable_post_types;
	}

	private static function is_selectable_post_type( $post_type ): bool {
		if ( ! is_object( $post_type ) || empty( $post_type->name ) ) {
			return false;
		}

		if ( in_array( $post_type->name, array( 'post', 'page' ), true ) ) {
			return true;
		}

		if ( ! empty( $post_type->_builtin ) ) {
			return false;
		}

		if ( in_array( $post_type->name, self::get_excluded_post_types(), true ) ) {
			return false;
		}

		if ( ! self::post_type_supports_content( $post_type->name ) ) {
			return false;
		}

		return (bool) apply_filters( 'post_calendar_is_selectable_post_type', true, $post_type );
	}

	private static function post_type_supports_content( string $post_type ): bool {
		$content_features = array(
			'title',
			'editor',
			'excerpt',
			'thumbnail',
			'custom-fields',
			'author',
			'comments',
			'page-attributes',
		);

		foreach ( $content_features as $feature ) {
			if ( post_type_supports( $post_type, $feature ) ) {
				return true;
			}
		}

		return false;
	}

	private static function get_excluded_post_types(): array {
		$excluded_post_types = apply_filters( 'post_calendar_excluded_post_types', self::EXCLUDED_POST_TYPES );

		if ( ! is_array( $excluded_post_types ) ) {
			return self::EXCLUDED_POST_TYPES;
		}

		return array_values( array_filter( array_map( 'sanitize_key', $excluded_post_types ) ) );
	}

	private function clear_event_meta_for_post_type( string $post_type ): int {
		$query = new \WP_Query(
			array(
				'post_type'              => $post_type,
				'post_status'            => self::get_bulk_action_post_statuses(),
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					'relation' => 'OR',
					array(
						'key'   => self::EVENT_ENABLED_META,
						'value' => '1',
					),
					array(
						'key'     => self::EVENTS_META,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$removed_count = 0;

		foreach ( $query->posts as $post_id ) {
			$this->clear_event_meta_for_post( (int) $post_id );
			++$removed_count;
		}

		return $removed_count;
	}

	private function clear_event_meta_for_post( int $post_id ): void {
		foreach ( self::get_event_meta_keys() as $meta_key ) {
			delete_post_meta( $post_id, $meta_key );
		}

		$post_meta = get_post_meta( $post_id );

		if ( ! is_array( $post_meta ) ) {
			return;
		}

		foreach ( array_keys( $post_meta ) as $meta_key ) {
			if ( 0 === strpos( (string) $meta_key, self::EVENTS_META . '_' ) || 0 === strpos( (string) $meta_key, '_' . self::EVENTS_META . '_' ) ) {
				delete_post_meta( $post_id, (string) $meta_key );
			}
		}
	}

	private static function get_event_counts_for_post_types( array $post_types ): array {
		global $wpdb;

		$post_types = array_values( array_filter( array_map( 'sanitize_key', $post_types ) ) );
		$counts     = array_fill_keys( $post_types, 0 );

		if ( empty( $post_types ) ) {
			return $counts;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $post_types are sanitized via sanitize_key(); IN clause uses dynamic placeholder count.
		$query = $wpdb->prepare(
			"SELECT posts.post_type, COUNT(DISTINCT posts.ID) AS event_count
			FROM {$wpdb->posts} AS posts
			INNER JOIN {$wpdb->postmeta} AS postmeta
				ON posts.ID = postmeta.post_id
			WHERE posts.post_type IN ($placeholders)
				AND postmeta.meta_key = %s
				AND postmeta.meta_value = %s
				AND posts.post_status NOT IN ('auto-draft', 'trash', 'inherit')
			GROUP BY posts.post_type",
			array_merge( $post_types, array( self::EVENT_ENABLED_META, '1' ) )
		);

		$results = $wpdb->get_results( $query );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		if ( ! is_array( $results ) ) {
			return $counts;
		}

		foreach ( $results as $result ) {
			if ( empty( $result->post_type ) ) {
				continue;
			}

			$counts[ $result->post_type ] = isset( $result->event_count ) ? (int) $result->event_count : 0;
		}

		return $counts;
	}

	private function redirect_with_notice( string $notice, array $args = array() ): void {
		wp_safe_redirect(
			self::get_settings_page_url(
				array_merge(
					$args,
					array(
						'post_calendar_notice' => $notice,
					)
				)
			)
		);
		exit;
	}

	private static function get_event_meta_keys(): array {
		return array(
			self::EVENT_ENABLED_META,
			self::EVENTS_META,
			self::EVENT_RANGE_START_META,
			self::EVENT_RANGE_END_META,
		);
	}

	private static function get_bulk_action_post_statuses(): array {
		$post_statuses = array_keys( get_post_stati() );

		return array_values( array_diff( $post_statuses, array( 'auto-draft', 'trash', 'inherit' ) ) );
	}

	private static function get_settings_page_url( array $args = array() ): string {
		$url = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );

		if ( empty( $args ) ) {
			return $url;
		}

		return add_query_arg( $args, $url );
	}

	private static function get_default_post_types(): array {
		return array();
	}

	/**
	 * Validates a URL for SSRF safety before saving as an iCal feed source.
	 *
	 * Checks: scheme (HTTPS required), hostname resolution (blocks private/metadata IPs).
	 */
	public static function is_safe_url( string $url ): bool {
		$parsed = wp_parse_url( $url );

		if ( ! $parsed || empty( $parsed['host'] ) ) {
			return false;
		}

		// Scheme: HTTPS only (allow HTTP via filter for local dev).
		$scheme = strtolower( $parsed['scheme'] ?? '' );
		if ( 'https' !== $scheme ) {
			/**
			 * Allows insecure (non-HTTPS) feed URLs to be saved as iCal sources.
			 *
			 * Intended for local development only. Returning true also permits
			 * plain-HTTP schemes, but never overrides the SSRF checks below.
			 *
			 * @since 0.5.2
			 *
			 * @return bool Whether HTTP feed URLs are permitted.
			 */
			if ( 'http' !== $scheme || ! apply_filters( 'post_calendar_allow_insecure_feed_urls', false ) ) {
				return false;
			}
		}

		$host = $parsed['host'];

		// Block IPv6 loopback and unspecified.
		if ( str_starts_with( $host, '[' ) ) {
			$clean = trim( $host, '[]' );
			if ( in_array( $clean, array( '::1', '::', '0:0:0:0:0:0:0:0' ), true ) ) {
				return false;
			}
			return true;
		}

		// Block known private/metadata IPv4 ranges.
		$ip = @inet_pton( $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- inet_pton may not exist on all systems; false fallback handled below.
		if ( false !== $ip ) {
			// Pack the IP for comparison.
			$packed = $ip;

			// 127.0.0.0/8 — loopback.
			if ( substr( $packed, 0, 1 ) === "\x7f" ) {
				return false;
			}

			// 10.0.0.0/8 — private.
			if ( substr( $packed, 0, 1 ) === "\x0a" ) {
				return false;
			}

			// 172.16.0.0/12 — private.
			if ( substr( $packed, 0, 2 ) === "\xac\x10" ) {
				return false;
			}

			// 192.168.0.0/16 — private.
			if ( substr( $packed, 0, 2 ) === "\xc0\xa8" ) {
				return false;
			}

			// 169.254.0.0/16 — link-local / metadata.
			if ( substr( $packed, 0, 2 ) === "\xa9\xfe" ) {
				return false;
			}

			// 0.0.0.0/8 — current network.
			if ( substr( $packed, 0, 1 ) === "\x00" ) {
				return false;
			}
		}

		// Block reserved/localhost hostnames.
		$reserved = array( 'localhost', 'localhost.localdomain', 'local', 'ip6-localhost', 'ip6-loopback' );
		$reversed = strrev( $host );
		foreach ( $reserved as $name ) {
			if ( $host === $name || str_ends_with( $reversed, strrev( '.' . $name ) ) ) {
				return false;
			}
		}

		return true;
	}

	private function get_settings_strings(): array {
		return array(
			'eventSourcesTitle'    => esc_html__( 'Event Sources', 'post-calendar' ),
			'sourcesHeaderSummary' => esc_html__( 'Event sources provide data to the calendar.', 'post-calendar' ),
			// translators: %d: number of events.
			'totalEvents'          => esc_html( _n( '%d total event', '%d total events', 1, 'post-calendar' ) ),
			// translators: %d: number of events from posts.
			'byPostType'           => esc_html( _n( '%d from posts', '%d from posts', 1, 'post-calendar' ) ),
			// translators: %d: number of iCal feeds.
			'byIcal'               => esc_html( _n( '%d iCal feed', '%d iCal feeds', 1, 'post-calendar' ) ),
			// translators: %d: number of Discord servers.
			'byDiscord'            => esc_html( _n( '%d Discord server', '%d Discord servers', 1, 'post-calendar' ) ),
			'noSources'            => esc_html__( 'No event sources configured. Add a source to get started.', 'post-calendar' ),
			'addSource'            => esc_html__( 'Add source', 'post-calendar' ),
			'addPostType'          => esc_html__( 'Add post type', 'post-calendar' ),
			'addIcalFeed'          => esc_html__( 'Add iCal feed', 'post-calendar' ),
			'addDiscordGuild'      => esc_html__( 'Add Discord server', 'post-calendar' ),
			'removeSource'         => esc_html__( 'Remove', 'post-calendar' ),
			'save'                 => esc_html__( 'Save Changes', 'post-calendar' ),
			'discordConnected'     => esc_html__( 'Discord connected', 'post-calendar' ),
			'discordNotConfigured' => esc_html__( 'Discord not configured', 'post-calendar' ),
			// translators: %d: number of Discord servers.
			'discordServers'       => esc_html( _n( '%d server', '%d servers', 1, 'post-calendar' ) ),
		);
	}
}