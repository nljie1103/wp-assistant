<?php
/** Scoped media settings must not reset any other feature area. */
if (!defined('ABSPATH')) { exit; }
if (!defined('JRMU_VERSION')) { define('JRMU_VERSION', '4.2.0'); }
if (!defined('JRMU_OPTION_KEY')) { define('JRMU_OPTION_KEY', 'jiuliu_relative_media_urls_options'); }
if (!defined('JRMU_PLUGIN_DIR')) { define('JRMU_PLUGIN_DIR', dirname(__DIR__) . '/features/relative-media-urls/'); }
require_once dirname(__DIR__) . '/features/relative-media-urls/includes/class-jrmu-settings.php';
require_once dirname(__DIR__) . '/features/relative-media-urls/includes/class-jrmu-admin.php';

use PHPUnit\Framework\TestCase;

final class MediaSettingsTest extends TestCase {
    private $before;

    protected function setUp(): void {
        $this->before = JRMU_Settings::get_defaults();
        $this->before['convert_existing_media_output'] = 1;
        $this->before['convert_future_media_output'] = 1;
        $this->before['future_media_enabled_at'] = 1700000000;
        $this->before['convert_post_on_frontend'] = 1;
        $this->before['domain_adaptation_enabled'] = 1;
        $this->before['domain_allowed_hosts'] = "proxy.example.com\nsecond.example.com";
        $this->before['target_uploads'] = 1;
        $this->before['origin_url'] = 'https://origin.example.com';
        $this->before['canonical_enabled'] = 1;
        $this->before['canonical_primary_host'] = 'origin.example.com';
        $GLOBALS['jlwa_test_stored_options'][JRMU_OPTION_KEY] = $this->before;
    }

    protected function tearDown(): void {
        unset($GLOBALS['jlwa_test_stored_options'][JRMU_OPTION_KEY]);
    }

    public function test_asset_rewrite_is_default_off_and_domain_save_is_scoped(): void {
        $this->assertSame( 0, JRMU_Settings::get_defaults()['domain_asset_rewrite_enabled'] );
        $this->assertSame( 0, JRMU_Settings::get_defaults()['domain_legacy_identity_override'] );
        $this->assertContains( 'domain_legacy_identity_override', JRMU_Settings::tab_fields()['domains'] );
        $this->assertSame( 0, JRMU_Settings::get_options()['domain_asset_rewrite_enabled'] );
        $this->assertContains( 'domain_asset_rewrite_enabled', JRMU_Settings::tab_fields()['domains'] );
        $result = JRMU_Settings::sanitize( array(
            'jrmu_settings_scope' => 'domains',
            'domain_adaptation_enabled' => '1',
            'domain_asset_rewrite_enabled' => '1',
            'domain_allowed_hosts' => 'proxy.example.com',
        ) );
        $this->assertSame( 1, $result['domain_asset_rewrite_enabled'] );
        $this->assertSame( 1, $result['convert_existing_media_output'] );
        $this->assertSame( 'https://origin.example.com', $result['origin_url'] );
        $GLOBALS['jlwa_test_stored_options'][JRMU_OPTION_KEY] = $result;
        $disabled = JRMU_Settings::sanitize( array(
            'jrmu_settings_scope' => 'domains',
            'domain_adaptation_enabled' => '1',
            'domain_allowed_hosts' => 'proxy.example.com',
        ) );
        $this->assertSame( 0, $disabled['domain_asset_rewrite_enabled'] );
        $this->assertSame( 1, $disabled['convert_existing_media_output'] );
    }

    public function test_media_only_save_preserves_domains_seo_content_and_source(): void {
        $result = JRMU_Settings::sanitize(array('jrmu_settings_scope' => 'media'));
        $this->assertSame(0, $result['convert_existing_media_output']);
        $this->assertSame(0, $result['convert_future_media_output']);
        $this->assertSame(0, $result['future_media_enabled_at']);
        foreach (array('convert_post_on_frontend','domain_adaptation_enabled','domain_allowed_hosts','canonical_enabled','canonical_primary_host','origin_url') as $key) {
            $this->assertSame($this->before[$key], $result[$key], $key);
        }
    }

