<?php
/**
 * Plugin Name: 九流WP助手
 * Plugin URI: https://github.com/nljie1103/wp-assistant
 * Description: 一体化 WordPress 增强插件，包含页面特效、反调试、AI 摘要、预加载、可选多域名适配，以及完整经典／子比原生双模式下载页与私有安全交付。
 * Version: 3.1.3
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

define( 'JLWA_VERSION', '3.1.3' );
define( 'JLWA_PLUGIN_FILE', __FILE__ );
define( 'JLWA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'JLWA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'JLWA_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'JLWA_MENU_SLUG', 'jiuliu-wp-assistant' );
define( 'JLWA_UNIFIED_ADMIN', true );

require_once JLWA_PLUGIN_DIR . 'includes/class-jlwa-feature-registry.php';
require_once JLWA_PLUGIN_DIR . 'includes/class-jlwa-admin.php';
require_once JLWA_PLUGIN_DIR . 'includes/class-jlwa-updater.php';
require_once JLWA_PLUGIN_DIR . 'features/page-effects/mouse-interactions.php';
require_once JLWA_PLUGIN_DIR . 'features/page-effects/admin-save-fix.php';
require_once JLWA_PLUGIN_DIR . 'features/download-page-cover-fix.php';
require_once JLWA_PLUGIN_DIR . 'features/private-download.php';
require_once JLWA_PLUGIN_DIR . 'features/private-download-deploy.php';

JLWA_Feature_Registry::boot();
JLWA_Admin::instance();

if ( is_admin() ) {
	JLWA_Updater::instance();
}

register_activation_hook( JLWA_PLUGIN_FILE, array( 'JLWA_Feature_Registry', 'activate' ) );
