<?php
/** Policy tests for low-friction adaptive challenge and safe custom scopes. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once dirname( __DIR__ ) . '/features/login-security/bootstrap.php';
if ( ! function_exists( 'wp_salt' ) ) { function wp_salt( $scheme = 'auth' ) { return 'jlwa-test-only-keyed-hash-salt'; } }
use PHPUnit\Framework\TestCase;

final class AdaptiveLoginSecurityTest extends TestCase {
    private $settings = array( 'ip_limit' => 6, 'global_limit' => 50, 'cooldown' => 300, 'mode' => 'adaptive' );

    public function test_benign_oauth_is_not_challenged_until_threshold(): void {
        $state = array();
        for ( $i = 1; $i <= 5; ++$i ) {
            list( $state, $challenged ) = JLWA_Login_Risk::evaluate( $state, 1000 + $i, 'one', $this->settings, true );
            $this->assertFalse( $challenged );
        }
        list( $state, $challenged ) = JLWA_Login_Risk::evaluate( $state, 1006, 'one', $this->settings, true );
        $this->assertTrue( $challenged );
        list( $state, $same_user ) = JLWA_Login_Risk::evaluate( $state, 1010, 'one', $this->settings, false );
        list( $state, $other_user ) = JLWA_Login_Risk::evaluate( $state, 1010, 'two', $this->settings, false );
        $this->assertTrue( $same_user );
        $this->assertFalse( $other_user );
        list( $state, $expired ) = JLWA_Login_Risk::evaluate( $state, 1310, 'one', $this->settings, false );
        $this->assertFalse( $expired );
    }

    public function test_distributed_ips_trigger_global_threshold_and_cooldown(): void {
        $state = array();
        for ( $i = 1; $i < 50; ++$i ) {
            list( $state, $elevated ) = JLWA_Login_Risk::evaluate( $state, 2000 + (int) ( $i / 5 ), 'ip-' . $i, $this->settings, true );
            $this->assertFalse( $elevated );
        }
        list( $state, $elevated ) = JLWA_Login_Risk::evaluate( $state, 2011, 'ip-50', $this->settings, true );
        $this->assertTrue( $elevated );
        list( $state, $unseen ) = JLWA_Login_Risk::evaluate( $state, 2020, 'new-ip', $this->settings, false );
        $this->assertTrue( $unseen );
        list( $state, $cooldown ) = JLWA_Login_Risk::evaluate( $state, 2312, 'new-ip', $this->settings, false );
        $this->assertFalse( $cooldown );
    }

    public function test_bounded_state_with_many_distinct_sources(): void {
        $state = array();
        for ( $i = 0; $i < 1100; ++$i ) {
            list( $state, $elevated ) = JLWA_Login_Risk::evaluate( $state, 3000 + (int) ( $i / 100 ), 'ip-' . $i, $this->settings, true );
        }
        $this->assertLessThanOrEqual( 512, count( $state['clients'] ) );
        $this->assertSame( 1100, $state['total'] );
    }

    public function test_custom_path_rules_match_only_selected_dynamic_routes(): void {
        $rules = JLWA_Login_Scope::normalize( "/members/private\n/member-center/*\n" );
        $this->assertSame( "/members/private\n/member-center/*", $rules );
        $this->assertSame( '/members/private', JLWA_Login_Scope::match( '/members/private?id=1', $rules ) );
        $this->assertSame( '/members/private', JLWA_Login_Scope::match( '/members/private/', $rules ) );
        $this->assertSame( '/members/private', JLWA_Login_Scope::normalize( '/members/private/' ) );
        $this->assertSame( '/member-center/*', JLWA_Login_Scope::match( '/member-center/users', $rules ) );
        $this->assertSame( '/member-center/*', JLWA_Login_Scope::match( '/member-center%2Fusers', $rules ) );
        $this->assertSame( '/member-center/*', JLWA_Login_Scope::match( '/member-center%252Fusers', $rules ) );
        foreach ( array( '/members/private-settings', '/member-centerish/users', '/oauth/clogin/callback', '/wp-admin/', '/wp-admin/admin-ajax.php', '/wp-login.php' ) as $path ) {
            $this->assertSame( '', JLWA_Login_Scope::match( $path, $rules ), $path );
        }
        foreach ( array( '/', '/*', '/wp-admin/*', '/wp-login.php', '/oauth/clogin', '/order/payment', '/private/../other', '/private?q=1', 'https://example.com/private', '/private|php', str_repeat( 'a', 130 ) ) as $bad ) {
            $this->assertFalse( JLWA_Login_Scope::normalize( $bad ), $bad );
        }
    }

    public function test_wp_login_scope_does_not_include_ajax_or_password_reset(): void {
        $original = $_SERVER;
        $request = $_REQUEST;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_REQUEST['action'] = 'login';
        $this->assertTrue( JLWA_Login_Security_Feature::is_wp_password_post( '/wp-login.php' ) );
        foreach ( array( '/wp-admin/', '/wp-admin/admin-ajax.php', '/wp-login.php.backup', '/oauth/clogin' ) as $path ) {
            $this->assertFalse( JLWA_Login_Security_Feature::is_wp_password_post( $path ), $path );
        }
        $_REQUEST['action'] = 'lostpassword';
        $this->assertFalse( JLWA_Login_Security_Feature::is_wp_password_post( '/wp-login.php' ) );
        $_SERVER = $original;
        $_REQUEST = $request;
    }

    public function test_custom_scope_cookie_is_signed_time_limited_and_path_bound(): void {
        $guard = JLWA_Login_Security_Feature::instance();
        $method = new ReflectionMethod( JLWA_Login_Security_Feature::class, 'has_scope_cookie' );
        $method->setAccessible( true );
        $scope = '/members/*';
        $name = 'jlwa_scope_' . substr( hash( 'sha256', $scope ), 0, 16 );
        $expiry = time() + 300;
        $payload = $expiry . ':abcdef0123456789';
        $signature = hash_hmac( 'sha256', $scope . '|' . $payload, wp_salt( 'auth' ) );
        $_COOKIE[ $name ] = $payload . ':' . $signature;
        $this->assertTrue( $method->invoke( $guard, $scope ) );
        $this->assertFalse( $method->invoke( $guard, '/members/private' ) );
        $_COOKIE[ $name ] .= '0';
        $this->assertFalse( $method->invoke( $guard, $scope ) );
        unset( $_COOKIE[ $name ] );
    }

    public function test_turnstile_action_must_match_its_separate_entry_point(): void {
        $reply = array( 'success' => true, 'hostname' => 'example.com', 'action' => 'jlwa_oauth_login' );
        $this->assertFalse( JLWA_Login_Security_Feature::verified_response( $reply, 'example.com', 'jlwa_wp_login' ) );
        $this->assertTrue( JLWA_Login_Security_Feature::verified_response( $reply, 'example.com', 'jlwa_oauth_login' ) );
    }
}
