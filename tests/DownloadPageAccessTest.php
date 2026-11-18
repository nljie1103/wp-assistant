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
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( $value ) { return preg_replace( "/[^a-z0-9_-]/", "", strtolower( (string) $value ) ); }

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
function zibpay_get_post_down_buts() {}
function zibpay_get_post_down_array() {}
function zib_get_page_content_style() {}


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
require_once dirname( __DIR__ ) . '/features/private-download-deploy.php';

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

    public function test_privileged_helper_status_does_not_depend_on_php_open_basedir_file_visibility(): void {
        // Regression: Baota PHP-FPM often cannot stat /usr/local/sbin because
        // open_basedir is limited to the website. The root helper may still
        // be installed and reachable through the approved sudo command.
        $code = file_get_contents( dirname( __DIR__ ) . '/features/private-download-deploy.php' );
        $this->assertStringNotContainsString( 'is_file( self::BRIDGE )', $code );
        $this->assertStringContainsString( "self::call( 'status' )", $code );
        $this->assertStringContainsString( 'function_exists( \'proc_open\' )', $code );
        $this->assertStringContainsString( "array( '/usr/bin/sudo', '-n', self::BRIDGE, \$operation )", $code );
        $this->assertStringContainsString( '安装状态尚未确认（不代表未安装）', $code );
    }

    public function test_deployment_helper_operation_whitelist_remains_closed(): void {
        $this->assertSame( array( 'ok' => false, 'message' => '不支持的部署操作。' ), JLWA_Private_Deploy::call( 'install-auto' ) );
        $this->assertSame( array( 'ok' => false, 'message' => '不支持的部署操作。' ), JLWA_Private_Deploy::call( 'sudo -i' ) );
    }

    public function test_public_images_only_upgrade_generates_root_review_and_apply_commands(): void {
        $settings = JLWA_Private_Download::defaults();
        $settings['private_source_prefix'] = '/d/storage/private/';
        $settings['openlist_public_images'] = '/d/storage/图片/';
        $directory = '/opt/wp/wp-content/plugins/wp-assistant';
        $preview = JLWA_Private_Deploy::public_images_command( $settings, 'preview-images-only', $directory );
        $apply = JLWA_Private_Deploy::public_images_command( $settings, 'upgrade-images-only', $directory );
        $this->assertStringContainsString( ' preview-images-only --public-images ', $preview );
        $this->assertStringContainsString( ' upgrade-images-only --public-images ', $apply );
        $this->assertStringContainsString( "'/d/storage/图片/'", $preview );
        $this->assertStringContainsString( "'/d/storage/图片/'", $apply );
        $this->assertStringContainsString( "'/opt/wp/wp-content/plugins/wp-assistant/ops/jlwa-nginx-helper.py'", $apply );
        $this->assertSame( '', JLWA_Private_Deploy::public_images_command( $settings, 'status', $directory ) );
        $settings['openlist_public_images'] = '/d/storage/private/';
        $this->assertSame( '', JLWA_Private_Deploy::public_images_command( $settings, 'upgrade-images-only', $directory ) );
        $settings['openlist_public_images'] = '/d/another/图片/';
        $this->assertSame( '', JLWA_Private_Deploy::public_images_command( $settings, 'upgrade-images-only', $directory ) );
    }

    public function test_image_only_root_rollback_command_is_distinct_from_full_restore(): void {
        $command = JLWA_Private_Deploy::public_images_rollback_command( '/opt/wp/wp-content/plugins/wp-assistant' );
        $this->assertSame(
            "sudo python3 '/opt/wp/wp-content/plugins/wp-assistant/ops/jlwa-nginx-helper.py' rollback-images-only",
            $command
        );
        $this->assertStringNotContainsString( ' rollback', substr( $command, 0, -strlen('rollback-images-only') ) );
        $this->assertSame( '', JLWA_Private_Deploy::public_images_rollback_command( './relative' ) );
    }

    public function test_image_whitelist_setting_saves_only_in_advanced_scope(): void {
        $old = JLWA_Download_Page_Feature::defaults();
        $old['private_hosts'] = 'files.example.com';
        $old['private_source_prefix'] = '/d/storage/private/';
        $GLOBALS['jlwa_test_stored_options'][JLWA_Download_Page_Feature::OPTION_KEY] = $old;
        $saved = $this->feature->sanitize( array(
            'jlwa_settings_scope' => 'advanced',
            'openlist_public_images' => '/d/storage/图片/',
        ) );
        $this->assertSame( '/d/storage/图片/', $saved['openlist_public_images'] );
        $this->assertSame( $old['private_source_prefix'], $saved['private_source_prefix'] );
        $this->assertSame( $old['private_hosts'], $saved['private_hosts'] );
        $GLOBALS['jlwa_test_stored_options'][JLWA_Download_Page_Feature::OPTION_KEY] = $saved;
        $visual = $this->feature->sanitize( array( 'jlwa_settings_scope' => 'templates', 'template' => 'vip' ) );
        $this->assertSame( '/d/storage/图片/', $visual['openlist_public_images'] );
        $this->assertSame( array(), $GLOBALS['jlwa_test_settings_errors'] );
    }

    public function test_image_whitelist_rejects_private_subfolder_and_other_mount(): void {
        $old = JLWA_Download_Page_Feature::defaults();
        $old['private_hosts'] = 'files.example.com';
        $old['private_source_prefix'] = '/d/storage/private/';
        $GLOBALS['jlwa_test_stored_options'][JLWA_Download_Page_Feature::OPTION_KEY] = $old;
        foreach ( array( '/d/storage/private/', '/d/storage/private/sub/', '/d/storage/', '/d/other/图片/', '/p/storage/图片/' ) as $bad ) {
            $GLOBALS['jlwa_test_settings_errors'] = array();
            $saved = $this->feature->sanitize( array(
                'jlwa_settings_scope' => 'advanced',
                'openlist_public_images' => $bad,
            ) );
            $this->assertSame( $old, $saved, $bad );
            $this->assertNotEmpty( $GLOBALS['jlwa_test_settings_errors'], $bad );
        }
    }

    public function test_nginx_operations_return_to_deployment_tab_and_result_anchor(): void {
        $preview = JLWA_Private_Deploy::result_redirect_url( 'deployment' );
        $probe = JLWA_Private_Deploy::result_redirect_url( 'probe' );
        $this->assertSame( 'https://example.test/wp-admin/admin.php?page=jlwa-download-page&tab=deployment&jlwa_deploy_result=1#jlwa-deploy-result', $preview );
        $this->assertSame( 'https://example.test/wp-admin/admin.php?page=jlwa-download-page&tab=deployment&jlwa_probe_result=1#jlwa-probe-result', $probe );
        $this->assertSame( $preview, JLWA_Private_Deploy::result_redirect_url( 'invalid' ) );
    }

    public function test_nginx_result_redirect_is_used_for_both_deploy_and_probe(): void {
        $code = file_get_contents( dirname( __DIR__ ) . '/features/private-download-deploy.php' );
        $this->assertStringContainsString( "wp_safe_redirect( self::result_redirect_url( 'deployment' ), 303 )", $code );
        $this->assertStringContainsString( "wp_safe_redirect( self::result_redirect_url( 'probe' ), 303 )", $code );
        $this->assertStringNotContainsString( "wp_safe_redirect( admin_url( 'admin.php?page=jlwa-download-page' ) )", $code );
    }

    public function test_one_line_autoinstall_is_generated_from_saved_site_settings(): void {
        $settings = JLWA_Private_Download::defaults();
        $this->assertSame( '', JLWA_Private_Deploy::installer_command( $settings, 'site.example.com', '/opt/wordpress/wp-content/plugins/wp-assistant', 'www' ) );
        $settings['private_hosts'] = "files.example.com\nfiles-cdn.example.com";
        $settings['private_source_prefix'] = '/d/storage/private/';
        $settings['openlist_host_port'] = '5244';
        $command = JLWA_Private_Deploy::installer_command( $settings, 'site.example.com', '/opt/wordpress/wp-content/plugins/wp-assistant', 'www' );
        $this->assertStringContainsString( 'install-auto', $command );
        $this->assertStringContainsString( "--wordpress-host 'site.example.com'", $command );
        $this->assertStringContainsString( "--openlist-host 'files.example.com'", $command );
        $this->assertStringContainsString( "--port '5244'", $command );
        $this->assertStringContainsString( "--source '/d/storage/private/'", $command );
        $this->assertStringNotContainsString( '--public-images', $command );
        $settings['openlist_public_images'] = '/d/storage/图片/';
        $direct = JLWA_Private_Deploy::installer_command( $settings, 'site.example.com', '/opt/wordpress/wp-content/plugins/wp-assistant', 'www' );
        $this->assertStringContainsString( "--public-images '/d/storage/图片/'", $direct );
        $this->assertStringNotContainsString( 'upgrade-images-only', $direct );
        $settings['openlist_public_images'] = '/d/storage/private/';
        $this->assertSame( '', JLWA_Private_Deploy::installer_command( $settings, 'site.example.com', '/opt/wordpress/wp-content/plugins/wp-assistant', 'www' ) );
        $settings['openlist_public_images'] = '/d/storage/图片/';

        $this->assertStringNotContainsString( '--wordpress-vhost', $command );
        $this->assertStringNotContainsString( '--openlist-vhost', $command );
        $settings['openlist_container'] = 'my-openlist';
        $with_container = JLWA_Private_Deploy::installer_command( $settings, 'site.example.com', '/opt/wordpress/wp-content/plugins/wp-assistant', 'www' );
        $this->assertStringContainsString( "--container 'my-openlist'", $with_container );
        $settings['openlist_host_port'] = '99999';
        $this->assertSame( '', JLWA_Private_Deploy::installer_command( $settings, 'site.example.com', '/opt/wordpress/wp-content/plugins/wp-assistant', 'www' ) );
    }

    public function test_untrusted_port_or_docker_name_rejects_settings_atomically(): void {
        $stored = JLWA_Download_Page_Feature::defaults();
        $GLOBALS['jlwa_test_stored_options'][JLWA_Download_Page_Feature::OPTION_KEY] = $stored;
        $bad = $stored;
        $bad['openlist_host_port'] = '0';
        $this->assertSame( $stored, $this->feature->sanitize( $bad ) );
        $this->assertStringContainsString( '本机端口', $GLOBALS['jlwa_test_settings_errors'][0]['message'] );
        $GLOBALS['jlwa_test_settings_errors'] = array();
        $bad = $stored;
        $bad['openlist_container'] = 'openlist;rm -rf /';
        $this->assertSame( $stored, $this->feature->sanitize( $bad ) );
        $this->assertStringContainsString( '容器名称', $GLOBALS['jlwa_test_settings_errors'][0]['message'] );
    }

    public function test_download_admin_tabs_and_invalid_fallback(): void {
        $_GET['tab'] = 'security';
        $this->assertSame( 'security', JLWA_Download_Page_Feature::admin_tab() );
        $_GET['tab'] = 'deployment';
        $this->assertSame( 'deployment', JLWA_Download_Page_Feature::admin_tab() );
        $_GET['tab'] = 'advanced';
        $this->assertSame( 'advanced', JLWA_Download_Page_Feature::admin_tab() );
        $_GET['tab'] = 'help';
        $this->assertSame( 'security', JLWA_Download_Page_Feature::admin_tab() );
        $_GET['tab'] = 'invalid';
        $this->assertSame( 'templates', JLWA_Download_Page_Feature::admin_tab() );
        $_GET['tab'] = array( 'security' );
        $this->assertSame( 'templates', JLWA_Download_Page_Feature::admin_tab() );
        ob_start();
        JLWA_Download_Page_Feature::render_admin_tabs( 'security' );
        $html = ob_get_clean();
        foreach ( array( '下载页模板', '私有安全交付', '交付高级设置', 'Nginx 自动部署', '下载记录' ) as $label ) {
            $this->assertStringContainsString( $label, $html );
        }
        $this->assertStringNotContainsString( 'tab=help', $html );
        $this->assertStringNotContainsString( '>说明<', $html );
        $this->assertStringContainsString( 'tab=advanced', $html );
        $this->assertStringContainsString( 'tab=security', $html );
        $this->assertSame( 1, substr_count( $html, 'aria-current="page"' ) );
    }

    public function test_visual_form_does_not_reset_saved_private_download_security(): void {
        $old = JLWA_Download_Page_Feature::defaults();
        $old['private_hosts'] = 'files.example.com';
        $old['private_source_prefix'] = '/d/storage/private/';
        $old['private_enabled'] = 1;
        $old['delivery_host'] = 'download.example.com';
        $old['delivery_enabled'] = 1;
        $old['audit_enabled'] = 1;
        $GLOBALS['jlwa_test_stored_options'][JLWA_Download_Page_Feature::OPTION_KEY] = $old;
        $saved = $this->feature->sanitize( array(
            'jlwa_settings_scope' => 'templates',
            'template' => 'editorial',
            'cover_mode' => 'default',
            'default_image_url' => 'https://site.example.com/a.png',
            // omitted visual checkbox means explicitly disabled.
        ) );
        $this->assertSame( 0, $saved['enabled'] );
        $this->assertSame( 'editorial', $saved['template'] );
        foreach ( array( 'private_hosts', 'private_source_prefix', 'private_enabled', 'delivery_host', 'delivery_enabled', 'audit_enabled' ) as $key ) {
            $this->assertSame( $old[ $key ], $saved[ $key ], $key );
        }
    }

    public function test_visual_only_save_preserves_legacy_invalid_security_fields_verbatim(): void {
        $old = JLWA_Download_Page_Feature::defaults();
        $old['private_hosts'] = 'old-site.example.net';
        $old['private_source_prefix'] = '/d/storage/%E6%B5%8B%E8%AF%95/';
        $old['private_enabled'] = 0;
        $GLOBALS['jlwa_test_stored_options'][JLWA_Download_Page_Feature::OPTION_KEY] = $old;
        $saved = $this->feature->sanitize( array(
            'jlwa_settings_scope' => 'templates',
            'enabled' => '1',
            'template' => 'vip',
            'cover_mode' => 'featured',
        ) );
        $this->assertSame( 'vip', $saved['template'] );
        $this->assertSame( $old['private_source_prefix'], $saved['private_source_prefix'] );
        $this->assertSame( $old['private_hosts'], $saved['private_hosts'] );
        $this->assertSame( array(), $GLOBALS['jlwa_test_settings_errors'] );
    }

    public function test_security_form_does_not_reset_saved_visual_settings(): void {
        $old = JLWA_Download_Page_Feature::defaults();
        $old['enabled'] = 1;
        $old['template'] = 'midnight';
        $old['cover_mode'] = 'featured';
        $old['default_image_url'] = 'https://site.example.com/cover.jpg';
        $old['private_hosts'] = 'files.example.com';
        $GLOBALS['jlwa_test_stored_options'][JLWA_Download_Page_Feature::OPTION_KEY] = $old;
        $saved = $this->feature->sanitize( array(
            'jlwa_settings_scope' => 'security',
            'private_hosts' => 'files.example.com',
            'private_source_prefix' => '/d/storage/private/',
            'private_internal_prefix' => '/_jlwa_private_openlist/',
            'private_enabled' => '1',
            'openlist_host_port' => '5244',
            'audit_enabled' => '1',
        ) );
        foreach ( array( 'enabled', 'template', 'cover_mode', 'default_image_url' ) as $key ) {
            $this->assertSame( $old[ $key ], $saved[ $key ], $key );
        }
        $this->assertSame( 1, $saved['private_enabled'] );
        $this->assertSame( '', $saved['openlist_host_port'] ); // Moved to advanced tab, cannot overwrite from security form.
        $this->assertSame( 0, $saved['rate_enabled'] );
        $this->assertSame( 0, $saved['delivery_enabled'] );
    }

    public function test_advanced_form_preserves_private_security_and_visual_settings(): void {
        $old = JLWA_Download_Page_Feature::defaults();
        $old['enabled'] = 1;
        $old['template'] = 'vip';
        $old['private_hosts'] = 'files.example.com';
        $old['private_enabled'] = 1;
        $old['openlist_host_port'] = '5244';
        $old['delivery_enabled'] = 1;
        $old['delivery_host'] = 'download.example.com';
        $old['rate_enabled'] = 1;
        $GLOBALS['jlwa_test_stored_options'][JLWA_Download_Page_Feature::OPTION_KEY] = $old;
        $saved = $this->feature->sanitize( array(
            'jlwa_settings_scope' => 'advanced',
            'openlist_host_port' => '5245',
            'openlist_container' => 'openlist',
            'delivery_host' => 'download.example.com',
            'ticket_lifetime' => '240',
            'rate_limit' => '45',
            // Missing checkboxes are intentionally disabled only in this group.
        ) );
        foreach ( array( 'enabled', 'template', 'private_hosts', 'private_source_prefix', 'private_internal_prefix', 'private_enabled' ) as $key ) {
            $this->assertSame( $old[$key], $saved[$key], $key );
        }
        $this->assertSame( '5245', $saved['openlist_host_port'] );
        $this->assertSame( 'openlist', $saved['openlist_container'] );
        $this->assertSame( 240, $saved['ticket_lifetime'] );
        $this->assertSame( 45, $saved['rate_limit'] );
        $this->assertSame( 0, $saved['delivery_enabled'] );
        $this->assertSame( 0, $saved['audit_enabled'] );
        $this->assertSame( 0, $saved['rate_enabled'] );
        $this->assertEmpty( $GLOBALS['jlwa_test_settings_errors'] );
    }

    public function test_security_form_does_not_reset_advanced_download_settings(): void {
        $old = JLWA_Download_Page_Feature::defaults();
        $old['private_hosts'] = 'files.example.com';
        $old['private_enabled'] = 1;
        $old['openlist_host_port'] = '5245';
        $old['openlist_container'] = 'openlist';
        $old['delivery_host'] = 'download.example.com';
        $old['delivery_enabled'] = 1;
        $old['rate_enabled'] = 1;
        $old['rate_limit'] = 40;
        $GLOBALS['jlwa_test_stored_options'][JLWA_Download_Page_Feature::OPTION_KEY] = $old;
        $saved = $this->feature->sanitize( array(
            'jlwa_settings_scope' => 'security',
            'private_hosts' => 'files.example.com',
            'private_source_prefix' => '/d/storage/private/',
            'private_internal_prefix' => '/_jlwa_private_openlist/',
            'private_enabled' => 1,
        ) );
        foreach ( array( 'openlist_host_port', 'openlist_container', 'delivery_host', 'delivery_enabled', 'audit_enabled', 'rate_enabled', 'rate_limit' ) as $key ) {
            $this->assertSame( $old[$key], $saved[$key], $key );
        }
        $this->assertEmpty( $GLOBALS['jlwa_test_settings_errors'] );
    }

    public function test_advanced_form_rejects_bad_port_without_disabling_private_delivery(): void {
        $old = JLWA_Download_Page_Feature::defaults();
        $old['private_hosts'] = 'files.example.com';
        $old['private_enabled'] = 1;
        $GLOBALS['jlwa_test_stored_options'][JLWA_Download_Page_Feature::OPTION_KEY] = $old;
        $saved = $this->feature->sanitize( array(
            'jlwa_settings_scope' => 'advanced',
            'openlist_host_port' => '-1',
        ) );
        $this->assertSame( $old, $saved );
        $this->assertNotEmpty( $GLOBALS['jlwa_test_settings_errors'] );
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

    public function test_default_download_support_does_not_redirect_users_to_plugin_author() {
        $defaults = JLWA_Download_Page_Feature::defaults();
        $this->assertSame( '', $defaults['support_primary_url'] );
        $this->assertSame( '', $defaults['support_secondary_url'] );

        ob_start();
        JLWA_Download_Page_Feature::render_support_panel( $defaults );
        $this->assertSame( '', ob_get_clean() );

        foreach ( array( 'technology', 'business', 'vip' ) as $skin ) {
            $source = file_get_contents( dirname( __DIR__ ) . '/features/download-page-templates/' . $skin . '.php' );
            $this->assertStringNotContainsString( 't.me/jiuliu_org', $source );
            $this->assertStringNotContainsString( 't.me/jiuliu_group', $source );
            $this->assertStringContainsString( 'render_support_panel( $jlwa_download_options )', $source );
        }
    }

    public function test_contact_links_and_button_labels_are_sanitized_and_rendered() {
        $input = array(
            'jlwa_settings_scope' => 'templates',
            'enabled' => 1,
            'template' => 'aurora',
            'support_title' => '<b>售前与售后</b>',
            'support_description' => '<i>所有订单问题</i>',
            'support_primary_url' => 'https://t.me/example_support',
            'support_primary_label' => '<b>立即咨询</b>',
            'support_secondary_url' => 'https://example.com/community?from=download&join=1',
            'support_secondary_label' => '<i>社区交流</i>',
        );
        $saved = $this->feature->sanitize( $input );
        $this->assertSame( '售前与售后', $saved['support_title'] );
        $this->assertSame( '立即咨询', $saved['support_primary_label'] );
        $this->assertSame( '社区交流', $saved['support_secondary_label'] );
        $this->assertSame( 'aurora', $saved['template'] );
        ob_start();
        JLWA_Download_Page_Feature::render_support_panel( $saved );
        $html = ob_get_clean();
        $this->assertStringContainsString( 'href="https://t.me/example_support"', $html );
        $this->assertStringContainsString( 'href="https://example.com/community?from=download&amp;join=1"', $html );
        $this->assertStringContainsString( '售前与售后', $html );
        $this->assertStringContainsString( '立即咨询', $html );
        $this->assertStringContainsString( '社区交流', $html );
        $this->assertSame( 2, substr_count( $html, '<a ' ) );
        $this->assertStringNotContainsString( 't.me/jiuliu_org', $html );
        $this->assertStringNotContainsString( '<b>', $html );
    }

    public function test_invalid_contact_urls_are_rejected_without_php_warnings() {
        foreach ( array( 'javascript:alert(1)', 'data:text/html,abc', '//example.com/contact', array( 'https://evil.example' ) ) as $bad ) {
            $submitted = $this->feature->sanitize( array(
                'jlwa_settings_scope' => 'templates',
                'support_primary_url' => $bad,
                'support_secondary_url' => 'mailto:help@example.com',
            ) );
            $this->assertSame( '', $submitted['support_primary_url'] );
            $this->assertSame( 'mailto:help@example.com', $submitted['support_secondary_url'] );
        }
    }

    public function test_contact_configuration_survives_other_tabs_and_legacy_forms_but_can_be_cleared() {
        $old = JLWA_Download_Page_Feature::defaults();
        $old['support_primary_url'] = 'https://support.example.com';
        $old['support_primary_label'] = '售后专线';
        $old['support_secondary_url'] = 'https://example.com/community';
        $old['private_hosts'] = 'files.example.com';
        $old['private_source_prefix'] = '/d/storage/private/';
        $GLOBALS['jlwa_test_stored_options'][JLWA_Download_Page_Feature::OPTION_KEY] = $old;

        $secure = $this->feature->sanitize( array( 'jlwa_settings_scope' => 'security', 'private_enabled' => 0 ) );
        $this->assertSame( $old['support_primary_url'], $secure['support_primary_url'] );
        $this->assertSame( $old['support_secondary_url'], $secure['support_secondary_url'] );
        $this->assertSame( $old['support_primary_label'], $secure['support_primary_label'] );
        $legacy = $this->feature->sanitize( array( 'enabled' => 1, 'template' => 'vip' ) );
        $this->assertSame( $old['support_primary_url'], $legacy['support_primary_url'] );
        $this->assertSame( $old['support_secondary_url'], $legacy['support_secondary_url'] );

        $cleared = $this->feature->sanitize( array(
            'jlwa_settings_scope' => 'templates',
            'support_primary_url' => '',
            'support_secondary_url' => '',
        ) );
        $this->assertSame( '', $cleared['support_primary_url'] );
        $this->assertSame( '', $cleared['support_secondary_url'] );
        $this->assertSame( $old['private_source_prefix'], $cleared['private_source_prefix'] );
        ob_start();
        JLWA_Download_Page_Feature::render_support_panel( $cleared );
        $this->assertSame( '', ob_get_clean() );
    }

    public function test_one_custom_support_link_only_renders_one_button() {
        $options = JLWA_Download_Page_Feature::defaults();
        $options['support_secondary_url'] = 'https://example.com/chat';
        ob_start();
        JLWA_Download_Page_Feature::render_support_panel( $options );
        $html = ob_get_clean();
        $this->assertSame( 1, substr_count( $html, '<a ' ) );
        $this->assertStringContainsString( 'href="https://example.com/chat"', $html );
    }


    public function test_native_skin_keeps_zibll_in_charge_of_the_download_template(): void {
        $options = JLWA_Download_Page_Feature::defaults();
        $options['enabled'] = 1;
        $options['render_mode'] = 'native';
        $options['template'] = 'technology';
        $GLOBALS['jlwa_test_stored_options'][JLWA_Download_Page_Feature::OPTION_KEY] = $options;

        // No paid privilege means no skin or customer-service panel.
        $this->assertSame( array( 'test' ), $this->feature->native_body_class( array( 'test' ) ) );
        ob_start();
        $this->feature->render_native_support();
        $this->assertSame( '', ob_get_clean() );

        $GLOBALS['jlwa_test_has_purchase'] = true;
        foreach ( array( 'technology', 'business', 'vip', 'aurora', 'editorial', 'midnight' ) as $skin ) {
            $options['template'] = $skin;
            $GLOBALS['jlwa_test_stored_options'][JLWA_Download_Page_Feature::OPTION_KEY] = $options;
            $classes = $this->feature->native_body_class( array( 'test' ) );
            $this->assertContains( 'jlwa-native-download', $classes );
            $this->assertContains( 'jlwa-native-' . $skin, $classes );
            $this->assertContains( 'test', $classes );
        }

        $source = file_get_contents( dirname( __DIR__ ) . '/features/download-page.php' );
        $this->assertStringNotContainsString( 'include $selected;', $source );
        $this->assertStringNotContainsString( "add_action( 'template_redirect', array( " . '$this' . ", 'maybe_render' )", $source );
        $this->assertStringContainsString( "add_action( 'template_redirect', array( " . '$this' . ", 'guard_paid_download_page' )", $source );
        $this->assertFileExists( dirname( __DIR__ ) . '/assets/css/download-page-native.css' );
        $this->assertFileExists( dirname( __DIR__ ) . '/assets/js/download-page-native.js' );
    }

    public function test_native_service_panel_only_adds_own_marked_markup(): void {
        $options = JLWA_Download_Page_Feature::defaults();
        $options['enabled'] = 1;
        $options['render_mode'] = 'native';
        $options['template'] = 'midnight';
        $options['support_primary_url'] = 'https://example.test/help';
        $options['support_primary_label'] = '售后客服';
        $GLOBALS['jlwa_test_stored_options'][JLWA_Download_Page_Feature::OPTION_KEY] = $options;
        $GLOBALS['jlwa_test_has_purchase'] = true;
        ob_start();
        $this->feature->render_native_support();
        $html = ob_get_clean();
        $this->assertStringContainsString( 'id="jlwa-native-support"', $html );
        $this->assertStringContainsString( 'data-jlwa-owned="1"', $html );
        $this->assertStringContainsString( 'https://example.test/help', $html );
        $this->assertStringContainsString( '售后客服', $html );
        $this->assertStringNotContainsString( 't.me/jiuliu_org', $html );
        $this->assertSame( 1, substr_count( $html, '<a ' ) );
    }

    public function test_native_mode_keeps_private_delivery_separately_scoped(): void {
        $options = JLWA_Download_Page_Feature::defaults();
        $options['enabled'] = 1;
        $GLOBALS['jlwa_test_stored_options'][JLWA_Download_Page_Feature::OPTION_KEY] = $options;
        $this->assertFalse( JLWA_Private_Download::private_delivery_enabled() );
        $options['private_enabled'] = 1;
        $GLOBALS['jlwa_test_stored_options'][JLWA_Download_Page_Feature::OPTION_KEY] = $options;
        $this->assertTrue( JLWA_Private_Download::private_delivery_enabled() );
        $this->assertSame( 1, $GLOBALS['jlwa_test_stored_options'][JLWA_Download_Page_Feature::OPTION_KEY]['private_enabled'] );
    }


    public function test_classic_full_layout_is_restored_by_default_without_early_exit(): void {
        $this->assertSame( 'classic', JLWA_Download_Page_Feature::defaults()['render_mode'] );
        $original = '/wordpress/theme/pages/download.php';
        $this->assertSame( $original, $this->feature->choose_download_template( $original ) );

        $options = JLWA_Download_Page_Feature::defaults();
        $options['enabled'] = 1;
        $options['template'] = 'business';
        $GLOBALS['jlwa_test_stored_options'][JLWA_Download_Page_Feature::OPTION_KEY] = $options;
        $this->assertSame( $original, $this->feature->choose_download_template( $original ) ); // Unpurchased.
        $GLOBALS['jlwa_test_has_purchase'] = true;
        foreach ( array(
            'technology' => 'technology',
            'business' => 'business',
            'vip' => 'vip',
            'aurora' => 'technology',
            'editorial' => 'technology',
            'midnight' => 'technology',
        ) as $skin => $renderer ) {
            $options['template'] = $skin;
            $GLOBALS['jlwa_test_stored_options'][JLWA_Download_Page_Feature::OPTION_KEY] = $options;
            $selected = $this->feature->choose_download_template( $original );
            $this->assertSame(
                dirname( __DIR__ ) . '/features/download-page-templates/' . $renderer . '.php',
                $selected,
                $skin
            );
            $this->assertFileExists( $selected );
            // Classic mode cannot also inject native CSS/classes or duplicate support.
            $this->assertSame( array( 'original' ), $this->feature->native_body_class( array( 'original' ) ) );
            ob_start();
            $this->feature->render_native_support();
            $this->assertSame( '', ob_get_clean() );
        }
        $source = file_get_contents( dirname( __DIR__ ) . '/features/download-page.php' );
        $this->assertStringContainsString( "add_filter( 'template_include'", $source );
        $this->assertStringNotContainsString( 'include $selected;', $source );
    }

    public function test_classic_and_native_modes_switch_without_disabling_download_security(): void {
        $old = JLWA_Download_Page_Feature::defaults();
        $old['enabled'] = 1;
        $old['private_enabled'] = 1;
        $old['private_hosts'] = 'files.example.test';
        $old['private_source_prefix'] = '/d/storage/private/';
        $old['support_primary_url'] = 'https://example.test/help';
        $GLOBALS['jlwa_test_stored_options'][JLWA_Download_Page_Feature::OPTION_KEY] = $old;

        $native = $this->feature->sanitize( array(
            'jlwa_settings_scope' => 'templates',
            'enabled' => 1,
            'render_mode' => 'native',
            'template' => 'aurora',
        ) );
        $this->assertSame( 'native', $native['render_mode'] );
        $this->assertSame( 1, $native['private_enabled'] );
        $this->assertSame( 'files.example.test', $native['private_hosts'] );
        $this->assertSame( 'https://example.test/help', $native['support_primary_url'] );
        $GLOBALS['jlwa_test_stored_options'][JLWA_Download_Page_Feature::OPTION_KEY] = $native;
        $GLOBALS['jlwa_test_has_purchase'] = true;
        $this->assertSame( 'original-theme.php', $this->feature->choose_download_template( 'original-theme.php' ) );
        $this->assertContains( 'jlwa-native-aurora', $this->feature->native_body_class( array() ) );

        $classic = $this->feature->sanitize( array(
            'jlwa_settings_scope' => 'templates',
            'enabled' => 1,
            'render_mode' => 'classic',
            'template' => 'aurora',
        ) );
        $this->assertSame( 'classic', $classic['render_mode'] );
        $this->assertSame( $native['private_hosts'], $classic['private_hosts'] );
        $this->assertSame( $native['support_primary_url'], $classic['support_primary_url'] );
        $GLOBALS['jlwa_test_stored_options'][JLWA_Download_Page_Feature::OPTION_KEY] = $classic;
        $this->assertStringContainsString(
            '/download-page-templates/technology.php',
            $this->feature->choose_download_template( 'original-theme.php' )
        );
        foreach ( array( 'technology', 'business', 'vip' ) as $file ) {
            $source = file_get_contents( dirname( __DIR__ ) . '/features/download-page-templates/' . $file . '.php' );
            $this->assertStringContainsString( 'JLWA_Download_Page_Feature::options()', $source );
            $this->assertStringContainsString( 'render_support_panel( $jlwa_download_options )', $source );
        }
    }

}
