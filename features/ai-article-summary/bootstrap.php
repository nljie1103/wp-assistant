<?php
/** Internal AI article summary feature bootstrap. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPAIAS_VERSION', '1.1.3' );
define( 'WPAIAS_PLUGIN_FILE', __FILE__ );
define( 'WPAIAS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPAIAS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPAIAS_PLUGIN_BASENAME', JLWA_PLUGIN_BASENAME );
define( 'WPAIAS_OPTION_KEY', 'wpaias_settings' );
define( 'WPAIAS_CACHE_PREFIX', 'wpaias_summary_' );
define( 'WPAIAS_META_KEY', '_wpaias_has_summary' );

require_once WPAIAS_PLUGIN_DIR . 'includes/class-wpaias-providers.php';
require_once WPAIAS_PLUGIN_DIR . 'includes/class-wpaias-styles.php';
require_once WPAIAS_PLUGIN_DIR . 'includes/class-wpaias-cache.php';
require_once WPAIAS_PLUGIN_DIR . 'includes/class-wpaias-api.php';
require_once WPAIAS_PLUGIN_DIR . 'includes/class-wpaias-admin.php';
require_once WPAIAS_PLUGIN_DIR . 'includes/class-wpaias-frontend.php';
require_once WPAIAS_PLUGIN_DIR . 'includes/class-wpaias-plugin.php';
require_once WPAIAS_PLUGIN_DIR . 'includes/animation-fix.php';

JLWA_AI_Summary_Feature::instance();
