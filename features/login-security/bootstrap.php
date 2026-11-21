<?php
/** Cloudflare Turnstile protection for Zibll /oauth/clogin, without CF proxy. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/includes/class-jlwa-login-risk.php';
require_once __DIR__ . '/includes/class-jlwa-login-scope.php';
require_once __DIR__ . '/includes/class-jlwa-oauth-return.php';

final class JLWA_Login_Security_Feature {
    const OPTION = 'jlwa_login_security_options';
    const SECRET = 'jlwa_login_security_secret';
    const COOKIE = 'jlwa_oauth_ticket';
    private static $instance;
    private $wp_login_challenge = null;

    public static function instance() {
        if ( ! self::$instance ) { self::$instance = new self(); }
        return self::$instance;
    }
    private function __construct() {
        // The theme dispatches OAuth later. Block the request before it reaches the SDK.
        add_action( 'init', array( $this, 'prepare_oauth_return' ), -20 );
        add_action( 'init', array( $this, 'guard_oauth_start' ), 0 );
        add_filter( 'wp_redirect', array( $this, 'guard_oauth_callback_redirect' ), 1, 2 );
        add_filter( 'wp_safe_redirect_fallback', array( $this, 'oauth_callback_safe_fallback' ), 10, 2 );
        add_action( 'admin_post_jlwa_login_security_save', array( $this, 'save' ) );
        add_action( 'login_form', array( $this, 'show_wp_login_widget' ) );
        add_filter( 'authenticate', array( $this, 'guard_wp_password' ), 5, 3 );
    }
    public static function settings() {
        $saved = get_option( self::OPTION, array() );
        $saved = is_array( $saved ) ? $saved : array();
        return array(
            'enabled' => ! empty( $saved['enabled'] ) ? 1 : 0,
            'site_key' => isset( $saved['site_key'] ) && is_string( $saved['site_key'] ) ? $saved['site_key'] : '',
            // Missing mode on older installs means adaptive, not always-on.
            'mode' => isset( $saved['mode'] ) && 'always' === $saved['mode'] ? 'always' : 'adaptive',
            'wp_login' => ! empty( $saved['wp_login'] ) ? 1 : 0,
            'custom_paths' => isset( $saved['custom_paths'] ) && is_string( $saved['custom_paths'] ) ? $saved['custom_paths'] : '',
            'ip_limit' => isset( $saved['ip_limit'] ) ? max( 2, min( 100, (int) $saved['ip_limit'] ) ) : 6,
            'global_limit' => isset( $saved['global_limit'] ) ? max( 10, min( 3000, (int) $saved['global_limit'] ) ) : 50,
            'cooldown' => isset( $saved['cooldown'] ) ? max( 60, min( 3600, (int) $saved['cooldown'] ) ) : 300,
        );
    }
    public static function secret() {
        if ( defined( 'JLWA_TURNSTILE_SECRET_KEY' ) && is_string( JLWA_TURNSTILE_SECRET_KEY ) && '' !== JLWA_TURNSTILE_SECRET_KEY ) {
            return JLWA_TURNSTILE_SECRET_KEY;
        }
        $secret = get_option( self::SECRET, '' );
        return is_string( $secret ) ? $secret : '';
    }
    public static function configured() {
        $settings = self::settings();
        return (bool) ( preg_match( '/^[A-Za-z0-9_-]{10,160}$/', $settings['site_key'] ) && '' !== self::secret() );
    }
    public function save() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Access denied', '', array( 'response' => 403 ) ); }
        check_admin_referer( 'jlwa_login_security_save' );
        $raw = isset( $_POST['jlwa_login_security'] ) && is_array( $_POST['jlwa_login_security'] ) ? wp_unslash( $_POST['jlwa_login_security'] ) : array();
        $key = isset( $raw['site_key'] ) && is_string( $raw['site_key'] ) ? trim( $raw['site_key'] ) : '';
        $enabled = ! empty( $raw['enabled'] ) ? 1 : 0;
        $previous = self::settings();
        $paths = isset( $raw['custom_paths'] ) ? JLWA_Login_Scope::normalize( $raw['custom_paths'] ) : '';
        $invalid_paths = false === $paths;
        $new_secret = isset( $raw['secret_key'] ) && is_string( $raw['secret_key'] ) ? trim( $raw['secret_key'] ) : '';
        $existing_secret = self::secret();
        if ( '' !== $new_secret && strlen( $new_secret ) <= 256 && ! defined( 'JLWA_TURNSTILE_SECRET_KEY' ) ) {
            // Never autoload credentials or echo them back into the admin page.
            update_option( self::SECRET, $new_secret, false );
            $existing_secret = $new_secret;
        }
        if ( ! preg_match( '/^[A-Za-z0-9_-]{10,160}$/', $key ) || '' === $existing_secret ) {
            // Settings may be saved while incomplete, but protection cannot turn on.
            $enabled = 0;
            $notice = 'incomplete';
        } else {
            $notice = 'saved';
        }
        if ( $invalid_paths ) { $paths = $previous['custom_paths']; $notice = 'invalid-paths'; }
        update_option( self::OPTION, array(
            'enabled' => $enabled,
            'site_key' => $key,
            'mode' => isset( $raw['mode'] ) && 'always' === $raw['mode'] ? 'always' : 'adaptive',
            'wp_login' => ! empty( $raw['wp_login'] ) ? 1 : 0,
            'custom_paths' => $paths,
            'ip_limit' => isset( $raw['ip_limit'] ) ? max( 2, min( 100, absint( $raw['ip_limit'] ) ) ) : $previous['ip_limit'],
            'global_limit' => isset( $raw['global_limit'] ) ? max( 10, min( 3000, absint( $raw['global_limit'] ) ) ) : $previous['global_limit'],
            'cooldown' => isset( $raw['cooldown'] ) ? max( 60, min( 3600, absint( $raw['cooldown'] ) ) ) : $previous['cooldown'],
        ), false );
        wp_safe_redirect( add_query_arg( 'jlwa_login_notice', $notice, admin_url( 'admin.php?page=jlwa-login-security' ) ) );
        exit;
    }
    /**
     * Correct the Zibll default URL builder without editing commercial theme files.
     * It uses $_GET['redirect_to'] before falling back to the current request URI.
     * OAuth error pages may display a login link: force a safe destination there.
     */
    public function prepare_oauth_return() {
        $home = home_url( '/' );
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
        if ( self::is_oauth_start_path( $uri ) ) {
            $_GET['redirect_to'] = $home;
            $_REQUEST['redirect_to'] = $home;
        } elseif ( isset( $_GET['redirect_to'] ) ) {
            // Limit changes on ordinary pages to recursive login links; do not
            // rewrite unrelated plugin/payment redirect_to values globally.
            $value = $_GET['redirect_to'];
            if ( ! is_string( $value ) || JLWA_OAuth_Return::contains_recursive_login( $value ) ) {
                $_GET['redirect_to'] = $home;
                $_REQUEST['redirect_to'] = $home;
            }
        }
    }
    public function oauth_callback_safe_fallback( $fallback, $status ) {
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
        $path = wp_parse_url( $uri, PHP_URL_PATH );
        return is_string( $path ) && preg_match( '~(?:^|/)oauth/clogin/callback/?$~i', $path ) ? home_url( '/' ) : $fallback;
    }
    /** A safe redirect may still point back into a login loop; check the final callback. */
    public function guard_oauth_callback_redirect( $location, $status ) {
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
        $path = wp_parse_url( $uri, PHP_URL_PATH );
        if ( ! is_string( $path ) || ! preg_match( '~(?:^|/)oauth/clogin/callback/?$~i', $path ) ) { return $location; }
        return JLWA_OAuth_Return::sanitize( $location, home_url( '/' ) );
    }
    /** Only the login INITIATION route: never intercept /oauth/clogin/callback. */
    public static function is_oauth_start_path( $uri ) {
        if ( ! is_string( $uri ) || '' === $uri ) { return false; }
        $path = wp_parse_url( $uri, PHP_URL_PATH );
        return is_string( $path ) && (bool) preg_match( '~(?:^|/)oauth/clogin/?$~i', $path );
    }
    /** Reject self-referential /oauth/clogin destinations, including nested encodings. */
    public static function unsafe_return( $value ) {
        if ( ! is_string( $value ) || strlen( $value ) > 2048 ) { return true; }
        for ( $i = 0; $i < 6; $i++ ) {
            if ( preg_match( '~(?:^|[/?])oauth/clogin(?:[/?#&]|$)~i', $value ) ) { return true; }
            $decoded = rawurldecode( $value );
            if ( $decoded === $value ) { break; }
            $value = $decoded;
        }
        return (bool) preg_match( '~(?:^|[/?])oauth/clogin(?:[/?#&]|$)~i', $value );
    }
    public static function is_canonical_oauth_host( $host_header, $home ) {
        if ( ! is_string( $host_header ) || ! is_string( $home ) || '' === $host_header || preg_match( '/[\x00-\x20\x7f\\@]/', $host_header ) ) { return false; }
        // Do not trust HTTP Host as an authentication signal: this only selects the canonical site.
        $raw = wp_parse_url( 'https://' . $host_header );
        $home_host = wp_parse_url( $home, PHP_URL_HOST );
        return is_array( $raw ) && isset( $raw['host'] ) && ! isset( $raw['user'] ) && ! isset( $raw['pass'] )
            && ! isset( $raw['path'] ) && ! isset( $raw['query'] ) && ! isset( $raw['fragment'] )
            && ( ! isset( $raw['port'] ) || 443 === $raw['port'] ) && is_string( $home_host ) && strtolower( $raw['host'] ) === strtolower( $home_host );
    }
    /** Untrusted request data must never cause a PHP redirect to an external destination. */
    public static function valid_login_type( $type ) {
        return is_string( $type ) && (bool) preg_match( '/\A[a-z][a-z0-9_-]{0,39}\z/D', $type );
    }
    public function guard_oauth_start() {
        $options = self::settings();
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
        if ( self::is_oauth_start_path( $uri ) ) {
            $method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
            $home = home_url( '/' );
            $raw_rurl = isset( $_GET['rurl'] ) ? $_GET['rurl'] : $home;
            // Recursive returns are an error even when Turnstile is disabled.
            if ( ! is_string( $raw_rurl ) || self::unsafe_return( $raw_rurl ) ) {
                status_header( 403 );
                exit;
            }
            // External / alternate-domain destinations are not sent back into the OAuth SDK.
            $safe_rurl = JLWA_OAuth_Return::sanitize( $raw_rurl, $home );
            if ( $safe_rurl !== $raw_rurl ) {
                $_GET['rurl'] = $safe_rurl;
                $_REQUEST['rurl'] = $safe_rurl;
            }
            // A secondary domain should not perform a separate OAuth/Session handshake.
            // Only the canonical domain receives the login initiation and Turnstile token.
            if ( ! self::is_canonical_oauth_host( isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '', $home ) ) {
                if ( 'GET' !== $method || ! isset( $_GET['type'] ) || ! self::valid_login_type( $_GET['type'] ) ) {
                    status_header( 403 );
                    exit;
                }
                $target = add_query_arg( array( 'type' => $_GET['type'], 'rurl' => rawurlencode( $safe_rurl ) ), home_url( '/oauth/clogin' ) );
                wp_safe_redirect( $target, 302 );
                exit;
            }
        }
        if ( ! $options['enabled'] ) { return; }
        if ( ! self::is_oauth_start_path( $uri ) ) {
            if ( $options['wp_login'] && self::is_wp_password_post( $uri ) ) {
                $this->wp_login_challenge = self::risk_required( $options, true );
            }
            $this->guard_custom_path( $uri, $options );
            return;
        }
        if ( strlen( $uri ) > 4096 ) { status_header( 414 ); exit; }
        $method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
        if ( ! in_array( $method, array( 'GET', 'POST' ), true ) ) { status_header( 405 ); header( 'Allow: GET, POST' ); exit; }
        if ( empty( $_GET['type'] ) || ! self::valid_login_type( $_GET['type'] ) || ( isset( $_GET['rurl'] ) && self::unsafe_return( $_GET['rurl'] ) ) ) {
            status_header( 403 ); exit;
        }
        if ( ! self::configured() || ! is_ssl() ) { status_header( 503 ); exit; }
        if ( 'POST' === $method ) { $this->process_challenge( $uri ); return; }
        if ( $this->consume_ticket( $uri ) ) { return; }
        if ( ! self::risk_required( $options, true ) ) { return; }
        $this->challenge_page( $uri, $options['site_key'], 'jlwa_oauth_login' );
    }
    private static function ticket_key( $ticket ) {
        return 'jlwa_oauth_' . hash( 'sha256', $ticket );
    }
    private static function clear_ticket_cookie() {
        setcookie( self::COOKIE, '', array( 'expires' => time() - 3600, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax' ) );
    }
    private function consume_ticket( $uri ) {
        $ticket = isset( $_COOKIE[ self::COOKIE ] ) && is_string( $_COOKIE[ self::COOKIE ] ) ? $_COOKIE[ self::COOKIE ] : '';
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $ticket ) ) { return false; }
        $key = self::ticket_key( $ticket );
        $expected = get_transient( $key );
        delete_transient( $key ); // Single-use, including on a failed URL binding.
        self::clear_ticket_cookie();
        return is_string( $expected ) && hash_equals( $expected, hash( 'sha256', $uri ) );
    }
    public static function verified_response( $response, $hostname, $action = 'jlwa_oauth_login' ) {
        return is_array( $response ) && isset( $response['success'] ) && true === $response['success']
            && isset( $response['hostname'], $response['action'] )
            && is_string( $response['hostname'] ) && is_string( $response['action'] )
            && hash_equals( strtolower( $hostname ), strtolower( $response['hostname'] ) )
            && hash_equals( $action, $response['action'] );
    }
    private function process_challenge( $uri ) {
        $token = isset( $_POST['cf-turnstile-response'] ) && is_string( $_POST['cf-turnstile-response'] ) ? trim( wp_unslash( $_POST['cf-turnstile-response'] ) ) : '';
        if ( '' === $token || strlen( $token ) > 2048 ) { status_header( 403 ); exit; }
        if ( ! $this->verify_token( $token, 'jlwa_oauth_login' ) ) { status_header( 403 ); exit; }
        $ticket = bin2hex( random_bytes( 32 ) );
        set_transient( self::ticket_key( $ticket ), hash( 'sha256', $uri ), 90 );
        setcookie( self::COOKIE, $ticket, array( 'expires' => time() + 90, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax' ) );
        nocache_headers();
        wp_safe_redirect( home_url( $uri ), 303 );
        exit;
    }
    private function verify_token( $token, $action ) {
        if ( ! is_string( $token ) || '' === $token || strlen( $token ) > 2048 ) { return false; }
        $reply = wp_remote_post( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', array(
            'timeout' => 7, 'redirection' => 0,
            'body' => array( 'secret' => self::secret(), 'response' => $token ),
        ) );
        $result = is_wp_error( $reply ) ? array() : json_decode( wp_remote_retrieve_body( $reply ), true );
        $host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
        return self::verified_response( $result, $host, $action );
    }
    /** False for normal traffic; true after global or peer volume triggers. */
    public static function risk_required( $options, $increment = false ) {
        if ( 'always' === $options['mode'] ) { return true; }
        return JLWA_Login_Risk::active( $options, $increment );
    }
    public static function is_wp_password_post( $uri ) {
        $path = wp_parse_url( $uri, PHP_URL_PATH );
        if ( ! is_string( $path ) || ! preg_match( '~(?:^|/)wp-login\.php$~i', $path ) ) { return false; }
        $action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? $_REQUEST['action'] : 'login';
        return 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( $_SERVER['REQUEST_METHOD'] ) : '' ) && ( '' === $action || 'login' === $action );
    }
    /** The core WP login form uses login_form; avoid wp-admin AJAX/API endpoints. */
    public function show_wp_login_widget() {
        $options = self::settings();
        if ( ! $options['enabled'] || ! $options['wp_login'] || ! self::configured() || ! is_ssl() ) { return; }
        if ( ! self::risk_required( $options, false ) ) { return; }
        $message = 'always' === $options['mode'] ? '为保障账号安全，请先完成验证后继续登录。' : '检测到短时间内较多登录请求，请先完成安全验证。';
        echo '<div class="jlwa-wordpress-login-check"><p>' . esc_html( $message ) . '</p>';
        echo '<div class="cf-turnstile" data-sitekey="' . esc_attr( $options['site_key'] ) . '" data-action="jlwa_wp_login"></div></div>';
        echo '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
    }
    public function guard_wp_password( $user, $username, $password ) {
        $options = self::settings();
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
        if ( ! $options['enabled'] || ! $options['wp_login'] || ! self::is_wp_password_post( $uri ) ) { return $user; }
        if ( null === $this->wp_login_challenge ) { $this->wp_login_challenge = self::risk_required( $options, true ); }
        if ( ! $this->wp_login_challenge ) { return $user; }
        $token = isset( $_POST['cf-turnstile-response'] ) && is_string( $_POST['cf-turnstile-response'] ) ? trim( wp_unslash( $_POST['cf-turnstile-response'] ) ) : '';
        if ( ! self::configured() || ! is_ssl() || ! $this->verify_token( $token, 'jlwa_wp_login' ) ) {
            return new WP_Error( 'jlwa_login_challenge', '登录请求较多，请完成安全验证后重试。' );
        }
        return $user;
    }
    /** Custom paths are PHP-routed requests only; never alter admin AJAX / auth callbacks. */
    private function guard_custom_path( $uri, $options ) {
        if ( '' === $options['custom_paths'] || strlen( $uri ) > 4096 ) { return; }
        $rule = JLWA_Login_Scope::match( $uri, $options['custom_paths'] );
        if ( '' === $rule ) { return; }
        if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) { return; }
        $method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( $_SERVER['REQUEST_METHOD'] ) : 'GET';
        if ( ! in_array( $method, array( 'GET', 'HEAD', 'POST' ), true ) ) { status_header( 405 ); exit; }
        if ( $this->has_scope_cookie( $rule ) ) { return; }
        if ( 'POST' === $method && isset( $_POST['jlwa_scope_verify'] ) ) {
            $token = isset( $_POST['cf-turnstile-response'] ) && is_string( $_POST['cf-turnstile-response'] ) ? trim( wp_unslash( $_POST['cf-turnstile-response'] ) ) : '';
            if ( ! $this->verify_token( $token, 'jlwa_scope_gate' ) ) { status_header( 403 ); exit; }
            $this->set_scope_cookie( $rule );
            nocache_headers();
            wp_safe_redirect( home_url( $uri ), 303 );
            exit;
        }
        if ( ! self::risk_required( $options, true ) ) { return; }
        if ( ! self::configured() || ! is_ssl() ) { status_header( 503 ); exit; }
        if ( 'HEAD' === $method || 'POST' === $method ) { status_header( 403 ); exit; }
        $this->challenge_page( $uri, $options['site_key'], 'jlwa_scope_gate' );
    }
    private static function scope_cookie_name( $rule ) {
        return 'jlwa_scope_' . substr( hash( 'sha256', $rule ), 0, 16 );
    }
    private function set_scope_cookie( $rule ) {
        $expiry = time() + 600;
        $salt = wp_salt( 'auth' );
        $payload = $expiry . ':' . bin2hex( random_bytes( 8 ) );
        $signature = hash_hmac( 'sha256', $rule . '|' . $payload, $salt );
        setcookie( self::scope_cookie_name( $rule ), $payload . ':' . $signature, array( 'expires' => $expiry, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax' ) );
    }
    private function has_scope_cookie( $rule ) {
        $cookie = self::scope_cookie_name( $rule );
        if ( ! isset( $_COOKIE[ $cookie ] ) || ! is_string( $_COOKIE[ $cookie ] ) ) { return false; }
        $value = $_COOKIE[ $cookie ];
        if ( ! preg_match( '/\A([0-9]{10,11}):([a-f0-9]{16}):([a-f0-9]{64})\z/D', $value, $parts ) ) { return false; }
        if ( (int) $parts[1] < time() || (int) $parts[1] > time() + 600 ) { return false; }
        $check = hash_hmac( 'sha256', $rule . '|' . $parts[1] . ':' . $parts[2], wp_salt( 'auth' ) );
        return hash_equals( $check, $parts[3] );
    }
    private function challenge_page( $uri, $site_key, $action ) {
        status_header( 200 );
        nocache_headers();
        header( 'X-Robots-Tag: noindex, nofollow', true );
        header( 'Content-Type: text/html; charset=utf-8' );
        $site_name = get_bloginfo( 'name' );
        $safe_action = esc_url( home_url( $uri ) );
        $scope_input = 'jlwa_scope_gate' === $action ? '<input type="hidden" name="jlwa_scope_verify" value="1">' : '';
        $options = self::settings();
        $is_scope = 'jlwa_scope_gate' === $action;
        if ( $is_scope ) {
            $message = 'always' === $options['mode'] ? '为保障访问安全，请先完成验证后继续访问。' : '检测到短时间内访问请求较多，为保障安全，请先完成验证后继续访问。';
        } else {
            $message = 'always' === $options['mode'] ? '为保障账号安全，请先完成验证后继续登录。' : '检测到短时间内多次登录请求，为保障账号安全，请先完成验证后继续登录。';
        }
        $button = $is_scope ? '验证并继续访问' : '验证并继续登录';
        echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>登录安全验证 - ' . esc_html( $site_name ) . '</title>';
        echo '<meta name="referrer" content="same-origin"><style>body{margin:0;background:#f5f6f9;font:16px system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#1c2637;display:grid;place-items:center;min-height:100vh}.jlwa-verify{box-sizing:border-box;width:min(92vw,440px);padding:36px;background:white;border:1px solid #e8eaf0;border-radius:16px;box-shadow:0 16px 50px #233b5610}h1{font-size:22px;margin:0 0 12px}p{color:#607086;line-height:1.7;margin:0 0 22px}button{border:0;border-radius:8px;padding:12px 20px;background:#205fe6;color:white;font-size:15px;cursor:pointer;margin-top:22px}small{display:block;margin-top:20px;color:#8691a1}</style>';
        echo '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script></head><body><main class="jlwa-verify"><h1>请完成安全验证</h1><p>' . esc_html( $message ) . '</p><form method="post" action="' . $safe_action . '">' . $scope_input . '<div class="cf-turnstile" data-sitekey="' . esc_attr( $site_key ) . '" data-action="' . esc_attr( $action ) . '"></div><button type="submit">' . esc_html( $button ) . '</button></form></main></body></html>';
        exit;
    }
    public function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $options = self::settings();
        $configured = self::configured();
        $constant = defined( 'JLWA_TURNSTILE_SECRET_KEY' );
        $keys_guide_url = 'https://github.com/nljie1103/wp-assistant/blob/main/docs/turnstile-keys-guide.md';
        $adaptive_guide_url = 'https://github.com/nljie1103/wp-assistant/blob/main/docs/login-security-adaptive.md';
        $notice = isset( $_GET['jlwa_login_notice'] ) ? sanitize_key( wp_unslash( $_GET['jlwa_login_notice'] ) ) : '';
        ?>
        <div class="wrap jlwa-login-security">
          <div class="jlwa-hero jlwa-hero--overview"><div class="jlwa-hero__copy"><span class="jlwa-kicker">OAUTH SECURITY</span><h2>登录安全 · 智能验证</h2><p>正常访问无感；短时间出现大量登录请求时再要求验证。支持彩虹 OAuth、WordPress 原生密码登录和自定义动态路径，不改变主题授权回调。</p></div></div>
          <?php if ( 'saved' === $notice ) : ?><div class="notice notice-success inline"><p>配置已保存。</p></div><?php elseif ( 'incomplete' === $notice ) : ?><div class="notice notice-warning inline"><p>配置已保存，但缺少有效 Site Key 或 Secret Key，防护保持关闭。</p></div><?php elseif ( 'invalid-paths' === $notice ) : ?><div class="notice notice-error inline"><p>自定义路径无效，已保留先前的路径配置。请每行填写 /private 或 /private/*，不能填写系统后台、OAuth 回调及支付路径。</p></div><?php endif; ?>
          <div class="jlwa-system-grid">
            <section class="jlwa-update-panel">
              <div class="jlwa-update-panel__head"><div><span class="jlwa-kicker">LOGIN PROTECTION</span><h2>风险触发与保护范围</h2><p>Turnstile 由浏览器加载；WordPress 服务器必须通过 Siteverify 执行强制校验。开启前请先用中国大陆网络测试验证码可用性。<a href="<?php echo esc_url( $keys_guide_url ); ?>" target="_blank" rel="noopener noreferrer">查看 Site Key / Secret Key 获取教程</a></p></div></div>
              <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="jlwa_login_security_save" />
                <?php wp_nonce_field( 'jlwa_login_security_save' ); ?>
                <table class="form-table" role="presentation"><tbody>
                  <tr><th scope="row">保护状态</th><td><label><input type="checkbox" name="jlwa_login_security[enabled]" value="1" <?php checked( $options['enabled'], 1 ); ?> /> 启用登录安全防护</label><p class="description">功能总开关默认关闭；启用后默认智能验证，不会让每个正常访问者都过验证码。OAuth 返回地址去递归和主域名归一化始终生效；Turnstile 开关不会关闭这些基础安全校验。已有 Nginx 异常 rurl 防护不受此开关影响。</p></td></tr>
                  <tr><th scope="row">验证码出现时机</th><td><label><input type="radio" name="jlwa_login_security[mode]" value="adaptive" <?php checked( $options['mode'], 'adaptive' ); ?> /> 智能验证（推荐，默认）</label><br><label><input type="radio" name="jlwa_login_security[mode]" value="always" <?php checked( $options['mode'], 'always' ); ?> /> 每次要求验证（高防护）</label><p class="description">智能模式以 60 秒窗口评估单来源和全站请求频率，触发后保持短期高风险状态。攻击者不断换 IP 时，全站阈值同样有效。<a href="<?php echo esc_url( $adaptive_guide_url ); ?>" target="_blank" rel="noopener noreferrer">查看策略说明</a></p></td></tr>
                  <tr><th scope="row">智能阈值</th><td><label>单来源 / 分钟 <input type="number" class="small-text" min="2" max="100" name="jlwa_login_security[ip_limit]" value="<?php echo esc_attr( $options['ip_limit'] ); ?>" /></label>　<label>全站 / 分钟 <input type="number" class="small-text" min="10" max="3000" name="jlwa_login_security[global_limit]" value="<?php echo esc_attr( $options['global_limit'] ); ?>" /></label>　<label>风险持续（秒） <input type="number" class="small-text" min="60" max="3600" step="30" name="jlwa_login_security[cooldown]" value="<?php echo esc_attr( $options['cooldown'] ); ?>" /></label><p class="description">默认每分钟单来源 6 次或全站 50 次触发，持续 300 秒。风险统计使用服务器临时文件和哈希来源，不在数据库逐条写入攻击 IP。</p></td></tr>
                  <tr><th scope="row">WordPress 后台登录</th><td><label><input type="checkbox" name="jlwa_login_security[wp_login]" value="1" <?php checked( $options['wp_login'], 1 ); ?> /> 保护原生 <code>/wp-login.php</code> 密码登录</label><p class="description">未登录访问 /wp-admin/ 通常会转至此处；在密码认证前强制验证。不会阻断后台管理、admin-ajax.php、admin-post.php 或 OAuth 回调。子比自定义 AJAX 登录需另行接入真实认证端点，不能只靠目录规则。</p></td></tr>
                  <tr><th scope="row"><label for="jlwa-login-scope">自定义动态路径</label></th><td><textarea id="jlwa-login-scope" class="large-text code" rows="5" name="jlwa_login_security[custom_paths]" placeholder="/members/private&#10;/member-center/*"><?php echo esc_textarea( $options['custom_paths'] ); ?></textarea><p class="description">一行一条，<code>/private</code> 精确匹配、<code>/private/*</code> 匹配目录下动态请求。仅对经过 WordPress PHP 的请求生效；直接访问静态文件无法由本插件拦截。系统接口、订单支付、OAuth 路由禁止写入，/wp-admin 请用上方专用开关。<a href="<?php echo esc_url( $adaptive_guide_url ); ?>" target="_blank" rel="noopener noreferrer">范围与旁路说明</a></p></td></tr>
                  <tr><th scope="row"><label for="jlwa-ts-site-key">Site Key</label></th><td><input id="jlwa-ts-site-key" class="regular-text" autocomplete="off" name="jlwa_login_security[site_key]" type="text" value="<?php echo esc_attr( $options['site_key'] ); ?>" /><p class="description">Cloudflare Turnstile Widget 的公开站点密钥，允许主机名需包含本博客主域名。<a href="<?php echo esc_url( $keys_guide_url ); ?>" target="_blank" rel="noopener noreferrer">如何获取？</a></p></td></tr>
                  <tr><th scope="row"><label for="jlwa-ts-secret-key">Secret Key</label></th><td><input id="jlwa-ts-secret-key" class="regular-text" autocomplete="new-password" name="jlwa_login_security[secret_key]" type="password" value="" placeholder="<?php echo $configured ? '已配置（留空不修改）' : '输入 Secret Key'; ?>" <?php disabled( $constant, true ); ?> /><p class="description"><?php echo $constant ? 'Secret Key 已通过 wp-config.php 常量 JLWA_TURNSTILE_SECRET_KEY 提供。' : '已保存的密钥不会回显；新密钥仅在填写时更新。也可在 wp-config.php 中使用 JLWA_TURNSTILE_SECRET_KEY 常量。'; ?> <a href="<?php echo esc_url( $keys_guide_url ); ?>" target="_blank" rel="noopener noreferrer">密钥获取与安全说明</a></p></td></tr>
                </tbody></table>
                <?php submit_button( '保存登录安全设置' ); ?>
              </form>
            </section>
            <aside class="jlwa-system-side"><h2>接入检查</h2><div class="jlwa-system-tip"><strong>配置状态</strong><p><?php echo esc_html( $configured ? 'Turnstile 密钥已配置' : '尚未完成密钥配置' ); ?></p></div><div class="jlwa-system-tip"><strong>生效范围</strong><p>彩虹 OAuth 发起入口默认受控；WordPress 原生密码登录与自定义动态路径需按需开启。第三方 OAuth 回调不会触发验证码。</p></div><div class="jlwa-system-tip"><strong>防护分层</strong><p>Nginx 先阻断异常自引用 rurl，本模块负责登录请求的风险触发与验证。未通过 WordPress 的静态资源仍需 Nginx/WAF 控制。未开启橙云不享有 CF 边缘 WAF。</p></div><div class="jlwa-system-tip"><strong>故障恢复</strong><p>若验证码无法加载，在 WP 后台取消总开关保存即可；不影响已有 Nginx 防护。自定义路径和密码登录请先用匿名测试账户验收。</p></div></aside>
          </div>
        </div>
        <?php
    }
}
JLWA_Login_Security_Feature::instance();
