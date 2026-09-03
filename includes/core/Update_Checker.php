<?php

namespace PostCalendar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks the monorepo's GitHub Releases for a newer stable version of the
 * plugin and surfaces the standard WordPress update notice, including one-click
 * auto-install.
 *
 * All plugins in the monorepo are released together on a single GitHub release
 * tag, with one zip asset per plugin named "<slug>-<version>.zip" (for example
 * `post-calendar-0.5.2.zip`). This checker:
 *  1. Hooks into `pre_set_site_transient_update_plugins` to inject update data
 *     whenever WordPress refreshes its plugin update cache.
 *  2. Fetches the monorepo's latest release and picks the asset whose name
 *     starts with this plugin's slug, reading the per-plugin version straight
 *     from the asset filename.
 *  3. Compares that version to the installed plugin version and, if newer,
 *     points `package` at the asset download URL so WordPress can install the
 *     update automatically (or falls back to a manual download link).
 *
 * The GitHub API response is cached in a separate transient so we don't hit the
 * API on every admin page load (TTL: 12 h on success, 1 h on failure).
 */
class Update_Checker {

	/**
	 * The GitHub monorepo that hosts releases for all plugins.
	 *
	 * @var string
	 */
	const GITHUB_REPO = 'spill-the-ink/wp-plugins';

	/**
	 * Slug prefix used to match this plugin's zip asset on the release.
	 *
	 * @var string
	 */
	const ASSET_SLUG = 'post-calendar';

	const TRANSIENT_KEY = 'post_calendar_github_update';
	const PLUGIN_SLUG    = 'post-calendar/post-calendar.php';

