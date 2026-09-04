<?php

namespace WpCalendar\Integrations\Discord;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles Discord Bot token management and API communication.
 */
class Discord_Client {
	private const OPTION_KEY = 'wp_calendar_discord';
	private const API_BASE   = 'https://discord.com/api/v10';

	/**
	 * Returns the Bot token.
	 *
	 * Priority: wp-config constant > database option.
	 */
	public static function get_bot_token(): string {
		// wp-config constant takes precedence.
		if ( defined( 'WP_CALENDAR_DISCORD_TOKEN' ) && '' !== WP_CALENDAR_DISCORD_TOKEN ) {
			return WP_CALENDAR_DISCORD_TOKEN;
		}

		$option = get_option( self::OPTION_KEY, '' );
		if ( is_array( $option ) ) {
			return $option['token'] ?? '';
		}
		return '';
	}

	/**
	 * Stores the Bot token.
	 */
	public static function store_bot_token( string $token ): bool {
		return (bool) update_option(
			self::OPTION_KEY,
			array( 'token' => $token ),
			false
		);
	}

	/**
	 * Clears the stored Bot token and guilds.
	 */
	public static function clear(): bool {
		return delete_option( self::OPTION_KEY );
	}

	/**
	 * Whether a Bot token is configured.
	 */
	public static function is_configured(): bool {
		return '' !== self::get_bot_token();
	}

	/**
	 * Validates a Bot token by calling /users/@me (bot endpoint).
	 *
	 * Returns bot info array on success, null on failure.
	 */
	public static function validate_token( string $token ): ?array {
		$response = wp_remote_get(
			self::API_BASE . '/users/@me',
			array(
				'headers' => array(
					'Authorization' => 'Bot ' . $token,
				),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		return is_array( $data ) && isset( $data['id'] ) ? $data : null;
	}

	/**
	 * Fetches guilds the Bot is a member of.
	 *
	 * @param string $token  Bot token.
	 * @return array|null  Array of guild objects, or null on failure.
	 */
	public static function get_bot_guilds( string $token ): ?array {
		$response = wp_remote_get(
			self::API_BASE . '/users/@me/guilds',
			array(
				'headers' => array(
					'Authorization' => 'Bot ' . $token,
				),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Fetches scheduled events for a specific guild.
	 *
	 * @param string $token            Bot token.
	 * @param string $guild_id         Discord guild ID.
	 * @param bool   $with_user_count  Include user_count field.
	 * @return array|null  Array of scheduled event objects, or null on failure.
	 */
	public static function get_guild_scheduled_events( string $token, string $guild_id, bool $with_user_count = false ): ?array {
		$url = self::API_BASE . '/guilds/' . rawurlencode( $guild_id ) . '/scheduled-events';
		if ( $with_user_count ) {
			$url = add_query_arg( 'with_user_count', 'true', $url );
		}

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bot ' . $token,
				),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( '[WordPress Calendar] Discord API error: ' . $response->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging for Discord API errors.
			return null;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			$body = wp_remote_retrieve_body( $response );
			error_log( '[WordPress Calendar] Discord API returned ' . $status_code . ' for guild ' . $guild_id . ': ' . $body ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging for Discord API errors.
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		return is_array( $data ) ? $data : null;
	}
}
