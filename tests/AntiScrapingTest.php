<?php
/** Regression tests for strict, login-state-independent content export rules. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once dirname( __DIR__ ) . '/features/anti-scraping/bootstrap.php';

use PHPUnit\Framework\TestCase;

final class AntiScrapingRequestDouble {
    private $route;
    private $method;
    public function __construct( $route, $method ) { $this->route = $route; $this->method = $method; }
    public function get_route() { return $this->route; }
    public function get_method() { return $this->method; }
}

final class AntiScrapingTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['jlwa_test_stored_options'][JLWA_Anti_Scraping_Feature::OPTION] = array(
            'enabled' => 1, 'rest_content' => 1, 'feeds' => 1, 'oembed' => 1, 'xmlrpc' => 1,
        );
        $GLOBALS['test_logged_in'] = false;
    }

    protected function tearDown(): void {
        unset( $GLOBALS['jlwa_test_stored_options'][JLWA_Anti_Scraping_Feature::OPTION] );
        $GLOBALS['test_logged_in'] = false;
    }

    public function test_disabled_feature_is_safe_by_default(): void {
        $settings = JLWA_Anti_Scraping_Feature::defaults();
        $this->assertFalse( JLWA_Anti_Scraping_Feature::blocks_rest_route( '/wp/v2/posts', 'GET', $settings ) );
        $this->assertFalse( JLWA_Anti_Scraping_Feature::blocks_feed( $settings ) );
    }

    public function test_content_routes_are_blocked_regardless_of_login_state(): void {
        $guard = JLWA_Anti_Scraping_Feature::instance();
        foreach ( array( false, true ) as $logged_in ) {
            $GLOBALS['test_logged_in'] = $logged_in;
            foreach ( array( '/wp/v2', '/wp/v2/posts', '/wp/v2/posts/123', '/wp/v2/pages', '/wp/v2/media/123', '/wp/v2/categories', '/wp/v2/comments', '/wp/v2/users', '/wp/v2/search', '/wp/v2/custom-type', '/wp/v2/posts/' ) as $route ) {
                $response = $guard->guard_rest( null, null, new AntiScrapingRequestDouble( $route, 'GET' ) );
                $this->assertInstanceOf( WP_Error::class, $response, $route );
                $this->assertSame( 'jlwa_anti_scraping_blocked', $response->get_error_code(), $route );
            }
        }
    }

    public function test_rest_route_query_alias_cannot_bypass_route_guard(): void {
        // WP_REST_Server supplies normalized /wp/v2/... routes for both URLs.
        $path_a = '/wp-json/wp/v2/posts';
        $path_b = '/?rest_route=/wp/v2/posts';
        foreach ( array( $path_a, $path_b ) as $path ) {
            $resolved = '/wp/v2/posts';
            $this->assertTrue( JLWA_Anti_Scraping_Feature::blocks_rest_route( $resolved, 'GET', JLWA_Anti_Scraping_Feature::settings() ), $path );
        }
    }

    public function test_only_read_methods_are_blocked(): void {
        $guard = JLWA_Anti_Scraping_Feature::instance();
        foreach ( array( 'POST', 'PUT', 'PATCH', 'DELETE' ) as $method ) {
            $this->assertNull( $guard->guard_rest( null, null, new AntiScrapingRequestDouble( '/wp/v2/posts', $method ) ) );
        }
        $this->assertInstanceOf( WP_Error::class, $guard->guard_rest( null, null, new AntiScrapingRequestDouble( '/wp/v2/posts', 'HEAD' ) ) );
    }

    public function test_plugin_and_service_routes_are_not_globally_disabled(): void {
        $guard = JLWA_Anti_Scraping_Feature::instance();
        foreach ( array( '/', '/jlwc/v1/run', '/jlwa/v1/config', '/wc/v3/orders', '/wp-site-health/v1/tests', '/oembed/1.0/proxy', '/wp/v20/posts', '/wp/v2foo/posts' ) as $route ) {
            $this->assertNull( $guard->guard_rest( null, null, new AntiScrapingRequestDouble( $route, 'GET' ) ), $route );
        }
    }

    public function test_oembed_feed_and_xmlrpc_are_separately_controllable(): void {
        $guard = JLWA_Anti_Scraping_Feature::instance();
        $this->assertInstanceOf( WP_Error::class, $guard->guard_rest( null, null, new AntiScrapingRequestDouble( '/oembed/1.0/embed', 'GET' ) ) );
        $this->assertTrue( JLWA_Anti_Scraping_Feature::blocks_feed( JLWA_Anti_Scraping_Feature::settings() ) );
        $this->assertFalse( $guard->disable_xmlrpc( true ) );
        $this->assertSame( array(), $guard->filter_xmlrpc_methods( array( 'pingback.ping' => 'fn' ) ) );
        $GLOBALS['jlwa_test_stored_options'][JLWA_Anti_Scraping_Feature::OPTION] = array(
            'enabled' => 1, 'rest_content' => 0, 'feeds' => 0, 'oembed' => 0, 'xmlrpc' => 0,
        );
        $this->assertNull( $guard->guard_rest( null, null, new AntiScrapingRequestDouble( '/wp/v2/posts', 'GET' ) ) );
        $this->assertNull( $guard->guard_rest( null, null, new AntiScrapingRequestDouble( '/oembed/1.0/embed', 'GET' ) ) );
        $this->assertFalse( JLWA_Anti_Scraping_Feature::blocks_feed( JLWA_Anti_Scraping_Feature::settings() ) );
        $this->assertTrue( $guard->disable_xmlrpc( true ) );
    }

    public function test_guard_is_active_even_when_an_earlier_filter_supplies_a_response(): void {
        $guard = JLWA_Anti_Scraping_Feature::instance();
        $this->assertInstanceOf( WP_Error::class, $guard->guard_rest( 'cached-content', null, new AntiScrapingRequestDouble( '/wp/v2/posts', 'GET' ) ) );
    }
}
