<?php
/**
 * 卸载脚本
 *
 * 仅在用户通过 WordPress 后台「删除」插件时执行。
 * 我们清理插件创建的所有选项，但不动用户的媒体库附件。
 *
 * @package JiuliuImmersivePreloader
 */

// 安全检查：必须由 WordPress 触发。
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		delete_option( 'jiuliu_immersive_preloader_options' );
		restore_current_blog();
	}

	delete_site_option( 'jiuliu_immersive_preloader_options' );
} else {
	delete_option( 'jiuliu_immersive_preloader_options' );
}
