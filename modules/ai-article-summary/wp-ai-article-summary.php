<?php
/**
 * Plugin Name: 九流 AI 文章摘要
 * Plugin URI: https://github.com/nljie1103/WP-AI-Article-Summary
 * Description: 自动在文章顶部插入 AI 智能摘要，支持多厂商 API 一键选择、三级联动模型选择、丰富的文字入场动画特效、独立缓存系统、暗黑极简卡片样式，专为现代博客打造。
 * Version: 1.0.9
 * Author: 九流
 * Author URI: https://www.jiuliu.org
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-ai-article-summary
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * @package WP_AI_Article_Summary
 */

// 禁止直接访问。
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 定义常量。
define( 'WPAIAS_VERSION', '1.0.9' );
define( 'WPAIAS_PLUGIN_FILE', __FILE__ );
define( 'WPAIAS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPAIAS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPAIAS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'WPAIAS_OPTION_KEY', 'wpaias_settings' );
define( 'WPAIAS_CACHE_PREFIX', 'wpaias_summary_' );
define( 'WPAIAS_META_KEY', '_wpaias_has_summary' );

// 引入核心类文件。
require_once WPAIAS_PLUGIN_DIR . 'includes/class-wpaias-providers.php';
require_once WPAIAS_PLUGIN_DIR . 'includes/class-wpaias-styles.php';
require_once WPAIAS_PLUGIN_DIR . 'includes/class-wpaias-cache.php';
require_once WPAIAS_PLUGIN_DIR . 'includes/class-wpaias-api.php';
require_once WPAIAS_PLUGIN_DIR . 'includes/class-wpaias-admin.php';
require_once WPAIAS_PLUGIN_DIR . 'includes/class-wpaias-frontend.php';
require_once WPAIAS_PLUGIN_DIR . 'includes/class-wpaias-plugin.php';

/**
 * 启动插件主入口。
 *
 * @return WPAIAS_Plugin
 */
function wpaias() {
	return WPAIAS_Plugin::instance();
}

// 启动。
add_action( 'plugins_loaded', 'wpaias', 5 );

/**
 * 激活插件时执行：写入默认设置。
 */
register_activation_hook( __FILE__, function () {
	$defaults = WPAIAS_Plugin::get_default_settings();
	$current  = get_option( WPAIAS_OPTION_KEY, array() );

	if ( ! is_array( $current ) ) {
		$current = array();
	}

	$merged = wp_parse_args( $current, $defaults );
	unset( $merged['api_key'] );
	update_option( WPAIAS_OPTION_KEY, $merged );
} );

/**
 * 停用插件时执行：保留数据，不做删除（删除由 uninstall.php 负责）。
 */
register_deactivation_hook( __FILE__, function () {
	// 预留位置。
} );
