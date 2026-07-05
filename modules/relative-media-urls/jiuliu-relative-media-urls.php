<?php
/**
 * Plugin Name: 九流媒体相对地址
 * Plugin URI: https://github.com/nljie1103/wp-relative-media-urls
 * Description: WordPress 反向代理与多域名链接助手：媒体相对地址、历史链接扫描/预览/恢复、多域名访问适配、反代环境检测、缓存检测与 Nginx 配置建议。默认不启用任何转换，所有永久修改均需手动确认。
 * Version: 4.1.1
 * Author: 九流
 * Author URI: https://www.jiuliu.org
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: jiuliu-relative-media-urls
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * @package JiuliuRelativeMediaUrls
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'JRMU_VERSION', '4.1.1' );
define( 'JRMU_PLUGIN_FILE', __FILE__ );
define( 'JRMU_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'JRMU_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'JRMU_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'JRMU_MENU_SLUG', defined( 'JLWA_MENU_SLUG' ) ? 'jlwa-relative-media-urls' : 'jiuliu-relative-media-urls' );
define( 'JRMU_OPTION_KEY', 'jiuliu_relative_media_urls_options' );
define( 'JRMU_ATTACHMENT_META_KEY', '_jrmu_future_relative_media' );
define( 'JRMU_LOG_OPTION_KEY', 'jiuliu_relative_media_urls_logs' );

/**
 * 插件主类。
 */
final class Jiuliu_Relative_Media_Urls {
	/**
	 * 单例实例。
	 *
	 * @var Jiuliu_Relative_Media_Urls|null
	 */
	private static $instance = null;

	/**
	 * 获取单例。
	 *
	 * @return Jiuliu_Relative_Media_Urls
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * 构造函数。
	 */
	private function __construct() {
		$this->load_dependencies();
		$this->init_hooks();
	}

	/**
	 * 加载依赖。
	 */
	private function load_dependencies() {
		require_once JRMU_PLUGIN_DIR . 'includes/class-jrmu-settings.php';
		require_once JRMU_PLUGIN_DIR . 'includes/class-jrmu-converter.php';
		require_once JRMU_PLUGIN_DIR . 'includes/class-jrmu-domain-adapter.php';
		require_once JRMU_PLUGIN_DIR . 'includes/class-jrmu-seo.php';
		require_once JRMU_PLUGIN_DIR . 'includes/class-jrmu-scanner.php';
		require_once JRMU_PLUGIN_DIR . 'includes/class-jrmu-diagnostics.php';
		require_once JRMU_PLUGIN_DIR . 'includes/class-jrmu-admin.php';
	}

	/**
	 * 初始化钩子。
	 */
	private function init_hooks() {
		register_activation_hook( JRMU_PLUGIN_FILE, array( $this, 'on_activate' ) );
		register_deactivation_hook( JRMU_PLUGIN_FILE, array( $this, 'on_deactivate' ) );

		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'init_modules' ), 1 );
		add_filter( 'plugin_action_links_' . JRMU_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );
	}

	/**
	 * 激活：只补齐设置，不自动开启转换。
	 */
	public function on_activate() {
		$current  = get_option( JRMU_OPTION_KEY, array() );
		$current  = is_array( $current ) ? $current : array();
		$defaults = JRMU_Settings::get_defaults();
		$merged   = wp_parse_args( $current, $defaults );

		// 从旧版本升级时，保留域名/路径配置，但重置会改变前后台输出或永久写库的开关，避免旧版本激进默认值继续生效。
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

	/**
	 * 停用：保留设置与日志，不删除任何内容。
	 */
	public function on_deactivate() {}

	/**
	 * 加载语言包。
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'jiuliu-relative-media-urls', false, dirname( JRMU_PLUGIN_BASENAME ) . '/languages' );
	}

	/**
	 * 初始化模块。
	 */
	public function init_modules() {
		JRMU_Domain_Adapter::instance();
		JRMU_Converter::instance();
		JRMU_SEO::instance();

		if ( is_admin() ) {
			JRMU_Admin::instance();
		}
	}

	/**
	 * 插件操作链接。
	 *
	 * @param array $links 操作链接。
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . JRMU_MENU_SLUG ) ),
			esc_html__( '设置', 'jiuliu-relative-media-urls' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}
}

Jiuliu_Relative_Media_Urls::instance();
