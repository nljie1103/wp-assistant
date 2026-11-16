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

    public function test_excluded_path_is_preserved(): void {
        $GLOBALS['test_is_page'] = true;
        $_SERVER['REQUEST_URI'] = '/wp-admin/';
        $settings = JLWA_Anti_Debug_Feature::defaults();
        $settings['scope']['pages'] = 1;
        $this->assertFalse($this->enabledFor($settings));
    }
}
