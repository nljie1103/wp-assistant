<?php
/** Paid Zibll business requests must never show global loading layers or buffers. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once dirname( __DIR__ ) . '/features/page-effects/bootstrap.php';
require_once dirname( __DIR__ ) . '/features/immersive-preloader/includes/class-jip-frontend.php';
use PHPUnit\Framework\TestCase;
final class NativeTransactionIsolationTest extends TestCase {
    private $uri;
    private $query;
    protected function setUp(): void {
        $this->uri = $_SERVER['REQUEST_URI'] ?? '';
        $this->query = $_GET;
        $_GET = array();
        $GLOBALS['test_is_admin'] = false;
        $GLOBALS['test_logged_in'] = false;
    }
    protected function tearDown(): void {
        $_SERVER['REQUEST_URI'] = $this->uri;
        $_GET = $this->query;
    }
    private function callPrivate( $instance, $method, $options = null ) {
        $ref = new ReflectionMethod( $instance, $method );
        $ref->setAccessible( true );
        return $method === 'should_display' ? $ref->invoke( $instance ) : $ref->invoke( $instance, $options );
    }
    public function test_preloader_excludes_zibll_business_routes(): void {
        $instance = ( new ReflectionClass( 'JIP_Frontend' ) )->newInstanceWithoutConstructor();
        $prop = new ReflectionProperty( 'JIP_Frontend', 'options' );
        $prop->setAccessible( true );
        $prop->setValue( $instance, array( 'enabled' => 1, 'home_only' => 0 ) );
        foreach ( array( '/user-sign?tab=signin', '/blog/user-sign/', '/wp-login.php',
            '/checkout/', '/pay-download/', '/download?post=2823', '/order/5566',
            '/zibpay/', '/?jlwa_dl=abcdef', '/?wc-ajax=checkout', '/?rest_route=/wp/v2/posts'
        ) as $uri ) {
            $_SERVER['REQUEST_URI'] = $uri;
            $this->assertFalse( $this->callPrivate( $instance, 'should_display' ), $uri );
        }
        foreach ( array( '/', '/article/hello', '/user-sign-guide', '/download-tutorial' ) as $uri ) {
            $_SERVER['REQUEST_URI'] = $uri;
            $this->assertTrue( $this->callPrivate( $instance, 'should_display' ), $uri );
        }
    }
    public function test_page_effects_buffer_excludes_zibll_business_routes(): void {
        $instance = JLWA_Page_Effects_Feature::instance();
        $options = JLWA_Page_Effects_Feature::default_options();
        $options['global']['custom_css'] = '.test{color:green}';
        $options['compat']['injection_mode'] = 'buffer';
        foreach ( array( '/user-sign', '/wp-login.php', '/pay-download/',
            '/download?post=2823', '/order/1', '/checkout/', '/?jlwa_dl=abc',
            '/?wc-ajax=checkout', '/wp-json/wp/v2/posts'
        ) as $uri ) {
            $_SERVER['REQUEST_URI'] = $uri;
            $this->assertFalse( $this->callPrivate( $instance, 'should_load_frontend', $options ), $uri );
        }
        foreach ( array( '/', '/post/ordinary', '/download-howto' ) as $uri ) {
            $_SERVER['REQUEST_URI'] = $uri;
            $this->assertTrue( $this->callPrivate( $instance, 'should_load_frontend', $options ), $uri );
        }
    }
    public function test_editorial_preloader_does_not_embed_plugin_author_brand(): void {
        $source = file_get_contents( dirname( __DIR__ ) . '/features/immersive-preloader/includes/class-jip-frontend.php' );
        $this->assertStringNotContainsString( 'JIULIU&nbsp;', $source );
        $this->assertStringContainsString( 'DIGITAL EXPERIENCE', $source );
    }
}
