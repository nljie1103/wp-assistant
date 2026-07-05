<?php
/**
 * Plugin Name: 九流WP助手
 * Plugin URI: https://www.jiuliu.org
 * Description: 将九流页面美化、媒体相对地址、AI 文章摘要和沉浸式预加载整合到一个统一后台入口中。
 * Version: 0.1.1
 * Author: 九流
 * Author URI: https://www.jiuliu.org
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: jiuliu-wp-assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'JLWA_VERSION', '0.1.1' );
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
