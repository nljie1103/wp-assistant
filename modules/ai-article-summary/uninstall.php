<?php
/**
 * 卸载脚本：清理所有数据。
 *
 * @package WP_AI_Article_Summary
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// 1. 删除设置选项。
delete_option( 'wpaias_settings' );
delete_option( 'wpaias_cache_index' );

// 2. 删除所有 transient 缓存（包含 timeout）。
$prefix      = 'wpaias_summary_';
$like_value  = $wpdb->esc_like( '_transient_' . $prefix ) . '%';
$like_time   = $wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_value ) ); // phpcs:ignore
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_time ) );  // phpcs:ignore

// 3. 删除 post meta 标记。
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", '_wpaias_has_summary' ) ); // phpcs:ignore

// 4. 多站点（multisite）兼容。
if ( is_multisite() ) {
	$blog_ids = $wpdb->get_col( "SELECT blog_id FROM {$wpdb->blogs}" );
	if ( ! empty( $blog_ids ) ) {
		foreach ( $blog_ids as $blog_id ) {
			switch_to_blog( (int) $blog_id );
			delete_option( 'wpaias_settings' );
			delete_option( 'wpaias_cache_index' );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_value ) ); // phpcs:ignore
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_time ) );  // phpcs:ignore
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", '_wpaias_has_summary' ) ); // phpcs:ignore
			restore_current_blog();
		}
	}
}
