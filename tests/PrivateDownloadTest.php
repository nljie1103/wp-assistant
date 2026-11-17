<?php
/** Private OpenList delivery must be strict and disabled by default. */
require_once dirname( __DIR__ ) . '/features/private-download.php';

use PHPUnit\Framework\TestCase;

final class PrivateDownloadTest extends TestCase {
    private $settings;

    protected function setUp(): void {
        $this->settings = JLWA_Private_Download::defaults();
    }

    public function test_disabled_by_default_and_ordinary_download_is_untouched(): void {
        $this->assertSame( 0, $this->settings['private_enabled'] );
        $this->assertSame(
            '/var/tmp/not-our-file.zip',
            JLWA_Private_Download::intercept( '/var/tmp/not-our-file.zip', 'https://unrelated.example/a.zip', 42, 0, array( 'paid_type' => 2 ) )
        );
    }

    public function test_valid_encoded_chinese_path_routes_to_nginx_internal_location(): void {
        $url = 'https://openlist-blog-cdn.jiuliu.org/d/google/%E4%BB%98%E8%B4%B9%E8%B5%84%E6%BA%90/psdstencil3/%E8%8F%9C%E5%8D%95%E4%B8%80/Kuwait-ID.zip';
        $actual = JLWA_Private_Download::resolve( $url, $this->settings );
        $this->assertSame( '/_jlwa_private_openlist/psdstencil3/%E8%8F%9C%E5%8D%95%E4%B8%80/Kuwait-ID.zip', $actual['uri'] );
        $this->assertSame( 'Kuwait-ID.zip', $actual['filename'] );
        $this->assertStringNotContainsString( 'openlist-blog', $actual['uri'] );
    }

    public function test_second_configured_host_and_unicode_filenames(): void {
        $url = 'https://openlist-blog.jiuliu.org/d/google/付费资源/档案/封面合集.zip';
        $mapped = JLWA_Private_Download::resolve( $url, $this->settings );
        $this->assertSame( '/_jlwa_private_openlist/%E6%A1%A3%E6%A1%88/%E5%B0%81%E9%9D%A2%E5%90%88%E9%9B%86.zip', $mapped['uri'] );
    }

    public function test_unrelated_public_images_or_other_hosts_are_not_intercepted(): void {
        foreach ( array(
            'https://openlist-blog-cdn.jiuliu.org/d/google/图片/photo.jpg',
            'https://openlist-blog-cdn.jiuliu.org/d/google/付费资源-other/file.zip',
            'https://openlist-blog-cdn.jiuliu.org.evil.example/d/google/付费资源/file.zip',
            'https://another.example/d/google/付费资源/file.zip',
        ) as $url ) {
            $this->assertNull( JLWA_Private_Download::resolve( $url, $this->settings ), $url );
        }
    }

    public function test_malformed_protected_paths_fail_closed(): void {
        foreach ( array(
            'https://openlist-blog-cdn.jiuliu.org/d/google/付费资源/',
            'https://openlist-blog-cdn.jiuliu.org/d/google/付费资源',
            'https://openlist-blog-cdn.jiuliu.org/d/google/付费资源/../图片/a.zip',
            'https://openlist-blog-cdn.jiuliu.org/d/google/付费资源/%2e%2e/图片/a.zip',
            'https://openlist-blog-cdn.jiuliu.org/d/google/付费资源/foo%2Fbar.zip',
            'https://openlist-blog-cdn.jiuliu.org/d/google/付费资源/a%255csecret.zip',
            'https://openlist-blog-cdn.jiuliu.org/d/google/付费资源/a.zip?token=secret',
            'https://openlist-blog-cdn.jiuliu.org/d/google/付费资源/a.zip#fragment',
            'https://user:pass@openlist-blog-cdn.jiuliu.org/d/google/付费资源/a.zip',
            'ftp://openlist-blog-cdn.jiuliu.org/d/google/付费资源/a.zip',
            'https://openlist-blog-cdn.jiuliu.org:5245/d/google/付费资源/a.zip',
        ) as $url ) {
            $this->assertFalse( JLWA_Private_Download::resolve( $url, $this->settings ), $url );
        }
    }

    public function test_invalid_admin_settings_cannot_become_a_public_proxy(): void {
        $this->assertSame( array(), JLWA_Private_Download::hosts( 'https://openlist-blog.jiuliu.org' ) );
        $this->assertSame( '', JLWA_Private_Download::prefix( '//evil.org/x/' ) );
        $this->assertSame( '', JLWA_Private_Download::prefix( '/_bad/../secret/', true ) );
        $this->assertSame( '', JLWA_Private_Download::prefix( '/d/google/图片/', true ) );
        $input = JLWA_Private_Download::sanitized( array(
            'private_enabled' => 1,
            'private_hosts' => "OPENLIST-BLOG.JIULIU.ORG\nopenlist-blog.jiuliu.org",
            'private_source_prefix' => '/d/google/付费资源/',
            'private_internal_prefix' => '/_jlwa_private_openlist/',
        ) );
        $this->assertSame( "openlist-blog.jiuliu.org", $input['private_hosts'] );
        $this->assertSame( 1, $input['private_enabled'] );
        $invalid = JLWA_Private_Download::sanitized( array(
            'private_enabled' => 1,
            'private_hosts' => 'https://untrusted.example',
            'private_source_prefix' => '/d/google/付费资源/',
            'private_internal_prefix' => '/_jlwa_private_openlist/',
        ) );
        $this->assertSame( 0, $invalid['private_enabled'] );
        $this->assertSame( JLWA_Private_Download::DEFAULT_HOSTS, $invalid['private_hosts'] );
    }
}
