<?php
/** Internal media and URL feature bootstrap. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'JRMU_VERSION', '4.1.1' );
define( 'JRMU_PLUGIN_FILE', __FILE__ );
define( 'JRMU_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'JRMU_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'JRMU_PLUGIN_BASENAME', JLWA_PLUGIN_BASENAME );
define( 'JRMU_MENU_SLUG', 'jlwa-relative-media-urls' );
define( 'JRMU_OPTION_KEY', 'jiuliu_relative_media_urls_options' );
define( 'JRMU_ATTACHMENT_META_KEY', '_jrmu_future_relative_media' );
define( 'JRMU_LOG_OPTION_KEY', 'jiuliu_relative_media_urls_logs' );

require_once JRMU_PLUGIN_DIR . 'includes/class-jrmu-settings.php';
require_once JRMU_PLUGIN_DIR . 'includes/class-jrmu-converter.php';
require_once JRMU_PLUGIN_DIR . 'includes/class-jrmu-domain-adapter.php';
require_once JRMU_PLUGIN_DIR . 'includes/class-jrmu-seo.php';
require_once JRMU_PLUGIN_DIR . 'includes/class-jrmu-scanner.php';
require_once JRMU_PLUGIN_DIR . 'includes/class-jrmu-diagnostics.php';
require_once JRMU_PLUGIN_DIR . 'includes/class-jrmu-admin.php';

final class JLWA_Media_Urls_Feature {
	/** @var JLWA_Media_Urls_Feature|null */
	private static $instance = null;

	/** @return JLWA_Media_Urls_Feature */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'init_services' ), 1 );
	}

	public function on_activate() {
		$current  = get_option( JRMU_OPTION_KEY, array() );
		$current  = is_array( $current ) ? $current : array();
		$defaults = JRMU_Settings::get_defaults();
		$merged   = wp_parse_args( $current, $defaults );
		$old_version = ! empty( $current['settings_version'] ) ? (string) $current['settings_version'] : '';
		if ( ! $old_version || version_compare( $old_version, '4.1.0', '<' ) ) {
			foreach ( array(
				'convert_existing_media_output',
				'convert_future_media_output',
				'convert_post_on_save',
				'convert_post_on_frontend',
				'domain_adaptation_enabled',
				'domain_rewrite_frontend_links',
				'canonical_enabled',
			) as $dangerous_key ) {
				$merged[ $dangerous_key ] = 0;
			}
		}
		foreach ( JRMU_Settings::get_boolean_keys() as $key ) {
			if ( ! array_key_exists( $key, $current ) ) {
				$merged[ $key ] = $defaults[ $key ];
			}
		}
		$merged['settings_version'] = JRMU_VERSION;
		update_option( JRMU_OPTION_KEY, $merged );
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'jiuliu-relative-media-urls', false, dirname( JLWA_PLUGIN_BASENAME ) . '/features/relative-media-urls/languages' );
	}

	public function init_services() {
		JRMU_Domain_Adapter::instance();
		JRMU_Converter::instance();
		JRMU_SEO::instance();
		if ( is_admin() ) {
			JRMU_Admin::instance();
		}
	}
}

JLWA_Media_Urls_Feature::instance();
