<?php

namespace PostCalendar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks GitHub Releases for a newer stable version of the plugin and surfaces
 * the standard WordPress update notice, including one-click auto-install.
 *
 * How it works:
 *  1. Hooks into `pre_set_site_transient_update_plugins` to inject update data
 *     whenever WordPress refreshes its plugin update cache.
 *  2. The GitHub API response is itself cached in a separate transient so we
 *     don't hit the API on every admin page load (TTL: 12 h on success, 1 h
 *     on failure / empty response).
 *  3. When a `.zip` release asset is found, `package` is set to its download
 *     URL so WordPress can install the update automatically. If no asset is
 *     attached, `package` falls back to `false` and a manual download link is
 *     shown instead.
 *
 * Release requirement: each GitHub release must have the plugin ZIP (built via
 * `yarn build:zip`) uploaded as a release asset before being published.
 */
class Update_Checker {

	const TRANSIENT_KEY  = 'post_calendar_github_update';
	const GITHUB_API_URL = 'https://api.github.com/repos/achtender/post-calendar/releases/latest';
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

	/**
	 * Returns the browser_download_url of the first .zip asset attached to a
	 * release, or null if none is present.
	 *
	 * @param  object $release Decoded GitHub release object.
	 * @return string|null
	 */
	private function get_zip_url( object $release ): ?string {
		if ( empty( $release->assets ) ) {
			return null;
		}
		foreach ( $release->assets as $asset ) {
			if ( isset( $asset->name ) && $this->has_zip_suffix( (string) $asset->name ) ) {
				return $asset->browser_download_url;
			}
		}
		return null;
	}

	private function has_zip_suffix( string $value ): bool {
		return strlen( $value ) >= 4 && 0 === substr_compare( $value, '.zip', -4, 4 );
	}

	/**
	 * Fetches the latest stable release from GitHub, with transient caching.
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
			self::GITHUB_API_URL,
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
	 * newer GitHub release is detected.
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

		// Strip a leading "v" so "v1.2.3" compares cleanly with "1.2.3".
		$github_version = ltrim( $release->tag_name, 'v' );

		if ( version_compare( $github_version, POST_CALENDAR_VERSION, '>' ) ) {
			$zip_url = $this->get_zip_url( $release );

			$transient->response[ self::PLUGIN_SLUG ] = (object) array(
				'id'           => self::PLUGIN_SLUG,
				'slug'         => 'post-calendar',
				'plugin'       => self::PLUGIN_SLUG,
				'new_version'  => $github_version,
				'url'          => esc_url( $release->html_url ?? 'https://github.com/achtender/post-calendar/releases' ),
				// When a ZIP asset is attached, WordPress can install it automatically.
				// If no asset is found (e.g. forgot to upload), falls back to false
				// and the update_message hook appends a manual download link.
				'package'      => $zip_url ?? false,
				'tested'       => '',
				'requires_php' => '7.4',
			);
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

		$github_version = ltrim( $release->tag_name, 'v' );
		$releases_url   = esc_url( 'https://github.com/achtender/post-calendar/releases' );
		$zip_url        = $this->get_zip_url( $release );

		// Use the GitHub release body as the changelog if available.
		$changelog = ! empty( $release->body )
			? '<pre>' . esc_html( $release->body ) . '</pre>'
			: '<p>' . esc_html__( 'See the GitHub releases page for the full changelog.', 'post-calendar' ) . '</p>';

		$info = (object) array(
			'name'          => 'Post Calendar',
			'slug'          => 'post-calendar',
			'version'       => $github_version,
			'author'        => '<a href="https://github.com/achtender" target="_blank">Achtender</a>',
			'homepage'      => $releases_url,
			'requires'      => '6.0',
			'requires_php'  => '7.4',
			'download_link' => $zip_url ?? false,
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

		$releases_url = esc_url( 'https://github.com/achtender/post-calendar/releases' );
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
