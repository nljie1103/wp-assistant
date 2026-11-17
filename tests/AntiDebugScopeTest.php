<?php
use PHPUnit\Framework\TestCase;

final class AntiDebugScopeTest extends TestCase {
    protected function setUp(): void {
        foreach (array('test_is_admin','test_logged_in','test_mobile','test_is_page','test_is_front_page','test_is_home','test_user_can_edit') as $key) {
            $GLOBALS[$key] = false;
        }
        $GLOBALS['test_is_singular'] = false;
        unset($_GET['jlwa_safe']);
        $_SERVER['REQUEST_URI'] = '/';
    }

    private function enabledFor( $settings ) {
        $method = new ReflectionMethod('JLWA_Anti_Debug_Feature', 'should_load');
        // Required on PHP 7.4; PHP 8.1+ allows private reflection invocation by default.
        $method->setAccessible(true);
        $instance = JLWA_Anti_Debug_Feature::instance();
        return $method->invoke($instance, $settings);
    }

    public function test_static_front_page_follows_home_toggle_instead_of_page_toggle(): void {
        $GLOBALS['test_is_page'] = true;
        $GLOBALS['test_is_front_page'] = true;
        $settings = JLWA_Anti_Debug_Feature::defaults();
        $settings['scope']['home'] = 1;
        $settings['scope']['pages'] = 0;
        $this->assertTrue($this->enabledFor($settings));
        $settings['scope']['home'] = 0;
        $settings['scope']['pages'] = 1;
        $this->assertFalse($this->enabledFor($settings));
    }

    public function test_regular_page_uses_pages_toggle(): void {
        $GLOBALS['test_is_page'] = true;
        $settings = JLWA_Anti_Debug_Feature::defaults();
        $settings['scope']['pages'] = 1;
        $this->assertTrue($this->enabledFor($settings));
    }

    public function test_posts_index_uses_home_toggle(): void {
        $GLOBALS['test_is_home'] = true;
        $settings = JLWA_Anti_Debug_Feature::defaults();
        $settings['scope']['home'] = 1;
        $this->assertTrue($this->enabledFor($settings));
    }

    public function test_admin_bypass_is_preserved(): void {
        $GLOBALS['test_is_page'] = true;
        $GLOBALS['test_logged_in'] = true;
        $GLOBALS['test_user_can_edit'] = true;
        $settings = JLWA_Anti_Debug_Feature::defaults();
        $settings['scope']['pages'] = 1;
        $this->assertFalse($this->enabledFor($settings));
    }

    public function test_zibll_login_routes_always_bypass_even_with_legacy_or_empty_excludes(): void {
        $GLOBALS['test_is_page'] = true;
        $settings = JLWA_Anti_Debug_Feature::defaults();
        $settings['mode'] = 'strict';
        $settings['scope']['pages'] = 1;
        $settings['scope']['exclude_paths'] = ''; // Legacy saved settings.
        foreach (array(
            '/user-sign?tab=signin&redirect_to=%2Fpost%2F',
            '/user-sign/',
            '/wp-login.php?action=login',
            '/wp-admin/',
            '/user/login',
            '/user/register/',
            '/blog/user-sign?tab=signup',
            '/blog/wp-login.php',
        ) as $path) {
            $_SERVER['REQUEST_URI'] = $path;
            $this->assertFalse($this->enabledFor($settings), 'Auth must not be protected: ' . $path);
        }
    }

    public function test_auth_exclusion_matches_complete_segments_not_article_names(): void {
        $GLOBALS['test_is_page'] = true;
        $settings = JLWA_Anti_Debug_Feature::defaults();
        $settings['scope']['pages'] = 1;
        $settings['scope']['exclude_paths'] = '';
        foreach (array(
            '/user-sign-tips',
            '/user-signature',
            '/blog/wp-login.php-tutorial',
            '/user/login-guide',
            '/post/debug-guide',
        ) as $path) {
            $_SERVER['REQUEST_URI'] = $path;
            $this->assertTrue($this->enabledFor($settings), 'Ordinary page still protected: ' . $path);
        }
    }

    public function test_excluded_path_is_preserved(): void {
        $GLOBALS['test_is_page'] = true;
        $_SERVER['REQUEST_URI'] = '/wp-admin/';
        $settings = JLWA_Anti_Debug_Feature::defaults();
        $settings['scope']['pages'] = 1;
        $this->assertFalse($this->enabledFor($settings));
    }
}
