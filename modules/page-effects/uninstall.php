<?php
/**
 * Cleanup for 九流页面美化特效.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'xjpe_options' );
