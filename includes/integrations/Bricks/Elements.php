<?php

namespace WpCalendar\Integrations\Bricks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Elements {
	private $dependency_missing = false;

	public function __construct() {
		add_action( 'init', array( $this, 'init_elements' ), 11 );
		add_action( 'admin_notices', array( $this, 'render_dependency_notice' ) );
	}

	public function init_elements(): void {
		if ( ! $this->is_bricks_available() ) {
			$this->dependency_missing = true;
			return;
		}

		$element_files = array(
			'wp-calendar' => array(
				'file' => WP_CALENDAR_PLUGIN_DIR . 'includes/integrations/Bricks/Calendar.php',
				'class' => \WpCalendar\Integrations\Bricks\Element_Calendar::class,
			),
		);

		foreach ( $element_files as $element_name => $element ) {
			\Bricks\Elements::register_element( $element['file'], $element_name, $element['class'] );
		}
	}

	public function render_dependency_notice(): void {
		if ( ! $this->dependency_missing || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'WordPress Calendar could not register its Bricks element because Bricks is not active or its API is unavailable.', 'wp-calendar' ),
		);
	}

	private function is_bricks_available(): bool {
		return class_exists( '\\Bricks\\Element' ) && class_exists( '\\Bricks\\Elements' ) && method_exists( '\\Bricks\\Elements', 'register_element' );
	}
}
