<?php
/**
 * Plugin Name: 九流WP助手
 * Plugin URI: https://github.com/nljie1103/wp-assistant
 * Description: 在一个统一、可审计的插件中管理页面美化、媒体相对地址、AI 文章摘要和沉浸式预加载。
 * Version: 1.0.0
 * Author: 九流
 * Author URI: https://www.jiuliu.org
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: jiuliu-wp-assistant
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'JLWA_VERSION', '1.0.0' );
define( 'JLWA_PLUGIN_FILE', __FILE__ );
define( 'JLWA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'JLWA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'JLWA_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'JLWA_MENU_SLUG', 'jiuliu-wp-assistant' );
define( 'JLWA_IS_SUITE', true );

require_once JLWA_PLUGIN_DIR . 'includes/class-jlwa-module-loader.php';
require_once JLWA_PLUGIN_DIR . 'includes/class-jlwa-admin.php';
require_once JLWA_PLUGIN_DIR . 'includes/class-jlwa-updater.php';

JLWA_Module_Loader::load_modules();
JLWA_Admin::instance();

if ( is_admin() ) {
	JLWA_Updater::instance();
}

register_activation_hook( JLWA_PLUGIN_FILE, array( 'JLWA_Module_Loader', 'activate' ) );
