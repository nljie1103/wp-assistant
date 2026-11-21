<?php
/** Real Zibll OAuth return URL boundary regression tests. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once dirname( __DIR__ ) . '/features/login-security/bootstrap.php';
if ( ! function_exists( 'home_url' ) ) { function home_url( $path = '' ) { return 'https://blog.jiuliu.org' . ( '/' === substr( $path, 0, 1 ) ? $path : '/' . $path ); } }
use PHPUnit\Framework\TestCase;
final class OAuthReturnBoundaryTest extends TestCase {
    const HOME = 'https://blog.jiuliu.org/';
    public function test_valid_article_and_member_returns_are_retained(): void {
        foreach ( array( '/', '/article/123', '/article/123?from=home', 'https://blog.jiuliu.org/398.html', 'https://blog.jiuliu.org/user/7/?tab=posts' ) as $url ) {
            $this->assertSame( $url, JLWA_OAuth_Return::sanitize( $url, self::HOME ), $url );
        }
    }
    public function test_recursive_login_destinations_are_replaced_with_home(): void {
        foreach ( array(
            'https://blog.jiuliu.org/oauth/clogin?type=qq',
            'https://boke.jiuliu.org%2Foauth%2Fclogin',
            'https://boke.jiuliu.org%252Foauth%252Fclogin',
            '/oauth/clogin/callback?code=private',
            'https://blog.jiuliu.org/%6f%61uth/%63login',
            'https://blog.jiuliu.org/a?next=%25252Foauth%25252Fclogin%253Ftype%253Dqq',
            '/user-sign?redirect_to=/oauth/clogin',
            '/wp-login.php?redirect_to=/admin',
        ) as $url ) {
            $this->assertSame( self::HOME, JLWA_OAuth_Return::sanitize( $url, self::HOME ), $url );
        }
    }
    public function test_external_dangerous_or_ambiguous_returns_are_replaced_with_home(): void {
        foreach ( array(
            'https://boke.jiuliu.org/article/123',
            'https://evil.invalid/',
            'https://blog.jiuliu.org@evil.invalid/article',
            'https://evil.invalid@blog.jiuliu.org/abc',
            'http://blog.jiuliu.org/article',
            '//evil.invalid/path', '/%2Fexample.invalid/path',
            'https%3A%2F%2Fevil.invalid%2Ffoo',
            "https://blog.jiuliu.org/a\r\nX-Forwarded-For:evil",
            '\\evil.invalid\\path', 'javascript:alert(1)',
            'relative/file', '?redirect_to=/article',
            str_repeat( 'a', 2100 ), array( 'https://blog.jiuliu.org' ),
        ) as $url ) {
            $this->assertSame( self::HOME, JLWA_OAuth_Return::sanitize( $url, self::HOME ), is_string( $url ) ? $url : 'array' );
        }
    }
    public function test_callback_guard_only_changes_actual_clogin_callback_redirects(): void {
        $original = $_SERVER;
        $guard = JLWA_Login_Security_Feature::instance();
        $_SERVER['REQUEST_URI'] = '/oauth/clogin/callback?code=anything';
        $this->assertSame( self::HOME, $guard->guard_oauth_callback_redirect( '/oauth/clogin?type=qq', 302 ) );
        $this->assertSame( '/article/1', $guard->guard_oauth_callback_redirect( '/article/1', 302 ) );
        $this->assertSame( self::HOME, $guard->oauth_callback_safe_fallback( '/wp-admin/', 302 ) );
        $_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php';
        $this->assertSame( '/oauth/clogin?type=qq', $guard->guard_oauth_callback_redirect( '/oauth/clogin?type=qq', 302 ) );
        $this->assertSame( '/wp-admin/', $guard->oauth_callback_safe_fallback( '/wp-admin/', 302 ) );
        $_SERVER = $original;
    }
    public function test_oauth_login_is_canonical_for_alternate_hosts(): void {
        foreach ( array( 'blog.jiuliu.org', 'BLOG.JIULIU.ORG', 'blog.jiuliu.org:443' ) as $host ) {
            $this->assertTrue( JLWA_Login_Security_Feature::is_canonical_oauth_host( $host, self::HOME ), $host );
        }
        foreach ( array( 'boke.jiuliu.org', '152.70.124.102', 'evil.invalid', 'blog.jiuliu.org:8443', 'blog.jiuliu.org@evil.invalid', 'blog.jiuliu.org/x', 'blog.jiuliu.org?q=1', 'blog.jiuliu.org\r\nHost: evil.invalid', array() ) as $host ) {
            $this->assertFalse( JLWA_Login_Security_Feature::is_canonical_oauth_host( $host, self::HOME ), is_string( $host ) ? $host : 'array' );
        }
    }
    public function test_theme_default_redirect_to_is_cleaned_even_if_turnstile_disabled(): void {
        $before_get = $_GET;
        $before_request = $_REQUEST;
        $before_server = $_SERVER;
        $guard = JLWA_Login_Security_Feature::instance();
        $_SERVER['REQUEST_URI'] = '/article/123';
        $_GET['redirect_to'] = 'https://blog.jiuliu.org/oauth/clogin?type=qq';
        $guard->prepare_oauth_return();
        $this->assertSame( self::HOME, $_GET['redirect_to'] );
        $_GET['redirect_to'] = 'https://some-merchant.example/checkout';
        $guard->prepare_oauth_return();
        $this->assertSame( 'https://some-merchant.example/checkout', $_GET['redirect_to'] );
        $_SERVER['REQUEST_URI'] = '/oauth/clogin?type=qq';
        unset( $_GET['redirect_to'] );
        $guard->prepare_oauth_return();
        $this->assertSame( self::HOME, $_GET['redirect_to'] );
        $_GET = $before_get;
        $_REQUEST = $before_request;
        $_SERVER = $before_server;
    }
}
