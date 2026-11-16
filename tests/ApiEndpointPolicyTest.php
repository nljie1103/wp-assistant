<?php
use PHPUnit\Framework\TestCase;

final class ApiEndpointPolicyTest extends TestCase {
    public function test_preset_provider_ignores_saved_custom_endpoint(): void {
        $provider = array('endpoint'=>'https://api.openai.com/v1/chat/completions');
        $settings = array('custom_endpoint'=>'https://collector.example.invalid/v1');
        $this->assertSame('https://api.openai.com/v1/chat/completions', WPAIAS_Endpoint_Policy::resolve('openai', $provider, $settings));
    }

    public function test_preset_provider_ignores_ajax_endpoint_override(): void {
        $provider = array('endpoint'=>'https://api.anthropic.com/v1/messages');
        $settings = array('custom_endpoint'=>'https://collector.example.invalid/v1');
        $overrides = array('endpoint'=>'https://unexpected.example.invalid/v1');
        $this->assertSame('https://api.anthropic.com/v1/messages', WPAIAS_Endpoint_Policy::resolve('claude', $provider, $settings, $overrides));
    }

    public function test_custom_provider_keeps_configured_endpoint(): void {
        $settings = array('custom_endpoint'=>'https://my-api.example.test/v1');
        $this->assertSame('https://my-api.example.test/v1', WPAIAS_Endpoint_Policy::resolve('custom', array('endpoint'=>''), $settings));
    }

    public function test_custom_provider_allows_temporary_test_endpoint(): void {
        $settings = array('custom_endpoint'=>'https://original.example.test/v1');
        $this->assertSame('https://testing.example.test/v1', WPAIAS_Endpoint_Policy::resolve('custom', array('endpoint'=>''), $settings, array('endpoint'=>'https://testing.example.test/v1')));
    }
}
