<?php
/** Run via wp-cli eval-file under a real WordPress admin context, not PHPUnit. */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
$user = get_user_by( 'login', 'jlwaadmin' );
if ( ! $user || ! user_can( $user, 'manage_options' ) ) { throw new RuntimeException( 'Missing isolated test administrator' ); }
wp_set_current_user( $user->ID );
$features = JLWA_Feature_Registry::features();
if ( count( $features ) < 8 || ! isset( $features['anti-scraping'] ) ) { throw new RuntimeException( 'Missing anti-scraping feature' ); }
foreach ( $features as $key => $feature ) {
    $state = JLWA_Feature_Registry::state( $key );
    foreach ( array( 'ready', 'enabled', 'label', 'tone', 'hint' ) as $field ) {
        if ( ! array_key_exists( $field, $state ) ) { throw new RuntimeException( 'Missing status field ' . $field . ' for ' . $key ); }
    }
}
$download = JLWA_Feature_Registry::state( 'download-page' );
if ( ! JLWA_Download_Page_Feature::is_compatible() && ( $download['ready'] || '需子比主题' !== $download['label'] ) ) {
    throw new RuntimeException( 'Download page incorrectly shown as functional without Zibll dependencies' );
}
ob_start();
JLWA_Admin::instance()->render_dashboard();
$html = ob_get_clean();
foreach ( array( '站点功能概况', '正常启用', '需要关注', '模块已加载', '建议优先处理', '功能与下一步' ) as $phrase ) {
    if ( false === strpos( $html, $phrase ) ) { throw new RuntimeException( 'Dashboard omitted ' . $phrase ); }
}
if ( ! JLWA_Download_Page_Feature::is_compatible() && false === strpos( $html, '当前缺少子比下载/付费接口' ) ) {
    throw new RuntimeException( 'Missing incompatible-theme guidance' );
}
if ( ! preg_match( '/aria-current="page"/', $html ) ) { throw new RuntimeException( 'Active navigation has no aria-current' ); }
if ( count( $features ) !== substr_count( $html, 'class="jlwa-feature-card jlwa-feature-card--' ) ) { throw new RuntimeException( 'Missing feature cards' ); }
if ( false !== strpos( $html, '统一生命周期，功能域内部解耦' ) ) { throw new RuntimeException( 'Legacy non-actionable architecture banner remains' ); }
echo "后台总览：" . count( $features ) . " 个功能卡片、状态诊断、操作入口和导航无障碍标记全部通过\n";
// Simulate an enabled AI summary with no usable key, without changing the DB.
$mock_settings = JLWA_AI_Summary_Feature::get_settings();
$mock_settings['enabled'] = 1;
$mock_settings['api_keys'] = array();
unset( $mock_settings['api_key'] );
$filter = function () use ( $mock_settings ) { return $mock_settings; };
add_filter( 'pre_option_wpaias_settings', $filter );
try {
    $ai = JLWA_Feature_Registry::state( 'ai-summary' );
    if ( $ai['ready'] || '待配置密钥' !== $ai['label'] ) { throw new RuntimeException( 'Missing AI configuration falsely shown as ready' ); }
    ob_start();
    JLWA_Admin::instance()->render_dashboard();
    $needs_key = ob_get_clean();
    if ( false === strpos( $needs_key, 'tab=api' ) || false === strpos( $needs_key, '待配置密钥' ) ) {
        throw new RuntimeException( 'AI settings link does not open the API tab' );
    }
} finally {
    remove_filter( 'pre_option_wpaias_settings', $filter );
}
echo "AI 未配置密钥：显示待配置状态并直接跳转 API 标签（未修改数据库）通过\n";
