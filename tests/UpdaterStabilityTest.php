<?php
use PHPUnit\Framework\TestCase;

if ( ! defined( 'JLWA_VERSION' ) ) { define( 'JLWA_VERSION', '3.1.0' ); }

class Test_Updater extends JLWA_Updater {
    public $branch_fallback_called = 0;
    public function __construct() {}
    protected function get_release_info() { return new WP_Error( 'release_unavailable', 'Simulated unavailable GitHub release' ); }
    public function probe_remote() { return $this->get_remote_info( true ); }
    public function probe_install_info( $info ) { return $this->perform_update( $info ); }
    public function restore_prior( $previous, $destination, $backup ) { return $this->restore_previous( $previous, $destination, $backup ); }
    public function swap_package( $source, $destination ) { return $this->install_package( $source, $destination ); }
    public function inspect_package( $root, $info ) { return $this->validate_package( $root, $info ); }
}

final class UpdaterStabilityTest extends TestCase {
    private $root;
    private $source;
    private $current;
    private $updater;

    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/jlwa-tests-' . uniqid( '', true );
        $this->source = $this->root . '/new';
        $this->current = $this->root . '/plugin';
        mkdir( $this->source, 0755, true );
        mkdir( $this->current, 0755, true );
        file_put_contents( $this->source . '/jiuliu-wp-assistant.php', 'new code' );
        file_put_contents( $this->current . '/jiuliu-wp-assistant.php', 'previous working code' );
        $GLOBALS['wp_filesystem'] = new Test_FileSystem();
        $GLOBALS['test_copy_fail'] = false;
        $this->updater = new Test_Updater();
    }

    protected function tearDown(): void {
        $GLOBALS['wp_filesystem']->delete( $this->root, true );
    }

    public function test_update_rejects_package_without_vue_admin_assets_before_install(): void {
        $root = $this->root . '/candidate';
        mkdir( $root, 0755, true );
        $required = array(
            'assets/css/admin.css', 'assets/css/admin-content.css',
            'assets/js/admin.js', 'assets/js/admin-content.js',
            'includes/class-jlwa-admin.php', 'includes/class-jlwa-feature-registry.php',
            'includes/class-jlwa-updater.php', 'features/page-effects/bootstrap.php',
            'features/page-effects/assets/css/frontend.css', 'features/page-effects/assets/js/frontend.js',
            'features/anti-debug/bootstrap.php', 'features/anti-debug/assets/css/frontend.css',
            'features/anti-debug/assets/js/frontend.js', 'features/ai-article-summary/bootstrap.php',
            'features/ai-article-summary/includes/class-wpaias-api.php',
            'features/ai-article-summary/includes/class-wpaias-endpoint-policy.php',
            'features/ai-article-summary/includes/class-wpaias-cache.php',
            'features/immersive-preloader/bootstrap.php',
            'features/immersive-preloader/assets/css/preloader.css',
            'features/immersive-preloader/assets/js/preloader.js',
            'features/relative-media-urls/bootstrap.php',
            'features/relative-media-urls/includes/class-jrmu-domain-adapter.php',
            'ops/jlwa-media-nginx-helper.py', 'readme.txt', 'uninstall.php',
        );
        $main = "<?php\n/**\n * Version: 3.1.1\n */\n";
        file_put_contents( $root . '/jiuliu-wp-assistant.php', $main );
        foreach ( $required as $file ) {
            $path = $root . '/' . $file;
            if ( ! is_dir( dirname( $path ) ) ) { mkdir( dirname( $path ), 0755, true ); }
            file_put_contents( $path, 'fixture' );
        }
        $info = array( 'latest_version' => '3.1.1', 'main_sha256' => hash( 'sha256', $main ) );
        $result = $this->updater->inspect_package( $root, $info );
        $this->assertInstanceOf( WP_Error::class, $result, 'Missing Vue admin bundles must be rejected' );
        $this->assertSame( 'jlwa_incomplete_package', $result->get_error_code() );
        mkdir( $root . '/assets/vue', 0755, true );
        file_put_contents( $root . '/assets/vue/admin.js', 'window.JLWA = true;' );
        $result = $this->updater->inspect_package( $root, $info );
        $this->assertInstanceOf( WP_Error::class, $result, 'Missing Vue CSS must be rejected' );
        file_put_contents( $root . '/assets/vue/admin.css', '.jlwa{}' );
        $this->assertTrue( $this->updater->inspect_package( $root, $info ) );
        $this->assertSame( 'previous working code', file_get_contents( $this->current . '/jiuliu-wp-assistant.php' ) );
    }

    public function test_release_unavailable_must_not_fallback_to_unsigned_main_branch(): void {
        $result = $this->updater->probe_remote();
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'release_unavailable', $result->get_error_code() );
    }

    public function test_unsigned_package_is_rejected_before_any_filesystem_change(): void {
        $result = $this->updater->probe_install_info( array(
            'source' => 'branch-fallback',
            'download_url' => 'https://example.test/source.zip',
            'package_sha256' => '',
        ) );
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'jlwa_unsigned_package', $result->get_error_code() );
        $this->assertSame( 'previous working code', file_get_contents( $this->current . '/jiuliu-wp-assistant.php' ) );
    }

    public function test_failed_first_rename_keeps_current_plugin_untouched(): void {
        $GLOBALS['wp_filesystem']->fail_move_number = 1;
        $result = $this->updater->swap_package( $this->source, $this->current );
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'previous working code', file_get_contents( $this->current . '/jiuliu-wp-assistant.php' ) );
    }

    public function test_recovery_restores_previous_release_after_bad_upgrade(): void {
        $result = $this->updater->swap_package( $this->source, $this->current );
        $this->assertIsArray( $result );
        $restored = $this->updater->restore_prior( $result['previous_dir'], $this->current, $this->root . '/backup' );
        $this->assertTrue( $restored );
        $this->assertSame( 'previous working code', file_get_contents( $this->current . '/jiuliu-wp-assistant.php' ) );
    }

    public function test_failed_staging_copy_never_removes_current_plugin(): void {
        $GLOBALS['test_copy_fail'] = true;
        $result = $this->updater->swap_package( $this->source, $this->current );
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'previous working code', file_get_contents( $this->current . '/jiuliu-wp-assistant.php' ) );
    }

    public function test_successful_swap_preserves_prior_version_until_post_install_validation(): void {
        $result = $this->updater->swap_package( $this->source, $this->current );
        $this->assertIsArray( $result );
        $this->assertSame( 'new code', file_get_contents( $this->current . '/jiuliu-wp-assistant.php' ) );
        $this->assertSame( 'previous working code', file_get_contents( $result['previous_dir'] . '/jiuliu-wp-assistant.php' ) );
        $this->assertCount( 2, $GLOBALS['wp_filesystem']->moves );
    }

    public function test_failed_activation_move_restores_original_directory(): void {
        $GLOBALS['wp_filesystem']->fail_move_number = 2;
        $result = $this->updater->swap_package( $this->source, $this->current );
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'previous working code', file_get_contents( $this->current . '/jiuliu-wp-assistant.php' ) );
    }
}