	public function __construct() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_action( 'in_plugin_update_message-' . self::PLUGIN_SLUG, array( $this, 'update_message' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'clear_transient' ), 10, 2 );
	}

	// -------------------------------------------------------------------------
	// GitHub API
	// -------------------------------------------------------------------------

	private function github_api_url(): string {
		return 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest';
	}

	private function github_releases_url(): string {
		return 'https://github.com/' . self::GITHUB_REPO . '/releases';
	}

	/**
	 * Finds this plugin's zip asset on a release and returns its download URL
	 * and per-plugin version (parsed from the asset filename).
	 *
	 * @param  object $release Decoded GitHub release object.
	 * @return array{url:string,version:string}|null URL + version, or null.
	 */
	private function find_plugin_asset( object $release ): ?array {
		if ( empty( $release->assets ) ) {
			return null;
		}
		foreach ( $release->assets as $asset ) {
			if ( ! isset( $asset->name ) || ! is_string( $asset->name ) ) {
				continue;
			}

			$name = $asset->name;

			// Match "<slug>-<version>.zip".
			if ( 0 !== strpos( $name, self::ASSET_SLUG . '-' ) || ! $this->has_zip_suffix( $name ) ) {
				continue;
			}

			$version = substr( $name, strlen( self::ASSET_SLUG ) + 1, -4 );
			if ( '' === $version ) {
				continue;
			}

			return array(
				'url'     => $asset->browser_download_url ?? '',
				'version' => $version,
			);
		}
		return null;
	}

	private function has_zip_suffix( string $value ): bool {
		return strlen( $value ) >= 4 && 0 === substr_compare( $value, '.zip', -4, 4 );
	}

	/**
	 * Fetches the latest stable release from the monorepo, with transient
	 * caching.
	 *
	 * Returns null when the fetch fails or the latest release is a pre-release
	 * or draft.
	 *
	 * @return object|null Decoded JSON body of the GitHub release, or null.
	 */
	public function fetch_github_release(): ?object {
		$cached = get_transient( self::TRANSIENT_KEY );

		// Transient present and not the failure sentinel ('none').
		if ( false !== $cached ) {
			return ( 'none' === $cached ) ? null : $cached;
		}

		$response = wp_remote_get(
			$this->github_api_url(),
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github.v3+json',
					'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
				),
			),
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			// Cache failure for 1 hour to avoid hammering the API.
			set_transient( self::TRANSIENT_KEY, 'none', HOUR_IN_SECONDS );
			return null;
		}

		$release = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $release ) || ! is_object( $release ) || ! property_exists( $release, 'tag_name' ) ) {
			set_transient( self::TRANSIENT_KEY, 'none', HOUR_IN_SECONDS );
			return null;
		}

		// Skip pre-releases and drafts — stable releases only.
		if ( ! empty( $release->prerelease ) || ! empty( $release->draft ) ) {
			set_transient( self::TRANSIENT_KEY, 'none', HOUR_IN_SECONDS );
			return null;
		}

		// Cache the decoded release object for 12 hours.
		set_transient( self::TRANSIENT_KEY, $release, 12 * HOUR_IN_SECONDS );

		return $release;
	}

	// -------------------------------------------------------------------------
	// WordPress update hooks
	// -------------------------------------------------------------------------

	/**
	 * Injects update data into the WordPress plugin update transient when a
	 * newer release of this plugin is detected on the monorepo.
	 *
	 * @param  object $transient The update_plugins site transient.
	 * @return object            Modified transient.
	 */
	public function check_for_update( object $transient ): object {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->fetch_github_release();
		if ( null === $release ) {
			return $transient;
		}

		$asset = $this->find_plugin_asset( $release );
		if ( null === $asset ) {
			return $transient;
		}

		$details = (object) array(
			'id'           => self::PLUGIN_SLUG,
			'slug'         => 'post-calendar',
			'plugin'       => self::PLUGIN_SLUG,
			'new_version'  => $asset['version'],
			'url'          => esc_url( $release->html_url ?? $this->github_releases_url() ),
			// When a ZIP asset is attached, WordPress can install it automatically.
			'package'      => $asset['url'] ?: false,
			'tested'       => '',
			'requires_php' => '7.4',
		);

		// Always place the plugin in either response or no_update so WordPress
		// treats it as update-supported (this is what enables the "Enable/Disable
		// auto-updates" toggle on the Plugins screen). Mirroring core's own
		// Update URI handling, put it in response when a newer version exists and
		// in no_update when it is already current.
		if ( version_compare( $asset['version'], POST_CALENDAR_VERSION, '>' ) ) {
			$transient->response[ self::PLUGIN_SLUG ] = $details;
		} else {
			$transient->no_update[ self::PLUGIN_SLUG ] = $details;
		}

		return $transient;
	}

	/**
	 * Populates the "View version X.X details" modal in the Plugins list.
	 *
	 * @param  false|object|array $result The result object/array. Default false.
	 * @param  string             $action The type of information being requested.
	 * @param  object             $args   Plugin API arguments.
	 * @return false|object               Modified result.
	 */
	public function plugin_info( $result, string $action, object $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || 'post-calendar' !== $args->slug ) {
			return $result;
		}

		$release = $this->fetch_github_release();
		if ( null === $release ) {
			return $result;
		}

		$asset         = $this->find_plugin_asset( $release );
		$releases_url  = esc_url( $this->github_releases_url() );
		$download_link = ( null !== $asset && $asset['url'] ) ? $asset['url'] : '';

		// Use the GitHub release body as the changelog if available.
		$changelog = ! empty( $release->body )
			? '<pre>' . esc_html( $release->body ) . '</pre>'
			: '<p>' . esc_html__( 'See the GitHub releases page for the full changelog.', 'post-calendar' ) . '</p>';

		$info = (object) array(
			'name'          => 'Post Calendar',
			'slug'          => 'post-calendar',
			'version'       => ( null !== $asset ) ? $asset['version'] : POST_CALENDAR_VERSION,
			'author'        => '<a href="https://github.com/achtender" target="_blank">Achtender</a>',
			'homepage'      => $releases_url,
			'requires'      => '6.0',
			'requires_php'  => '7.4',
			'download_link' => $download_link ?: false,
			'sections'      => array(
				'description' => '<p>' . esc_html__( 'Display posts as events in a calendar via Bricks or shortcode, using existing post types and the built-in Post Calendar editor or direct event meta.', 'post-calendar' ) . '</p>'
					. '<p><a href="' . $releases_url . '" target="_blank">' . esc_html__( 'View all releases on GitHub', 'post-calendar' ) . '</a></p>',
				'changelog'   => $changelog,
			),
		);

		return $info;
	}

	/**
	 * Shown only when no ZIP asset is attached to the release (package === false).
	 * Appends a manual download link so the user still has a clear path to update.
	 *
	 * @param array  $plugin_data Plugin metadata.
	 * @param object $response    Update response data.
	 */
	public function update_message( array $plugin_data, object $response ): void {
		// When a ZIP package is available WordPress shows its own "Update Now"
		// button — no extra message needed.
		if ( ! empty( $response->package ) ) {
			return;
		}

		$releases_url = esc_url( $this->github_releases_url() );
		printf(
			' <a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $releases_url is already escaped via esc_url().
			$releases_url,
			esc_html__( 'Download from GitHub ↗', 'post-calendar' ),
		);
	}

	/**
	 * Clears the cached GitHub release data after any plugin update so the next
	 * check fetches fresh data.
	 *
	 * @param \WP_Upgrader $upgrader Upgrader instance.
	 * @param array        $options  Update options.
	 */
	public function clear_transient( $upgrader, array $options ): void {
		if ( 'update' === ( $options['action'] ?? '' ) && 'plugin' === ( $options['type'] ?? '' ) ) {
			delete_transient( self::TRANSIENT_KEY );
		}
	}
}
