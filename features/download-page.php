<?php
/** 九流WP助手：子比资源下载页完整模板管理。 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class JLWA_Download_Page_Feature {
// Internal option/feature compatibility version, not JLWA public release.
    const VERSION = '1.1.0';
    const OPTION_KEY = 'jlwa_download_page_options';
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_init', array( $this, 'register_setting' ) );
        // Block unpurchased download-page requests before *any* template emits
        // preview images, post metadata or resource link markup.
        add_action( 'template_redirect', array( $this, 'guard_paid_download_page' ), 0 );
        add_action( 'template_redirect', array( $this, 'maybe_render' ), 1 );
        add_action( 'wp_footer', array( $this, 'render_purchase_required_notice' ), 20 );
    }

    public static function defaults() {
        return array(
            'enabled' => 0,
            'template' => 'technology',
            'cover_mode' => 'auto',
            'default_image_url' => '',
        ) + JLWA_Private_Download::defaults();
    }

    public static function options() {
        $value = get_option( self::OPTION_KEY, array() );
        return wp_parse_args( is_array( $value ) ? $value : array(), self::defaults() );
    }

    public static function activate() {
        update_option( self::OPTION_KEY, self::options(), false );
    }

    public function register_setting() {
        register_setting(
            'jlwa_download_page',
            self::OPTION_KEY,
            array(
                'default' => self::defaults(),
                'sanitize_callback' => array( $this, 'sanitize' ),
            )
        );
    }

    public function sanitize( $input ) {
        $input = is_array( $input ) ? $input : array();
        $templates = array( 'technology', 'business', 'vip', 'aurora', 'editorial', 'midnight' );
        $modes = array( 'auto', 'featured', 'first', 'random', 'default' );
        return array(
            'enabled' => empty( $input['enabled'] ) ? 0 : 1,
            'template' => in_array( $input['template'] ?? '', $templates, true ) ? $input['template'] : 'technology',
            'cover_mode' => in_array( $input['cover_mode'] ?? '', $modes, true ) ? $input['cover_mode'] : 'auto',
            'default_image_url' => esc_url_raw( $input['default_image_url'] ?? '' ),
        ) + JLWA_Private_Download::sanitized( $input );
    }

    public function enqueue_admin_assets( $hook = '' ) {
        wp_enqueue_media();
        wp_enqueue_style( 'jlwa-download-page-admin', JLWA_PLUGIN_URL . 'assets/css/download-page-admin.css', array(), JLWA_VERSION );
    }

    /** Read-only compatibility check used by the dashboard and download runtime. */
    public static function is_compatible() {
        foreach ( array( 'zibpay_is_paid', 'zibpay_get_post_down_buts', 'zibpay_get_post_down_array', 'zib_get_page_content_style' ) as $function ) {
            if ( ! function_exists( $function ) ) { return false; }
        }
        return true;
    }

    private function compatible() { return self::is_compatible(); }

    /**
     * Detect a real published Zibll paid-download page, not just a random
     * ?post= parameter on any public article.
     */
    private function target_post_id() {
        if ( is_admin() || wp_doing_ajax() || ! isset( $_GET['post'] ) || ! is_scalar( $_GET['post'] ) ) {
            return 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }
        $raw_id = (string) wp_unslash( $_GET['post'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! preg_match( '/^[1-9][0-9]*$/D', $raw_id ) ) { return 0; }
        $post_id = absint( $raw_id );
        if ( ! $post_id ) { return 0; }
        $post = get_post( $post_id );
        $meta = get_post_meta( $post_id, 'posts_zibpay', true );
        if ( ! $post || 'publish' !== $post->post_status || ! is_array( $meta ) || '2' !== (string) ( $meta['pay_type'] ?? '' ) ) {
            return 0;
        }
        $page_id = get_queried_object_id();
        $slug = $page_id ? str_replace( '\\', '/', (string) get_page_template_slug( $page_id ) ) : '';
        $is_download = 'download.php' === basename( $slug ) ||
            ( function_exists( 'is_page' ) && is_page( 'download' ) ) ||
            (bool) apply_filters( 'jlwa_download_page_force_request', false, $post_id );
        return $is_download ? $post_id : 0;
    }

    private function target_request() { return $this->target_post_id() > 0; }

    /** Consult the same source of truth as the Zibll paid download template. */
    public static function has_purchase_rights( $post_id ) {
        return function_exists( 'zibpay_is_paid' ) && ! empty( zibpay_is_paid( $post_id ) );
    }

    public static function purchase_redirect_url( $post_id ) {
        $permalink = get_permalink( $post_id );
        if ( ! $permalink ) { return ''; }
        return add_query_arg( 'jlwa_purchase_required', (string) absint( $post_id ), $permalink ) . '#posts-pay';
    }

    /** True only on the product article which received our notice query flag. */
    private function is_purchase_notice_request() {
        if ( is_admin() || ! isset( $_GET['jlwa_purchase_required'] ) || ! is_scalar( $_GET['jlwa_purchase_required'] ) ) { return false; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $value = (string) wp_unslash( $_GET['jlwa_purchase_required'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return preg_match( '/^[1-9][0-9]*$/D', $value ) && is_singular() && absint( $value ) === (int) get_queried_object_id();
    }

    /** Run even when the visual download-page skin is disabled. */
    public function guard_paid_download_page() {
        $post_id = $this->target_post_id();
        if ( ! $post_id ) {
            // A purchase warning is per-visitor and must not be shared by page caches.
            if ( $this->is_purchase_notice_request() ) { nocache_headers(); }
            return;
        }
        if ( self::has_purchase_rights( $post_id ) ) { return; }
        $destination = self::purchase_redirect_url( $post_id );
        nocache_headers();
        if ( ! $destination ) {
            wp_die( '尚未购买商品，请先购买后再访问下载页。', '需要购买权限', array( 'response' => 403 ) );
            exit;
        }
        wp_safe_redirect( $destination, 302, 'Jiuliu WP Assistant' );
        exit;
    }

    /** A server-rendered notice on the original post, not on the secret page. */
    public function render_purchase_required_notice() {
        if ( ! $this->is_purchase_notice_request() ) { return; }
        ?>
        <div id="jlwa-purchase-required" role="alert" aria-live="assertive" style="position:fixed;z-index:2147483000;top:24px;left:50%;transform:translateX(-50%);box-sizing:border-box;width:calc(100% - 32px);max-width:520px;padding:16px 44px 16px 20px;background:#fff7ed;color:#7c2d12;border:1px solid #fdba74;border-radius:12px;box-shadow:0 12px 35px rgba(0,0,0,.17);font-size:15px;line-height:1.65;text-align:left">
            <strong>尚未购买商品</strong>：请先购买商品，再来访问下载页。
            <button type="button" aria-label="关闭提示" onclick="this.parentNode.remove()" style="position:absolute;right:10px;top:8px;border:0;background:transparent;color:inherit;font-size:22px;cursor:pointer">×</button>
        </div>
        <?php
    }

    public function maybe_render() {
        $options = self::options();
        if ( empty( $options['enabled'] ) || ! $this->compatible() || ! $this->target_request() ) { return; }
        $templates = array(
            'technology' => JLWA_PLUGIN_DIR . 'features/download-page-templates/technology.php',
            'business' => JLWA_PLUGIN_DIR . 'features/download-page-templates/business.php',
            'vip' => JLWA_PLUGIN_DIR . 'features/download-page-templates/vip.php',
            // Three new CSS skins reuse the audited core renderer; no cloned payment logic.
            'aurora' => JLWA_PLUGIN_DIR . 'features/download-page-templates/technology.php',
            'editorial' => JLWA_PLUGIN_DIR . 'features/download-page-templates/technology.php',
            'midnight' => JLWA_PLUGIN_DIR . 'features/download-page-templates/technology.php',
        );
        $selected = isset( $templates[ $options['template'] ] ) ? $templates[ $options['template'] ] : $templates['technology'];
        if ( ! is_readable( $selected ) ) { return; }
        $jlwa_download_page_options = $options;
        include $selected;
        exit;
    }

    public function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $options = self::options();
        $templates = array(
            'technology' => array( '科技蓝紫版', '完整资源中心布局，蓝紫科技视觉。' ),
            'business' => array( '简洁商务版', '完整信息布局，浅色商务与深色适配。' ),
            'vip' => array( 'VIP 资源中心版', '完整会员资源布局，象牙金与深海金视觉。' ),
            'aurora' => array( '流光极光版', '深蓝青绿光幕，适合科技及创意资源。' ),
            'editorial' => array( '杂志留白版', '暖白纸张与深墨标题，适合文字与素材资源。' ),
            'midnight' => array( '曜石夜幕版', '石墨黑与冷金细节，适合高端付费资源。' ),
        );
        $modes = array(
            'auto' => '自动模式：特色图 → 正文首图 → 稳定随机图 → 默认图',
            'featured' => '固定特色图片；没有特色图时使用默认图',
            'first' => '固定正文首图；正文无图时使用默认图',
            'random' => '固定稳定随机图；无可用图时使用默认图',
            'default' => '始终使用默认图片',
        );
        ?>
        <div class="jlwa-download-admin"><form action="options.php" method="post"><?php settings_fields( 'jlwa_download_page' ); ?>
            <section class="jlwa-download-intro"><div><span>DOWNLOAD EXPERIENCE</span><h2>子比下载页美化</h2><p>六种视觉方案共用已有下载、支付与权限逻辑，新增主题不复制支付代码。</p></div><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enabled]" value="1" <?php checked( ! empty( $options['enabled'] ) ); ?>><b>启用功能</b></label></section>
            <div class="jlwa-download-section"><h3>选择完整页面模板</h3><div class="jlwa-template-grid"><?php foreach ( $templates as $key => $item ) : ?><label class="jlwa-template-card is-<?php echo esc_attr( $key ); ?>"><input type="radio" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[template]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $options['template'], $key ); ?>><span class="preview"></span><strong><?php echo esc_html( $item[0] ); ?></strong><small><?php echo esc_html( $item[1] ); ?></small></label><?php endforeach; ?></div></div>
            <div class="jlwa-download-columns"><section class="jlwa-download-section"><h3>左上角封面策略</h3><p>六种视觉方案都自动识别横图、方图和竖图，并适配封面比例。</p><?php foreach ( $modes as $key => $label ) : ?><label class="jlwa-mode-row"><input type="radio" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[cover_mode]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $options['cover_mode'], $key ); ?>><span><?php echo esc_html( $label ); ?></span></label><?php endforeach; ?></section><section class="jlwa-download-section"><h3>默认图片</h3><input class="regular-text" type="url" id="jlwa-default-cover" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[default_image_url]" value="<?php echo esc_attr( $options['default_image_url'] ); ?>" placeholder="留空使用插件内置默认图"><button type="button" class="button" id="jlwa-select-cover">从媒体库选择</button><p><?php echo $this->compatible() ? '已检测到子比支付、权限与下载接口。' : '未检测到完整子比接口，前台不会强行接管。'; ?></p></section></div>
            <section class="jlwa-download-section" id="jlwa-private-delivery">
                <h3>🔒 付费资源 · 私有安全交付</h3>
                <p><strong>独立于下载页美化开关。</strong>子比确认订单权限后由 Nginx 内部转发 OpenList 文件，不向浏览器提供原始链接。默认关闭，升级时不会自动改变下载行为。</p>
                <p><strong>注意：</strong>开启之前必须先配置 WordPress 站点的 Nginx <code>internal</code> 入口并测试；启用之后，还必须在 OpenList 公网入口拒绝相应付费目录的直链，才能真正阻止绕过付款。<strong>仅打开这里的开关不能保护公开文件。</strong></p>
                <label class="jlwa-mode-row"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[private_enabled]" value="1" <?php checked( ! empty( $options['private_enabled'] ) ); ?>><span><strong>启用私有交付拦截</strong>（未匹配的普通下载仍使用子比原有流程；已匹配资源遇到配置错误会拒绝并提示，不回退到公开 302）</span></label>
                <p><label for="jlwa-private-hosts"><strong>受保护源站域名（每行一个，不带协议）</strong></label></p>
                <textarea id="jlwa-private-hosts" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[private_hosts]" rows="2" class="large-text code"><?php echo esc_textarea( $options['private_hosts'] ); ?></textarea>
                <p><label for="jlwa-private-source"><strong>公开源路径前缀</strong>（仅保护此目录，必须以斜杠结尾）</label></p>
                <input id="jlwa-private-source" class="large-text code" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[private_source_prefix]" value="<?php echo esc_attr( $options['private_source_prefix'] ); ?>">
                <p><label for="jlwa-private-internal"><strong>WordPress Nginx 私有内部入口</strong>（须和 Nginx 配置完全一致）</label></p>
                <input id="jlwa-private-internal" class="large-text code" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[private_internal_prefix]" value="<?php echo esc_attr( $options['private_internal_prefix'] ); ?>">
                <p><a class="button button-secondary" href="<?php echo esc_url( 'https://github.com/nljie1103/wp-assistant/blob/main/docs/paid-download-security.md' ); ?>" target="_blank" rel="noopener noreferrer">📘 查看 WordPress Nginx + OpenList 详细配置教程</a></p>
                <p>教程包括宝塔配置文件位置、完整 Nginx 代码、每一步测试命令和故障回滚方法。升级不修改子比订单、WebDAV 密码或文章下载地址。</p>
            </section>
            <?php submit_button( '保存下载页设置' ); ?>
        </form></div>
        <script>jQuery(function($){var frame;$('#jlwa-select-cover').on('click',function(e){e.preventDefault();if(frame){frame.open();return;}frame=wp.media({title:'选择默认封面',button:{text:'使用这张图片'},multiple:false,library:{type:'image'}});frame.on('select',function(){$('#jlwa-default-cover').val(frame.state().get('selection').first().toJSON().url);});frame.open();});});</script>
        <?php
    }
}
JLWA_Download_Page_Feature::instance();
