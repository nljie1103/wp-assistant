<?php
/** OAuth guard has strict scope, rejects recursive rurl, and verifies Turnstile claims. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once dirname( __DIR__ ) . '/features/login-security/bootstrap.php';
use PHPUnit\Framework\TestCase;
final class LoginSecurityTest extends TestCase {
    public function test_only_oauth_initiation_path_is_guarded(): void {
        foreach ( array( '/oauth/clogin', '/oauth/clogin?type=qq', '/oauth/clogin/?type=qq', '/blog/oauth/clogin?type=qq' ) as $url ) {
            $this->assertTrue( JLWA_Login_Security_Feature::is_oauth_start_path( $url ), $url );
        }
        foreach ( array( '/oauth/clogin/callback?code=x', '/oauth/clogin-article', '/oauth/qq', '/wp-login.php' ) as $url ) {
            $this->assertFalse( JLWA_Login_Security_Feature::is_oauth_start_path( $url ), $url );
        }
    }
    public function test_self_referring_return_urls_rejected_at_multiple_encoding_levels(): void {
        foreach ( array( 'https://blog.jiuliu.org/oauth/clogin?type=qq', 'https://boke.jiuliu.org%2Foauth%2Fclogin', 'https://boke.jiuliu.org%252Foauth%252Fclogin', 'https://blog.jiuliu.org%25252Foauth%25252Fclogin' ) as $url ) {
            $this->assertTrue( JLWA_Login_Security_Feature::unsafe_return( $url ), $url );
        }
        foreach ( array( '', '/', 'https://blog.jiuliu.org/398.html', '/user-sign', 'https://blog.jiuliu.org/oauth/documents' ) as $url ) {
            $this->assertFalse( JLWA_Login_Security_Feature::unsafe_return( $url ), $url );
        }
        $this->assertTrue( JLWA_Login_Security_Feature::unsafe_return( str_repeat( 'a', 2049 ) ) );
    }
    public function test_typed_oauth_only(): void {
        foreach ( array( 'qq', 'weixin', 'alipay', 'github', 'weibo' ) as $type ) {
            $this->assertTrue( JLWA_Login_Security_Feature::valid_login_type( $type ) );
        }
        foreach ( array( '', array('qq'), "qq\n", 'qq/../', 'QQ', str_repeat('q', 50) ) as $type ) {
            $this->assertFalse( JLWA_Login_Security_Feature::valid_login_type( $type ) );
        }
    }
    public function test_siteverify_requires_action_and_matching_hostname(): void {
        $valid = array( 'success' => true, 'hostname' => 'blog.jiuliu.org', 'action' => 'jlwa_oauth_login' );
        $this->assertTrue( JLWA_Login_Security_Feature::verified_response( $valid, 'blog.jiuliu.org' ) );
        foreach ( array(
            array( 'success' => 'true', 'hostname' => 'blog.jiuliu.org', 'action' => 'jlwa_oauth_login' ),
            array( 'success' => false, 'hostname' => 'blog.jiuliu.org', 'action' => 'jlwa_oauth_login' ),
            array( 'success' => true, 'hostname' => 'attacker.test', 'action' => 'jlwa_oauth_login' ),
            array( 'success' => true, 'hostname' => 'blog.jiuliu.org', 'action' => 'login' ),
            array( 'success' => true, 'hostname' => 'blog.jiuliu.org' ),
        ) as $invalid ) {
            $this->assertFalse( JLWA_Login_Security_Feature::verified_response( $invalid, 'blog.jiuliu.org' ) );
        }
    }
}
