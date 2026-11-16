<?php
/** Run only in the isolated WordPress fixture via wp-cli eval-file. */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
$user = get_user_by( 'login', 'jlwaadmin' );
if ( ! $user || ! user_can( $user, 'manage_options' ) ) { throw new RuntimeException( 'Isolated admin fixture unavailable' ); }
wp_set_current_user( $user->ID );

// Saving an untouched API-key field must retain every persisted key, including
// those for other providers. This scenario previously replaced the map with {}.
$fixture = JLWA_AI_Summary_Feature::get_default_settings();
$fixture['api_keys'] = array(
    'openai::__provider__' => 'FICTIONAL_FIXTURE_OPENAI',
    'gemini::__provider__' => 'FICTIONAL_FIXTURE_GEMINI',
);
$mock = function () use ( $fixture ) { return $fixture; };
add_filter( 'pre_option_' . WPAIAS_OPTION_KEY, $mock );
try {
    $admin = new WPAIAS_Admin();
    $out = $admin->sanitize_settings( array(
        '_current_tab' => 'api',
        'provider' => 'openai',
        'model' => $fixture['model'],
        'api_keys_json' => '{}',
        'api_key_current' => '',
    ) );
    foreach ( $fixture['api_keys'] as $slot => $value ) {
        if ( ! isset( $out['api_keys'][ $slot ] ) || $out['api_keys'][ $slot ] !== $value ) {
            throw new RuntimeException( 'Empty draft removed saved key: ' . $slot );
        }
    }
    $out2 = $admin->sanitize_settings( array(
        '_current_tab' => 'api', 'provider' => 'openai',
        'api_keys_json' => '{"openai::__provider__":"FICTIONAL_NEW_DRAFT"}',
    ) );
    if ( $out2['api_keys']['gemini::__provider__'] !== 'FICTIONAL_FIXTURE_GEMINI'
        || $out2['api_keys']['openai::__provider__'] !== 'FICTIONAL_NEW_DRAFT' ) {
        throw new RuntimeException( 'New draft damaged an unrelated provider key' );
    }
} finally {
    remove_filter( 'pre_option_' . WPAIAS_OPTION_KEY, $mock );
}
echo "AI 密钥空草稿保留原值、更新当前服务商不影响其它服务商：通过\n";

$preloader = JIP_Settings::get_effects();
foreach ( array( 'aurora', 'minimal' ) as $id ) {
    if ( empty( $preloader[ $id ]['preview'] ) || ! is_readable( JIP_PLUGIN_DIR . $preloader[ $id ]['preview'] ) ) {
        throw new RuntimeException( 'Missing preloader preview for ' . $id );
    }
    $config = JIP_Settings::get_defaults();
    $config['effect'] = $id;
    $clean = JIP_Settings::sanitize( $config );
    if ( $clean['effect'] !== $id ) { throw new RuntimeException( 'Preloader setting rejected ' . $id ); }
}
echo "开屏极光与极简效果预览资源及选项保存：通过\n";

$page_defaults = JLWA_Page_Effects_Feature::default_options();
$page_defs = JLWA_Page_Effects_Feature::effect_definitions();
foreach ( array( 'fireflies', 'meteors' ) as $id ) {
    if ( ! isset( $page_defaults['effects'][ $id ], $page_defs[ $id ] ) ) { throw new RuntimeException( 'Missing ambient effect ' . $id ); }
    $page_defaults['effects'][ $id ]['enabled'] = 1;
    $page_defaults['effects'][ $id ]['count'] = 13;
}
$clean_page = JLWA_Page_Effects_Feature::instance()->sanitize_options( $page_defaults );
foreach ( array( 'fireflies', 'meteors' ) as $id ) {
    if ( 1 !== $clean_page['effects'][ $id ]['enabled'] || 13 !== $clean_page['effects'][ $id ]['count'] ) {
        throw new RuntimeException( 'Ambient settings rejected ' . $id );
    }
}
echo "新增两种氛围特效可保存配置：通过\n";

$download = JLWA_Download_Page_Feature::instance();
foreach ( array( 'technology', 'business', 'vip', 'aurora', 'editorial', 'midnight' ) as $id ) {
    $clean = $download->sanitize( array( 'enabled' => 1, 'template' => $id ) );
    if ( $clean['template'] !== $id ) { throw new RuntimeException( 'Download theme rejected ' . $id ); }
}
ob_start();
$download->render_admin_page();
$html = ob_get_clean();
if ( 6 !== substr_count( $html, 'class="jlwa-template-card is-' ) ) { throw new RuntimeException( 'Download UI did not show all six themes' ); }
echo "六种下载页方案可保存且后台选择卡完整：通过\n";

// The PHP renderer must output every selected opening effect, not merely list it.
$preloader_frontend = JIP_Frontend::instance();
$reflection = new ReflectionClass( $preloader_frontend );
$prop = $reflection->getProperty( 'options' );
$prop->setAccessible( true );
$render = $reflection->getMethod( 'get_preloader_html' );
$render->setAccessible( true );
$previous_options = $prop->getValue( $preloader_frontend );
try {
    foreach ( array_keys( $preloader ) as $id ) {
        $cfg = JIP_Settings::get_defaults();
        $cfg['effect'] = $id;
        $prop->setValue( $preloader_frontend, $cfg );
        $markup = $render->invoke( $preloader_frontend );
        if ( false === strpos( $markup, 'jip-effect-' . $id ) || false === strpos( $markup, 'jip-preloader' ) ) {
            throw new RuntimeException( 'Frontend preloader has missing markup for ' . $id );
        }
        if ( 'aurora' === $id && false === strpos( $markup, 'jip-aurora-ambient' ) ) {
            throw new RuntimeException( 'Aurora frontend not rendered' );
        }
        if ( 'minimal' === $id && false === strpos( $markup, 'jip-minimal-rule' ) ) {
            throw new RuntimeException( 'Minimal frontend not rendered' );
        }
    }
} finally {
    $prop->setValue( $preloader_frontend, $previous_options );
}
echo "全部开屏选择均能真实生成对应 HTML，极光/极简 DOM 正确：通过\n";

// The page-effects visitor payload excludes any inactive effect settings.
$page_feature = JLWA_Page_Effects_Feature::instance();
$reflect = new ReflectionMethod( 'JLWA_Page_Effects_Feature', 'frontend_config' );
$reflect->setAccessible( true );
$page_config = JLWA_Page_Effects_Feature::default_options();
$page_config['effects']['fireflies']['enabled'] = 1;
$active_only = $reflect->invoke( $page_feature, $page_config );
if ( ! isset( $active_only['effects']['fireflies'] ) || isset( $active_only['effects']['snow'] ) ) {
    throw new RuntimeException( 'Inactive effects still serialized to visitors' );
}
echo "仅向访客下发已启用动画配置，禁用效果不再随页传输：通过\n";
