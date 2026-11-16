<?php
use PHPUnit\Framework\TestCase;

final class FrontendSecurityTest extends TestCase {
    private $frontend;

    protected function setUp(): void {
        $GLOBALS['test_post'] = new WP_Post( 42, '' );
        $GLOBALS['test_is_singular'] = true;
        $GLOBALS['test_is_page'] = false;
        $GLOBALS['test_is_front_page'] = false;
        $GLOBALS['test_is_home'] = false;
        $GLOBALS['test_password_valid'] = false;
        $GLOBALS['test_user_can_edit'] = false;
        $GLOBALS['test_localized'] = array();
        WPAIAS_Cache::$summary = 'Already cached summary';
        WPAIAS_Cache::$reads = 0;
        WPAIAS_API::$calls = 0;
        JLWA_AI_Summary_Feature::$settings = array(
            'enabled' => 1,
            'mobile_enable' => 1,
            'post_types' => array( 'post' ),
            'exclude_post_ids' => '',
            'exclude_categories' => array(),
            'public_generation' => 1,
            'insert_method' => 'auto',
            'title' => 'AI summary',
            'animation' => 'none',
            'anim_duration' => 800,
            'type_speed' => 35,
            'anim_delay' => 0,
            'cursor_enable' => 0,
            'cursor_color' => '#ffffff',
            'decoration_type' => 'none',
            'decoration_position' => 'top-right',
            'decoration_size' => 58,
            'decoration_opacity' => 0.22,
            'decoration_offset_x' => 0,
            'decoration_offset_y' => 0,
            'decoration_builtin' => 'sparkles',
        );
        $_POST['post_id'] = '42';
        $this->frontend = new WPAIAS_Frontend();
    }

    public function test_password_protected_post_is_not_shown_without_access(): void {
        $GLOBALS['test_post']->post_password = 'secret';
        $this->assertFalse( $this->frontend->should_show() );
        $this->assertSame( 0, WPAIAS_Cache::$reads );
    }

    public function test_shortcode_does_not_disclose_cached_protected_summary(): void {
        $GLOBALS['test_post']->post_password = 'secret';
        $this->assertSame( '', $this->frontend->shortcode_handler( array() ) );
        $this->assertSame( 0, WPAIAS_Cache::$reads );
    }

    public function test_public_ajax_rejects_protected_post_before_cache_read(): void {
        $GLOBALS['test_post']->post_password = 'secret';
        try {
            $this->frontend->ajax_front_generate();
            $this->fail( 'AJAX did not terminate' );
        } catch ( Test_Ajax_Response $response ) {
            $this->assertFalse( $response->success );
            $this->assertSame( 403, $response->getCode() );
        }
        $this->assertSame( 0, WPAIAS_Cache::$reads );
        $this->assertSame( 0, WPAIAS_API::$calls );
    }

    public function test_correct_password_allows_summary_display(): void {
        $GLOBALS['test_post']->post_password = 'secret';
        $GLOBALS['test_password_valid'] = true;
        $this->assertTrue( $this->frontend->should_show() );
    }

    public function test_post_editor_can_view_summary_without_password_cookie(): void {
        $GLOBALS['test_post']->post_password = 'secret';
        $GLOBALS['test_user_can_edit'] = true;
        $this->assertTrue( $this->frontend->should_show() );
    }

    public function test_public_unprotected_article_keeps_cached_ajax_behavior(): void {
        try {
            $this->frontend->ajax_front_generate();
            $this->fail( 'AJAX did not terminate' );
        } catch ( Test_Ajax_Response $response ) {
            $this->assertTrue( $response->success );
            $this->assertSame( 'Already cached summary', $response->payload['summary'] );
        }
        $this->assertSame( 1, WPAIAS_Cache::$reads );
        $this->assertSame( 0, WPAIAS_API::$calls );
    }

    public function test_disabled_summary_feature_cannot_serve_stale_cached_ajax(): void {
        JLWA_AI_Summary_Feature::$settings['enabled'] = 0;
        try {
            $this->frontend->ajax_front_generate();
            $this->fail( 'AJAX did not terminate' );
        } catch ( Test_Ajax_Response $response ) {
            $this->assertFalse( $response->success );
            $this->assertSame( 403, $response->getCode() );
        }
        $this->assertSame( 0, WPAIAS_Cache::$reads );
    }

    public function test_excluded_article_cannot_serve_cached_ajax(): void {
        JLWA_AI_Summary_Feature::$settings['exclude_post_ids'] = '42';
        try {
            $this->frontend->ajax_front_generate();
            $this->fail( 'AJAX did not terminate' );
        } catch ( Test_Ajax_Response $response ) {
            $this->assertFalse( $response->success );
            $this->assertSame( 403, $response->getCode() );
        }
        $this->assertSame( 0, WPAIAS_Cache::$reads );
    }

    public function test_cached_public_summary_stays_available_when_live_generation_disabled(): void {
        JLWA_AI_Summary_Feature::$settings['public_generation'] = 0;
        try {
            $this->frontend->ajax_front_generate();
            $this->fail( 'AJAX did not terminate' );
        } catch ( Test_Ajax_Response $response ) {
            $this->assertTrue( $response->success );
            $this->assertSame( 'Already cached summary', $response->payload['summary'] );
        }
    }

    public function test_mobile_setting_enabled_is_passed_to_frontend(): void {
        $this->frontend->enqueue_assets();
        $this->assertTrue( $GLOBALS['test_localized']['WPAIAS_FRONT']['mobile_enable'] );
    }

    public function test_mobile_setting_disabled_is_passed_to_frontend(): void {
        JLWA_AI_Summary_Feature::$settings['mobile_enable'] = 0;
        $this->frontend->enqueue_assets();
        $this->assertFalse( $GLOBALS['test_localized']['WPAIAS_FRONT']['mobile_enable'] );
    }
}
