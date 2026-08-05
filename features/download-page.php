<?php
/** 九流WP助手：子比资源下载页完整模板管理。 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class JLWA_Download_Page_Feature {
    const VERSION = '1.1.0';
    const OPTION_KEY = 'jlwa_download_page_options';
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_init', array( $this, 'register_setting' ) );
        add_action( 'template_redirect', array( $this, 'maybe_render' ), 1 );
    }

    public static function defaults() {
        return array(
            'enabled' => 0,
            'template' => 'technology',
            'cover_mode' => 'auto',
            'default_image_url' => '',
        );
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
        $templates = array( 'technology', 'business', 'vip' );
        $modes = array( 'auto', 'featured', 'first', 'random', 'default' );
        return array(
            'enabled' => empty( $input['enabled'] ) ? 0 : 1,
            'template' => in_array( $input['template'] ?? '', $templates, true ) ? $input['template'] : 'technology',
            'cover_mode' => in_array( $input['cover_mode'] ?? '', $modes, true ) ? $input['cover_mode'] : 'auto',
            'default_image_url' => esc_url_raw( $input['default_image_url'] ?? '' ),
        );
    }

    public function enqueue_admin_assets( $hook = '' ) {
        wp_enqueue_media();
        wp_enqueue_style( 'jlwa-download-page-admin', JLWA_PLUGIN_URL . 'assets/css/download-page-admin.css', array(), JLWA_VERSION );
    }

    private function compatible() {
        foreach ( array( 'zibpay_is_paid', 'zibpay_get_post_down_buts', 'zibpay_get_post_down_array', 'zib_get_page_content_style' ) as $function ) {
            if ( ! function_exists( $function ) ) { return false; }
        }
        return true;
    }

    private function target_request() {
        if ( is_admin() || wp_doing_ajax() || empty( $_GET['post'] ) ) { return false; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $post_id = absint( wp_unslash( $_GET['post'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $post = get_post( $post_id );
        $meta = get_post_meta( $post_id, 'posts_zibpay', true );
        if ( ! $post || 'publish' !== $post->post_status || ! is_array( $meta ) || '2' !== (string) ( $meta['pay_type'] ?? '' ) ) { return false; }
        $page_id = get_queried_object_id();
        $slug = $page_id ? str_replace( '\\', '/', (string) get_page_template_slug( $page_id ) ) : '';
        return 'download.php' === basename( $slug ) || false !== strpos( $slug, 'pages/download.php' ) || (bool) apply_filters( 'jlwa_download_page_force_request', false, $post_id );
    }

    public function maybe_render() {
        $options = self::options();
        if ( empty( $options['enabled'] ) || ! $this->compatible() || ! $this->target_request() ) { return; }
        $templates = array(
            'technology' => JLWA_PLUGIN_DIR . 'features/download-page-templates/technology.php',
            'business' => JLWA_PLUGIN_DIR . 'features/download-page-templates/business.php',
            'vip' => JLWA_PLUGIN_DIR . 'features/download-page-templates/vip.php',
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
            <section class="jlwa-download-intro"><div><span>DOWNLOAD EXPERIENCE</span><h2>子比下载页美化</h2><p>加载原三套完整资源中心模板，不修改子比支付、权限与下载逻辑。</p></div><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enabled]" value="1" <?php checked( ! empty( $options['enabled'] ) ); ?>><b>启用功能</b></label></section>
            <div class="jlwa-download-section"><h3>选择完整页面模板</h3><div class="jlwa-template-grid"><?php foreach ( $templates as $key => $item ) : ?><label class="jlwa-template-card is-<?php echo esc_attr( $key ); ?>"><input type="radio" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[template]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $options['template'], $key ); ?>><span class="preview"></span><strong><?php echo esc_html( $item[0] ); ?></strong><small><?php echo esc_html( $item[1] ); ?></small></label><?php endforeach; ?></div></div>
            <div class="jlwa-download-columns"><section class="jlwa-download-section"><h3>左上角封面策略</h3><p>三套模板都会自动识别横图、方图和竖图，并切换对应容器比例。</p><?php foreach ( $modes as $key => $label ) : ?><label class="jlwa-mode-row"><input type="radio" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[cover_mode]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $options['cover_mode'], $key ); ?>><span><?php echo esc_html( $label ); ?></span></label><?php endforeach; ?></section><section class="jlwa-download-section"><h3>默认图片</h3><input class="regular-text" type="url" id="jlwa-default-cover" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[default_image_url]" value="<?php echo esc_attr( $options['default_image_url'] ); ?>" placeholder="留空使用插件内置默认图"><button type="button" class="button" id="jlwa-select-cover">从媒体库选择</button><p><?php echo $this->compatible() ? '已检测到子比支付、权限与下载接口。' : '未检测到完整子比接口，前台不会强行接管。'; ?></p></section></div>
            <?php submit_button( '保存下载页设置' ); ?>
        </form></div>
        <script>jQuery(function($){var frame;$('#jlwa-select-cover').on('click',function(e){e.preventDefault();if(frame){frame.open();return;}frame=wp.media({title:'选择默认封面',button:{text:'使用这张图片'},multiple:false,library:{type:'image'}});frame.on('select',function(){$('#jlwa-default-cover').val(frame.state().get('selection').first().toJSON().url);});frame.open();});});</script>
        <?php
    }
}
JLWA_Download_Page_Feature::instance();
