<?php
/** 九流WP助手：子比原生下载页渐进式视觉增强与安全交付配置。 */
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
        // WordPress loads the classic template at the normal template_include
        // stage, not via an early template_redirect include/exit. Native mode
        // never replaces Zibll markup or native checkout handlers.
        add_filter( 'template_include', array( $this, 'choose_download_template' ), 99 );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_native_assets' ), 20 );
        add_filter( 'body_class', array( $this, 'native_body_class' ) );
        add_action( 'wp_footer', array( $this, 'render_native_support' ), 15 );
        add_action( 'wp_footer', array( $this, 'render_purchase_required_notice' ), 20 );
    }

    public static function defaults() {
        return array(
            'enabled' => 0,
            'template' => 'technology',
            // Existing sites retain the v3.0.6 full download-page design.
            // Select "native" explicitly for minimal Zibll interaction.
            'render_mode' => 'classic',
            'cover_mode' => 'auto',
            'default_image_url' => '',
            // Public plugin: never ship one site's Telegram/customer-service links.
            'support_title' => '售前咨询与售后服务',
            'support_description' => '会员、订单、支付或资源下载遇到问题？请通过以下方式联系我们。',
            'support_primary_url' => '',
            'support_primary_label' => '联系客服',
            'support_secondary_url' => '',
            'support_secondary_label' => '加入交流群',
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
        // Separate admin tabs must never reset settings belonging to another tab.
        // Legacy full-form submissions remain supported for existing integrations.
        $scope = isset( $input['jlwa_settings_scope'] ) ? (string) $input['jlwa_settings_scope'] : '';
        if ( in_array( $scope, array( 'templates', 'security', 'advanced' ), true ) ) {
            $previous = self::options();
            $template_keys = array( 'enabled', 'template', 'render_mode', 'cover_mode', 'default_image_url', 'support_title', 'support_description', 'support_primary_url', 'support_primary_label', 'support_secondary_url', 'support_secondary_label' );
            $security_keys = array( 'private_enabled', 'private_hosts', 'private_source_prefix', 'private_internal_prefix' );
            $advanced_keys = array( 'openlist_host_port', 'openlist_container', 'openlist_public_images', 'delivery_host', 'delivery_enabled', 'ticket_lifetime', 'audit_enabled', 'rate_enabled', 'rate_limit' );
            $chosen = 'templates' === $scope ? $template_keys : ( 'security' === $scope ? $security_keys : $advanced_keys );
            $submitted = $input;
            $input = $previous;
            foreach ( $chosen as $key ) {
                if ( array_key_exists( $key, $submitted ) ) {
                    $input[ $key ] = $submitted[ $key ];
                }
            }
            $checkboxes = 'templates' === $scope ? array( 'enabled' ) : ( 'security' === $scope ? array( 'private_enabled' ) : array( 'delivery_enabled', 'audit_enabled', 'rate_enabled' ) );
            foreach ( $checkboxes as $checkbox ) {
                $input[ $checkbox ] = ! empty( $submitted[ $checkbox ] ) ? 1 : 0;
            }
        }
        // Visual-only save must not revalidate, normalize, or overwrite an
        // independently managed security configuration (even if it predates
        // modern validation rules).
        if ( 'templates' === $scope ) {
            $templates = array( 'technology', 'business', 'vip', 'aurora', 'editorial', 'midnight' );
            $modes = array( 'auto', 'featured', 'first', 'random', 'default' );
            return array_replace(
                $previous,
                array(
                    'enabled' => empty( $input['enabled'] ) ? 0 : 1,
                    'template' => in_array( $input['template'] ?? '', $templates, true ) ? $input['template'] : 'technology',
                    'render_mode' => 'native' === ( $input['render_mode'] ?? '' ) ? 'native' : 'classic',
                    'cover_mode' => in_array( $input['cover_mode'] ?? '', $modes, true ) ? $input['cover_mode'] : 'auto',
                    'default_image_url' => esc_url_raw( $input['default_image_url'] ?? '' ),
                ),
                self::sanitize_support_fields( $input )
            );
        }
        $errors = JLWA_Private_Download::validation_errors( $input );
        if ( $errors ) {
            foreach ( $errors as $index => $message ) {
                add_settings_error( self::OPTION_KEY, 'jlwa_download_validation_' . $index, $message, 'error' );
            }
            // Reject this entire submission atomically. Keep the last working config.
            // Returning defaults here could silently overwrite a live source path.
            return get_option( self::OPTION_KEY, self::defaults() );
        }
        $templates = array( 'technology', 'business', 'vip', 'aurora', 'editorial', 'midnight' );
        $modes = array( 'auto', 'featured', 'first', 'random', 'default' );
        return array(
            'enabled' => empty( $input['enabled'] ) ? 0 : 1,
            'template' => in_array( $input['template'] ?? '', $templates, true ) ? $input['template'] : 'technology',
            'render_mode' => 'native' === ( $input['render_mode'] ?? '' ) ? 'native' : 'classic',
            'cover_mode' => in_array( $input['cover_mode'] ?? '', $modes, true ) ? $input['cover_mode'] : 'auto',
            'default_image_url' => esc_url_raw( $input['default_image_url'] ?? '' ),
        ) + self::sanitize_support_fields( $input ) + JLWA_Private_Download::sanitized( $input );
    }

    /** Allow only explicit external links / contact protocols (no javascript:, data: or relative URLs). */
    private static function support_url( $value ) {
        if ( ! is_string( $value ) ) {
            return '';
        }
        $value = trim( $value );
        if ( '' === $value || ! preg_match( '~^(?:https?://|mailto:|tel:)~i', $value ) ) {
            return '';
        }
        return esc_url_raw( $value, array( 'http', 'https', 'mailto', 'tel' ) );
    }

    /** Scope-aware option sanitization, preserving links across legacy and security-only saves. */
    private static function sanitize_support_fields( $input ) {
        $old = self::options();
        $defaults = self::defaults();
        $fields = array(
            'support_title', 'support_description',
            'support_primary_url', 'support_primary_label',
            'support_secondary_url', 'support_secondary_label',
        );
        $clean = array();
        foreach ( $fields as $key ) {
            $value = array_key_exists( $key, $input ) ? $input[ $key ] : ( $old[ $key ] ?? $defaults[ $key ] );
            if ( false !== strpos( $key, '_url' ) ) {
                $clean[ $key ] = self::support_url( $value );
            } else {
                $clean[ $key ] = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : $defaults[ $key ];
            }
        }
        return $clean;
    }

    /** Shared across all six skins. Empty contact links never expose the developer's account. */
    public static function render_support_panel( $options = array() ) {
        $options = wp_parse_args( is_array( $options ) ? $options : array(), self::defaults() );
        $primary = self::support_url( $options['support_primary_url'] );
        $secondary = self::support_url( $options['support_secondary_url'] );
        if ( '' === $primary && '' === $secondary ) {
            return;
        }
        $title = trim( (string) $options['support_title'] );
        $description = trim( (string) $options['support_description'] );
        $primary_label = trim( (string) $options['support_primary_label'] );
        $secondary_label = trim( (string) $options['support_secondary_label'] );
        ?>
        <section class="jld-panel jld-help-panel jld-help-panel-v4">
            <div class="jld-help-icon"><i class="fa fa-comments" aria-hidden="true"></i></div>
            <div>
                <span class="jld-panel-kicker">SUPPORT</span>
                <h3><?php echo esc_html( $title ?: '售前咨询与售后服务' ); ?></h3>
                <?php if ( '' !== $description ) : ?><p><?php echo esc_html( $description ); ?></p><?php endif; ?>
            </div>
            <div class="jld-help-actions">
                <?php if ( '' !== $primary ) : ?>
                    <a target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( $primary ); ?>"><i class="fa fa-paper-plane" aria-hidden="true"></i><?php echo esc_html( $primary_label ?: '联系客服' ); ?></a>
                <?php endif; ?>
                <?php if ( '' !== $secondary ) : ?>
                    <a target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( $secondary ); ?>"><i class="fa fa-users" aria-hidden="true"></i><?php echo esc_html( $secondary_label ?: '加入交流群' ); ?></a>
                <?php endif; ?>
            </div>
        </section>
        <?php
    }

    /** Show the Settings API result inside the custom feature panel after its PRG redirect. */
    public static function save_feedback() {
        if ( ! isset( $_GET['settings-updated'] ) || 'true' !== (string) $_GET['settings-updated'] ) {
            return;
        }
        $notices = get_settings_errors();
        $failures = array();
        $confirmed = false;
        foreach ( (array) $notices as $notice ) {
            if ( ! is_array( $notice ) || ! in_array( $notice['setting'] ?? '', array( self::OPTION_KEY, 'general' ), true ) ) {
                continue;
            }
            if ( 'error' === ( $notice['type'] ?? '' ) ) {
                $failures[] = (string) ( $notice['message'] ?? '设置未保存，请检查输入。' );
            }
            if ( in_array( $notice['type'] ?? '', array( 'success', 'updated' ), true ) ) {
                $confirmed = true;
            }
        }
        if ( $failures ) {
            echo '<div class="notice notice-error jlwa-download-save-notice" role="alert" id="jlwa-download-save-feedback"><p><strong>保存失败：原设置已保留。</strong></p><ul>';
            foreach ( $failures as $message ) {
                echo '<li>' . esc_html( $message ) . '</li>';
            }
            echo '</ul></div>';
        } elseif ( $confirmed ) {
            echo '<div class="notice notice-success jlwa-download-save-notice is-dismissible" role="status" id="jlwa-download-save-feedback"><p><strong>下载页设置保存成功。</strong> 已更新到 WordPress 数据库；Nginx 自动部署和公网源站封锁需另外执行并验证。</p></div>';
        } else {
            echo '<div class="notice notice-warning jlwa-download-save-notice" role="alert" id="jlwa-download-save-feedback"><p><strong>无法确认本次保存结果。</strong> WordPress 未返回可核实的设置消息，请刷新页面核对配置。</p></div>';
        }
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

    /**
     * Full-size v3.0.6 download layouts are still shipped with the plugin.
     * Restore those layouts without ending the WordPress template lifecycle:
     * return a theme-compatible template path instead of include/exit.
     *
     * The independent purchase gate runs earlier on template_redirect for
     * either presentation mode, before any paid resource HTML is emitted.
     */
    public function choose_download_template( $template ) {
        $options = self::options();
        if ( empty( $options['enabled'] ) || 'native' === ( $options['render_mode'] ?? 'classic' )
            || is_admin() || wp_doing_ajax() || ! $this->compatible() ) {
            return $template;
        }
        $post_id = $this->target_post_id();
        if ( ! $post_id || ! self::has_purchase_rights( $post_id ) ) {
            return $template;
        }
        $templates = array(
            'technology' => 'technology',
            'business' => 'business',
            'vip' => 'vip',
            // Additional designs reuse the same audited renderer + its skin.
            'aurora' => 'technology',
            'editorial' => 'technology',
            'midnight' => 'technology',
        );
        $skin = $options['template'] ?? 'technology';
        $file = JLWA_PLUGIN_DIR . 'features/download-page-templates/' .
            ( $templates[ $skin ] ?? 'technology' ) . '.php';
        return is_readable( $file ) ? $file : $template;
    }

    /**
     * Frontend appearance is additive. The theme owns all paid-state checks,
     * download buttons, markup, redirects and AJAX; this class only loads its
     * own stylesheet and a tiny support-panel placement helper.
     */
    public function enqueue_native_assets() {
        if ( ! $this->should_enhance_native_download() ) {
            return;
        }
        wp_enqueue_style(
            'jlwa-native-download',
            JLWA_PLUGIN_URL . 'assets/css/download-page-native.css',
            array(),
            JLWA_VERSION
        );
        wp_enqueue_script(
            'jlwa-native-download',
            JLWA_PLUGIN_URL . 'assets/js/download-page-native.js',
            array(),
            JLWA_VERSION,
            true
        );
    }

    /** Only a validated, purchased Zibll download page receives the skin. */
    private function should_enhance_native_download() {
        if ( is_admin() || wp_doing_ajax() ) {
            return false;
        }
        $options = self::options();
        return ! empty( $options['enabled'] ) && 'native' === ( $options['render_mode'] ?? 'classic' )
            && $this->compatible() && $this->target_request()
            && self::has_purchase_rights( $this->target_post_id() );
    }

    /** Add only a namespaced body class, never edit the theme's native HTML. */
    public function native_body_class( $classes ) {
        if ( ! is_array( $classes ) || ! $this->should_enhance_native_download() ) {
            return $classes;
        }
        $options = self::options();
        $skins = array( 'technology', 'business', 'vip', 'aurora', 'editorial', 'midnight' );
        $skin = in_array( $options['template'], $skins, true ) ? $options['template'] : 'technology';
        $classes[] = 'jlwa-native-download';
        $classes[] = 'jlwa-native-' . $skin;
        return $classes;
    }

    /** Own markup only. Native buttons and native Zibll containers are untouched. */
    public function render_native_support() {
        if ( ! $this->should_enhance_native_download() ) {
            return;
        }
        ?>
        <div id="jlwa-native-support" class="jlwa-native-support-wrap" data-jlwa-owned="1">
            <?php self::render_support_panel( self::options() ); ?>
        </div>
        <?php
    }

    /** Server-side tabs, matching the media-and-links feature navigation. */
    public static function admin_tab() {
        $tab = isset( $_GET['tab'] ) && is_scalar( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'templates';
        if ( 'help' === $tab ) { return 'security'; }
        return in_array( $tab, array( 'templates', 'security', 'advanced', 'deployment', 'logs' ), true ) ? $tab : 'templates';
    }

    public static function render_admin_tabs( $active ) {
        $tabs = array(
            'templates' => '下载页模板',
            'security' => '私有安全交付',
            'advanced' => '交付高级设置',
            'deployment' => 'Nginx 自动部署',
            'logs' => '下载记录',
        );
        echo '<h2 class="nav-tab-wrapper jlwa-download-tabs" aria-label="下载页设置分类">';
        foreach ( $tabs as $key => $label ) {
            $url = admin_url( 'admin.php?page=jlwa-download-page&tab=' . $key );
            echo '<a class="nav-tab ' . esc_attr( $active === $key ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '"';
            if ( $active === $key ) { echo ' aria-current="page"'; }
            echo '>' . esc_html( $label ) . '</a>';
        }
        echo '</h2>';
    }

    public function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $options = self::options();
        $tab = self::admin_tab();
        $templates = array(
            'technology' => array( '科技蓝紫版', '完整经典科技资源中心布局；原生模式使用科技外观。' ),
            'business' => array( '简洁商务版', '完整简洁商务资源页面；原生模式使用商务外观。' ),
            'vip' => array( 'VIP 资源中心版', '完整 VIP 资源中心布局；原生模式使用象牙金外观。' ),
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
        <div class="jlwa-download-admin">
        <?php self::render_admin_tabs( $tab ); ?>
        <?php self::save_feedback(); ?>
        <?php if ( in_array( $tab, array( 'templates', 'security', 'advanced' ), true ) ) : ?>
        <form action="options.php" method="post">
            <?php settings_fields( 'jlwa_download_page' ); ?>
            <input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[jlwa_settings_scope]" value="<?php echo esc_attr( $tab ); ?>">
            <?php if ( 'templates' === $tab ) : ?>
            <section class="jlwa-download-intro"><div><span>DOWNLOAD EXPERIENCE</span><h2>子比下载页美化</h2><p>完整经典模板已恢复；可切换到不替换子比 HTML 的原生兼容皮肤。两种模式都不会修改子比主题源码。</p></div><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enabled]" value="1" <?php checked( ! empty( $options['enabled'] ) ); ?>><b>启用功能</b></label></section>
            <section class="jlwa-download-section" id="jlwa-download-render-mode">
                <h3>下载页渲染方式</h3>
                <label class="jlwa-mode-row"><input type="radio" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[render_mode]" value="classic" <?php checked( $options['render_mode'], 'classic' ); ?>><span><strong>完整经典模板（恢复原先设计）</strong> — 保留全部六种原版布局、左上角封面、卡片、资源区块与客服面板。仅在确认已购的下载页替换展示模板，不修改子比授权或 AJAX。需要在子比更新后检查兼容性。</span></label>
                <label class="jlwa-mode-row"><input type="radio" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[render_mode]" value="native" <?php checked( $options['render_mode'], 'native' ); ?>><span><strong>子比原生兼容皮肤</strong> — 不替换子比下载页面，只叠加轻量 CSS 和本站客服；布局会与完整经典模板不同。</span></label>
                <p class="description"><strong>升级兼容：</strong>历史未保存此选项的站点默认恢复完整经典模板。两种模式均保留服务端未购买拦截；独立 OpenList 安全交付设置不受切换影响。</p>
            </section>
            <div class="jlwa-download-section"><h3>选择下载页视觉方案</h3><div class="jlwa-template-grid"><?php foreach ( $templates as $key => $item ) : ?><label class="jlwa-template-card is-<?php echo esc_attr( $key ); ?>"><input type="radio" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[template]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $options['template'], $key ); ?>><span class="preview"></span><strong><?php echo esc_html( $item[0] ); ?></strong><small><?php echo esc_html( $item[1] ); ?></small></label><?php endforeach; ?></div></div>
            <div class="jlwa-download-columns"><section class="jlwa-download-section"><h3>资源封面策略</h3><p>完整经典模板控制左上角大封面；原生模式保持子比自身封面展示规则。</p><?php foreach ( $modes as $key => $label ) : ?><label class="jlwa-mode-row"><input type="radio" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[cover_mode]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $options['cover_mode'], $key ); ?>><span><?php echo esc_html( $label ); ?></span></label><?php endforeach; ?></section><section class="jlwa-download-section"><h3>默认图片</h3><input class="regular-text" type="url" id="jlwa-default-cover" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[default_image_url]" value="<?php echo esc_attr( $options['default_image_url'] ); ?>" placeholder="留空使用插件内置默认图"><button type="button" class="button" id="jlwa-select-cover">从媒体库选择</button><p><?php echo $this->compatible() ? '已检测到子比支付、权限与下载接口。' : '未检测到完整子比接口，前台不会加载本插件的下载页模板或皮肤。'; ?></p></section></div>
            <section class="jlwa-download-section" id="jlwa-download-support">
                <h3>售前咨询与售后服务 · 自定义联系方式</h3>
                <p class="description">六种下载页皮肤共用以下设置，可填写 Telegram、WhatsApp、QQ 客服网页、邮件链接等。默认不填写任何网址，未配置时不显示联系区块，绝不指向插件作者的账号。</p>
                <p><label for="jlwa-support-title"><strong>联系区块标题</strong></label></p>
                <input id="jlwa-support-title" class="regular-text" type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[support_title]" value="<?php echo esc_attr( $options['support_title'] ); ?>">
                <p><label for="jlwa-support-description"><strong>联系说明</strong></label></p>
                <input id="jlwa-support-description" class="large-text" type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[support_description]" value="<?php echo esc_attr( $options['support_description'] ); ?>">
                <div class="jlwa-download-columns">
                    <div>
                        <p><label for="jlwa-support-primary-url"><strong>客服链接</strong>（留空不显示）</label></p>
                        <input id="jlwa-support-primary-url" class="large-text code" type="url" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[support_primary_url]" value="<?php echo esc_attr( $options['support_primary_url'] ); ?>" placeholder="https://example.com/contact">
                        <p><label for="jlwa-support-primary-label"><strong>客服按钮文字</strong></label></p>
                        <input id="jlwa-support-primary-label" class="regular-text" type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[support_primary_label]" value="<?php echo esc_attr( $options['support_primary_label'] ); ?>">
                    </div>
                    <div>
                        <p><label for="jlwa-support-secondary-url"><strong>社群／第二联系方式</strong>（留空不显示）</label></p>
                        <input id="jlwa-support-secondary-url" class="large-text code" type="url" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[support_secondary_url]" value="<?php echo esc_attr( $options['support_secondary_url'] ); ?>" placeholder="https://example.com/community">
                        <p><label for="jlwa-support-secondary-label"><strong>第二按钮文字</strong></label></p>
                        <input id="jlwa-support-secondary-label" class="regular-text" type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[support_secondary_label]" value="<?php echo esc_attr( $options['support_secondary_label'] ); ?>">
                    </div>
                </div>
                <p class="description">仅接受 http(s)、mailto:、tel: 链接；全部留空表示关闭联系区块，不影响下载或订单逻辑。</p>
            </section>
            <?php endif; ?>
            <?php if ( 'security' === $tab ) : ?>
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
            </section>
            <?php endif; ?>
            <?php if ( 'advanced' === $tab ) : ?>
            <section class="jlwa-download-section" id="jlwa-delivery-advanced">
                <h3>交付高级设置</h3>
                <p class="description">与“私有安全交付”共用原有权限和资源配置。本页独立保存 OpenList 本机连接、下载出口、授权记录和限流参数；无需重新填写受保护源站与私有路径。</p>
                <h4>一键部署 · 本地 OpenList 连接</h4>
                <p>只需填写 Docker 发布在宿主机 <code>127.0.0.1</code> 的端口号（不是容器内部端口）。保存后，前往顶部“Nginx 自动部署”分类获取完整的一键复制命令，不必自己找 Nginx 配置文件。</p>
                <p><label for="jlwa-openlist-host-port"><strong>OpenList 本机端口</strong></label></p>
                <input id="jlwa-openlist-host-port" type="number" min="1" max="65535" step="1" style="width:160px" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[openlist_host_port]" value="<?php echo esc_attr( $options['openlist_host_port'] ); ?>" placeholder="例如 5244">
                <details style="margin:12px 0">
                    <summary>高级设置：Docker 容器名称（可选）</summary>
                    <p>一般无需填写，安装助手会尝试根据端口自动识别。仅当同机存在多个容器、需要明确验证指定容器时才填写。</p>
                    <input class="regular-text code" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[openlist_container]" value="<?php echo esc_attr( $options['openlist_container'] ); ?>" placeholder="例如 openlist">
                </details>
                <p class="description">付费目录将由 OpenList 公网 Nginx 拦截，只有 WordPress 经本地内部通道读取；公开图片目录不会被封锁。</p>
                <hr>
                <h4>公网文件访问策略 · 仅开放指定图片目录</h4>
                <p class="description">首次安装前填写并保存本目录，一键安装就会记录完整图片白名单策略。完成内部代理与已购测试后，第④步直接拒绝所有公网 /d/、/p/ 文件路由，只放行这一个图片目录及其子文件；**不需要安装旧方案或额外升级步骤**。目录由站长自行填写，不限制名称必须叫“图片”。API 与需要认证的 WebDAV 不受该文件访问规则影响。留空则沿用仅保护付费目录的兼容模式。</p>
                <p><label for="jlwa-openlist-public-images"><strong>唯一公开图片目录（绝对路径，以 / 结尾）</strong></label></p>
                <input id="jlwa-openlist-public-images" class="large-text code" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[openlist_public_images]" value="<?php echo esc_attr( $options['openlist_public_images'] ); ?>" placeholder="/d/storage/public-images/">
                <p class="description">首次部署：保存后进入“Nginx 自动部署”复制一次性安装命令。已经安装过受限助手的站点：修改此设置不会自动覆盖 root 授权，须使用后台的旧部署兼容工具或保持现状。</p>
                <hr>
                <h4>下载出口域名（可选）</h4>
                <p><strong>留空时：</strong>已购下载通过实际发起 <code>/pay-download/</code> 请求的域名直接使用 Nginx <code>X-Accel-Redirect</code> 交付，不签发跨域跳转票据，也不会从未经信任的 HTTP Host 自动创建新出口。多域名入口需要确认子比生成的下载按钮确实指向当前域名。</p>
                <p>购买权限仍由子比在原站验证，开启后向指定的同一 WordPress 站点别名签发短期随机下载凭证。不显示 OpenList URL。必须先在 DNS、SSL 和站点配置中将这个别名指向同一 WordPress 网站并测试。</p>
                <p><label for="jlwa-delivery-host"><strong>下载出口域名</strong>（不含 https://，例如 download.example.com）</label></p>
                <input id="jlwa-delivery-host" class="large-text code" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[delivery_host]" value="<?php echo esc_attr( $options['delivery_host'] ); ?>" placeholder="download.example.com">
                <label class="jlwa-mode-row"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[delivery_enabled]" value="1" <?php checked( ! empty( $options['delivery_enabled'] ) ); ?>><span>开启自定义下载出口（默认关闭，先配置并验证域名）</span></label>
                <p><label>下载凭证有效期（秒，60～600） <input type="number" min="60" max="600" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[ticket_lifetime]" value="<?php echo esc_attr( $options['ticket_lifetime'] ); ?>"></label></p>
                <details class="jlwa-delivery-details"><summary>CDN 安全边界与下载凭证说明</summary><p class="description">下载凭证属于短期 Bearer 凭据，同有效期内持有者可使用，且支持同凭据范围请求。插件发送 no-store，但仍必须在 CDN 配置中强制对下载凭证参数 <code>jlwa_dl</code> 绕过缓存。普通 CDN 反代不等于文件内容缓存加速；当前版本不允许将私有下载设为公开缓存。</p></details>
                <hr>
                <h4>下载记录与保守限流</h4>
                <label class="jlwa-mode-row"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[audit_enabled]" value="1" <?php checked( ! empty( $options['audit_enabled'] ) ); ?>><span>记录授权下载操作（只存用户、文章、线路、时间、结果，不记录下载密码、凭证或源 URL）</span></label>
                <label class="jlwa-mode-row"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[rate_enabled]" value="1" <?php checked( ! empty( $options['rate_enabled'] ) ); ?>><span>启用每用户每文章的下载申请限流（默认关闭，避免影响正常下载）</span></label>
                <p><label>60 秒内最多请求次数（10～120） <input type="number" min="10" max="120" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[rate_limit]" value="<?php echo esc_attr( $options['rate_limit'] ); ?>"></label> <small>仅统计重新申请下载；范围续传不额外计数。</small></p>
                <p><a class="button button-secondary" href="<?php echo esc_url( 'https://github.com/nljie1103/wp-assistant/blob/main/docs/paid-download-security.md' ); ?>" target="_blank" rel="noopener noreferrer">📘 查看 WordPress Nginx + OpenList 详细配置教程</a></p>
                <p class="description">包含部署、验证与回滚步骤；不修改子比订单、WebDAV 密码和文章下载地址。</p>
            </section>
            <?php endif; ?>
            <?php submit_button( 'templates' === $tab ? '保存下载页模板' : ( 'security' === $tab ? '保存私有安全交付' : '保存交付高级设置' ) ); ?>
        </form>
        <?php endif; ?>
        <?php if ( 'deployment' === $tab && class_exists( 'JLWA_Private_Deploy' ) ) { JLWA_Private_Deploy::render_panel(); } ?>
        <?php
        $recent = 'logs' === $tab ? get_option( 'jlwa_private_download_events', array() ) : array();
        if ( is_array( $recent ) && $recent ) :
        ?>
        <section class="jlwa-download-section"><h3>最近授权下载事件</h3><p>仅保留最近 120 条，不包含 URL、Token 和提取密码。</p><table class="widefat striped"><thead><tr><th>时间</th><th>用户 ID</th><th>文章 ID</th><th>下载线路</th><th>结果</th></tr></thead><tbody>
        <?php foreach ( array_reverse( array_slice( $recent, -20 ) ) as $record ) : ?>
        <tr><td><?php echo esc_html( wp_date( 'Y-m-d H:i:s', (int) ( $record['time'] ?? 0 ) ) ); ?></td><td><?php echo esc_html( (string) (int) ( $record['user_id'] ?? 0 ) ); ?></td><td><?php echo esc_html( (string) (int) ( $record['post_id'] ?? 0 ) ); ?></td><td><?php echo esc_html( (string) (int) ( $record['down_id'] ?? 0 ) ); ?></td><td><?php echo esc_html( (string) ( $record['result'] ?? '' ) ); ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></section>
        <?php elseif ( 'logs' === $tab ) : ?>
        <section class="jlwa-download-section"><h3>下载记录</h3><p>暂无下载事件；记录仅包含时间、用户、文章、线路和状态，不包含下载凭证或真实文件 URL。</p></section>
        <?php endif; ?>
        </div>
        <script>jQuery(function($){var frame;$('#jlwa-select-cover').on('click',function(e){e.preventDefault();if(frame){frame.open();return;}frame=wp.media({title:'选择默认封面',button:{text:'使用这张图片'},multiple:false,library:{type:'image'}});frame.on('select',function(){$('#jlwa-default-cover').val(frame.state().get('selection').first().toJSON().url);});frame.open();});});</script>
        <?php
    }
}
JLWA_Download_Page_Feature::instance();
