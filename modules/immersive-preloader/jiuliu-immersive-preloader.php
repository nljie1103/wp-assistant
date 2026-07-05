<?php
/**
 * Plugin Name: 九流沉浸式预加载
 * Plugin URI: https://github.com/nljie1103/wp-immersive-preloader
 * Description: 为 WordPress 网站提供多种炫酷的沉浸式预加载效果，完全掩盖页面加载过程，实现丝滑的开门式进入体验。支持自定义 Logo、动画参数、效果切换，无需修改任何主题代码，一键启用即用。
 * Version: 1.0.6
 * Author: 九流
 * Author URI: https://www.jiuliu.org
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: jiuliu-immersive-preloader
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * @package JiuliuImmersivePreloader
 */

// 阻止直接访问插件文件
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 定义插件常量
define( 'JIP_VERSION', '1.0.6' );
define( 'JIP_PLUGIN_FILE', __FILE__ );
define( 'JIP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'JIP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'JIP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'JIP_MENU_SLUG', defined( 'JLWA_MENU_SLUG' ) ? 'jlwa-immersive-preloader' : 'jiuliu-immersive-preloader' );
define( 'JIP_OPTION_KEY', 'jiuliu_immersive_preloader_options' );

/**
 * 插件主类
 *
 * 单例模式，负责加载所有功能模块。
 */
final class Jiuliu_Immersive_Preloader {

	/**
	 * 单例实例。
	 *
	 * @var Jiuliu_Immersive_Preloader|null
	 */
	private static $instance = null;

	/**
	 * 获取单例实例。
	 *
	 * @return Jiuliu_Immersive_Preloader
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
	 * 加载依赖文件。
	 */
	private function load_dependencies() {
		require_once JIP_PLUGIN_DIR . 'includes/class-jip-settings.php';
		require_once JIP_PLUGIN_DIR . 'includes/class-jip-admin.php';
		require_once JIP_PLUGIN_DIR . 'includes/class-jip-frontend.php';
		require_once JIP_PLUGIN_DIR . 'includes/class-jip-updater.php';
	}

	/**
	 * 初始化钩子。
	 */
	private function init_hooks() {
		// 激活与停用钩子
		register_activation_hook( JIP_PLUGIN_FILE, array( $this, 'on_activate' ) );
		register_deactivation_hook( JIP_PLUGIN_FILE, array( $this, 'on_deactivate' ) );

		// 加载多语言（保留扩展性，当前已硬编码中文）
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );

		// 初始化后台与前台
		add_action( 'init', array( $this, 'init_modules' ) );

		// 插件操作链接
		add_filter( 'plugin_action_links_' . JIP_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );
	}

	/**
	 * 插件激活回调。
	 */
	public function on_activate() {
		// 写入默认选项（如果不存在）。
		$defaults = JIP_Settings::get_defaults();
		$current  = get_option( JIP_OPTION_KEY, array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		$merged = wp_parse_args( $current, $defaults );
		update_option( JIP_OPTION_KEY, $merged );
	}

	/**
	 * 插件停用回调。
	 */
	public function on_deactivate() {
		// 停用时不删除数据，仅在卸载时由 uninstall.php 处理。
	}

	/**
	 * 加载多语言文件。
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'jiuliu-immersive-preloader', false, dirname( JIP_PLUGIN_BASENAME ) . '/languages' );
	}

	/**
	 * 初始化后台与前台模块。
	 */
	public function init_modules() {
		if ( is_admin() ) {
			JIP_Admin::instance();
			JIP_Updater::instance();
		}
		// 前台模块在前后台都需要注册，因为 wp_enqueue_scripts 是前台钩子。
		JIP_Frontend::instance();
	}

	/**
	 * 插件操作链接。
	 *
	 * @param array $links 现有链接。
	 * @return array 修改后的链接。
	 */
	public function plugin_action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . JIP_MENU_SLUG ) ),
			'设置'
		);
		array_unshift( $links, $settings_link );
		return $links;
	}
}

// 启动插件
Jiuliu_Immersive_Preloader::instance();
