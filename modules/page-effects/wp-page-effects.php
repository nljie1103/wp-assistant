<?php
/**
 * Plugin Name: 九流页面美化特效
 * Plugin URI: https://github.com/nljie1103/wp-page-effects
 * Description: 独立后台页面一键勾选樱花、雪花、灯笼、粒子、鼠标跟随、彩带、灰色模式、右键菜单、基础防查看、背景音乐和节日欢迎弹窗，支持兼容注入模式。
 * Version: 1.5.1
 * Author: 九流
 * Author URI: https://www.jiuliu.org
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: xiaojie-page-effects
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'XJPE_Plugin' ) ) {
    final class XJPE_Plugin {
        const VERSION     = '1.5.1';
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
            add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
            add_action( 'admin_init', array( $this, 'register_settings' ) );
            add_action( 'admin_init', array( $this, 'maybe_handle_direct_save' ) );
            add_action( 'admin_post_xjpe_save_options', array( $this, 'handle_save_options' ) );
            add_action( 'admin_notices', array( $this, 'activation_notice' ) );
            add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
            add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
            register_activation_hook( __FILE__, array( __CLASS__, 'activate' ) );
            add_action( 'template_redirect', array( $this, 'maybe_start_buffer_injection' ), 0 );

        }

        public static function activate() {
            if ( false === get_option( self::OPTION_NAME, false ) ) {
                add_option( self::OPTION_NAME, self::default_options(), '', false );
            }
            set_transient( 'xjpe_activated_notice', 1, 60 );
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

        public function activation_notice() {
            if ( ! current_user_can( 'manage_options' ) || ! get_transient( 'xjpe_activated_notice' ) ) {
                return;
            }
            delete_transient( 'xjpe_activated_notice' );
            $url = admin_url( 'admin.php?page=' . self::MENU_SLUG );
            echo '<div class="notice notice-success is-dismissible"><p><strong>九流页面美化已启用。</strong> 请进入左侧菜单 <a href="' . esc_url( $url ) . '">页面美化</a> 勾选需要的特效并保存。</p></div>';
        }

        public function plugin_action_links( $links ) {
            $settings_url = admin_url( 'admin.php?page=' . self::MENU_SLUG );
            array_unshift( $links, '<a href="' . esc_url( $settings_url ) . '">特效设置</a>' );
            return $links;
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
                    'injection_mode' => 'buffer',
                    'body_wait'     => 1,
                    'safe_mode'     => 1,
                ),
                'effects' => array(
                    'sakura' => array(
                        'enabled' => 0,
                        'count'   => 28,
                        'size'    => 18,
                        'speed'   => 1.0,
                        'opacity' => 0.85,
                    ),
                    'snow' => array(
                        'enabled' => 0,
                        'count'   => 48,
                        'size'    => 13,
                        'speed'   => 1.0,
                        'opacity' => 0.9,
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
                        'enabled' => 0,
                        'size'    => 13,
                        'density' => 1,
                        'symbol'  => '✦',
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
                        'custom_items' => "首页|/\n刷新页面|#refresh\n返回顶部|#top",
                    ),
                    'nosource' => array(
                        'enabled' => 0,
                        'message' => '本站已开启基础防复制保护。',
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
                'sakura' => array( 'icon' => '🌸', 'title' => '全屏樱花', 'desc' => '飘落的樱花瓣特效', 'group' => '氛围特效' ),
                'snow' => array( 'icon' => '❄️', 'title' => '全屏雪花', 'desc' => '飘落的雪花特效', 'group' => '氛围特效' ),
                'lantern' => array( 'icon' => '🏮', 'title' => '节日灯笼', 'desc' => '页面顶部悬挂灯笼', 'group' => '氛围特效' ),
                'particles' => array( 'icon' => '✨', 'title' => '粒子背景', 'desc' => '动态粒子连线效果', 'group' => '氛围特效' ),
                'cursor' => array( 'icon' => '🌟', 'title' => '鼠标跟随', 'desc' => '鼠标移动时星星拖尾', 'group' => '交互增强' ),
                'ribbon' => array( 'icon' => '🎀', 'title' => '彩带背景', 'desc' => '点击刷新彩带背景', 'group' => '氛围特效' ),
                'grayscale' => array( 'icon' => '🕯️', 'title' => '全站灰色', 'desc' => '纪念/悼念模式', 'group' => '特殊模式' ),
                'contextmenu' => array( 'icon' => '🖱️', 'title' => '右键美化', 'desc' => '自定义右键菜单', 'group' => '交互增强' ),
                'nosource' => array( 'icon' => '🔒', 'title' => '基础防查看', 'desc' => '禁用右键查看源码/F12等常见操作', 'group' => '基础防护' ),
                'bgmusic' => array( 'icon' => '🎵', 'title' => '背景音乐', 'desc' => '网站背景音乐播放', 'group' => '节日与音乐' ),
                'welcome' => array( 'icon' => '🎉', 'title' => '节日欢迎弹窗', 'desc' => '节日自动弹窗祝福', 'group' => '节日与音乐' ),
            );
        }

        public function add_admin_menu() {
            if ( defined( 'JLWA_MENU_SLUG' ) ) {
                add_submenu_page(
                    JLWA_MENU_SLUG,
                    '九流页面美化',
                    '页面美化',
                    'manage_options',
                    self::MENU_SLUG,
                    array( $this, 'render_admin_page' )
                );
                return;
            }

            add_menu_page(
                '九流页面美化',
                '页面美化',
                'manage_options',
                self::MENU_SLUG,
                array( $this, 'render_admin_page' ),
                'dashicons-admin-customizer',
                58
            );

            add_submenu_page(
                self::MENU_SLUG,
                '特效设置',
                '特效设置',
                'manage_options',
                self::MENU_SLUG,
                array( $this, 'render_admin_page' )
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

            $output['effects']['sakura']['count']   = $this->sanitize_int( $effects['sakura']['count'] ?? 28, 1, 120, 28 );
            $output['effects']['sakura']['size']    = $this->sanitize_int( $effects['sakura']['size'] ?? 18, 6, 60, 18 );
            $output['effects']['sakura']['speed']   = $this->sanitize_float( $effects['sakura']['speed'] ?? 1, 0.2, 5, 1 );
            $output['effects']['sakura']['opacity'] = $this->sanitize_float( $effects['sakura']['opacity'] ?? 0.85, 0.05, 1, 0.85 );

            $output['effects']['snow']['count']   = $this->sanitize_int( $effects['snow']['count'] ?? 48, 1, 180, 48 );
            $output['effects']['snow']['size']    = $this->sanitize_int( $effects['snow']['size'] ?? 13, 4, 55, 13 );
            $output['effects']['snow']['speed']   = $this->sanitize_float( $effects['snow']['speed'] ?? 1, 0.2, 5, 1 );
            $output['effects']['snow']['opacity'] = $this->sanitize_float( $effects['snow']['opacity'] ?? 0.9, 0.05, 1, 0.9 );

            $output['effects']['lantern']['size']     = $this->sanitize_int( $effects['lantern']['size'] ?? 82, 36, 180, 82 );
            $output['effects']['lantern']['text']     = sanitize_text_field( $effects['lantern']['text'] ?? '福' );
            $output['effects']['lantern']['quantity'] = $this->sanitize_int( $effects['lantern']['quantity'] ?? 2, 1, 6, 2 );

            $output['effects']['particles']['count']         = $this->sanitize_int( $effects['particles']['count'] ?? 70, 8, 200, 70 );
            $output['effects']['particles']['speed']         = $this->sanitize_float( $effects['particles']['speed'] ?? 0.7, 0.05, 4, 0.7 );
            $output['effects']['particles']['opacity']       = $this->sanitize_float( $effects['particles']['opacity'] ?? 0.55, 0.05, 1, 0.55 );
            $output['effects']['particles']['line_distance'] = $this->sanitize_int( $effects['particles']['line_distance'] ?? 130, 40, 300, 130 );

            $output['effects']['cursor']['size']    = $this->sanitize_int( $effects['cursor']['size'] ?? 13, 4, 40, 13 );
            $output['effects']['cursor']['density'] = $this->sanitize_int( $effects['cursor']['density'] ?? 1, 1, 5, 1 );
            $output['effects']['cursor']['symbol']  = sanitize_text_field( $effects['cursor']['symbol'] ?? '✦' );

            $output['effects']['ribbon']['opacity'] = $this->sanitize_float( $effects['ribbon']['opacity'] ?? 0.42, 0.05, 1, 0.42 );
            $output['effects']['ribbon']['click']   = empty( $effects['ribbon']['click'] ) ? 0 : 1;

            $output['effects']['grayscale']['percent'] = $this->sanitize_int( $effects['grayscale']['percent'] ?? 100, 1, 100, 100 );

            $output['effects']['contextmenu']['title']        = sanitize_text_field( $effects['contextmenu']['title'] ?? '九流网站菜单' );
            $output['effects']['contextmenu']['show_copy']    = empty( $effects['contextmenu']['show_copy'] ) ? 0 : 1;
            $output['effects']['contextmenu']['show_refresh'] = empty( $effects['contextmenu']['show_refresh'] ) ? 0 : 1;
            $output['effects']['contextmenu']['show_top']     = empty( $effects['contextmenu']['show_top'] ) ? 0 : 1;
            $output['effects']['contextmenu']['show_back']    = empty( $effects['contextmenu']['show_back'] ) ? 0 : 1;
            $output['effects']['contextmenu']['custom_items'] = $this->sanitize_custom_items( $effects['contextmenu']['custom_items'] ?? '' );

            $output['effects']['nosource']['message'] = sanitize_text_field( $effects['nosource']['message'] ?? '本站已开启基础防复制保护。' );

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
            $js = str_replace( array( '<script', '</script' ), array( '&lt;script', '&lt;/script' ), $js );
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
            if ( ! empty( $options['global']['custom_css'] ) ) {
                wp_add_inline_style( 'xjpe-frontend', $options['global']['custom_css'] );
            }

            $in_footer = isset( $options['compat']['load_location'] ) && 'footer' === $options['compat']['load_location'];
            wp_enqueue_script( 'xjpe-frontend', plugins_url( 'assets/js/frontend.js', __FILE__ ), array(), self::VERSION, $in_footer );
            wp_add_inline_script( 'xjpe-frontend', 'window.XJPE_CONFIG=' . wp_json_encode( $config ) . ';', 'before' );
            if ( ! empty( $options['global']['custom_js'] ) ) {
                wp_add_inline_script( 'xjpe-frontend', "\ntry {\n" . $options['global']['custom_js'] . "\n} catch(e) { console.warn('XJPE custom JS error:', e); }\n", 'after' );
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
            if ( empty( $options['global']['mobile_enabled'] ) && wp_is_mobile() ) {
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
            $options = $this->get_options();
            $assets  = $this->direct_assets_html( $options );
            if ( false !== stripos( $html, '</head>' ) ) {
                return preg_replace( '/<\/head>/i', $assets . "\n</head>", $html, 1 );
            }
            if ( false !== stripos( $html, '</body>' ) ) {
                return preg_replace( '/<\/body>/i', $assets . "\n</body>", $html, 1 );
            }
            return $html . $assets;
        }

        private function direct_assets_html( $options ) {
            $config   = $this->frontend_config( $options );
            $css_url  = plugins_url( 'assets/css/frontend.css', __FILE__ );
            $js_url   = plugins_url( 'assets/js/frontend.js', __FILE__ );
            $html     = "\n<!-- 九流页面美化特效 v" . esc_html( self::VERSION ) . " -->\n";
            $html    .= '<link rel="stylesheet" id="xjpe-frontend-css" href="' . esc_url( add_query_arg( 'ver', self::VERSION, $css_url ) ) . '" media="all">' . "\n";
            if ( ! empty( $options['global']['custom_css'] ) ) {
                $html .= '<style id="xjpe-custom-css">' . "\n" . $options['global']['custom_css'] . "\n</style>\n";
            }
            $html .= '<script id="xjpe-config-js">window.XJPE_CONFIG=' . wp_json_encode( $config ) . ';</script>' . "\n";
            $html .= '<script id="xjpe-frontend-js" src="' . esc_url( add_query_arg( 'ver', self::VERSION, $js_url ) ) . '" defer></script>' . "\n";
            if ( ! empty( $options['global']['custom_js'] ) ) {
                $html .= '<script id="xjpe-custom-js">try {' . "\n" . $options['global']['custom_js'] . "\n" . '} catch(e) { console.warn("XJPE custom JS error:", e); }</script>' . "\n";
            }
            return $html;
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
                $options['global']['respect_reduce_motion'] = 0;
                foreach ( array( 'sakura', 'snow', 'lantern', 'cursor', 'ribbon', 'welcome' ) as $preview_effect ) {
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
                    'homeUrl'             => home_url( '/' ),
                    'preview'             => $this->is_preview_request(),
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
            return isset( $_GET['xjpe_preview'] ) && '1' === (string) $_GET['xjpe_preview']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }
        public function render_admin_page() {
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }

            $options = $this->get_options();
            $defs    = self::effect_definitions();
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
                        <a class="button button-hero" href="<?php echo esc_url( home_url( '/?xjpe_preview=1' ) ); ?>" target="_blank" rel="noopener">不保存，直接测试前台特效</a>
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
                            <a class="button" href="<?php echo esc_url( home_url( '/?xjpe_preview=1' ) ); ?>" target="_blank" rel="noopener">打开前台预览</a>
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
                    $this->number_field( $base, 'count', '数量', $effect['count'], 1, 180, 1 );
                    $this->number_field( $base, 'size', '大小', $effect['size'], 4, 60, 1 );
                    $this->number_field( $base, 'speed', '速度', $effect['speed'], 0.2, 5, 0.1 );
                    $this->number_field( $base, 'opacity', '透明度', $effect['opacity'], 0.05, 1, 0.05 );
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
                    $this->number_field( $base, 'size', '星星大小', $effect['size'], 4, 40, 1 );
                    $this->number_field( $base, 'density', '拖尾密度', $effect['density'], 1, 5, 1 );
                    $this->text_field( $base, 'symbol', '拖尾符号', $effect['symbol'], '✦' );
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
                    $this->text_field( $base, 'message', '提示文字', $effect['message'], '本站已开启基础防复制保护。' );
                    echo '<p class="xjpe-tip">注意：这个功能只能降低普通用户复制/查看成本，不能作为真正源码保护。</p>';
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

        private function inline_checkbox( $base, $key, $label, $checked ) {
            ?>
            <label class="xjpe-inline-check">
                <input type="checkbox" name="<?php echo esc_attr( $base . '[' . $key . ']' ); ?>" value="1" <?php checked( ! empty( $checked ) ); ?>>
                <span><?php echo esc_html( $label ); ?></span>
            </label>
            <?php
        }
    }

    XJPE_Plugin::instance();
}
