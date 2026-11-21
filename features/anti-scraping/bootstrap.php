<?php
/**
 * Jiuliu WP Assistant: strict public content export controls.
 *
 * Intentionally no is_user_logged_in() / administrator exception. Opt-in because
 * WordPress' own block editor uses the REST API and may cease to work.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class JLWA_Anti_Scraping_Feature {
    const OPTION = 'jlwa_anti_scraping_options';
    private static $instance;

    public static function instance() {
        if ( null === self::$instance ) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        add_filter( 'rest_pre_dispatch', array( $this, 'guard_rest' ), -100, 3 );
        add_action( 'template_redirect', array( $this, 'guard_frontend' ), 0 );
        foreach ( array( 'do_feed', 'do_feed_rdf', 'do_feed_rss', 'do_feed_rss2', 'do_feed_atom' ) as $hook ) {
            add_action( $hook, array( $this, 'guard_feed' ), 0 );
        }
        add_action( 'init', array( $this, 'guard_xmlrpc' ), -100 );
        add_filter( 'xmlrpc_enabled', array( $this, 'disable_xmlrpc' ), 0 );
        add_filter( 'xmlrpc_methods', array( $this, 'filter_xmlrpc_methods' ), 0 );
        add_action( 'admin_post_jlwa_anti_scraping_save', array( $this, 'save' ) );
    }

    public static function defaults() {
        return array( 'enabled' => 0, 'rest_content' => 1, 'feeds' => 1, 'oembed' => 1, 'xmlrpc' => 1 );
    }

    public static function settings() {
        $saved = get_option( self::OPTION, array() );
        $saved = is_array( $saved ) ? $saved : array();
        $settings = self::defaults();
        foreach ( $settings as $key => $default ) {
            if ( array_key_exists( $key, $saved ) ) {
                $settings[ $key ] = ! empty( $saved[ $key ] ) ? 1 : 0;
            }
        }
        return $settings;
    }

    public static function enabled() {
        $settings = self::settings();
        return ! empty( $settings['enabled'] );
    }

    public static function activate() {
        if ( false === get_option( self::OPTION, false ) ) {
            add_option( self::OPTION, self::defaults(), '', false );
        }
    }

    /** WordPress resolves both /wp-json and ?rest_route= into the same REST route. */
    public static function blocks_rest_route( $route, $method, $settings ) {
        if ( empty( $settings['enabled'] ) || ! is_string( $route ) || ! is_string( $method ) ) { return false; }
        if ( ! in_array( strtoupper( $method ), array( 'GET', 'HEAD' ), true ) ) { return false; }
        $path = '/' . ltrim( rtrim( $route, '/' ), '/' );
        if ( ! empty( $settings['rest_content'] ) && preg_match( '~^/wp/v2(?:/|$)~i', $path ) ) { return true; }
        if ( ! empty( $settings['oembed'] ) && preg_match( '~^/oembed/1\.0/embed(?:/|$)~i', $path ) ) { return true; }
        return false;
    }

    public function guard_rest( $result, $server, $request ) {
        if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) || ! method_exists( $request, 'get_method' ) ) { return $result; }
        if ( ! self::blocks_rest_route( $request->get_route(), $request->get_method(), self::settings() ) ) { return $result; }
        return new WP_Error( 'jlwa_anti_scraping_blocked', '内容导出接口已关闭。', array( 'status' => 403 ) );
    }

    public static function blocks_feed( $settings ) {
        return ! empty( $settings['enabled'] ) && ! empty( $settings['feeds'] );
    }

    private static function deny() {
        if ( function_exists( 'nocache_headers' ) ) { nocache_headers(); }
        wp_die( '该内容导出入口已关闭。', 'Forbidden', array( 'response' => 403 ) );
    }

    public function guard_feed() {
        if ( self::blocks_feed( self::settings() ) ) { self::deny(); }
    }

    public function guard_frontend() {
        $settings = self::settings();
        if ( empty( $settings['enabled'] ) ) { return; }
        if ( ! empty( $settings['feeds'] ) && is_feed() ) { self::deny(); }
        if ( ! empty( $settings['oembed'] ) && function_exists( 'is_embed' ) && is_embed() ) { self::deny(); }
    }

    public static function blocks_xmlrpc_request( $settings, $is_xmlrpc ) {
        return ! empty( $settings['enabled'] ) && ! empty( $settings['xmlrpc'] ) && (bool) $is_xmlrpc;
    }

    public function guard_xmlrpc() {
        // At init, the XML-RPC server object does not exist yet. wp_die() uses
        // WordPress' XML-RPC handler, which can return 200 + an empty body here.
        // Return a proper HTTP denial before any XML-RPC method is dispatched.
        if ( ! self::blocks_xmlrpc_request( self::settings(), defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) { return; }
        if ( function_exists( 'nocache_headers' ) ) { nocache_headers(); }
        status_header( 403 );
        header( 'Content-Type: text/plain; charset=UTF-8' );
        exit;
    }

    public function disable_xmlrpc( $enabled ) {
        $settings = self::settings();
        return ! empty( $settings['enabled'] ) && ! empty( $settings['xmlrpc'] ) ? false : $enabled;
    }

    public function filter_xmlrpc_methods( $methods ) {
        $settings = self::settings();
        return ! empty( $settings['enabled'] ) && ! empty( $settings['xmlrpc'] ) ? array() : $methods;
    }

    public function save() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Permission denied.', 'Forbidden', array( 'response' => 403 ) ); }
        check_admin_referer( 'jlwa_anti_scraping_save' );
        $raw = isset( $_POST['jlwa_anti_scraping'] ) && is_array( $_POST['jlwa_anti_scraping'] ) ? wp_unslash( $_POST['jlwa_anti_scraping'] ) : array();
        $next = array();
        foreach ( self::defaults() as $key => $default ) {
            $next[ $key ] = ! empty( $raw[ $key ] ) ? 1 : 0;
        }
        $notice = 'saved';
        // Never enable an admin-breaking REST restriction by accidental form save.
        if ( $next['enabled'] && $next['rest_content'] && empty( $raw['confirm_editor_impact'] ) ) {
            $next['enabled'] = 0;
            $notice = 'confirm-required';
        }
        update_option( self::OPTION, $next, false );
        wp_safe_redirect( add_query_arg( 'jlwa_anti_scraping_notice', $notice, admin_url( 'admin.php?page=jlwa-anti-scraping' ) ) );
        exit;
    }

    public function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $settings = self::settings();
        $notice = isset( $_GET['jlwa_anti_scraping_notice'] ) ? sanitize_key( wp_unslash( $_GET['jlwa_anti_scraping_notice'] ) ) : '';
        $fields = array(
            'rest_content' => array( '封锁 WordPress 内容 REST 读取', 'GET/HEAD /wp/v2/*（包括 ?rest_route= 同一路由）；登录用户和管理员同样返回 403。会影响区块编辑器、媒体库及部分前台功能。' ),
            'feeds' => array( '关闭 RSS / Atom / RDF 订阅输出', '包括文章、评论和分类 Feed；浏览器订阅器会不可用。' ),
            'oembed' => array( '关闭 oEmbed 导出', '拒绝 oEmbed REST 端点和页面嵌入展示，可能影响外部网站嵌入。' ),
            'xmlrpc' => array( '关闭 XML-RPC', '关闭 XML-RPC 方法及认证（已有 Nginx 封锁时作为应用层兜底）。' ),
        );
        ?>
        <div class="jlwa-anti-scraping-settings" style="max-width:880px">
          <?php if ( 'saved' === $notice ) : ?><div class="notice notice-success inline"><p>反采集设置已保存。</p></div><?php endif; ?>
          <?php if ( 'confirm-required' === $notice ) : ?><div class="notice notice-error inline"><p>未确认 REST 严格封锁对后台编辑的影响，保护总开关保持关闭。</p></div><?php endif; ?>
          <div class="notice notice-warning inline"><p><strong>保护范围说明：</strong>此模块用于关闭可批量读取的标准输出接口，并不能阻止已公开文章的 HTML 抓取或屏幕复制。建议继续使用 Cloudflare 机器人防护和速率限制。</p></div>
          <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="jlwa_anti_scraping_save">
            <?php wp_nonce_field( 'jlwa_anti_scraping_save' ); ?>
            <table class="form-table" role="presentation"><tbody>
              <tr><th scope="row">主开关</th><td><label><input type="checkbox" name="jlwa_anti_scraping[enabled]" value="1" <?php checked( $settings['enabled'], 1 ); ?>> <strong>启用严格反采集保护</strong></label><p class="description">默认关闭；启用后下列已勾选规则立即作用于所有访客，无登录豁免。</p></td></tr>
              <?php foreach ( $fields as $key => $field ) : ?>
              <tr><th scope="row"><?php echo esc_html( $field[0] ); ?></th><td><label><input type="checkbox" name="jlwa_anti_scraping[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $settings[ $key ], 1 ); ?>> 启用此项</label><p class="description"><?php echo esc_html( $field[1] ); ?></p></td></tr>
              <?php endforeach; ?>
              <tr><th scope="row">严格模式确认</th><td><label><input type="checkbox" name="jlwa_anti_scraping[confirm_editor_impact]" value="1"> 我确认禁止管理员 REST 读取可能导致区块编辑器、媒体选择器等功能失效，已准备回滚。</label><p class="description">仅在开启主开关且勾选 REST 封锁时必须确认。遇到故障可在此页面关闭主开关，无需删除插件。</p></td></tr>
            </tbody></table>
            <?php submit_button( '保存反采集设置' ); ?>
          </form>
        </div>
        <?php
    }
}

JLWA_Anti_Scraping_Feature::instance();
