<?php
/** Deterministic cross-domain asset tests: no live sites or CORS exceptions. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! defined( 'JRMU_VERSION' ) ) { define( 'JRMU_VERSION', '4.2.0' ); }
if ( ! defined( 'JRMU_OPTION_KEY' ) ) { define( 'JRMU_OPTION_KEY', 'jiuliu_relative_media_urls_options' ); }
require_once dirname( __DIR__ ) . '/features/relative-media-urls/includes/class-jrmu-settings.php';
require_once dirname( __DIR__ ) . '/features/relative-media-urls/includes/class-jrmu-domain-adapter.php';

use PHPUnit\Framework\TestCase;

final class MediaDomainAdapterTest extends TestCase {
    private $adapter;
    private $savedServer;

    protected function setUp(): void {
        $this->savedServer = $_SERVER;
        $_SERVER['HTTP_HOST'] = 'mirror.example.com';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REQUEST_URI'] = '/';
        $GLOBALS['test_is_admin'] = false;
        $GLOBALS['jlwa_test_stored_options'][JRMU_OPTION_KEY] = array(
            'domain_adaptation_enabled' => 1,
            'domain_asset_rewrite_enabled' => 1,
            'domain_allowed_hosts' => 'mirror.example.com',
            'domain_dynamic_siteurl' => 0,
            'domain_exclude_admin' => 1,
            'domain_exclude_login' => 1,
            'domain_skip_system_endpoints' => 1,
            'domain_scheme' => 'https',
            'domain_rewrite_mode' => 'current_full',
        );
        $GLOBALS['jlwa_test_stored_options']['home'] = 'https://origin.example.com';
        $GLOBALS['jlwa_test_stored_options']['siteurl'] = 'https://origin.example.com';
        $this->adapter = JRMU_Domain_Adapter::instance();
        foreach ( array( 'allowed_domain_hosts_cache', 'rewrite_source_hosts_cache' ) as $property ) {
            $reflect = new ReflectionProperty( JRMU_Domain_Adapter::class, $property );
            $reflect->setAccessible( true );
            $reflect->setValue( $this->adapter, null );
        }
    }

    protected function tearDown(): void {
        $_SERVER = $this->savedServer;
        unset( $GLOBALS['jlwa_test_stored_options'][JRMU_OPTION_KEY] );
        unset( $GLOBALS['jlwa_test_stored_options']['home'], $GLOBALS['jlwa_test_stored_options']['siteurl'] );
        $GLOBALS['test_is_admin'] = false;
    }

    public function test_css_and_relative_font_are_same_origin_on_whitelisted_alias(): void {
        $original = 'https://origin.example.com/wp-content/themes/zibll/css/font-awesome.min.css?ver=9.2';
        $expected = 'https://mirror.example.com/wp-content/themes/zibll/css/font-awesome.min.css?ver=9.2';
        $this->assertSame( $expected, $this->adapter->rewrite_asset_url( $original ) );
        $css_origin = wp_parse_url( $expected, PHP_URL_HOST );
        $font_origin = wp_parse_url( 'https://' . $css_origin . '/wp-content/themes/zibll/fonts/fontawesome-webfont.woff?v=4.7.0', PHP_URL_HOST );
        $this->assertSame( 'mirror.example.com', $font_origin );
        $this->assertSame( 'https://mirror.example.com/wp-content/plugins/tool/app.js?ver=1', $this->adapter->rewrite_asset_url( 'https://origin.example.com/wp-content/plugins/tool/app.js?ver=1' ) );
        $this->assertSame( 'https://mirror.example.com/wp-includes/js/jquery/jquery.min.js', $this->adapter->rewrite_asset_url( 'https://origin.example.com/wp-includes/js/jquery/jquery.min.js' ) );
    }

    public function test_external_assets_and_private_download_urls_never_rewrite(): void {
        foreach ( array(
            'https://cdn.example.net/wp-content/themes/zibll/css/font-awesome.min.css',
            'https://origin.example.com/pay-download/123?key=not-a-real-secret',
            'https://origin.example.com/d/storage/private/locked.zip',
            '//origin.example.com/wp-content/style.css',
            '/wp-content/themes/zibll/main.css',
        ) as $url ) {
            $this->assertSame( $url, $this->adapter->rewrite_asset_url( $url ), $url );
        }
    }

    public function test_unknown_host_and_admin_or_login_never_adapt(): void {
        $url = 'https://origin.example.com/wp-content/themes/zibll/css/font-awesome.min.css';
        $_SERVER['HTTP_HOST'] = 'untrusted.example.com';
        $this->assertSame( $url, $this->adapter->rewrite_asset_url( $url ) );
        $_SERVER['HTTP_HOST'] = 'mirror.example.com';
        $GLOBALS['test_is_admin'] = true;
        $this->assertSame( $url, $this->adapter->rewrite_asset_url( $url ) );
        $GLOBALS['test_is_admin'] = false;
        $_SERVER['SCRIPT_NAME'] = '/wp-login.php';
        $this->assertSame( $url, $this->adapter->rewrite_asset_url( $url ) );
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts';
        $this->assertSame( $url, $this->adapter->rewrite_asset_url( $url ) );
    }

    public function test_static_asset_opt_in_is_independent_of_multidomain_main_switch(): void {
        $url = 'https://origin.example.com/wp-content/themes/zibll/css/font-awesome.min.css';
        $GLOBALS['jlwa_test_stored_options'][JRMU_OPTION_KEY]['domain_asset_rewrite_enabled'] = 0;
        $this->assertSame( $url, $this->adapter->rewrite_asset_url( $url ) );
        $this->assertSame( 'https://mirror.example.com', $this->adapter->get_dynamic_base_url_or_false() );
        // Existing installs without this newly introduced option stay opted out.
        unset( $GLOBALS['jlwa_test_stored_options'][JRMU_OPTION_KEY]['domain_asset_rewrite_enabled'] );
        $this->assertSame( $url, $this->adapter->rewrite_asset_url( $url ) );
    }

    public function test_disabled_adaptation_keeps_asset_url(): void {
        $GLOBALS['jlwa_test_stored_options'][JRMU_OPTION_KEY]['domain_adaptation_enabled'] = 0;
        $url = 'https://origin.example.com/wp-content/themes/zibll/css/font-awesome.min.css';
        $this->assertSame( $url, $this->adapter->rewrite_asset_url( $url ) );
    }

    public function test_canonical_home_and_siteurl_are_never_overridden(): void {
        // Even a whitelisted alternate entry must not become the licensed site.
        $this->assertSame( 'https://origin.example.com', get_option( 'home' ) );
        $this->assertSame( 'https://origin.example.com', get_option( 'siteurl' ) );
        $this->assertSame( 'existing', $this->adapter->filter_pre_option_home( 'existing' ) );
        $this->assertSame( 'existing', $this->adapter->filter_pre_option_siteurl( 'existing' ) );
        $this->assertFalse( $this->adapter->filter_pre_option_home( false ) );
        $this->assertFalse( $this->adapter->filter_pre_option_siteurl( false ) );

        $source = file_get_contents( dirname( __DIR__ ) . '/features/relative-media-urls/includes/class-jrmu-domain-adapter.php' );
        $this->assertStringNotContainsString( "add_filter( 'pre_option_home'", $source );
        $this->assertStringNotContainsString( "add_filter( 'pre_option_siteurl'", $source );
        // Static assets remain independently configurable.
        $this->assertSame(
            'https://mirror.example.com/wp-content/themes/zibll/css/main.css',
            $this->adapter->rewrite_asset_url( 'https://origin.example.com/wp-content/themes/zibll/css/main.css' )
        );
    }

    public function test_sensitive_zibll_auth_ajax_and_payment_paths_bypass_even_when_exclusions_are_disabled(): void {
        $options = &$GLOBALS['jlwa_test_stored_options'][JRMU_OPTION_KEY];
        $options['domain_exclude_login'] = 0;
        $options['domain_exclude_admin'] = 0;
        $options['domain_skip_system_endpoints'] = 0;
        $css = 'https://origin.example.com/wp-content/themes/zibll/css/main.css';
        foreach ( array(
            '/user-sign', '/user/login', '/user/register/', '/user/forgot',
            '/wp-login.php', '/wp-admin/', '/wp-admin/admin-ajax.php?action=zibpay',
            '/pay-download/?post=42&key=secret', '/checkout/', '/zibpay/',
            '/wp-json/wp/v2/posts', '/?rest_route=/wp/v2/posts',
        ) as $path ) {
            $_SERVER['REQUEST_URI'] = $path;
            $this->assertFalse( $this->adapter->should_adapt_current_request(), $path );
            $this->assertSame( $css, $this->adapter->rewrite_asset_url( $css ), $path );
        }
        $_SERVER['REQUEST_URI'] = '/regular-article';
        $this->assertTrue( $this->adapter->should_adapt_current_request() );
    }

}