    public function test_domain_only_save_preserves_attachment_timestamps_and_other_groups(): void {
        $result = JRMU_Settings::sanitize(array(
            'jrmu_settings_scope' => 'domains',
            'domain_adaptation_enabled' => 1,
            'domain_allowed_hosts' => "proxy.example.com\nsecond.example.com",
            'domain_scheme' => 'https',
        ));
        $this->assertSame(1, $result['convert_future_media_output']);
        $this->assertSame(1700000000, $result['future_media_enabled_at']);
        $this->assertSame(1, $result['convert_post_on_frontend']);
        $this->assertSame(1, $result['canonical_enabled']);
        $this->assertSame(0, $result['domain_dynamic_siteurl']);
        $this->assertSame('https://origin.example.com', $result['origin_url']);
    }

    public function test_nginx_only_save_cannot_reset_domain_whitelist(): void {
        $result = JRMU_Settings::sanitize(array(
            'jrmu_settings_scope' => 'nginx',
            'origin_url' => 'https://source.example.com',
            'media_nginx_target_host' => 'proxy.example.com',
        ));
        $this->assertSame('https://source.example.com', $result['origin_url']);
        $this->assertSame('proxy.example.com', $result['media_nginx_target_host']);
        foreach (array('domain_adaptation_enabled','domain_allowed_hosts','convert_future_media_output','canonical_enabled') as $key) {
            $this->assertSame($this->before[$key], $result[$key], $key);
        }
    }

    public function test_one_line_commands_require_valid_distinct_and_whitelisted_sites(): void {
        $settings = $this->before;
        $settings['media_nginx_target_host'] = 'proxy.example.com';
        $command = JRMU_Admin::media_nginx_command('preview', $settings, '/opt/wp/wp-content/plugins/jlwa');
        $this->assertStringContainsString('sudo python3', $command);
        $this->assertStringContainsString('jlwa-media-nginx-helper.py', $command);
        $this->assertStringContainsString("preview --host 'proxy.example.com'", $command);
        $this->assertStringContainsString("--origin 'https://origin.example.com'", $command);
        $this->assertStringContainsString('apply --host', JRMU_Admin::media_nginx_command('apply', $settings, '/opt/wp/wp-content/plugins/jlwa'));
        $this->assertStringContainsString('rollback --host', JRMU_Admin::media_nginx_command('rollback', $settings, '/opt/wp/wp-content/plugins/jlwa'));
        $settings['media_nginx_target_host'] = 'unknown.example.com';
        $this->assertSame('', JRMU_Admin::media_nginx_command('apply', $settings, '/opt/wp/wp-content/plugins/jlwa'));
        $settings['media_nginx_target_host'] = 'origin.example.com';
        $this->assertSame('', JRMU_Admin::media_nginx_command('apply', $settings, '/opt/wp/wp-content/plugins/jlwa'));
    }

    public function test_old_dynamic_siteurl_option_does_not_implicitly_enable_legacy_override(): void {
        $saved = $this->before;
        $saved['domain_dynamic_siteurl'] = 1;
        unset( $saved['domain_legacy_identity_override'] );
        $GLOBALS['jlwa_test_stored_options'][JRMU_OPTION_KEY] = $saved;
        $this->assertSame( 0, JRMU_Settings::get_options()['domain_legacy_identity_override'] );

        $new = JRMU_Settings::sanitize( array(
            'jrmu_settings_scope' => 'domains',
            'domain_adaptation_enabled' => 1,
            'domain_dynamic_siteurl' => 1,
            'domain_allowed_hosts' => 'proxy.example.com',
        ) );
        $this->assertSame( 0, $new['domain_legacy_identity_override'] );

        $new['domain_legacy_identity_override'] = 1;
        $GLOBALS['jlwa_test_stored_options'][JRMU_OPTION_KEY] = $new;
        $retained = JRMU_Settings::sanitize( array( 'jrmu_settings_scope' => 'media' ) );
        $this->assertSame( 1, $retained['domain_legacy_identity_override'] );

        $disabled = JRMU_Settings::sanitize( array(
            'jrmu_settings_scope' => 'domains',
            'domain_adaptation_enabled' => 1,
            'domain_dynamic_siteurl' => 1,
            'domain_allowed_hosts' => 'proxy.example.com',
        ) );
        $this->assertSame( 0, $disabled['domain_legacy_identity_override'] );
        $this->assertSame( 1, $disabled['domain_dynamic_siteurl'] );
    }

}
