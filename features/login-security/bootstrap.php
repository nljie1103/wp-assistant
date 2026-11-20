<?php
/** Cloudflare Turnstile protection for Zibll /oauth/clogin, without CF proxy. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class JLWA_Login_Security_Feature {
    const OPTION = 'jlwa_login_security_options';
    const SECRET = 'jlwa_login_security_secret';
    const COOKIE = 'jlwa_oauth_ticket';
    private static $instance;

    public static function instance() {
        if ( ! self::$instance ) { self::$instance = new self(); }
        return self::$instance;
    }
    private function __construct() {
        // The theme dispatches OAuth later. Block the request before it reaches the SDK.
        add_action( 'init', array( $this, 'guard_oauth_start' ), 0 );
        add_action( 'admin_post_jlwa_login_security_save', array( $this, 'save' ) );
    }
    public static function settings() {
        $saved = get_option( self::OPTION, array() );
        $saved = is_array( $saved ) ? $saved : array();
        return array(
            'enabled' => ! empty( $saved['enabled'] ) ? 1 : 0,
            'site_key' => isset( $saved['site_key'] ) && is_string( $saved['site_key'] ) ? $saved['site_key'] : '',
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
        update_option( self::OPTION, array( 'enabled' => $enabled, 'site_key' => $key ), false );
        wp_safe_redirect( add_query_arg( 'jlwa_login_notice', $notice, admin_url( 'admin.php?page=jlwa-login-security' ) ) );
        exit;
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
    /** Untrusted request data must never cause a PHP redirect to an external destination. */
    public static function valid_login_type( $type ) {
        return is_string( $type ) && (bool) preg_match( '/\A[a-z][a-z0-9_-]{0,39}\z/D', $type );
    }
    public function guard_oauth_start() {
        $options = self::settings();
        if ( ! $options['enabled'] ) { return; }
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
        if ( ! self::is_oauth_start_path( $uri ) ) { return; }
        if ( strlen( $uri ) > 4096 ) { status_header( 414 ); exit; }
        $method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
        if ( ! in_array( $method, array( 'GET', 'POST' ), true ) ) { status_header( 405 ); header( 'Allow: GET, POST' ); exit; }
        if ( empty( $_GET['type'] ) || ! self::valid_login_type( $_GET['type'] ) || ( isset( $_GET['rurl'] ) && self::unsafe_return( $_GET['rurl'] ) ) ) {
            status_header( 403 ); exit;
        }
        if ( ! self::configured() || ! is_ssl() ) { status_header( 503 ); exit; }
        if ( 'POST' === $method ) { $this->process_challenge( $uri ); return; }
        if ( $this->consume_ticket( $uri ) ) { return; }
        $this->challenge_page( $uri, $options['site_key'] );
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
    public static function verified_response( $response, $hostname ) {
        return is_array( $response ) && isset( $response['success'] ) && true === $response['success']
            && isset( $response['hostname'], $response['action'] )
            && is_string( $response['hostname'] ) && is_string( $response['action'] )
            && hash_equals( strtolower( $hostname ), strtolower( $response['hostname'] ) )
            && hash_equals( 'jlwa_oauth_login', $response['action'] );
    }
    private function process_challenge( $uri ) {
        $token = isset( $_POST['cf-turnstile-response'] ) && is_string( $_POST['cf-turnstile-response'] ) ? trim( wp_unslash( $_POST['cf-turnstile-response'] ) ) : '';
        if ( '' === $token || strlen( $token ) > 2048 ) { status_header( 403 ); exit; }
        $reply = wp_remote_post( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', array(
            'timeout' => 7, 'redirection' => 0,
            'body' => array( 'secret' => self::secret(), 'response' => $token ),
        ) );
        $result = is_wp_error( $reply ) ? array() : json_decode( wp_remote_retrieve_body( $reply ), true );
        $host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
        if ( ! self::verified_response( $result, $host ) ) { status_header( 403 ); exit; }
        $ticket = bin2hex( random_bytes( 32 ) );
        set_transient( self::ticket_key( $ticket ), hash( 'sha256', $uri ), 90 );
        setcookie( self::COOKIE, $ticket, array( 'expires' => time() + 90, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax' ) );
        nocache_headers();
        wp_safe_redirect( home_url( $uri ), 303 );
        exit;
    }
    private function challenge_page( $uri, $site_key ) {
        status_header( 200 );
        nocache_headers();
        header( 'X-Robots-Tag: noindex, nofollow', true );
        header( 'Content-Type: text/html; charset=utf-8' );
        $site_name = get_bloginfo( 'name' );
        $safe_action = esc_url( home_url( $uri ) );
        echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>登录安全验证 - ' . esc_html( $site_name ) . '</title>';
        echo '<meta name="referrer" content="same-origin"><style>body{margin:0;background:#f5f6f9;font:16px system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#1c2637;display:grid;place-items:center;min-height:100vh}.jlwa-verify{box-sizing:border-box;width:min(92vw,440px);padding:36px;background:white;border:1px solid #e8eaf0;border-radius:16px;box-shadow:0 16px 50px #233b5610}h1{font-size:22px;margin:0 0 12px}p{color:#607086;line-height:1.7;margin:0 0 22px}button{border:0;border-radius:8px;padding:12px 20px;background:#205fe6;color:white;font-size:15px;cursor:pointer;margin-top:22px}small{display:block;margin-top:20px;color:#8691a1}</style>';
        echo '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script></head><body><main class="jlwa-verify"><h1>请完成登录安全验证</h1><p>为了保护账号安全，继续第三方登录前需要进行一次人机验证。</p><form method="post" action="' . $safe_action . '"><div class="cf-turnstile" data-sitekey="' . esc_attr( $site_key ) . '" data-action="jlwa_oauth_login"></div><button type="submit">验证并继续登录</button></form><small>验证由 Cloudflare Turnstile 提供，不经过 Cloudflare CDN 代理。</small></main></body></html>';
        exit;
    }
    public function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $options = self::settings();
        $configured = self::configured();
        $constant = defined( 'JLWA_TURNSTILE_SECRET_KEY' );
        $keys_guide_url = 'https://github.com/nljie1103/wp-assistant/blob/main/docs/turnstile-keys-guide.md';
        $notice = isset( $_GET['jlwa_login_notice'] ) ? sanitize_key( wp_unslash( $_GET['jlwa_login_notice'] ) ) : '';
        ?>
        <div class="wrap jlwa-login-security">
          <div class="jlwa-hero jlwa-hero--overview"><div class="jlwa-hero__copy"><span class="jlwa-kicker">OAUTH SECURITY</span><h2>彩虹聚合登录 · Turnstile</h2><p>保留圣何塞源站直连和子比原有授权回调。只保护 <code>/oauth/clogin</code> 发起阶段，不修改子比主题，不要求 Cloudflare 橙云。</p></div></div>
          <?php if ( 'saved' === $notice ) : ?><div class="notice notice-success inline"><p>配置已保存。</p></div><?php elseif ( 'incomplete' === $notice ) : ?><div class="notice notice-warning inline"><p>配置已保存，但缺少有效 Site Key 或 Secret Key，防护保持关闭。</p></div><?php endif; ?>
          <div class="jlwa-system-grid">
            <section class="jlwa-update-panel">
              <div class="jlwa-update-panel__head"><div><span class="jlwa-kicker">LOGIN PROTECTION</span><h2>入口验证设置</h2><p>Turnstile 由浏览器加载；WordPress 服务器必须通过 Siteverify 执行强制校验。开启前请先用中国大陆网络测试验证码可用性。<a href="<?php echo esc_url( $keys_guide_url ); ?>" target="_blank" rel="noopener noreferrer">查看 Site Key / Secret Key 获取教程</a></p></div></div>
              <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="jlwa_login_security_save" />
                <?php wp_nonce_field( 'jlwa_login_security_save' ); ?>
                <table class="form-table" role="presentation"><tbody>
                  <tr><th scope="row">保护状态</th><td><label><input type="checkbox" name="jlwa_login_security[enabled]" value="1" <?php checked( $options['enabled'], 1 ); ?> /> 开启彩虹 OAuth 登录前的强制人机验证</label><p class="description">默认关闭；缺少密钥时不能开启。已有 Nginx 异常 rurl 防护不受此开关影响。</p></td></tr>
                  <tr><th scope="row"><label for="jlwa-ts-site-key">Site Key</label></th><td><input id="jlwa-ts-site-key" class="regular-text" autocomplete="off" name="jlwa_login_security[site_key]" type="text" value="<?php echo esc_attr( $options['site_key'] ); ?>" /><p class="description">Cloudflare Turnstile Widget 的公开站点密钥，允许主机名需包含本博客主域名。<a href="<?php echo esc_url( $keys_guide_url ); ?>" target="_blank" rel="noopener noreferrer">如何获取？</a></p></td></tr>
                  <tr><th scope="row"><label for="jlwa-ts-secret-key">Secret Key</label></th><td><input id="jlwa-ts-secret-key" class="regular-text" autocomplete="new-password" name="jlwa_login_security[secret_key]" type="password" value="" placeholder="<?php echo $configured ? '已配置（留空不修改）' : '输入 Secret Key'; ?>" <?php disabled( $constant, true ); ?> /><p class="description"><?php echo $constant ? 'Secret Key 已通过 wp-config.php 常量 JLWA_TURNSTILE_SECRET_KEY 提供。' : '已保存的密钥不会回显；新密钥仅在填写时更新。也可在 wp-config.php 中使用 JLWA_TURNSTILE_SECRET_KEY 常量。'; ?> <a href="<?php echo esc_url( $keys_guide_url ); ?>" target="_blank" rel="noopener noreferrer">密钥获取与安全说明</a></p></td></tr>
                </tbody></table>
                <?php submit_button( '保存登录安全设置' ); ?>
              </form>
            </section>
            <aside class="jlwa-system-side"><h2>接入检查</h2><div class="jlwa-system-tip"><strong>配置状态</strong><p><?php echo esc_html( $configured ? 'Turnstile 密钥已配置' : '尚未完成密钥配置' ); ?></p></div><div class="jlwa-system-tip"><strong>生效范围</strong><p>仅 OAuth 发起请求：<code>/oauth/clogin?type=...</code>；不会给 <code>/oauth/clogin/callback</code> 加验证。</p></div><div class="jlwa-system-tip"><strong>防护分层</strong><p>Nginx 先过滤恶意自引用 rurl，本模块只对有效 OAuth 发起请求做人机验证。不开橙云就不能使用 CF 边缘 WAF。</p></div><div class="jlwa-system-tip"><strong>故障恢复</strong><p>若验证码无法加载，从 WP 后台取消勾选后保存即可；不要为方便测试而直接关闭 Nginx 防护。</p></div></aside>
          </div>
        </div>
        <?php
    }
}
JLWA_Login_Security_Feature::instance();
