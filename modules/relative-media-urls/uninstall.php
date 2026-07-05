<?php
/**
 * 卸载清理。
 *
 * @package JiuliuRelativeMediaUrls
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'jiuliu_relative_media_urls_options' );
delete_option( 'jiuliu_relative_media_urls_logs' );
