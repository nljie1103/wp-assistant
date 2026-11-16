<?php
/** Read-only, isolated WordPress fixture smoke; NEVER use on production. */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
function jlwa_259_expect( $ok, $why ) {
    if ( ! $ok ) { throw new RuntimeException( $why ); }
}
$converter = JRMU_Converter::instance();
$local_host = wp_parse_url( home_url(), PHP_URL_HOST );
$valid = 'https://' . $local_host . '/wp-content/uploads/2026/11/photo.webp?size=400';
$wrong = 'https://' . $local_host . '/wp-content/uploads-backup/photo.webp';
$evil = 'https://user:secret@' . $local_host . '/wp-content/uploads/photo.webp';
jlwa_259_expect( '/wp-content/uploads/2026/11/photo.webp?size=400' === $converter->convert_url( $valid ), 'Valid uploads URL did not convert' );
jlwa_259_expect( $wrong === $converter->convert_url( $wrong ), 'Uploads-backup must not be treated as uploads' );
jlwa_259_expect( $evil === $converter->convert_url( $evil ), 'Credential-bearing URL must be left untouched' );
$restore = $converter->restore_content_to_domain( '<img src="/wp-content/uploads-backup/a.webp"><img src="/wp-content/uploads/a.webp">', 'https://media.example.test' );
jlwa_259_expect( false !== strpos( $restore, 'src="/wp-content/uploads-backup/a.webp"' ), 'Restore modified a similarly named directory' );
jlwa_259_expect( false !== strpos( $restore, 'src="https://media.example.test/wp-content/uploads/a.webp"' ), 'Restore failed for a real uploads directory' );
echo "媒体路径正向转换与恢复、伪前缀与凭据 URL 防护：通过\n";
foreach ( array('https://target.example.test/blog', 'http://target.example.test:8080') as $url ) {
    jlwa_259_expect( $url === JRMU_Settings::sanitize_rewrite_target( $url ), 'Valid target rejected: ' . $url );
}
foreach ( array('https://u:p@evil.example/path', 'https://example.test/path?redirect=evil', 'https://example.test/#abc', 'file:///etc/passwd', 'https://example.test/../secret', 'http://example.test:abc/path') as $url ) {
    jlwa_259_expect( '' === JRMU_Settings::sanitize_rewrite_target( $url ), 'Unsafe target accepted: ' . $url );
}
$dry_error = JRMU_Scanner::instance()->apply_post_action( array(), JRMU_Scanner::ACTION_RESTORE_MEDIA_FULL, '' );
jlwa_259_expect( ! empty( $dry_error['errors'] ), 'Missing restore target silently rewrites to home URL' );
$dry_error = JRMU_Scanner::instance()->apply_post_action( array(), JRMU_Scanner::ACTION_INTERNAL_TO_TARGET, 'https://user:pass@evil.example' );
jlwa_259_expect( ! empty( $dry_error['errors'] ), 'Unsafe rewrite target silently falls back to home URL' );
echo "永久改写目标 URL 严格校验（包括批量执行入口）：通过\n";
foreach ( array('editorial','aperture') as $effect ) {
    jlwa_259_expect( isset( JIP_Settings::get_effects()[$effect] ), 'New editorial effect missing' );
    $opts = JIP_Settings::get_defaults();
    $opts['effect'] = $effect;
    jlwa_259_expect( JIP_Settings::sanitize( $opts )['effect'] === $effect, 'New effect did not survive save' );
}
$front = JIP_Frontend::instance();
$ref = new ReflectionClass( $front );
$property = $ref->getProperty( 'options' );
$property->setAccessible( true );
$method = $ref->getMethod( 'get_preloader_html' );
$method->setAccessible( true );
$old = $property->getValue( $front );
try {
    foreach ( array_keys( JIP_Settings::get_effects() ) as $effect ) {
        $opts = JIP_Settings::get_defaults();
        $opts['effect'] = $effect;
        $property->setValue( $front, $opts );
        $markup = $method->invoke( $front );
        jlwa_259_expect( false !== strpos( $markup, 'jip-effect-' . $effect ), 'Missing HTML for ' . $effect );
        jlwa_259_expect( false === strpos( $markup, 'aria-valuenow' ), 'Fake ARIA percent still rendered for ' . $effect );
    }
} finally {
    $property->setValue( $front, $old );
}
echo "全部 12 种开屏真实渲染 HTML，进度无虚假百分比：通过\n";

// No real network traffic: record WordPress HTTP method from a mocked response.
$seen_methods = array();
$simulated_head_code = 200;
$mock_http = static function ( $preempt, $args, $url ) use ( &$seen_methods, &$simulated_head_code ) {
    $method = isset( $args['method'] ) ? $args['method'] : 'GET';
    $seen_methods[] = array( 'method' => $method, 'limit' => isset( $args['limit_response_size'] ) ? $args['limit_response_size'] : 0 );
    $code = 'HEAD' === $method ? $simulated_head_code : 200;
    return array( 'headers' => array( 'cache-control' => 'public,max-age=300' ),
        'body' => '', 'response' => array( 'code' => $code, 'message' => 'OK' ),
        'cookies' => array(), 'filename' => null );
};
add_filter( 'pre_http_request', $mock_http, 10, 3 );
try {
    $result = JRMU_Diagnostics::check_url_headers( home_url( '/wp-content/uploads/test.webp' ) );
    jlwa_259_expect( ! is_wp_error( $result ) && 200 === $result['code'], 'HEAD diagnostic failed' );
    jlwa_259_expect( 1 === count( $seen_methods ) && 'HEAD' === $seen_methods[0]['method'], 'Diagnostic downloaded media instead of using HEAD' );
    $seen_methods = array();
    $simulated_head_code = 405;
    $result = JRMU_Diagnostics::check_url_headers( home_url( '/wp-content/uploads/test.webp' ) );
    jlwa_259_expect( ! is_wp_error( $result ) && 200 === $result['code'], 'GET fallback diagnostic failed' );
    jlwa_259_expect( array( 'HEAD', 'GET' ) === array_column( $seen_methods, 'method' ), 'Unexpected HEAD/GET fallback sequence' );
    jlwa_259_expect( 1024 === $seen_methods[1]['limit'], 'GET fallback did not cap response bytes' );
} finally {
    remove_filter( 'pre_http_request', $mock_http, 10 );
}
echo "媒体诊断优先 HEAD，拒绝 HEAD 时 GET 仅允许 1024 字节：通过\n";
