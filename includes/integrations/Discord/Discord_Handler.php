<?php

namespace WpCalendar\Integrations\Discord;

use WpCalendar\Admin\Settings_Page;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles Discord Bot token validation and guild discovery.
 */
class Discord_Handler {
	/**
	 * Validates the Bot token and fetches guilds.
	 *
	 * Returns a WP_REST_Response with bot info and guilds, or an error.
	 */
	public static function validate_and_fetch_guilds( string $token ): \WP_REST_Response {
		$bot_info = Discord_Client::validate_token( $token );
		if ( null === $bot_info ) {
			return new \WP_REST_Response(
				array( 'error' => 'invalid-token' ),
				400
			);
		}

		$guilds_data = Discord_Client::get_bot_guilds( $token );
		$guilds = array();
		if ( is_array( $guilds_data ) ) {
			foreach ( $guilds_data as $guild ) {
				if ( ! isset( $guild['id'] ) ) {
					continue;
				}
				$guilds[] = array(
					'guild_id' => $guild['id'],
					'name' => $guild['name'] ?? '',
					'enabled' => false,
				);
			}
		}

		// Merge with existing saved guilds to preserve toggles.
		$sources = Settings_Page::get_sources();
		$existing_guilds = array();
		if ( ! empty( $sources['discord']['guilds'] ) && is_array( $sources['discord']['guilds'] ) ) {
			foreach ( $sources['discord']['guilds'] as $eg ) {
				$existing_guilds[ $eg['guild_id'] ?? '' ] = $eg;
			}
		}

		foreach ( $guilds as &$guild ) {
			if ( isset( $existing_guilds[ $guild['guild_id'] ] ) ) {
				$guild['enabled'] = ! empty( $existing_guilds[ $guild['guild_id'] ]['enabled'] );
			}
			unset($guild);
		}

		// Store token + guilds.
		Discord_Client::store_bot_token( $token );
		$sources['discord']['guilds'] = $guilds;
		update_option( Settings_Page::SOURCES_OPTION_NAME, $sources, false );

		return new \WP_REST_Response(
			array(
				'bot' => array(
					'id' => $bot_info['id'],
					'username' => $bot_info['username'] ?? '',
				),
				'guilds' => $guilds,
			),
			200
		);
	}
}
