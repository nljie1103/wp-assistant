<?php
/**
 * Uninstall handler for 九流WP助手.
 *
 * 模块业务设置默认保留，避免误删用户的 AI、媒体和页面美化配置。
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'jlwa_last_update_check' );
delete_transient( 'jlwa_remote_update_info' );
delete_transient( 'jlwa_update_lock' );
