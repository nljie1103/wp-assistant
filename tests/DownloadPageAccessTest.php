<?php
/** Paid page gate tests: no browser-side HTML may be generated before redirect. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once dirname( __DIR__ ) . '/features/private-download.php';

// Explicit doubles for option reads because this test loads the real feature.
function get_option( $key, $default = false ) { return $GLOBALS['jlwa_test_stored_options'][$key] ?? $default; }
function add_settings_error( $setting, $code, $message, $type = 'error' ) {
    $GLOBALS['jlwa_test_settings_errors'][] = compact( 'setting', 'code', 'message', 'type' );
}
function get_settings_errors() { return $GLOBALS['jlwa_test_settings_errors'] ?? array(); }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, (array) $args ); }
function esc_url_raw( $url ) { return (string) $url; }

function get_post_meta( $id, $key, $single = false ) {
    return isset( $GLOBALS['jlwa_test_paid_meta'] ) ? $GLOBALS['jlwa_test_paid_meta'] : array();
}
function get_page_template_slug( $id ) {
    return isset( $GLOBALS['jlwa_test_download_slug'] ) ? $GLOBALS['jlwa_test_download_slug'] : '';
}
function apply_filters( $name, $default, ...$args ) { return $default; }
function get_permalink( $id ) { return 'https://example.test/' . (int) $id . '.html'; }
function add_query_arg( $key, $value, $url ) {
    return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . rawurlencode( $key ) . '=' . rawurlencode( $value );
}
function zibpay_is_paid( $id ) { return ! empty( $GLOBALS['jlwa_test_has_purchase'] ) ? array( 'paid_type' => 2 ) : false; }

class JLWA_Test_Redirect extends RuntimeException {
    public $destination;
    public $status;
    public function __construct( $destination, $status ) {
        parent::__construct( 'Intercepted WordPress redirect' );
        $this->destination = $destination;
        $this->status = $status;
    }
}
function wp_safe_redirect( $url, $status = 302, $x_redirect_by = '' ) {
    throw new JLWA_Test_Redirect( $url, $status );
}
function nocache_headers() { $GLOBALS['jlwa_test_no_cache'] = true; }

require_once dirname( __DIR__ ) . '/features/download-page.php';

use PHPUnit\Framework\TestCase;

final class DownloadPageAccessTest extends TestCase {
    private $feature;
    private $method;
    private $oldGet;

    protected function setUp(): void {
        $this->oldGet = $_GET;
        $GLOBALS['test_is_admin'] = false;
        $GLOBALS['test_is_page'] = true;
        $GLOBALS['test_is_singular'] = true;
        $GLOBALS['test_post'] = new WP_Post( 2823 );
        $GLOBALS['jlwa_test_paid_meta'] = array( 'pay_type' => 2 );
        $GLOBALS['jlwa_test_download_slug'] = '';
        $GLOBALS['jlwa_test_has_purchase'] = false;
        $_GET = array( 'post' => '2823' );
        $GLOBALS['jlwa_test_no_cache'] = false;
        $GLOBALS['jlwa_test_stored_options'] = array();
        $GLOBALS['jlwa_test_settings_errors'] = array();
        $this->feature = JLWA_Download_Page_Feature::instance();
        $this->method = new ReflectionMethod( JLWA_Download_Page_Feature::class, 'target_post_id' );
        $this->method->setAccessible( true );
    }

    protected function tearDown(): void {
        $_GET = $this->oldGet;
        unset( $GLOBALS['jlwa_test_paid_meta'], $GLOBALS['jlwa_test_download_slug'], $GLOBALS['jlwa_test_has_purchase'], $GLOBALS['jlwa_test_stored_options'], $GLOBALS['jlwa_test_settings_errors'] );
        $GLOBALS['test_is_admin'] = false;
        $GLOBALS['test_is_page'] = false;
        unset( $GLOBALS['test_is_singular'] );
    }

    public function test_published_paid_download_page_is_detected_without_visual_skin(): void {
        $this->assertSame( 2823, $this->method->invoke( $this->feature ) );
        $this->assertFalse( JLWA_Download_Page_Feature::has_purchase_rights( 2823 ) );
        $GLOBALS['jlwa_test_has_purchase'] = true;
        $this->assertTrue( JLWA_Download_Page_Feature::has_purchase_rights( 2823 ) );
    }

    public function test_unrelated_url_or_draft_and_nonpaid_posts_do_not_trigger(): void {
        $GLOBALS['test_is_page'] = false;
        $this->assertSame( 0, $this->method->invoke( $this->feature ) );
        $GLOBALS['jlwa_test_download_slug'] = 'pages/download.php';
        $this->assertSame( 2823, $this->method->invoke( $this->feature ) );
        $GLOBALS['test_post']->post_status = 'draft';
        $this->assertSame( 0, $this->method->invoke( $this->feature ) );
        $GLOBALS['test_post']->post_status = 'publish';
        $GLOBALS['jlwa_test_paid_meta']['pay_type'] = 0;
        $this->assertSame( 0, $this->method->invoke( $this->feature ) );
    }

    public function test_non_numeric_query_and_admin_do_not_trigger(): void {
        foreach ( array( '0', '-1', '2823x', '1/../2823', array( '2823' ), '' ) as $input ) {
            $_GET['post'] = $input;
            $this->assertSame( 0, $this->method->invoke( $this->feature ) );
        }
        $_GET['post'] = '2823';
        $GLOBALS['test_is_admin'] = true;
        $this->assertSame( 0, $this->method->invoke( $this->feature ) );
    }

    public function test_unpurchased_request_triggers_server_redirect_before_template_render(): void {
        try {
            $this->feature->guard_paid_download_page();
            $this->fail( 'Unpaid request should redirect, not render the download page' );
        } catch ( JLWA_Test_Redirect $r ) {
            $this->assertSame( 302, $r->status );
            $this->assertSame( 'https://example.test/2823.html?jlwa_purchase_required=2823#posts-pay', $r->destination );
            $this->assertTrue( $GLOBALS['jlwa_test_no_cache'] );
        }
        $GLOBALS['jlwa_test_has_purchase'] = true;
        $GLOBALS['jlwa_test_no_cache'] = false;
        $this->feature->guard_paid_download_page(); // Must return normally.
        $this->assertFalse( $GLOBALS['jlwa_test_no_cache'] );
    }

    public function test_notice_page_disables_shared_cache(): void {
        $_GET = array( 'jlwa_purchase_required' => '2823' );
        $this->feature->guard_paid_download_page();
        $this->assertTrue( $GLOBALS['jlwa_test_no_cache'] );
    }

    public function test_redirect_is_original_article_purchase_section(): void {
        $url = JLWA_Download_Page_Feature::purchase_redirect_url( 2823 );
        $this->assertSame( 'https://example.test/2823.html?jlwa_purchase_required=2823#posts-pay', $url );
        $this->assertStringNotContainsString( 'download?post=', $url );
    }

    public function test_success_feedback_only_after_settings_redirect(): void {
        ob_start();
        JLWA_Download_Page_Feature::save_feedback();
        $this->assertSame( '', ob_get_clean() );
        $_GET['settings-updated'] = 'true';
        // A forged query string must not claim a successful write.
        ob_start();
        JLWA_Download_Page_Feature::save_feedback();
        $unknown = ob_get_clean();
        $this->assertStringContainsString( '无法确认本次保存结果', $unknown );
        $GLOBALS['jlwa_test_settings_errors'][] = array(
            'setting' => 'general', 'code' => 'settings_updated',
            'message' => 'Settings saved.', 'type' => 'success',
        );
        ob_start();
        JLWA_Download_Page_Feature::save_feedback();
        $html = ob_get_clean();
        $this->assertStringContainsString( '下载页设置保存成功', $html );
        $this->assertStringContainsString( 'Nginx', $html );
        $this->assertStringNotContainsString( '保存失败', $html );
    }

    public function test_invalid_encoded_path_rejects_entire_save_and_shows_error(): void {
        $previous = JLWA_Download_Page_Feature::defaults();
        $previous['private_hosts'] = 'files.example.com';
        $previous['private_source_prefix'] = '/d/storage/private/';
        $previous['private_enabled'] = 0;
        $GLOBALS['jlwa_test_stored_options'][JLWA_Download_Page_Feature::OPTION_KEY] = $previous;
        $input = $previous;
        $input['private_enabled'] = 1;
        $input['private_source_prefix'] = '/d/storage/%E7%A7%81%E6%9C%89/';
        $saved = $this->feature->sanitize( $input );
        $this->assertSame( $previous, $saved );
        $this->assertNotEmpty( $GLOBALS['jlwa_test_settings_errors'] );
        $_GET['settings-updated'] = 'true';
        ob_start();
        JLWA_Download_Page_Feature::save_feedback();
        $html = ob_get_clean();
        $this->assertStringContainsString( '保存失败：原设置已保留', $html );
        $this->assertStringContainsString( '公开源路径前缀', $html );
        $this->assertStringContainsString( '不要使用 % 编码', $html );
        $this->assertStringNotContainsString( '下载页设置保存成功', $html );
    }

    public function test_invalid_delivery_domain_rejects_and_keeps_previous(): void {
        $previous = JLWA_Download_Page_Feature::defaults();
        $GLOBALS['jlwa_test_stored_options'][JLWA_Download_Page_Feature::OPTION_KEY] = $previous;
        $submitted = $previous;
        $submitted['delivery_enabled'] = 1;
        $submitted['delivery_host'] = 'https://download.example.com';
        $this->assertSame( $previous, $this->feature->sanitize( $submitted ) );
        $this->assertStringContainsString( '下载出口域名', $GLOBALS['jlwa_test_settings_errors'][0]['message'] );
    }

    public function test_valid_settings_can_save_without_error(): void {
        $input = JLWA_Download_Page_Feature::defaults();
        $input['private_hosts'] = "files.example.com";
        $input['private_source_prefix'] = '/d/storage/private/';
        $input['private_enabled'] = 1;
        $saved = $this->feature->sanitize( $input );
        $this->assertSame( 1, $saved['private_enabled'] );
        $this->assertSame( '/d/storage/private/', $saved['private_source_prefix'] );
        $this->assertSame( array(), $GLOBALS['jlwa_test_settings_errors'] );
    }

    public function test_prompt_is_visible_only_on_matching_article(): void {
        $_GET = array( 'jlwa_purchase_required' => '2823' );
        ob_start();
        $this->feature->render_purchase_required_notice();
        $html = ob_get_clean();
        $this->assertStringContainsString( '尚未购买商品', $html );
        $this->assertStringContainsString( '请先购买商品，再来访问下载页', $html );

        $_GET['jlwa_purchase_required'] = '2830';
        ob_start();
        $this->feature->render_purchase_required_notice();
        $this->assertSame( '', ob_get_clean() );

        $_GET['jlwa_purchase_required'] = array( '2823' );
        ob_start();
        $this->feature->render_purchase_required_notice();
        $this->assertSame( '', ob_get_clean() );
    }
}
