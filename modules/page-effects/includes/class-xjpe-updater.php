<?php
/**
 * 九流页面美化特效 - GitHub 在线更新器。
 *
 * 按 WP-AI-Article-Summary 的后台更新方式实现：
 * - 固定 GitHub 更新源，不让普通用户在后台乱填更新源。
 * - 读取远程主插件文件 Version。
 * - 读取 readme.txt 的 Changelog。
 * - 下载 GitHub 分支 ZIP，解压后覆盖当前插件目录。
 * - 更新前备份插件设置，更新后做一次防御性恢复。
 *
 * @package Xiaojie_Page_Effects
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'XJPE_Updater' ) ) {
    final class XJPE_Updater {
        const TRANSIENT_KEY = 'xjpe_remote_update_info';
        const TRANSIENT_TTL = 6 * HOUR_IN_SECONDS;

        const GITHUB_OWNER = 'nljie1103';
        const GITHUB_REPO  = 'wp-page-effects';
        const GITHUB_BRANCH = 'main';
        const MAIN_FILE    = 'wp-page-effects.php';

        private static $instance = null;
        private $plugin_file;
        private $plugin_dir;

        public static function instance( $plugin_file ) {
            if ( null === self::$instance ) {
                self::$instance = new self( $plugin_file );
            }
            return self::$instance;
        }

        private function __construct( $plugin_file ) {
            $this->plugin_file = $plugin_file;
            $this->plugin_dir  = plugin_dir_path( $plugin_file );

            add_action( 'wp_ajax_xjpe_check_update', array( $this, 'ajax_check_update' ) );
            add_action( 'wp_ajax_xjpe_do_update', array( $this, 'ajax_do_update' ) );
        }

        /**
         * Ajax：检查更新。
         */
        public function ajax_check_update() {
            check_ajax_referer( 'xjpe_admin_nonce', 'nonce' );

            if ( ! current_user_can( 'update_plugins' ) ) {
                wp_send_json_error( array( 'message' => '权限不足。' ) );
            }

            $force = ! empty( $_POST['force'] );
            $info  = $this->fetch_remote_info( $force );

            if ( ! empty( $info['error'] ) ) {
                wp_send_json_error( array( 'message' => $info['error'] ) );
            }

            $has_update = ! empty( $info['latest_version'] ) && version_compare( $info['latest_version'], XJPE_Plugin::VERSION, '>' );

            wp_send_json_success(
                array(
                    'current_version' => XJPE_Plugin::VERSION,
                    'latest_version'  => $info['latest_version'],
                    'has_update'      => (bool) $has_update,
                    'changelog'       => isset( $info['changelog'] ) ? $info['changelog'] : '',
                    'checked_at'      => isset( $info['checked_at'] ) ? (int) $info['checked_at'] : time(),
                    'message'         => $has_update ? '检测到新版本 v' . $info['latest_version'] . '，可一键更新。' : '当前已是最新版本。',
                )
            );
        }

        /**
         * Ajax：执行更新。
         */
        public function ajax_do_update() {
            check_ajax_referer( 'xjpe_admin_nonce', 'nonce' );

            if ( ! current_user_can( 'update_plugins' ) ) {
                wp_send_json_error( array( 'message' => '权限不足。' ) );
            }

            $result = $this->run_update();

            if ( ! empty( $result['success'] ) ) {
                delete_transient( self::TRANSIENT_KEY );
                wp_send_json_success( $result );
            }

            wp_send_json_error( $result );
        }

        /**
         * 获取远程版本信息。
         *
         * @param bool $force 是否绕过缓存。
         * @return array
         */
        public function fetch_remote_info( $force = false ) {
            if ( ! $force ) {
                $cached = get_transient( self::TRANSIENT_KEY );
                if ( is_array( $cached ) ) {
                    return $cached;
                }
            }

            $response = wp_remote_get(
                self::raw_main_url(),
                array(
                    'timeout'    => 15,
                    'user-agent' => 'WP-Page-Effects-Updater/' . XJPE_Plugin::VERSION . '; ' . home_url( '/' ),
                    'headers'    => array(
                        'Accept'        => 'text/plain',
                        'Cache-Control' => 'no-cache',
                    ),
                )
            );

            if ( is_wp_error( $response ) ) {
                return array(
                    'latest_version' => '',
                    'changelog'      => '',
                    'error'          => $response->get_error_message(),
                    'checked_at'     => time(),
                );
            }

            $code = wp_remote_retrieve_response_code( $response );
            $body = wp_remote_retrieve_body( $response );
            if ( $code < 200 || $code >= 300 ) {
                return array(
                    'latest_version' => '',
                    'changelog'      => '',
                    'error'          => '远程 HTTP ' . (int) $code,
                    'checked_at'     => time(),
                );
            }

            $latest = $this->parse_version_from_string( $body );
            if ( '' === $latest ) {
                return array(
                    'latest_version' => '',
                    'changelog'      => '',
                    'error'          => '远程主插件文件没有解析到 Version。',
                    'checked_at'     => time(),
                );
            }

            $info = array(
                'latest_version' => $latest,
                'changelog'      => $this->fetch_changelog_snippet(),
                'error'          => '',
                'checked_at'     => time(),
            );

            set_transient( self::TRANSIENT_KEY, $info, self::TRANSIENT_TTL );
            return $info;
        }

        /**
         * 抓取 readme.txt Changelog 段落。
         *
         * @return string
         */
        protected function fetch_changelog_snippet() {
            $response = wp_remote_get(
                self::raw_readme_url(),
                array(
                    'timeout'    => 12,
                    'user-agent' => 'WP-Page-Effects-Updater/' . XJPE_Plugin::VERSION,
                )
            );

            if ( is_wp_error( $response ) ) {
                return '';
            }

            $body = wp_remote_retrieve_body( $response );
            if ( ! is_string( $body ) || '' === $body ) {
                return '';
            }

            if ( preg_match( '/==\s*Changelog\s*==\s*(.+?)(\n==\s|\z)/su', $body, $m ) ) {
                $snippet = trim( $m[1] );
                if ( strlen( $snippet ) > 2000 ) {
                    $snippet = substr( $snippet, 0, 2000 ) . "\n…";
                }
                return $snippet;
            }

            return '';
        }

        /**
         * 执行更新流程。
         *
         * @return array
         */
        protected function run_update() {
            @set_time_limit( 0 );

            $info = $this->fetch_remote_info( true );
            if ( ! empty( $info['error'] ) ) {
                return array( 'success' => false, 'message' => $info['error'] );
            }

            if ( empty( $info['latest_version'] ) || ! version_compare( $info['latest_version'], XJPE_Plugin::VERSION, '>' ) ) {
                return array( 'success' => false, 'message' => '没有可更新的新版本。' );
            }

            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/misc.php';

            $settings_snapshot = get_option( XJPE_Plugin::OPTION_NAME, array() );
            update_option(
                'xjpe_options_backup',
                array(
                    'time'     => time(),
                    'version'  => XJPE_Plugin::VERSION,
                    'settings' => $settings_snapshot,
                ),
                false
            );

            global $wp_filesystem;
            if ( ! WP_Filesystem() ) {
                return array( 'success' => false, 'message' => '初始化 WP_Filesystem 失败，请检查插件目录写入权限。' );
            }

            $zip_path = download_url( self::zip_url(), 60 );
            if ( is_wp_error( $zip_path ) ) {
                return array( 'success' => false, 'message' => '下载失败：' . $zip_path->get_error_message() );
            }

            $tmp_dir = trailingslashit( get_temp_dir() ) . 'xjpe_update_' . wp_generate_password( 8, false );
            if ( ! wp_mkdir_p( $tmp_dir ) ) {
                @unlink( $zip_path );
                return array( 'success' => false, 'message' => '创建临时目录失败。' );
            }

            $unzipped = unzip_file( $zip_path, $tmp_dir );
            @unlink( $zip_path );

            if ( is_wp_error( $unzipped ) ) {
                self::rrmdir( $tmp_dir );
                return array( 'success' => false, 'message' => '解压失败：' . $unzipped->get_error_message() );
            }

            $inner = $this->find_extracted_root( $tmp_dir );
            if ( ! $inner ) {
                self::rrmdir( $tmp_dir );
                return array( 'success' => false, 'message' => '未在压缩包中找到插件目录。' );
            }

            $new_main = trailingslashit( $inner ) . self::MAIN_FILE;
            if ( ! file_exists( $new_main ) ) {
                self::rrmdir( $tmp_dir );
                return array( 'success' => false, 'message' => '压缩包内未找到主插件文件，更新中止。' );
            }

            $new_version = $this->parse_version_from_file( $new_main );
            if ( '' === $new_version ) {
                self::rrmdir( $tmp_dir );
                return array( 'success' => false, 'message' => '新版本文件没有 Version 头，更新中止。' );
            }

            if ( ! version_compare( $new_version, XJPE_Plugin::VERSION, '>' ) ) {
                self::rrmdir( $tmp_dir );
                return array( 'success' => false, 'message' => '下载到的版本不高于当前版本，更新中止。' );
            }

            $copied = $this->recursive_copy_overwrite( $inner, untrailingslashit( $this->plugin_dir ) );
            self::rrmdir( $tmp_dir );

            if ( ! $copied ) {
                return array( 'success' => false, 'message' => '复制文件失败，请检查插件目录写入权限。' );
            }

            $after = get_option( XJPE_Plugin::OPTION_NAME, null );
            if ( ( ! is_array( $after ) || empty( $after ) ) && is_array( $settings_snapshot ) && ! empty( $settings_snapshot ) ) {
                update_option( XJPE_Plugin::OPTION_NAME, $settings_snapshot, false );
            }

            return array(
                'success'     => true,
                'message'     => '已成功更新：v' . XJPE_Plugin::VERSION . ' → v' . $new_version . '（你的所有设置均已保留）。',
                'old_version' => XJPE_Plugin::VERSION,
                'new_version' => $new_version,
            );
        }

        protected static function raw_main_url() {
            return sprintf(
                'https://raw.githubusercontent.com/%s/%s/%s/%s',
                self::GITHUB_OWNER,
                self::GITHUB_REPO,
                self::GITHUB_BRANCH,
                self::MAIN_FILE
            );
        }

        protected static function raw_readme_url() {
            return sprintf(
                'https://raw.githubusercontent.com/%s/%s/%s/readme.txt',
                self::GITHUB_OWNER,
                self::GITHUB_REPO,
                self::GITHUB_BRANCH
            );
        }

        protected static function zip_url() {
            return sprintf(
                'https://github.com/%s/%s/archive/refs/heads/%s.zip',
                self::GITHUB_OWNER,
                self::GITHUB_REPO,
                self::GITHUB_BRANCH
            );
        }

        protected function find_extracted_root( $tmp_dir ) {
            $tmp_dir = trailingslashit( $tmp_dir );

            if ( file_exists( $tmp_dir . self::MAIN_FILE ) ) {
                return untrailingslashit( $tmp_dir );
            }

            $dirs = glob( $tmp_dir . '*', GLOB_ONLYDIR );
            if ( ! $dirs ) {
                return false;
            }

            foreach ( $dirs as $dir ) {
                if ( file_exists( trailingslashit( $dir ) . self::MAIN_FILE ) ) {
                    return $dir;
                }
            }

            return false;
        }

        protected function parse_version_from_file( $file ) {
            $head = @file_get_contents( $file, false, null, 0, 8192 );
            return is_string( $head ) ? $this->parse_version_from_string( $head ) : '';
        }

        protected function parse_version_from_string( $content ) {
            if ( preg_match( '/^[ \t\/*#@]*Version:\s*([0-9A-Za-z\.\-_+]+)/mi', (string) $content, $m ) ) {
                return trim( $m[1] );
            }
            return '';
        }

        protected function recursive_copy_overwrite( $src, $dst ) {
            if ( ! is_dir( $src ) ) {
                return false;
            }
            if ( ! is_dir( $dst ) && ! wp_mkdir_p( $dst ) ) {
                return false;
            }

            $dir = @opendir( $src );
            if ( ! $dir ) {
                return false;
            }

            while ( false !== ( $entry = readdir( $dir ) ) ) {
                if ( '.' === $entry || '..' === $entry ) {
                    continue;
                }
                if ( '.' === substr( $entry, 0, 1 ) ) {
                    continue;
                }

                $src_path = trailingslashit( $src ) . $entry;
                $dst_path = trailingslashit( $dst ) . $entry;

                if ( is_dir( $src_path ) ) {
                    if ( ! $this->recursive_copy_overwrite( $src_path, $dst_path ) ) {
                        closedir( $dir );
                        return false;
                    }
                } else {
                    if ( ! @copy( $src_path, $dst_path ) ) {
                        closedir( $dir );
                        return false;
                    }
                }
            }

            closedir( $dir );
            return true;
        }

        protected static function rrmdir( $dir ) {
            if ( ! is_dir( $dir ) ) {
                return;
            }
            $items = @scandir( $dir );
            if ( ! $items ) {
                @rmdir( $dir );
                return;
            }
            foreach ( $items as $item ) {
                if ( '.' === $item || '..' === $item ) {
                    continue;
                }
                $path = trailingslashit( $dir ) . $item;
                if ( is_dir( $path ) ) {
                    self::rrmdir( $path );
                } else {
                    @unlink( $path );
                }
            }
            @rmdir( $dir );
        }
    }
}
