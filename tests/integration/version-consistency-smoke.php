<?php
/** Read-only version/asset validation in an isolated WordPress fixture. */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
$expected = defined( 'JLWA_VERSION' ) ? JLWA_VERSION : '';
if ( ! preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+$/D', $expected ) ) {
    throw new RuntimeException( 'Missing or invalid plugin version' );
}
if ( ! defined( 'JLWA_VERSION' ) || JLWA_VERSION !== $expected ) {
    throw new RuntimeException( 'Invalid plugin version: ' . ( defined( 'JLWA_VERSION' ) ? JLWA_VERSION : 'undefined' ) );
}
$features = JLWA_Feature_Registry::features();
$required_features = array( 'page-effects', 'anti-debug', 'ai-summary', 'preloader', 'download-page', 'anti-scraping', 'login-security', 'media-urls' );
if ( array_diff( $required_features, array_keys( $features ) ) ) {
    throw new RuntimeException( 'Required feature missing: ' . implode( ', ', array_diff( $required_features, array_keys( $features ) ) );
}
foreach ( $features as $key => $item ) {
    if ( $item['version'] !== $expected || JLWA_Feature_Registry::version( $key ) !== $expected ) {
        throw new RuntimeException( 'Visible feature version mismatch: ' . $key );
    }
}
if ( JLWA_Feature_Registry::version( 'unknown' ) !== '-' ) {
    throw new RuntimeException( 'Unknown feature was assigned a fake version' );
}
echo count( $features ) . " 个功能域统一显示 v{$expected}，缺失功能不误报版本：通过\n";

// Migrated settings must preserve the historical, independent schema markers.
if ( JLWA_Page_Effects_Feature::VERSION !== '1.8.0'
    || JLWA_Anti_Debug_Feature::VERSION !== '1.2.0'
    || JRMU_VERSION !== '4.2.0' ) {
    throw new RuntimeException( 'Internal option migrations were mistakenly retagged' );
}
echo "模块内部 schema 迁移版本保持不变：通过\n";

// Admin media URLs: previously used JRMU_VERSION 4.2.0, leaving stale JS.
JRMU_Admin::instance()->enqueue_assets( JLWA_MENU_SLUG . '_page_' . JRMU_MENU_SLUG );
foreach ( array( 'jrmu-admin' ) as $handle ) {
    $css = wp_styles()->registered[ $handle ] ?? null;
    $js  = wp_scripts()->registered[ $handle ] ?? null;
    if ( ! $css || ! $js || $css->ver !== $expected || $js->ver !== $expected ) {
        throw new RuntimeException( 'Media admin assets still use old cache version' );
    }
}
echo "媒体与链接后台 CSS/JS 缓存版本统一：通过\n";

// Frontend AI summary: no DB writes, and no API request is made. Simulate a
// public post context while temporarily enabling the frontend via an option filter.
$post = get_post( 6 );
if ( ! $post instanceof WP_Post || $post->post_type !== 'post' || post_password_required( $post ) ) {
    throw new RuntimeException( 'Isolated public-post fixture missing' );
}
$original_query = $GLOBALS['wp_query'] ?? null;
$original_post = $GLOBALS['post'] ?? null;
$original_user = get_current_user_id();
$opts = JLWA_AI_Summary_Feature::get_default_settings();
$opts['enabled'] = 1;
$opts['post_types'] = array( 'post' );
$opts['exclude_post_ids'] = '';
$opts['exclude_categories'] = array();
$mock = static function () use ( $opts ) { return $opts; };
add_filter( 'pre_option_' . WPAIAS_OPTION_KEY, $mock );
try {
    wp_set_current_user( 0 );
    $query = new WP_Query();
    $query->is_single = true;
    $query->is_singular = true;
    $query->queried_object = $post;
    $query->queried_object_id = $post->ID;
    $GLOBALS['wp_query'] = $query;
    $GLOBALS['post'] = $post;
    JLWA_AI_Summary_Feature::instance()->frontend->enqueue_assets();
    $css = wp_styles()->registered['wpaias-frontend'] ?? null;
    $js = wp_scripts()->registered['wpaias-frontend'] ?? null;
    if ( ! $css || ! $js || $css->ver !== $expected || $js->ver !== $expected ) {
        throw new RuntimeException( 'AI summary frontend assets still use old cache version' );
    }
} finally {
    remove_filter( 'pre_option_' . WPAIAS_OPTION_KEY, $mock );
    $GLOBALS['wp_query'] = $original_query;
    $GLOBALS['post'] = $original_post;
    wp_set_current_user( $original_user );
}
echo "AI 摘要前台 CSS/JS 缓存版本统一（无真实 API 请求）：通过\n";
