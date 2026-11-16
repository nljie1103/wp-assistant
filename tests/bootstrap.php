<?php
/** Minimal WordPress double for deterministic WP-AI frontend tests, without a live site. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'FS_CHMOD_DIR', 0755 );
define( 'WPAIAS_PLUGIN_URL', 'https://example.test/wp-content/plugins/jiuliu-wp-assistant/features/ai-article-summary/' );
define( 'WPAIAS_VERSION', '1.1.2' );

class WP_Post {
    public $ID;
    public $post_status;
    public $post_type;
    public $post_password;
    public $post_content;
    public function __construct( $id = 42, $password = '' ) {
        $this->ID = $id;
        $this->post_status = 'publish';
        $this->post_type = 'post';
        $this->post_password = $password;
        $this->post_content = 'Ordinary public text.';
    }
}
class Test_Ajax_Response extends RuntimeException {
    public $success;
    public $payload;
    public function __construct( $success, $payload, $code ) {
        parent::__construct( 'Ajax response', $code );
        $this->success = $success;
        $this->payload = $payload;
    }
}
class JLWA_AI_Summary_Feature {
    public static $settings = array();
    public static function get_settings() { return self::$settings; }
}
class WPAIAS_Cache {
    public static $summary = false;
    public static $reads = 0;
    public static function get( $post_id ) { ++self::$reads; return self::$summary; }
}
class WPAIAS_API {
    public static $calls = 0;
    public static function generate_summary( $content, $settings ) {
        ++self::$calls;
        return array( 'success' => true, 'data' => 'Generated summary' );
    }
}
function __( $value, $domain = '' ) { return $value; }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function esc_url( $value ) { return esc_attr( $value ); }
function esc_html_e( $value, $domain = '' ) { echo esc_html( $value ); }
function sanitize_html_class( $value ) { return preg_replace( '/[^a-zA-Z0-9_-]/', '', $value ); }
function absint( $value ) { return abs( (int) $value ); }
function get_queried_object_id() { return $GLOBALS['test_post']->ID; }
function get_post( $id = null ) { return $GLOBALS['test_post']; }
function is_admin() { return ! empty( $GLOBALS['test_is_admin'] ); }
function is_feed() { return false; }
function is_search() { return false; }
function is_singular( $post_type = null ) { return ! array_key_exists('test_is_singular', $GLOBALS) || ! empty($GLOBALS['test_is_singular']); }
function post_password_required( $post ) { return '' !== $post->post_password && empty( $GLOBALS['test_password_valid'] ); }
function current_user_can( $capability, ...$args ) { return ! empty( $GLOBALS['test_user_can_edit'] ); }
function wp_get_post_categories( $post_id ) { return array(); }
function check_ajax_referer( $action, $field ) { return true; }
function wp_send_json_error( $data, $code = 200 ) { throw new Test_Ajax_Response( false, $data, $code ); }
function wp_send_json_success( $data, $code = 200 ) { throw new Test_Ajax_Response( true, $data, $code ); }
function wp_enqueue_style( ...$args ) {}
function wp_enqueue_script( ...$args ) {}
function wp_localize_script( $handle, $name, $data ) { $GLOBALS['test_localized'][ $name ] = $data; }
function wp_create_nonce( $action ) { return 'test_nonce'; }
function admin_url( $path ) { return 'https://example.test/wp-admin/' . $path; }
function add_filter( ...$args ) {}
function add_action( ...$args ) {}
function add_shortcode( ...$args ) {}

require_once dirname( __DIR__ ) . '/features/ai-article-summary/includes/class-wpaias-frontend.php';

// Updater doubles: filesystem manipulation is tested in isolated temp directories.
class WP_Error {
    private $code;
    private $message;
    public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function get_transient( $key ) { return false; }
function set_transient( $key, $value, $ttl = 0 ) { return true; }
function wp_generate_uuid4() { return 'test-uuid'; }
function trailingslashit( $value ) { return rtrim( $value, '/' ) . '/'; }
function untrailingslashit( $value ) { return rtrim( $value, '/' ); }
function copy_dir( $source, $dest ) {
    if ( ! empty( $GLOBALS['test_copy_fail'] ) ) {
        return new WP_Error( 'copy_failed', 'Simulated staging copy failure' );
    }
    if ( ! is_dir( $source ) || ! mkdir( $dest, 0755, true ) ) {
        return new WP_Error( 'copy_failed', 'Cannot create destination' );
    }
    foreach ( new DirectoryIterator( $source ) as $file ) {
        if ( $file->isDot() ) { continue; }
        $from = $source . '/' . $file->getFilename();
        $to = $dest . '/' . $file->getFilename();
        if ( $file->isDir() ) {
            $result = copy_dir( $from, $to );
            if ( is_wp_error( $result ) ) { return $result; }
        } elseif ( ! copy( $from, $to ) ) {
            return new WP_Error( 'copy_failed', 'Cannot copy file' );
        }
    }
    return true;
}
class Test_FileSystem {
    public $moves = array();
    public $deletes = array();
    public $fail_move_number = 0;
    public function exists( $path ) { return file_exists( $path ); }
    public function is_dir( $path ) { return is_dir( $path ); }
    public function mkdir( $path, $mode = 0755 ) { return @mkdir( $path, $mode, true ); }
    public function move( $from, $to, $overwrite = false ) {
        $this->moves[] = array( $from, $to );
        if ( count( $this->moves ) === $this->fail_move_number ) { return false; }
        return @rename( $from, $to );
    }
    public function delete( $path, $recursive = false ) {
        $this->deletes[] = $path;
        if ( is_dir( $path ) ) {
            foreach ( new FilesystemIterator( $path ) as $item ) {
                $this->delete( $item->getPathname(), true );
            }
            return @rmdir( $path );
        }
        return file_exists( $path ) ? @unlink( $path ) : true;
    }
}

require_once dirname( __DIR__ ) . '/includes/class-jlwa-updater.php';

require_once dirname( __DIR__ ) . '/features/ai-article-summary/includes/class-wpaias-endpoint-policy.php';

function wp_doing_ajax() { return false; }
function wp_doing_cron() { return false; }
function is_robots() { return false; }
function is_user_logged_in() { return ! empty( $GLOBALS['test_logged_in'] ); }
function wp_is_mobile() { return ! empty( $GLOBALS['test_mobile'] ); }
function wp_unslash( $v ) { return $v; }
function wp_parse_url( $v, $component = -1 ) { return parse_url( $v, $component ); }
function is_page() { return ! empty( $GLOBALS['test_is_page'] ); }
function is_front_page() { return ! empty( $GLOBALS['test_is_front_page'] ); }
function is_home() { return ! empty( $GLOBALS['test_is_home'] ); }
function is_archive() { return false; }

require_once dirname( __DIR__ ) . '/features/anti-debug/bootstrap.php';
