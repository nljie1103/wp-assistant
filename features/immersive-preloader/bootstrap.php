<?php
/** Internal immersive preloader feature bootstrap. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'JIP_VERSION', '1.1.0' );
define( 'JIP_PLUGIN_FILE', __FILE__ );
define( 'JIP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'JIP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'JIP_PLUGIN_BASENAME', JLWA_PLUGIN_BASENAME );
define( 'JIP_MENU_SLUG', 'jlwa-immersive-preloader' );
define( 'JIP_OPTION_KEY', 'jiuliu_immersive_preloader_options' );

require_once JIP_PLUGIN_DIR . 'includes/class-jip-settings.php';
require_once JIP_PLUGIN_DIR . 'includes/class-jip-admin.php';
require_once JIP_PLUGIN_DIR . 'includes/class-jip-frontend.php';

final class JLWA_Immersive_Preloader_Feature {
	/** @var JLWA_Immersive_Preloader_Feature|null */
	private static $instance = null;

	/** @return JLWA_Immersive_Preloader_Feature */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'init_services' ) );
	}

	public function on_activate() {
		$defaults = JIP_Settings::get_defaults();
		$current  = get_option( JIP_OPTION_KEY, array() );
		$current  = is_array( $current ) ? $current : array();
		update_option( JIP_OPTION_KEY, wp_parse_args( $current, $defaults ) );
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'jiuliu-immersive-preloader', false, dirname( JLWA_PLUGIN_BASENAME ) . '/features/immersive-preloader/languages' );
	}

	public function init_services() {
		if ( is_admin() ) {
			JIP_Admin::instance();
		}
		JIP_Frontend::instance();
	}
}

JLWA_Immersive_Preloader_Feature::instance();
