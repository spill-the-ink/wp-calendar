<?php

spl_autoload_register(
	function ( string $qualified_name ): void {
		$prefix = 'PostCalendar\\';

		if ( strncmp( $qualified_name, $prefix, strlen( $prefix ) ) !== 0 ) {
			return;
		}

		$relative = substr( $qualified_name, strlen( $prefix ) );
		$file     = POST_CALENDAR_PLUGIN_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( ! file_exists( $file ) ) {
			$file = POST_CALENDAR_PLUGIN_DIR . 'includes/core/' . str_replace( '\\', '/', $relative ) . '.php';
		}

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);
