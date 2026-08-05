<?php
/** Internal page effects feature. */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'JLWA_Page_Effects_Feature' ) ) {
    final class JLWA_Page_Effects_Feature {
        const VERSION     = '1.7.1';
        const OPTION_NAME = 'xjpe_options';
        const MENU_SLUG   = 'jlwa-page-effects';

        private static $instance = null;

        public static function instance() {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        private function __construct() {
            add_action( 'init', array( $this, 'maybe_upgrade_options' ), 1 );
            add_action( 'admin_init', array( $this, 'register_settings' ) );
            add_action( 'admin_init', array( $this, 'maybe_handle_direct_save' ) );
            add_action( 'admin_post_xjpe_save_options', array( $this, 'handle_save_options' ) );
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
            add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
            add_action( 'template_redirect', array( $this, 'maybe_start_buffer_injection' ), 0 );
            add_filter( 'the_content', array( $this, 'filter_protected_content' ), 999 );
            add_filter( 'the_content_feed', array( $this, 'filter_protected_feed' ), 999 );
            add_filter( 'the_excerpt_rss', array( $this, 'filter_protected_feed' ), 999 );
            add_filter( 'rest_prepare_post', array( $this, 'filter_protected_rest_response' ), 999, 3 );
            add_filter( 'rest_prepare_page', array( $this, 'filter_protected_rest_response' ), 999, 3 );
            add_filter( 'wp_robots', array( $this, 'filter_protected_robots' ) );

        }

        public static function activate() {
            if ( false === get_option( self::OPTION_NAME, false ) ) {
                add_option( self::OPTION_NAME, self::default_options(), '', false );
            }
        }

        public function maybe_upgrade_options() {
            $saved = get_option( self::OPTION_NAME, false );
            if ( false === $saved ) {
                return;
            }
            if ( ! is_array( $saved ) ) {
                update_option( self::OPTION_NAME, self::default_options(), false );
                return;
            }
            $old_version = isset( $saved['version'] ) ? (string) $saved['version'] : '0.0.0';
            if ( version_compare( $old_version, self::VERSION, '>=' ) ) {
                return;
            }
            $merged = $this->merge_options( self::default_options(), $saved );
            $merged['version'] = self::VERSION;

            // v1.3.0 重点修复：旧版默认队列加载在部分主题里看不到效果；升级后默认改成缓冲注入，确保前台能看到。
            if ( version_compare( $old_version, '1.3.0', '<' ) ) {
                $merged['compat']['injection_mode'] = 'buffer';
                $merged['compat']['load_location'] = 'head';
                $merged['global']['respect_reduce_motion'] = 0;
            }
            update_option( self::OPTION_NAME, $merged, false );
        }

        public function maybe_handle_direct_save() {
            if ( ! is_admin() || empty( $_POST['xjpe_direct_save'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                return;
            }
            if ( empty( $_GET['page'] ) || self::MENU_SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                return;
            }
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( '权限不足，无法保存页面美化配置。', 'xiaojie-page-effects' ) );
            }
            check_admin_referer( 'xjpe_save_options', 'xjpe_nonce' );

            $raw = isset( $_POST[ self::OPTION_NAME ] ) ? wp_unslash( $_POST[ self::OPTION_NAME ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $options = $this->sanitize_options( $raw );
            $options['version'] = self::VERSION;
            update_option( self::OPTION_NAME, $options, false );

            $redirect = add_query_arg(
                array(
                    'page'       => self::MENU_SLUG,
                    'xjpe_saved' => 1,
                    't'          => time(),
                ),
                admin_url( 'admin.php' )
            );
            wp_safe_redirect( $redirect );
            exit;
        }

        public function handle_save_options() {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( '权限不足，无法保存页面美化配置。', 'xiaojie-page-effects' ) );
            }
            check_admin_referer( 'xjpe_save_options', 'xjpe_nonce' );

            $raw = isset( $_POST[ self::OPTION_NAME ] ) ? wp_unslash( $_POST[ self::OPTION_NAME ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $options = $this->sanitize_options( $raw );
            $options['version'] = self::VERSION;
            update_option( self::OPTION_NAME, $options, false );

            $redirect = add_query_arg(
                array(
                    'page'       => self::MENU_SLUG,
                    'xjpe_saved' => 1,
                ),
                admin_url( 'admin.php' )
            );
            wp_safe_redirect( $redirect );
            exit;
        }

        public static function default_options() {
            return array(
                'version' => self::VERSION,
                'global' => array(
                    'enabled'               => 1,
                    'mobile_enabled'        => 1,
                    'respect_reduce_motion' => 0,
                    'z_index'               => 999999,
                    'custom_css'            => '',
                    'custom_js'             => '',
                ),
                'compat' => array(
                    'load_location' => 'head',
                    'injection_mode' => 'enqueue',
                    'body_wait'     => 1,
                    'safe_mode'     => 1,
                ),
                'effects' => array(
                    'sakura' => array(
                        'enabled' => 0,
                        'count'   => 56,
                        'size'    => 18,
                        'speed'   => 1.0,
                        'opacity' => 0.85,
                        'wind'    => 0.45,
                        'sway'    => 1.25,
                    ),
                    'snow' => array(
                        'enabled' => 0,
                        'count'   => 72,
                        'size'    => 13,
                        'speed'   => 1.0,
                        'opacity' => 0.9,
                        'wind'    => 0.35,
                        'sway'    => 1.0,
                    ),
                    'leaves' => array(
                        'enabled' => 0,
                        'count'   => 44,
                        'size'    => 18,
                        'speed'   => 0.85,
                        'opacity' => 0.88,
                        'wind'    => 0.65,
                        'sway'    => 1.4,
                    ),
                    'bubbles' => array(
                        'enabled' => 0,
                        'count'   => 30,
                        'size'    => 18,
                        'speed'   => 0.65,
                        'opacity' => 0.5,
                        'wind'    => 0.18,
                        'sway'    => 0.8,
                    ),
                    'lantern' => array(
                        'enabled'  => 0,
                        'size'     => 82,
                        'text'     => '福',
                        'quantity' => 2,
                    ),
                    'particles' => array(
                        'enabled'      => 0,
                        'count'        => 70,
                        'speed'        => 0.7,
                        'opacity'      => 0.55,
                        'line_distance' => 130,
                    ),
                    'cursor' => array(
                        'enabled'    => 0,
                        'size'       => 13,
                        'density'    => 1,
                        'preset'     => 'star',
                        'symbol'     => '✦',
                        'color_mode' => 'rainbow',
                        'color'      => '#ff5ba7',
                        'duration'   => 760,
                    ),
                    'waves' => array(
                        'enabled' => 0,
                        'height'  => 72,
                        'opacity' => 0.48,
                        'speed'   => 12,
                        'color_1' => '#5b8cff',
                        'color_2' => '#9b5cff',
                    ),
                    'ribbon' => array(
                        'enabled' => 0,
                        'opacity' => 0.42,
                        'click'   => 1,
                    ),
                    'grayscale' => array(
                        'enabled' => 0,
                        'percent' => 100,
                    ),
                    'contextmenu' => array(
                        'enabled'      => 0,
                        'title'        => '九流网站菜单',
                        'show_copy'    => 1,
                        'show_refresh' => 1,
                        'show_top'     => 1,
                        'show_back'    => 1,
                        'custom_items' => "首页|/",
                    ),
                    'nosource' => array(
                        'enabled'            => 0,
                        'message'            => '本站已开启内容保护，请尊重原创。',
                        'admin_bypass'       => 1,
                        'block_contextmenu'  => 1,
                        'block_shortcuts'    => 1,
                        'block_copy'         => 0,
                        'block_selection'    => 0,
                        'block_drag'         => 1,
                        'block_print'        => 0,
                        'copy_mode'          => 'append',
                        'copy_prefix'        => '',
                        'copy_suffix'        => "

以上内容转载自九流网络，请保留版权：https://blog.jiuliu.org/",
                        'copy_min_chars'     => 12,
                        'copy_include_link'  => 1,
                        'copy_success_toast' => 1,
                        'copy_toast_message' => '复制成功，请保留文章版权与来源链接。',
                        'server_mode'        => 'public',
                        'server_capability'  => 'read',
                        'server_posts'       => 1,
                        'server_pages'       => 0,
                        'server_teaser_words'=> 60,
                        'server_message'     => '完整内容仅向已授权用户开放，请登录后继续阅读。',
                        'server_hide_rest'   => 1,
                        'server_hide_feed'   => 1,
                        'server_noindex'     => 1,
                    ),
                    'bgmusic' => array(
                        'enabled'  => 0,
                        'url'      => '',
                        'title'    => '背景音乐',
                        'volume'   => 0.35,
                        'loop'     => 1,
                        'autoplay' => 0,
                    ),
                    'welcome' => array(
                        'enabled'       => 0,
                        'auto_festival' => 1,
                        'title'         => '欢迎访问',
                        'message'       => '欢迎来到我的网站，祝你今天开心。',
                        'once_per_day'  => 1,
                    ),
                ),
            );
        }

        public static function effect_definitions() {
            return array(
                'sakura' => array( 'icon' => '🌸', 'title' => '全屏樱花', 'desc' => '高密度樱花瓣，可调风向与摆动', 'group' => '氛围特效' ),
                'snow' => array( 'icon' => '❄️', 'title' => '全屏雪花', 'desc' => '多层雪花飘屏，可调风向与摆动', 'group' => '氛围特效' ),
                'leaves' => array( 'icon' => '🍂', 'title' => '秋叶飘落', 'desc' => '多形态秋叶旋转飘落', 'group' => '氛围特效' ),
                'bubbles' => array( 'icon' => '🫧', 'title' => '梦幻气泡', 'desc' => '透明气泡从底部缓慢上升', 'group' => '氛围特效' ),
                'lantern' => array( 'icon' => '🏮', 'title' => '节日灯笼', 'desc' => '页面顶部悬挂灯笼', 'group' => '氛围特效' ),
                'particles' => array( 'icon' => '✨', 'title' => '粒子背景', 'desc' => '动态粒子连线效果', 'group' => '氛围特效' ),
                'cursor' => array( 'icon' => '🌟', 'title' => '鼠标跟随', 'desc' => '星星、爱心、萤火、花瓣、气泡或自定义拖尾', 'group' => '交互增强' ),
                'waves' => array( 'icon' => '🌊', 'title' => '底部波浪', 'desc' => '页面底部流动的双层渐变波浪', 'group' => '氛围特效' ),
                'ribbon' => array( 'icon' => '🎀', 'title' => '彩带背景', 'desc' => '点击刷新彩带背景', 'group' => '氛围特效' ),
                'grayscale' => array( 'icon' => '🕯️', 'title' => '全站灰色', 'desc' => '纪念/悼念模式', 'group' => '特殊模式' ),
                'contextmenu' => array( 'icon' => '🖱️', 'title' => '右键美化', 'desc' => '自定义右键菜单', 'group' => '交互增强' ),
                'nosource' => array( 'icon' => '🔒', 'title' => '内容保护与复制版权', 'desc' => '公开页面操作限制、复制署名与服务器权限保护', 'group' => '内容保护' ),
                'bgmusic' => array( 'icon' => '🎵', 'title' => '背景音乐', 'desc' => '网站背景音乐播放', 'group' => '节日与音乐' ),
                'welcome' => array( 'icon' => '🎉', 'title' => '节日欢迎弹窗', 'desc' => '节日自动弹窗祝福', 'group' => '节日与音乐' ),
            );
        }

        public function register_settings() {
            register_setting(
                'xjpe_settings_group',
                self::OPTION_NAME,
                array(
                    'type'              => 'array',
                    'sanitize_callback' => array( $this, 'sanitize_options' ),
                    'default'           => self::default_options(),
                )
            );
        }

        public function get_options() {
            $saved = get_option( self::OPTION_NAME, array() );
            if ( ! is_array( $saved ) ) {
                $saved = array();
            }
            return $this->merge_options( self::default_options(), $saved );
        }

        private function merge_options( $defaults, $saved ) {
            foreach ( $defaults as $key => $value ) {
                if ( is_array( $value ) ) {
                    $saved[ $key ] = isset( $saved[ $key ] ) && is_array( $saved[ $key ] ) ? $this->merge_options( $value, $saved[ $key ] ) : $value;
                } elseif ( ! array_key_exists( $key, $saved ) ) {
                    $saved[ $key ] = $value;
                }
            }
            return $saved;
        }

        public function sanitize_options( $input ) {
            $defaults = self::default_options();
            $input    = is_array( $input ) ? $input : array();
            $output   = $defaults;
            $output['version'] = self::VERSION;

            $global = isset( $input['global'] ) && is_array( $input['global'] ) ? $input['global'] : array();
            $output['global']['enabled']               = empty( $global['enabled'] ) ? 0 : 1;
            $output['global']['mobile_enabled']        = empty( $global['mobile_enabled'] ) ? 0 : 1;
            $output['global']['respect_reduce_motion'] = empty( $global['respect_reduce_motion'] ) ? 0 : 1;
            $output['global']['z_index']               = $this->sanitize_int( $global['z_index'] ?? $defaults['global']['z_index'], 1000, 2147483000, 999999 );
            $output['global']['custom_css']            = $this->sanitize_custom_css( $global['custom_css'] ?? '' );
            $output['global']['custom_js']             = $this->sanitize_custom_js( $global['custom_js'] ?? '' );

            $compat = isset( $input['compat'] ) && is_array( $input['compat'] ) ? $input['compat'] : array();
            $valid_load_locations = array( 'head', 'footer' );
            $load_location = isset( $compat['load_location'] ) ? sanitize_key( $compat['load_location'] ) : 'head';
            $output['compat']['load_location'] = in_array( $load_location, $valid_load_locations, true ) ? $load_location : 'head';
            $valid_injection_modes = array( 'enqueue', 'head_footer', 'buffer' );
            $injection_mode = isset( $compat['injection_mode'] ) ? sanitize_key( $compat['injection_mode'] ) : 'enqueue';
            $output['compat']['injection_mode'] = in_array( $injection_mode, $valid_injection_modes, true ) ? $injection_mode : 'enqueue';
            $output['compat']['body_wait']     = empty( $compat['body_wait'] ) ? 0 : 1;
            $output['compat']['safe_mode']     = empty( $compat['safe_mode'] ) ? 0 : 1;

            $effects = isset( $input['effects'] ) && is_array( $input['effects'] ) ? $input['effects'] : array();

            foreach ( $defaults['effects'] as $key => $default_effect ) {
                $effect_input = isset( $effects[ $key ] ) && is_array( $effects[ $key ] ) ? $effects[ $key ] : array();
                $output['effects'][ $key ]['enabled'] = empty( $effect_input['enabled'] ) ? 0 : 1;
            }

            foreach ( array( 'sakura', 'snow', 'leaves', 'bubbles' ) as $falling_key ) {
                $falling_defaults = $defaults['effects'][ $falling_key ];
                $output['effects'][ $falling_key ]['count']   = $this->sanitize_int( $effects[ $falling_key ]['count'] ?? $falling_defaults['count'], 1, 360, $falling_defaults['count'] );
                $output['effects'][ $falling_key ]['size']    = $this->sanitize_int( $effects[ $falling_key ]['size'] ?? $falling_defaults['size'], 4, 72, $falling_defaults['size'] );
                $output['effects'][ $falling_key ]['speed']   = $this->sanitize_float( $effects[ $falling_key ]['speed'] ?? $falling_defaults['speed'], 0.1, 5, $falling_defaults['speed'] );
                $output['effects'][ $falling_key ]['opacity'] = $this->sanitize_float( $effects[ $falling_key ]['opacity'] ?? $falling_defaults['opacity'], 0.05, 1, $falling_defaults['opacity'] );
                $output['effects'][ $falling_key ]['wind']    = $this->sanitize_float( $effects[ $falling_key ]['wind'] ?? $falling_defaults['wind'], -3, 3, $falling_defaults['wind'] );
                $output['effects'][ $falling_key ]['sway']    = $this->sanitize_float( $effects[ $falling_key ]['sway'] ?? $falling_defaults['sway'], 0, 4, $falling_defaults['sway'] );
            }

            $output['effects']['lantern']['size']     = $this->sanitize_int( $effects['lantern']['size'] ?? 82, 36, 180, 82 );
            $output['effects']['lantern']['text']     = sanitize_text_field( $effects['lantern']['text'] ?? '福' );
            $output['effects']['lantern']['quantity'] = $this->sanitize_int( $effects['lantern']['quantity'] ?? 2, 1, 6, 2 );

            $output['effects']['particles']['count']         = $this->sanitize_int( $effects['particles']['count'] ?? 70, 8, 200, 70 );
            $output['effects']['particles']['speed']         = $this->sanitize_float( $effects['particles']['speed'] ?? 0.7, 0.05, 4, 0.7 );
            $output['effects']['particles']['opacity']       = $this->sanitize_float( $effects['particles']['opacity'] ?? 0.55, 0.05, 1, 0.55 );
            $output['effects']['particles']['line_distance'] = $this->sanitize_int( $effects['particles']['line_distance'] ?? 130, 40, 300, 130 );

            $output['effects']['cursor']['size']       = $this->sanitize_int( $effects['cursor']['size'] ?? 13, 4, 48, 13 );
            $output['effects']['cursor']['density']    = $this->sanitize_int( $effects['cursor']['density'] ?? 1, 1, 6, 1 );
            $cursor_presets = array( 'star', 'heart', 'firefly', 'petal', 'bubble', 'custom' );
            $cursor_preset = isset( $effects['cursor']['preset'] ) ? sanitize_key( $effects['cursor']['preset'] ) : 'star';
            $output['effects']['cursor']['preset']     = in_array( $cursor_preset, $cursor_presets, true ) ? $cursor_preset : 'star';
            $cursor_symbol = sanitize_text_field( $effects['cursor']['symbol'] ?? '✦' );
            $output['effects']['cursor']['symbol']     = function_exists( 'mb_substr' ) ? mb_substr( $cursor_symbol, 0, 4 ) : substr( $cursor_symbol, 0, 12 );
            $output['effects']['cursor']['color_mode'] = in_array( $effects['cursor']['color_mode'] ?? 'rainbow', array( 'rainbow', 'fixed' ), true ) ? $effects['cursor']['color_mode'] : 'rainbow';
            $output['effects']['cursor']['color']      = sanitize_hex_color( $effects['cursor']['color'] ?? '#ff5ba7' ) ?: '#ff5ba7';
            $output['effects']['cursor']['duration']   = $this->sanitize_int( $effects['cursor']['duration'] ?? 760, 240, 2400, 760 );

            $output['effects']['waves']['height']  = $this->sanitize_int( $effects['waves']['height'] ?? 72, 24, 220, 72 );
            $output['effects']['waves']['opacity'] = $this->sanitize_float( $effects['waves']['opacity'] ?? 0.48, 0.05, 1, 0.48 );
            $output['effects']['waves']['speed']   = $this->sanitize_int( $effects['waves']['speed'] ?? 12, 4, 40, 12 );
            $output['effects']['waves']['color_1'] = sanitize_hex_color( $effects['waves']['color_1'] ?? '#5b8cff' ) ?: '#5b8cff';
            $output['effects']['waves']['color_2'] = sanitize_hex_color( $effects['waves']['color_2'] ?? '#9b5cff' ) ?: '#9b5cff';

            $output['effects']['ribbon']['opacity'] = $this->sanitize_float( $effects['ribbon']['opacity'] ?? 0.42, 0.05, 1, 0.42 );
            $output['effects']['ribbon']['click']   = empty( $effects['ribbon']['click'] ) ? 0 : 1;

            $output['effects']['grayscale']['percent'] = $this->sanitize_int( $effects['grayscale']['percent'] ?? 100, 1, 100, 100 );

            $output['effects']['contextmenu']['title']        = sanitize_text_field( $effects['contextmenu']['title'] ?? '九流网站菜单' );
            $output['effects']['contextmenu']['show_copy']    = empty( $effects['contextmenu']['show_copy'] ) ? 0 : 1;
            $output['effects']['contextmenu']['show_refresh'] = empty( $effects['contextmenu']['show_refresh'] ) ? 0 : 1;
            $output['effects']['contextmenu']['show_top']     = empty( $effects['contextmenu']['show_top'] ) ? 0 : 1;
            $output['effects']['contextmenu']['show_back']    = empty( $effects['contextmenu']['show_back'] ) ? 0 : 1;
            $output['effects']['contextmenu']['custom_items'] = $this->sanitize_custom_items( $effects['contextmenu']['custom_items'] ?? '' );

            $protection = isset( $effects['nosource'] ) && is_array( $effects['nosource'] ) ? $effects['nosource'] : array();
            $output['effects']['nosource']['message']            = sanitize_text_field( $protection['message'] ?? $defaults['effects']['nosource']['message'] );
            foreach ( array( 'admin_bypass', 'block_contextmenu', 'block_shortcuts', 'block_copy', 'block_selection', 'block_drag', 'block_print', 'copy_include_link', 'copy_success_toast', 'server_posts', 'server_pages', 'server_hide_rest', 'server_hide_feed', 'server_noindex' ) as $toggle ) {
                $output['effects']['nosource'][ $toggle ] = empty( $protection[ $toggle ] ) ? 0 : 1;
            }
            $copy_mode = isset( $protection['copy_mode'] ) ? sanitize_key( $protection['copy_mode'] ) : 'append';
            $output['effects']['nosource']['copy_mode']          = in_array( $copy_mode, array( 'none', 'prepend', 'append', 'both' ), true ) ? $copy_mode : 'append';
            $output['effects']['nosource']['copy_prefix']        = sanitize_textarea_field( $protection['copy_prefix'] ?? '' );
            $output['effects']['nosource']['copy_suffix']        = sanitize_textarea_field( $protection['copy_suffix'] ?? $defaults['effects']['nosource']['copy_suffix'] );
            $output['effects']['nosource']['copy_min_chars']     = $this->sanitize_int( $protection['copy_min_chars'] ?? 12, 0, 1000, 12 );
            $output['effects']['nosource']['copy_toast_message'] = sanitize_text_field( $protection['copy_toast_message'] ?? $defaults['effects']['nosource']['copy_toast_message'] );
            $server_mode = isset( $protection['server_mode'] ) ? sanitize_key( $protection['server_mode'] ) : 'public';
            $output['effects']['nosource']['server_mode'] = in_array( $server_mode, array( 'public', 'restricted' ), true ) ? $server_mode : 'public';
            $server_capability = isset( $protection['server_capability'] ) ? sanitize_key( $protection['server_capability'] ) : 'read';
            $output['effects']['nosource']['server_capability'] = in_array( $server_capability, array( 'read', 'edit_posts', 'manage_options' ), true ) ? $server_capability : 'read';
            $output['effects']['nosource']['server_teaser_words'] = $this->sanitize_int( $protection['server_teaser_words'] ?? 60, 0, 300, 60 );
            $output['effects']['nosource']['server_message'] = sanitize_text_field( $protection['server_message'] ?? $defaults['effects']['nosource']['server_message'] );

            $output['effects']['bgmusic']['url']      = esc_url_raw( $effects['bgmusic']['url'] ?? '' );
            $output['effects']['bgmusic']['title']    = sanitize_text_field( $effects['bgmusic']['title'] ?? '背景音乐' );
            $output['effects']['bgmusic']['volume']   = $this->sanitize_float( $effects['bgmusic']['volume'] ?? 0.35, 0, 1, 0.35 );
            $output['effects']['bgmusic']['loop']     = empty( $effects['bgmusic']['loop'] ) ? 0 : 1;
            $output['effects']['bgmusic']['autoplay'] = empty( $effects['bgmusic']['autoplay'] ) ? 0 : 1;

            $output['effects']['welcome']['auto_festival'] = empty( $effects['welcome']['auto_festival'] ) ? 0 : 1;
            $output['effects']['welcome']['title']         = sanitize_text_field( $effects['welcome']['title'] ?? '欢迎访问' );
            $output['effects']['welcome']['message']       = sanitize_textarea_field( $effects['welcome']['message'] ?? '欢迎来到我的网站，祝你今天开心。' );
            $output['effects']['welcome']['once_per_day']  = empty( $effects['welcome']['once_per_day'] ) ? 0 : 1;

            return $output;
        }

        private function sanitize_int( $value, $min, $max, $fallback ) {
            $value = is_numeric( $value ) ? (int) $value : (int) $fallback;
            return max( $min, min( $max, $value ) );
        }

        private function sanitize_float( $value, $min, $max, $fallback ) {
            $value = is_numeric( $value ) ? (float) $value : (float) $fallback;
            return max( $min, min( $max, $value ) );
        }

        private function sanitize_custom_css( $css ) {
            $css = is_string( $css ) ? $css : '';
            $css = wp_strip_all_tags( $css );
            return trim( $css );
        }

        private function sanitize_custom_js( $js ) {
            $js = is_string( $js ) ? $js : '';
            // 防止自定义代码提前闭合插件生成的 script 标签，同时不破坏普通 JavaScript 语法。
            $js = preg_replace( '#</script#i', '<\/script', $js );
            return trim( $js );
        }

        private function sanitize_custom_items( $text ) {
            $text  = is_string( $text ) ? $text : '';
            $lines = preg_split( '/\r\n|\r|\n/', $text );
            $clean = array();
            foreach ( $lines as $line ) {
                $line = trim( $line );
                if ( '' === $line || false === strpos( $line, '|' ) ) {
                    continue;
                }
                list( $label, $url ) = array_map( 'trim', explode( '|', $line, 2 ) );
                $label = sanitize_text_field( $label );
                if ( in_array( $url, array( '#top', '#refresh', '#back', '#copy' ), true ) ) {
                    $clean[] = $label . '|' . $url;
                } else {
                    $clean[] = $label . '|' . esc_url_raw( $url );
                }
            }
            return implode( "\n", array_slice( $clean, 0, 12 ) );
        }

        public function enqueue_admin_assets( $hook ) {
            $allowed_hooks = array(
                'toplevel_page_' . self::MENU_SLUG,
                'settings_page_' . self::MENU_SLUG,
                defined( 'JLWA_MENU_SLUG' ) ? JLWA_MENU_SLUG . '_page_' . self::MENU_SLUG : '',
            );
            if ( ! in_array( $hook, $allowed_hooks, true ) ) {
                return;
            }

            wp_enqueue_style( 'xjpe-admin', plugins_url( 'assets/css/admin.css', __FILE__ ), array(), self::VERSION );
            wp_enqueue_script( 'xjpe-admin', plugins_url( 'assets/js/admin.js', __FILE__ ), array(), self::VERSION, true );
        }

        private function protection_options() {
            $options = $this->get_options();
            if ( empty( $options['effects']['nosource']['enabled'] ) ) {
                return array();
            }
            return isset( $options['effects']['nosource'] ) && is_array( $options['effects']['nosource'] ) ? $options['effects']['nosource'] : array();
        }

        private function is_protected_post_type( $post_type, $protection ) {
            if ( 'post' === $post_type ) {
                return ! empty( $protection['server_posts'] );
            }
            if ( 'page' === $post_type ) {
                return ! empty( $protection['server_pages'] );
            }
            return false;
        }

        private function current_user_can_read_protected( $protection ) {
            $capability = isset( $protection['server_capability'] ) ? $protection['server_capability'] : 'read';
            return is_user_logged_in() && current_user_can( $capability );
        }

        private function protected_notice_html( $protection ) {
            $message = isset( $protection['server_message'] ) ? $protection['server_message'] : '完整内容仅向已授权用户开放，请登录后继续阅读。';
            $html = '<div class="xjpe-server-protected" role="note"><strong>🔒 ' . esc_html__( '内容受保护', 'jiuliu-wp-assistant' ) . '</strong><p>' . esc_html( $message ) . '</p>';
            if ( ! is_user_logged_in() ) {
                $html .= '<a class="xjpe-server-login" href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( '登录后继续阅读', 'jiuliu-wp-assistant' ) . '</a>';
            }
            $html .= '</div>';
            return $html;
        }

        public function filter_protected_content( $content ) {
            if ( is_admin() || wp_doing_ajax() || ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
                return $content;
            }
            $protection = $this->protection_options();
            if ( empty( $protection ) || 'restricted' !== ( $protection['server_mode'] ?? 'public' ) ) {
                return $content;
            }
            $post_type = get_post_type();
            if ( ! $this->is_protected_post_type( $post_type, $protection ) || $this->current_user_can_read_protected( $protection ) ) {
                return $content;
            }
            $teaser_words = isset( $protection['server_teaser_words'] ) ? (int) $protection['server_teaser_words'] : 60;
            $teaser = $teaser_words > 0 ? '<div class="xjpe-server-teaser">' . esc_html( wp_trim_words( wp_strip_all_tags( $content ), $teaser_words, '…' ) ) . '</div>' : '';
            return $teaser . $this->protected_notice_html( $protection );
        }

        public function filter_protected_feed( $content ) {
            $protection = $this->protection_options();
            if ( empty( $protection ) || empty( $protection['server_hide_feed'] ) || $this->current_user_can_read_protected( $protection ) ) {
                return $content;
            }
            $post_type = get_post_type();
            if ( ! $this->is_protected_post_type( $post_type, $protection ) ) {
                return $content;
            }
            return isset( $protection['server_message'] ) ? $protection['server_message'] : '完整内容仅向已授权用户开放。';
        }

        public function filter_protected_rest_response( $response, $post, $request ) {
            $protection = $this->protection_options();
            if ( empty( $protection ) || empty( $protection['server_hide_rest'] ) || ! $post instanceof WP_Post || $this->current_user_can_read_protected( $protection ) ) {
                return $response;
            }
            if ( ! $this->is_protected_post_type( $post->post_type, $protection ) || ! is_object( $response ) || ! method_exists( $response, 'get_data' ) ) {
                return $response;
            }
            $data = $response->get_data();
            $message = isset( $protection['server_message'] ) ? $protection['server_message'] : '完整内容仅向已授权用户开放。';
            if ( isset( $data['content'] ) && is_array( $data['content'] ) ) {
                $data['content']['rendered'] = '<p>' . esc_html( $message ) . '</p>';
                if ( isset( $data['content']['raw'] ) ) {
                    $data['content']['raw'] = '';
                }
            }
            if ( isset( $data['excerpt'] ) && is_array( $data['excerpt'] ) ) {
                $data['excerpt']['rendered'] = '<p>' . esc_html( $message ) . '</p>';
                if ( isset( $data['excerpt']['raw'] ) ) {
                    $data['excerpt']['raw'] = '';
                }
            }
            $response->set_data( $data );
            return $response;
        }

        public function filter_protected_robots( $robots ) {
            if ( is_admin() || ! is_singular() ) {
                return $robots;
            }
            $protection = $this->protection_options();
            if ( empty( $protection ) || empty( $protection['server_noindex'] ) || 'restricted' !== ( $protection['server_mode'] ?? 'public' ) || $this->current_user_can_read_protected( $protection ) ) {
                return $robots;
            }
            if ( ! $this->is_protected_post_type( get_post_type(), $protection ) ) {
                return $robots;
            }
            $robots['noindex'] = true;
            $robots['nofollow'] = true;
            return $robots;
        }

        public function enqueue_frontend_assets() {
            $options = $this->get_options();
            if ( ! $this->should_load_frontend( $options ) ) {
                return;
            }

            $mode = $options['compat']['injection_mode'] ?? 'enqueue';
            if ( 'buffer' === $mode ) {
                return;
            }

            if ( 'head_footer' === $mode ) {
                $hook = ( isset( $options['compat']['load_location'] ) && 'footer' === $options['compat']['load_location'] ) ? 'wp_footer' : 'wp_head';
                add_action( $hook, array( $this, 'output_direct_assets' ), 99 );
                return;
            }

            $config = $this->frontend_config( $options );
            wp_enqueue_style( 'xjpe-frontend', plugins_url( 'assets/css/frontend.css', __FILE__ ), array(), self::VERSION );

            $in_footer = isset( $options['compat']['load_location'] ) && 'footer' === $options['compat']['load_location'];
            wp_enqueue_script( 'xjpe-frontend', plugins_url( 'assets/js/frontend.js', __FILE__ ), array(), self::VERSION, $in_footer );
            wp_add_inline_script( 'xjpe-frontend', 'window.XJPE_CONFIG=' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
            if ( ! empty( $options['global']['custom_js'] ) ) {
                wp_add_inline_script( 'xjpe-frontend', $this->custom_js_wrapper( $options['global']['custom_js'] ), 'after' );
            }
        }

        private function should_load_frontend( $options ) {
            if ( is_admin() || wp_doing_ajax() ) {
                return false;
            }
            if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) {
                return false;
            }
            if ( $this->is_preview_request() ) {
                return true;
            }
            if ( empty( $options['global']['enabled'] ) ) {
                return false;
            }
            return $this->has_enabled_effect_or_code( $options );
        }

        public function output_direct_assets() {
            $options = $this->get_options();
            if ( ! $this->should_load_frontend( $options ) ) {
                return;
            }
            echo $this->direct_assets_html( $options ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }

        public function maybe_start_buffer_injection() {
            if ( is_admin() || wp_doing_ajax() || is_feed() || is_robots() || is_trackback() ) {
                return;
            }
            $options = $this->get_options();
            if ( ( $options['compat']['injection_mode'] ?? 'enqueue' ) !== 'buffer' ) {
                return;
            }
            if ( ! $this->should_load_frontend( $options ) ) {
                return;
            }
            ob_start( array( $this, 'inject_assets_into_html' ) );
        }

        public function inject_assets_into_html( $html ) {
            if ( ! is_string( $html ) || '' === $html || false !== strpos( $html, 'id="xjpe-frontend-js"' ) ) {
                return $html;
            }
            if ( false === stripos( $html, '<html' ) && false === stripos( $html, '<!doctype' ) ) {
                return $html;
            }

            $options  = $this->get_options();
            $assets   = $this->direct_assets_html( $options );
            $location = isset( $options['compat']['load_location'] ) ? $options['compat']['load_location'] : 'head';

            if ( 'footer' === $location && false !== stripos( $html, '</body>' ) ) {
                return preg_replace( '/<\/body>/i', $assets . "\n</body>", $html, 1 );
            }
            if ( false !== stripos( $html, '</head>' ) ) {
                return preg_replace( '/<\/head>/i', $assets . "\n</head>", $html, 1 );
            }
            if ( false !== stripos( $html, '</body>' ) ) {
                return preg_replace( '/<\/body>/i', $assets . "\n</body>", $html, 1 );
            }
            return $html . $assets;
        }

        private function direct_assets_html( $options ) {
            $config_json = wp_json_encode( $this->frontend_config( $options ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
            $css_url     = plugins_url( 'assets/css/frontend.css', __FILE__ );
            $js_url      = plugins_url( 'assets/js/frontend.js', __FILE__ );
            $html        = "\n<!-- 九流页面美化特效 v" . esc_html( self::VERSION ) . " -->\n";
            $html       .= '<link rel="stylesheet" id="xjpe-frontend-css" href="' . esc_url( add_query_arg( 'ver', self::VERSION, $css_url ) ) . '" media="all">' . "\n";
            $html       .= '<script id="xjpe-config-js">window.XJPE_CONFIG=' . $config_json . ';</script>' . "\n";
            $html       .= '<script id="xjpe-frontend-js" src="' . esc_url( add_query_arg( 'ver', self::VERSION, $js_url ) ) . '" defer></script>' . "\n";
            if ( ! empty( $options['global']['custom_js'] ) ) {
                $html .= '<script id="xjpe-custom-js">' . $this->custom_js_wrapper( $options['global']['custom_js'] ) . '</script>' . "\n";
            }
            return $html;
        }

        private function custom_js_wrapper( $js ) {
            return "(function(run){var execute=function(){if(window.XJPE_DISABLED){return;}run();};if(window.XJPE_READY){execute();}else{document.addEventListener('xjpe:ready',execute,{once:true});}})(function(){try{\n" . $js . "\n}catch(e){console.warn('XJPE custom JS error:',e);}});";
        }

        private function has_enabled_effect_or_code( $options ) {
            foreach ( $options['effects'] as $effect ) {
                if ( ! empty( $effect['enabled'] ) ) {
                    return true;
                }
            }
            return ! empty( $options['global']['custom_css'] ) || ! empty( $options['global']['custom_js'] );
        }

        private function frontend_config( $options ) {
            if ( $this->is_preview_request() ) {
                $options['global']['enabled'] = 1;
                $options['global']['mobile_enabled'] = 1;
                $options['global']['respect_reduce_motion'] = 0;
                foreach ( array( 'sakura', 'snow', 'leaves', 'bubbles', 'lantern', 'cursor', 'waves', 'welcome' ) as $preview_effect ) {
                    if ( isset( $options['effects'][ $preview_effect ] ) ) {
                        $options['effects'][ $preview_effect ]['enabled'] = 1;
                    }
                }
                $options['effects']['welcome']['once_per_day'] = 0;
                $options['effects']['welcome']['title'] = '页面美化预览';
                $options['effects']['welcome']['message'] = '如果你看到樱花、雪花、灯笼或这个弹窗，说明前台注入链路正常。';
            }

            $config = array(
                'version' => self::VERSION,
                'global'  => array(
                    'zIndex'              => (int) $options['global']['z_index'],
                    'respectReduceMotion' => ! empty( $options['global']['respect_reduce_motion'] ),
                    'mobileEnabled'       => ! empty( $options['global']['mobile_enabled'] ),
                    'customCss'           => (string) $options['global']['custom_css'],
                    'homeUrl'             => home_url( '/' ),
                    'preview'             => $this->is_preview_request(),
                    'isAdmin'             => is_user_logged_in() && current_user_can( 'manage_options' ),
                    'siteName'            => get_bloginfo( 'name' ),
                ),
                'compat'  => $options['compat'],
                'effects' => $options['effects'],
            );
            return $config;
        }


        private function enabled_effect_names( $options, $defs ) {
            $names = array();
            foreach ( $defs as $key => $def ) {
                if ( ! empty( $options['effects'][ $key ]['enabled'] ) ) {
                    $names[] = $def['title'];
                }
            }
            return $names ? implode( '、', $names ) : '暂无，请先勾选特效并保存';
        }

        private function is_preview_request() {
            if ( ! isset( $_GET['xjpe_preview'], $_GET['_xjpe_nonce'] ) || '1' !== (string) $_GET['xjpe_preview'] ) {
                return false;
            }
            if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
                return false;
            }
            return (bool) wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_xjpe_nonce'] ) ), 'xjpe_preview' );
        }

        private function preview_url() {
            return add_query_arg(
                array(
                    'xjpe_preview' => '1',
                    '_xjpe_nonce'  => wp_create_nonce( 'xjpe_preview' ),
                ),
                home_url( '/' )
            );
        }

        public function render_admin_page() {
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }

            $options = $this->get_options();
            $defs    = self::effect_definitions();
            $preview_url = $this->preview_url();
            $tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'basic'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $tabs    = array(
                'basic'       => '基础设置',
                'effects'     => '特效管理',
                'custom-code' => '自定义代码',
                'diagnostics' => '前台诊断',
            );
            if ( ! isset( $tabs[ $tab ] ) ) {
                $tab = 'basic';
            }
            ?>
            <div class="wrap xjpe-wrap">
                <div class="jiuliu-admin-header">
                    <div>
                        <h1><span class="dashicons dashicons-admin-customizer"></span>九流页面美化</h1>
                        <p class="jiuliu-admin-subtitle">独立后台控制台：勾选需要的特效，保存后前台立即加载；不修改主题文件和文章内容。</p>
                    </div>
                    <span class="jiuliu-version-badge">v<?php echo esc_html( self::VERSION ); ?></span>
                </div>
                <?php if ( isset( $_GET['xjpe_saved'] ) && '1' === $_GET['xjpe_saved'] ) : ?>
                    <div class="notice notice-success inline"><p><strong>配置已保存。</strong> 请刷新前台页面，或点击“打开前台预览”测试特效。</p></div>
                <?php endif; ?>
                <div id="xjpe-context-conflict" class="notice notice-info inline" <?php echo ( ! empty( $options['effects']['contextmenu']['enabled'] ) && ! empty( $options['effects']['nosource']['enabled'] ) ) ? '' : 'hidden'; ?>><p><strong>协同模式：</strong>同时启用时保留美化右键菜单；内容保护仍会处理快捷键、复制、拖拽和打印规则。</p></div>

                <h2 class="nav-tab-wrapper xjpe-tabs">
                    <?php foreach ( $tabs as $key => $label ) : ?>
                        <a class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=' . $key ) ); ?>"><?php echo esc_html( $label ); ?></a>
                    <?php endforeach; ?>
                </h2>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>" class="xjpe-form" id="xjpe-settings-form">
                    <input type="hidden" name="xjpe_direct_save" value="1">
                    <input type="hidden" name="action" value="xjpe_save_options">
                    <?php wp_nonce_field( 'xjpe_save_options', 'xjpe_nonce' ); ?>

                    <div class="xjpe-savebar">
                        <button type="submit" class="button button-primary button-hero">保存美化配置</button>
                        <a class="button button-hero" href="<?php echo esc_url( $preview_url ); ?>" target="_blank" rel="noopener">不保存，直接测试前台特效</a>
                        <span class="xjpe-savebar-note">保存后页面会返回并显示“配置已保存”，不会悄悄无反应。</span>
                    </div>

                    <div class="xjpe-tab-panel <?php echo 'basic' === $tab ? 'is-active' : ''; ?>">
                        <section class="xjpe-panel xjpe-global-panel">
                            <div>
                                <h2>基础设置</h2>
                                <p>控制插件是否加载、移动端策略和全站层级。</p>
                            </div>
                            <div class="xjpe-global-grid">
                                <?php $this->render_checkbox( 'global', 'enabled', '启用插件总开关', $options['global']['enabled'] ); ?>
                                <?php $this->render_checkbox( 'global', 'mobile_enabled', '手机端也启用', $options['global']['mobile_enabled'] ); ?>
                                <?php $this->render_checkbox( 'global', 'respect_reduce_motion', '尊重系统减少动态效果', $options['global']['respect_reduce_motion'] ); ?>
                                <label class="xjpe-field">
                                    <span>层级 z-index</span>
                                    <input type="number" min="1000" max="2147483000" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[global][z_index]" value="<?php echo esc_attr( $options['global']['z_index'] ); ?>">
                                </label>
                            </div>
                        </section>

                        <section class="xjpe-panel">
                            <h2>主题兼容设置</h2>
                            <p>不插入文章正文，而是使用独立的全站覆盖层，适配 Zibll、Astra、Divi、Elementor、FSE 等主题。</p>
                            <div class="xjpe-global-grid">
                                <label class="xjpe-field">
                                    <span>注入模式</span>
                                    <select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[compat][injection_mode]">
                                        <option value="enqueue" <?php selected( $options['compat']['injection_mode'], 'enqueue' ); ?>>标准模式：WordPress 队列加载，推荐正常主题</option>
                                        <option value="head_footer" <?php selected( $options['compat']['injection_mode'], 'head_footer' ); ?>>强制钩子模式：直接输出资源，适合魔改主题</option>
                                        <option value="buffer" <?php selected( $options['compat']['injection_mode'], 'buffer' ); ?>>终极兼容模式：HTML 缓冲注入，适合缺失 wp_head/wp_footer 的主题</option>
                                    </select>
                                </label>
                                <label class="xjpe-field">
                                    <span>前台 JS 加载位置</span>
                                    <select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[compat][load_location]">
                                        <option value="head" <?php selected( $options['compat']['load_location'], 'head' ); ?>>头部加载：兼容优先</option>
                                        <option value="footer" <?php selected( $options['compat']['load_location'], 'footer' ); ?>>页脚加载：性能优先</option>
                                    </select>
                                </label>
                                <?php $this->render_checkbox( 'compat', 'body_wait', '等待 DOM 完成后再创建特效层', $options['compat']['body_wait'] ); ?>
                                <?php $this->render_checkbox( 'compat', 'safe_mode', '安全模式：特效层默认不拦截鼠标点击', $options['compat']['safe_mode'] ); ?>
                            </div>
                            <p class="xjpe-tip">如果标准模式无效，改成“强制钩子模式”，再不行改成“终极兼容模式”。</p>
                        </section>
                    </div>

                    <div class="xjpe-tab-panel <?php echo 'effects' === $tab ? 'is-active' : ''; ?>">
                        <div class="xjpe-toolbar">
                            <button type="button" class="button" data-xjpe-enable-all>全部启用</button>
                            <button type="button" class="button" data-xjpe-disable-all>全部关闭</button>
                            <a class="button" href="<?php echo esc_url( $preview_url ); ?>" target="_blank" rel="noopener">打开前台预览</a>
                        </div>

                        <section class="xjpe-effects-grid">
                            <?php foreach ( $defs as $key => $def ) : ?>
                                <?php $effect = $options['effects'][ $key ]; ?>
                                <article class="xjpe-card <?php echo ! empty( $effect['enabled'] ) ? 'is-enabled' : ''; ?>" data-xjpe-card>
                                    <label class="xjpe-card-head">
                                        <input type="checkbox" class="xjpe-toggle" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[effects][<?php echo esc_attr( $key ); ?>][enabled]" value="1" <?php checked( ! empty( $effect['enabled'] ) ); ?>>
                                        <span class="xjpe-icon"><?php echo esc_html( $def['icon'] ); ?></span>
                                        <span class="xjpe-card-text">
                                            <strong><?php echo esc_html( $def['title'] ); ?></strong>
                                            <small><?php echo esc_html( $def['desc'] ); ?></small>
                                            <em class="xjpe-status"><?php echo ! empty( $effect['enabled'] ) ? '● 已启用' : '○ 未启用'; ?></em>
                                        </span>
                                    </label>
                                    <div class="xjpe-card-body">
                                        <?php $this->render_effect_fields( $key, $effect ); ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </section>
                    </div>

                    <div class="xjpe-tab-panel <?php echo 'custom-code' === $tab ? 'is-active' : ''; ?>">
                        <section class="xjpe-panel">
                            <h2>自定义代码注入</h2>
                            <p>只有管理员能填写。用于补充你自己的全站 CSS / JS；不填写就不加载。</p>
                            <div class="xjpe-code-grid">
                                <label class="xjpe-field xjpe-code-field">
                                    <span>自定义 CSS</span>
                                    <textarea name="<?php echo esc_attr( self::OPTION_NAME ); ?>[global][custom_css]" rows="8" spellcheck="false" placeholder="body { } ..."><?php echo esc_textarea( $options['global']['custom_css'] ); ?></textarea>
                                </label>
                                <label class="xjpe-field xjpe-code-field">
                                    <span>自定义 JS</span>
                                    <textarea name="<?php echo esc_attr( self::OPTION_NAME ); ?>[global][custom_js]" rows="8" spellcheck="false" placeholder="console.log('hello');"><?php echo esc_textarea( $options['global']['custom_js'] ); ?></textarea>
                                </label>
                            </div>
                        </section>
                    </div>

                    <div class="xjpe-tab-panel <?php echo 'diagnostics' === $tab ? 'is-active' : ''; ?>">
                        <section class="xjpe-panel">
                            <h2>前台诊断</h2>
                            <p>保存后打开前台源代码，搜索 <code>九流页面美化特效</code> 或 <code>xjpe-frontend-js</code>。能搜到说明插件已经注入；看不到则把注入模式改成“终极兼容模式”。</p>
                            <p class="xjpe-tip">当前注入模式：<strong><?php echo esc_html( $options['compat']['injection_mode'] ); ?></strong>；当前已启用特效：<strong><?php echo esc_html( $this->enabled_effect_names( $options, $defs ) ); ?></strong></p>
                        </section>
                    </div>

                    <?php submit_button( '保存美化配置' ); ?>
                </form>
            </div>
            <?php
        }

        private function render_checkbox( $section, $key, $label, $checked ) {
            ?>
            <label class="xjpe-switch-line">
                <input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[<?php echo esc_attr( $section ); ?>][<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $checked ) ); ?>>
                <span><?php echo esc_html( $label ); ?></span>
            </label>
            <?php
        }

        private function render_effect_fields( $key, $effect ) {
            $base = self::OPTION_NAME . '[effects][' . $key . ']';
            switch ( $key ) {
                case 'sakura':
                case 'snow':
                case 'leaves':
                case 'bubbles':
                    $this->number_field( $base, 'count', '数量（电脑端最多 360）', $effect['count'], 1, 360, 1 );
                    $this->number_field( $base, 'size', '大小', $effect['size'], 4, 72, 1 );
                    $this->number_field( $base, 'speed', '下落/上升速度', $effect['speed'], 0.1, 5, 0.1 );
                    $this->number_field( $base, 'opacity', '透明度', $effect['opacity'], 0.05, 1, 0.05 );
                    $this->number_field( $base, 'wind', '横向风力（可为负数）', $effect['wind'], -3, 3, 0.05 );
                    $this->number_field( $base, 'sway', '摆动幅度', $effect['sway'], 0, 4, 0.1 );
                    echo '<p class="xjpe-tip">移动端、减少动态效果或省流量模式会自动降低数量，避免动画拖慢页面。</p>';
                    break;
                case 'lantern':
                    $this->number_field( $base, 'size', '灯笼大小', $effect['size'], 36, 180, 1 );
                    $this->number_field( $base, 'quantity', '灯笼数量', $effect['quantity'], 1, 6, 1 );
                    $this->text_field( $base, 'text', '灯笼文字', $effect['text'], '福' );
                    break;
                case 'particles':
                    $this->number_field( $base, 'count', '粒子数量', $effect['count'], 8, 200, 1 );
                    $this->number_field( $base, 'speed', '移动速度', $effect['speed'], 0.05, 4, 0.05 );
                    $this->number_field( $base, 'opacity', '透明度', $effect['opacity'], 0.05, 1, 0.05 );
                    $this->number_field( $base, 'line_distance', '连线距离', $effect['line_distance'], 40, 300, 1 );
                    break;
                case 'cursor':
                    $this->select_field( $base, 'preset', '拖尾样式', $effect['preset'], array( 'star' => '星星', 'heart' => '爱心', 'firefly' => '萤火虫', 'petal' => '花瓣', 'bubble' => '气泡', 'custom' => '自定义符号' ) );
                    $this->text_field( $base, 'symbol', '自定义符号', $effect['symbol'], '✦' );
                    $this->select_field( $base, 'color_mode', '颜色模式', $effect['color_mode'], array( 'rainbow' => '随机彩虹', 'fixed' => '固定颜色' ) );
                    $this->color_field( $base, 'color', '固定颜色', $effect['color'] );
                    $this->number_field( $base, 'size', '拖尾大小', $effect['size'], 4, 48, 1 );
                    $this->number_field( $base, 'density', '拖尾密度', $effect['density'], 1, 6, 1 );
                    $this->number_field( $base, 'duration', '消散时间（毫秒）', $effect['duration'], 240, 2400, 20 );
                    break;
                case 'waves':
                    $this->number_field( $base, 'height', '波浪高度', $effect['height'], 24, 220, 1 );
                    $this->number_field( $base, 'opacity', '透明度', $effect['opacity'], 0.05, 1, 0.05 );
                    $this->number_field( $base, 'speed', '流动周期（秒）', $effect['speed'], 4, 40, 1 );
                    $this->color_field( $base, 'color_1', '前层颜色', $effect['color_1'] );
                    $this->color_field( $base, 'color_2', '后层颜色', $effect['color_2'] );
                    break;
                case 'ribbon':
                    $this->number_field( $base, 'opacity', '透明度', $effect['opacity'], 0.05, 1, 0.05 );
                    $this->inline_checkbox( $base, 'click', '点击页面时刷新彩带', $effect['click'] );
                    break;
                case 'grayscale':
                    $this->number_field( $base, 'percent', '灰度强度 %', $effect['percent'], 1, 100, 1 );
                    break;
                case 'contextmenu':
                    $this->text_field( $base, 'title', '菜单标题', $effect['title'], '九流网站菜单' );
                    $this->inline_checkbox( $base, 'show_copy', '显示复制链接', $effect['show_copy'] );
                    $this->inline_checkbox( $base, 'show_refresh', '显示刷新页面', $effect['show_refresh'] );
                    $this->inline_checkbox( $base, 'show_top', '显示返回顶部', $effect['show_top'] );
                    $this->inline_checkbox( $base, 'show_back', '显示返回上一页', $effect['show_back'] );
                    $this->textarea_field( $base, 'custom_items', '自定义菜单项，一行一个：名称|链接。特殊链接支持 #top、#refresh、#back、#copy', $effect['custom_items'], 4 );
                    break;
                case 'nosource':
                    $this->text_field( $base, 'message', '拦截提示文字', $effect['message'], '本站已开启内容保护，请尊重原创。' );
                    $this->inline_checkbox( $base, 'admin_bypass', '管理员登录时自动绕过保护', $effect['admin_bypass'] );
                    $this->inline_checkbox( $base, 'block_contextmenu', '禁用浏览器原生右键菜单（启用右键美化时自动保留美化菜单）', $effect['block_contextmenu'] );
                    $this->inline_checkbox( $base, 'block_shortcuts', '拦截页面内的 F12、Ctrl/Cmd+U、Ctrl/Cmd+S 与常见开发者快捷键', $effect['block_shortcuts'] );
                    $this->inline_checkbox( $base, 'block_copy', '完全禁止 Ctrl+C 与复制事件', $effect['block_copy'] );
                    $this->inline_checkbox( $base, 'block_selection', '禁止正文文字选择（表单、代码块除外）', $effect['block_selection'] );
                    $this->inline_checkbox( $base, 'block_drag', '禁止拖拽图片和文本', $effect['block_drag'] );
                    $this->inline_checkbox( $base, 'block_print', '拦截 Ctrl+P 打印快捷键', $effect['block_print'] );
                    $this->select_field( $base, 'copy_mode', '允许复制时版权插入位置', $effect['copy_mode'], array( 'none' => '不附加内容', 'prepend' => '复制内容前方', 'append' => '复制内容后方', 'both' => '前后都附加' ) );
                    $this->textarea_field( $base, 'copy_prefix', '复制内容前缀', $effect['copy_prefix'], 3 );
                    $this->textarea_field( $base, 'copy_suffix', '复制内容后缀', $effect['copy_suffix'], 4 );
                    $this->number_field( $base, 'copy_min_chars', '至少选择多少字符才附加版权', $effect['copy_min_chars'], 0, 1000, 1 );
                    $this->inline_checkbox( $base, 'copy_include_link', '自动加入当前文章链接，并为 HTML 剪贴板创建可点击链接', $effect['copy_include_link'] );
                    $this->inline_checkbox( $base, 'copy_success_toast', '复制后显示版权提醒弹窗', $effect['copy_success_toast'] );
                    $this->text_field( $base, 'copy_toast_message', '复制成功提示', $effect['copy_toast_message'], '复制成功，请保留文章版权与来源链接。' );
                    echo '<div class="xjpe-tip"><strong>服务器级正文保护</strong><br>只有“限制全文访问”能让未授权访客的页面源码中不出现完整正文。公开网页无法真正禁止浏览器菜单中的开发者工具，也无法阻止手动输入 <code>view-source:</code>。</div>';
                    $this->select_field( $base, 'server_mode', '正文访问模式', $effect['server_mode'], array( 'public' => '公开展示（快捷键、复制和版权提醒）', 'restricted' => '限制全文访问（服务器仅向授权用户发送正文）' ) );
                    $this->select_field( $base, 'server_capability', '允许查看全文的用户', $effect['server_capability'], array( 'read' => '所有已登录用户', 'edit_posts' => '作者、编辑和管理员', 'manage_options' => '仅管理员' ) );
                    $this->inline_checkbox( $base, 'server_posts', '保护文章正文', $effect['server_posts'] );
                    $this->inline_checkbox( $base, 'server_pages', '保护独立页面正文', $effect['server_pages'] );
                    $this->number_field( $base, 'server_teaser_words', '未授权访客可见的摘要字数（0 为不显示摘要）', $effect['server_teaser_words'], 0, 300, 5 );
                    $this->text_field( $base, 'server_message', '服务器保护提示', $effect['server_message'], '完整内容仅向已授权用户开放，请登录后继续阅读。' );
                    $this->inline_checkbox( $base, 'server_hide_rest', '从 WordPress REST API 隐藏受保护正文', $effect['server_hide_rest'] );
                    $this->inline_checkbox( $base, 'server_hide_feed', '从 RSS/Atom 订阅隐藏受保护正文', $effect['server_hide_feed'] );
                    $this->inline_checkbox( $base, 'server_noindex', '未授权访问时输出 noindex/nofollow', $effect['server_noindex'] );
                    echo '<p class="xjpe-tip"><strong>边界说明：</strong>已获授权并能看到正文的用户，仍可通过开发者工具、截图或网络请求取得内容。公开内容只能提高转载成本，不能做到绝对防复制。</p>';
                    break;
                case 'bgmusic':
                    $this->url_field( $base, 'url', '音乐文件 URL', $effect['url'], 'https://example.com/music.mp3' );
                    $this->text_field( $base, 'title', '音乐标题', $effect['title'], '背景音乐' );
                    $this->number_field( $base, 'volume', '音量', $effect['volume'], 0, 1, 0.05 );
                    $this->inline_checkbox( $base, 'loop', '循环播放', $effect['loop'] );
                    $this->inline_checkbox( $base, 'autoplay', '首次点击页面后自动播放', $effect['autoplay'] );
                    break;
                case 'welcome':
                    $this->inline_checkbox( $base, 'auto_festival', '自动识别常见公历节日', $effect['auto_festival'] );
                    $this->text_field( $base, 'title', '默认标题', $effect['title'], '欢迎访问' );
                    $this->textarea_field( $base, 'message', '默认文案', $effect['message'], 4 );
                    $this->inline_checkbox( $base, 'once_per_day', '同一访客每天只显示一次', $effect['once_per_day'] );
                    break;
            }
        }

        private function number_field( $base, $key, $label, $value, $min, $max, $step ) {
            ?>
            <label class="xjpe-field">
                <span><?php echo esc_html( $label ); ?></span>
                <input type="number" min="<?php echo esc_attr( $min ); ?>" max="<?php echo esc_attr( $max ); ?>" step="<?php echo esc_attr( $step ); ?>" name="<?php echo esc_attr( $base . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( $value ); ?>">
            </label>
            <?php
        }

        private function text_field( $base, $key, $label, $value, $placeholder = '' ) {
            ?>
            <label class="xjpe-field">
                <span><?php echo esc_html( $label ); ?></span>
                <input type="text" name="<?php echo esc_attr( $base . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>">
            </label>
            <?php
        }

        private function url_field( $base, $key, $label, $value, $placeholder = '' ) {
            ?>
            <label class="xjpe-field">
                <span><?php echo esc_html( $label ); ?></span>
                <input type="url" name="<?php echo esc_attr( $base . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>">
            </label>
            <?php
        }

        private function textarea_field( $base, $key, $label, $value, $rows = 4 ) {
            ?>
            <label class="xjpe-field xjpe-wide-field">
                <span><?php echo esc_html( $label ); ?></span>
                <textarea name="<?php echo esc_attr( $base . '[' . $key . ']' ); ?>" rows="<?php echo esc_attr( $rows ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
            </label>
            <?php
        }


        private function select_field( $base, $key, $label, $value, $options ) {
            ?>
            <label class="xjpe-field">
                <span><?php echo esc_html( $label ); ?></span>
                <select name="<?php echo esc_attr( $base . '[' . $key . ']' ); ?>">
                    <?php foreach ( $options as $option_value => $option_label ) : ?>
                        <option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( $value, $option_value ); ?>><?php echo esc_html( $option_label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php
        }

        private function color_field( $base, $key, $label, $value ) {
            ?>
            <label class="xjpe-field">
                <span><?php echo esc_html( $label ); ?></span>
                <input type="color" name="<?php echo esc_attr( $base . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( $value ); ?>">
            </label>
            <?php
        }

        private function inline_checkbox( $base, $key, $label, $checked ) {
            ?>
            <label class="xjpe-inline-check">
                <input type="checkbox" name="<?php echo esc_attr( $base . '[' . $key . ']' ); ?>" value="1" <?php checked( ! empty( $checked ) ); ?>>
                <span><?php echo esc_html( $label ); ?></span>
            </label>
            <?php
        }
    }

    JLWA_Page_Effects_Feature::instance();
}
