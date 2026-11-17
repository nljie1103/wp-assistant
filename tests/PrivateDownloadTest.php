<?php
/** Private OpenList delivery must be strict and disabled by default. */
require_once dirname( __DIR__ ) . '/features/private-download.php';

use PHPUnit\Framework\TestCase;

final class PrivateDownloadTest extends TestCase {
    private $settings;

    protected function setUp(): void {
        $this->settings = JLWA_Private_Download::defaults();
        $this->settings['private_hosts'] = "files.example.com\nfiles-alt.example.com";
        $this->settings['private_source_prefix'] = '/d/storage/private/';
    }

    public function test_disabled_by_default_and_ordinary_download_is_untouched(): void {
        $this->assertSame( 0, $this->settings['private_enabled'] );
        $this->assertSame(
            '/var/tmp/not-our-file.zip',
            JLWA_Private_Download::intercept( '/var/tmp/not-our-file.zip', 'https://unrelated.example/a.zip', 42, 0, array( 'paid_type' => 2 ) )
        );
    }

    public function test_valid_encoded_chinese_path_routes_to_nginx_internal_location(): void {
        $url = 'https://files.example.com/d/storage/private/designs/sample.zip';
        $actual = JLWA_Private_Download::resolve( $url, $this->settings );
        $this->assertSame( '/_jlwa_private_openlist/designs/sample.zip', $actual['uri'] );
        $this->assertSame( 'sample.zip', $actual['filename'] );
        $this->assertStringNotContainsString( 'files.example.com', $actual['uri'] );
    }

    public function test_second_configured_host_and_unicode_filenames(): void {
        $url = 'https://files-alt.example.com/d/storage/private/档案/封面合集.zip';
        $mapped = JLWA_Private_Download::resolve( $url, $this->settings );
        $this->assertSame( '/_jlwa_private_openlist/%E6%A1%A3%E6%A1%88/%E5%B0%81%E9%9D%A2%E5%90%88%E9%9B%86.zip', $mapped['uri'] );
    }

    public function test_unrelated_public_images_or_other_hosts_are_not_intercepted(): void {
        foreach ( array(
            'https://files.example.com/d/storage/public-images/photo.jpg',
            'https://files.example.com/d/storage/private-other/file.zip',
            'https://files.example.com.evil.example/d/storage/private/file.zip',
            'https://another.example/d/storage/private/file.zip',
        ) as $url ) {
            $this->assertNull( JLWA_Private_Download::resolve( $url, $this->settings ), $url );
        }
    }

    public function test_malformed_protected_paths_fail_closed(): void {
        foreach ( array(
            'https://files.example.com/d/storage/private/',
            'https://files.example.com/d/storage/private',
            'https://files.example.com/d/storage/private/../图片/a.zip',
            'https://files.example.com/d/storage/private/%2e%2e/图片/a.zip',
            'https://files.example.com/d/storage/private/foo%2Fbar.zip',
            'https://files.example.com/d/storage/private/a%255csecret.zip',
            'https://files.example.com/d/storage/private/a.zip?token=secret',
            'https://files.example.com/d/storage/private/a.zip#fragment',
            'https://user:pass@files.example.com/d/storage/private/a.zip',
            'ftp://files.example.com/d/storage/private/a.zip',
            'https://files.example.com:5245/d/storage/private/a.zip',
        ) as $url ) {
            $this->assertFalse( JLWA_Private_Download::resolve( $url, $this->settings ), $url );
        }
    }

    public function test_custom_delivery_host_is_validated_and_default_off(): void {
        $this->assertSame( '', JLWA_Private_Download::defaults()['delivery_host'] );
        $this->assertSame( 0, JLWA_Private_Download::defaults()['delivery_enabled'] );
        $config = JLWA_Private_Download::sanitized( array(
            'private_enabled' => 1,
            'private_hosts' => 'files.example.com',
            'private_source_prefix' => '/d/storage/private/',
            'private_internal_prefix' => '/_jlwa_private_openlist/',
            'delivery_host' => 'Download.EXAMPLE.COM',
            'delivery_enabled' => 1,
            'ticket_lifetime' => 9999,
            'rate_limit' => 999,
            'audit_enabled' => 1,
        ) );
        $this->assertSame( 'download.example.com', $config['delivery_host'] );
        $this->assertSame( 1, $config['delivery_enabled'] );
        $this->assertSame( 600, $config['ticket_lifetime'] );
        $this->assertSame( 120, $config['rate_limit'] );
        $this->assertSame( 0, $config['rate_enabled'] );
        $this->assertSame( 1, $config['audit_enabled'] );
        $ticket = str_repeat( 'a', 48 );
        $this->assertSame( 'https://download.example.com/?jlwa_dl=' . $ticket, JLWA_Private_Download::ticket_url( $ticket, $config['delivery_host'] ) );
        $this->assertSame( '', JLWA_Private_Download::ticket_url( 'invalid', $config['delivery_host'] ) );
        $this->assertSame( '', JLWA_Private_Download::ticket_url( $ticket, 'https://download.example.com' ) );
        $invalid = JLWA_Private_Download::sanitized( array(
            'private_enabled' => 1,
            'private_hosts' => 'files.example.com',
            'private_source_prefix' => '/d/storage/private/',
            'private_internal_prefix' => '/_jlwa_private_openlist/',
            'delivery_host' => 'https://evil.example/path',
            'delivery_enabled' => 1,
        ) );
        $this->assertSame( 0, $invalid['delivery_enabled'] );
        $this->assertSame( '', $invalid['delivery_host'] );
    }

    public function test_invalid_admin_settings_cannot_become_a_public_proxy(): void {
        $this->assertSame( array(), JLWA_Private_Download::hosts( 'https://files-alt.example.com' ) );
        $this->assertSame( '', JLWA_Private_Download::prefix( '//evil.org/x/' ) );
        $this->assertSame( '', JLWA_Private_Download::prefix( '/_bad/../secret/', true ) );
        $this->assertSame( '', JLWA_Private_Download::prefix( '/d/storage/public-images/', true ) );
        $input = JLWA_Private_Download::sanitized( array(
            'private_enabled' => 1,
            'private_hosts' => "FILES-ALT.EXAMPLE.COM\nfiles-alt.example.com",
            'private_source_prefix' => '/d/storage/private/',
            'private_internal_prefix' => '/_jlwa_private_openlist/',
        ) );
        $this->assertSame( "files-alt.example.com", $input['private_hosts'] );
        $this->assertSame( 1, $input['private_enabled'] );
        $invalid = JLWA_Private_Download::sanitized( array(
            'private_enabled' => 1,
            'private_hosts' => 'https://untrusted.example',
            'private_source_prefix' => '/d/storage/private/',
            'private_internal_prefix' => '/_jlwa_private_openlist/',
        ) );
        $this->assertSame( 0, $invalid['private_enabled'] );
        $this->assertSame( '', $invalid['private_hosts'] );
    }
}
