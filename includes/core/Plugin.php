<?php

namespace WpCalendar;

use WpCalendar\Admin\Admin_Editor;
use WpCalendar\Admin\Settings_Page;
use WpCalendar\Events\Event_Model_Sync;
use WpCalendar\Events\Meta_Keys_Migration;
use WpCalendar\Events\Post_Type;
use WpCalendar\Events\Schema_Migration;
use WpCalendar\Integrations\Bricks\Dynamic_Data_Queries;
use WpCalendar\Integrations\Bricks\Dynamic_Data_Tags;
use WpCalendar\Integrations\Bricks\Elements;
use WpCalendar\Integrations\Shortcode;
use WpCalendar\Rest\Rest_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {
	/**
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * @var Settings_Page
	 */
	private $settings_page;

	/**
	 * @var Admin_Editor
	 */
	private $admin_editor;

	/**
	 * @var Event_Model_Sync
	 */
	private $event_model_sync;

	/**
	 * @var Post_Type
	 */
	private $proxy_post_type;

	/**
	 * @var Elements
	 */
	private $bricks_elements;

	/**
	 * @var Dynamic_Data_Tags
	 */
	private $bricks_dynamic_tags;

	/**
	 * @var Dynamic_Data_Queries
	 */
	private $bricks_dynamic_queries;

	/**
	 * @var Assets
	 */
	private $assets;

	/**
	 * @var Rest_Controller
	 */
	private $proxy_post_type_rest;

	/**
	 * @var Shortcode
	 */
	private $shortcode;

	/**
	 * @var Update_Checker
	 */
	private $update_checker;

	public static function boot(): void {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
	}

	public static function instance(): ?self {
		return self::$instance;
	}

	private function __construct() {
		$this->proxy_post_type        = new Post_Type();
		$this->assets                 = new Assets();
		$this->admin_editor           = new Admin_Editor( $this->assets );
		$this->event_model_sync       = new Event_Model_Sync();
		$this->settings_page          = new Settings_Page();
		$this->proxy_post_type_rest   = new Rest_Controller();
		$this->shortcode              = new Shortcode();
		$this->bricks_elements        = new Elements();
		$this->bricks_dynamic_tags    = new Dynamic_Data_Tags();
		$this->bricks_dynamic_queries = new Dynamic_Data_Queries();
		$this->update_checker         = new Update_Checker();

		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'plugins_loaded', array( Schema_Migration::class, 'maybe_run' ), 5 );
		add_action( 'plugins_loaded', array( Meta_Keys_Migration::class, 'maybe_run' ), 5 );
	}

	public function assets(): Assets {
		return $this->assets;
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'wp-calendar', false, dirname( plugin_basename( WP_CALENDAR_PLUGIN_FILE ) ) . '/languages' );
	}
}
