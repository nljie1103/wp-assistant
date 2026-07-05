<?php
/**
 * 在线更新：从 GitHub 拉取最新版本并覆盖本地插件文件。
 *
 * @package JiuliuImmersivePreloader
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class JIP_Updater
 */
class JIP_Updater {

	const REPO_OWNER    = 'nljie1103';
	const REPO_NAME     = 'wp-immersive-preloader';
	const BRANCH        = 'main';
	const TRANSIENT_KEY = 'jip_update_check';
	const TRANSIENT_TTL = 21600; // 6 hours.

	/**
	 * Singleton.
	 *
	 * @var JIP_Updater|null
	 */
	private static $instance = null;

	/**
	 * Get singleton.
	 *
	 * @return JIP_Updater
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'wp_ajax_jip_check_update', array( $this, 'ajax_check_update' ) );
		add_action( 'wp_ajax_jip_do_update', array( $this, 'ajax_do_update' ) );
	}

	/**
	 * Raw main plugin file URL.
	 *
	 * @return string
	 */
	protected static function raw_main_url() {
		return sprintf(
			'https://raw.githubusercontent.com/%s/%s/%s/jiuliu-immersive-preloader.php',
			self::REPO_OWNER,
			self::REPO_NAME,
			self::BRANCH
		);
	}

	/**
	 * Raw readme URL.
	 *
	 * @return string
	 */
	protected static function raw_readme_url() {
		return sprintf(
			'https://raw.githubusercontent.com/%s/%s/%s/readme.txt',
			self::REPO_OWNER,
			self::REPO_NAME,
			self::BRANCH
		);
	}

	/**
	 * GitHub branch zip URL.
	 *
	 * @return string
	 */
	protected static function zip_url() {
		return sprintf(
			'https://codeload.github.com/%s/%s/zip/refs/heads/%s',
			self::REPO_OWNER,
			self::REPO_NAME,
			self::BRANCH
		);
	}

	/**
	 * Fetch remote version information.
	 *
	 * @param bool $force Force refresh.
	 * @return array
	 */
	public function fetch_remote_info( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT_KEY );
			if ( is_array( $cached ) && isset( $cached['latest_version'] ) ) {
				return $cached;
			}
		}

		$response = wp_remote_get(
			self::raw_main_url(),
			array(
				'timeout'    => 15,
				'user-agent' => 'Jiuliu-Immersive-Preloader-Updater/' . JIP_VERSION,
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
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			return array(
				'latest_version' => '',
				'changelog'      => '',
				'error'          => sprintf( '远程 HTTP %d', $code ),
			);
		}

		$latest = '';
		if ( preg_match( '/^[ \t\/*#@]*Version:\s*([0-9A-Za-z\.\-_+]+)/mi', (string) $body, $m ) ) {
			$latest = trim( $m[1] );
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
	 * Fetch latest changelog snippet from readme.txt.
	 *
	 * @return string
	 */
	protected function fetch_changelog_snippet() {
		$response = wp_remote_get(
			self::raw_readme_url(),
			array(
				'timeout'    => 12,
				'user-agent' => 'Jiuliu-Immersive-Preloader-Updater/' . JIP_VERSION,
			)
		);
		if ( is_wp_error( $response ) ) {
			return '';
		}

		$body = wp_remote_retrieve_body( $response );
		if ( ! is_string( $body ) || '' === $body ) {
			return '';
		}

		if ( preg_match( '/==\s*(?:Changelog|变更日志)\s*==\s*(.+?)(\n==\s|\z)/su', $body, $m ) ) {
			$snippet = trim( $m[1] );
			if ( strlen( $snippet ) > 2000 ) {
				$snippet = substr( $snippet, 0, 2000 ) . "\n...";
			}
			return $snippet;
		}

		return '';
	}

	/**
	 * Ajax: check update.
	 */
	public function ajax_check_update() {
		check_ajax_referer( 'jip_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_send_json_error( array( 'message' => '权限不足。' ) );
		}

		$force = ! empty( $_POST['force'] );
		$info  = $this->fetch_remote_info( $force );

		if ( ! empty( $info['error'] ) ) {
			wp_send_json_error( array( 'message' => $info['error'] ) );
		}

		$has_update = ! empty( $info['latest_version'] ) && version_compare( $info['latest_version'], JIP_VERSION, '>' );

		wp_send_json_success(
			array(
				'current_version' => JIP_VERSION,
				'latest_version'  => $info['latest_version'],
				'has_update'      => (bool) $has_update,
				'changelog'       => $info['changelog'],
				'checked_at'      => isset( $info['checked_at'] ) ? (int) $info['checked_at'] : time(),
				'message'         => $has_update ? sprintf( '检测到新版本 v%s，可一键更新。', $info['latest_version'] ) : '当前已是最新版本。',
			)
		);
	}

	/**
	 * Ajax: execute update.
	 */
	public function ajax_do_update() {
		check_ajax_referer( 'jip_admin_nonce', 'nonce' );
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
	 * Run update flow.
	 *
	 * @return array
	 */
	protected function run_update() {
		@set_time_limit( 0 );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		$settings_snapshot = get_option( JIP_OPTION_KEY, array() );
		update_option(
			'jip_settings_backup',
			array(
				'time'     => time(),
				'version'  => JIP_VERSION,
				'settings' => $settings_snapshot,
			),
			false
		);

		global $wp_filesystem;
		if ( ! WP_Filesystem() ) {
			return array(
				'success' => false,
				'message' => '初始化 WP_Filesystem 失败，请检查目录写入权限。',
			);
		}

		$zip_path = download_url( self::zip_url(), 60 );
		if ( is_wp_error( $zip_path ) ) {
			return array(
				'success' => false,
				'message' => sprintf( '下载失败：%s', $zip_path->get_error_message() ),
			);
		}

		$tmp_dir = trailingslashit( get_temp_dir() ) . 'jip_update_' . wp_generate_password( 8, false );
		if ( ! wp_mkdir_p( $tmp_dir ) ) {
			@unlink( $zip_path );
			return array(
				'success' => false,
				'message' => '创建临时目录失败。',
			);
		}

		$unzipped = unzip_file( $zip_path, $tmp_dir );
		@unlink( $zip_path );
		if ( is_wp_error( $unzipped ) ) {
			self::rrmdir( $tmp_dir );
			return array(
				'success' => false,
				'message' => sprintf( '解压失败：%s', $unzipped->get_error_message() ),
			);
		}

		$inner = $this->find_extracted_root( $tmp_dir );
		if ( ! $inner ) {
			self::rrmdir( $tmp_dir );
			return array(
				'success' => false,
				'message' => '未在压缩包中找到插件目录。',
			);
		}

		$new_main = trailingslashit( $inner ) . 'jiuliu-immersive-preloader.php';
		if ( ! file_exists( $new_main ) ) {
			self::rrmdir( $tmp_dir );
			return array(
				'success' => false,
				'message' => '压缩包内未找到主插件文件，更新中止。',
			);
		}

		$new_version = $this->parse_version_from_file( $new_main );
		$copied      = $this->recursive_copy_overwrite( $inner, untrailingslashit( JIP_PLUGIN_DIR ) );

		self::rrmdir( $tmp_dir );

		if ( ! $copied ) {
			return array(
				'success' => false,
				'message' => '复制文件失败，请检查插件目录写入权限。',
			);
		}

		$after = get_option( JIP_OPTION_KEY, null );
		if ( ( ! is_array( $after ) || empty( $after ) ) && is_array( $settings_snapshot ) && ! empty( $settings_snapshot ) ) {
			update_option( JIP_OPTION_KEY, $settings_snapshot );
		}

		return array(
			'success'     => true,
			'message'     => sprintf( '已成功更新：v%s -> v%s（你的设置已保留）', JIP_VERSION, $new_version ? $new_version : 'unknown' ),
			'old_version' => JIP_VERSION,
			'new_version' => $new_version,
		);
	}

	/**
	 * Find extracted plugin root.
	 *
	 * @param string $tmp_dir Temporary directory.
	 * @return string|false
	 */
	protected function find_extracted_root( $tmp_dir ) {
		$tmp_dir = trailingslashit( $tmp_dir );
		if ( file_exists( $tmp_dir . 'jiuliu-immersive-preloader.php' ) ) {
			return untrailingslashit( $tmp_dir );
		}

		$dirs = glob( $tmp_dir . '*', GLOB_ONLYDIR );
		if ( ! $dirs ) {
			return false;
		}

		foreach ( $dirs as $dir ) {
			if ( file_exists( trailingslashit( $dir ) . 'jiuliu-immersive-preloader.php' ) ) {
				return $dir;
			}
		}

		return false;
	}

	/**
	 * Parse Version header.
	 *
	 * @param string $file File path.
	 * @return string
	 */
	protected function parse_version_from_file( $file ) {
		$head = @file_get_contents( $file, false, null, 0, 8192 );
		if ( ! $head ) {
			return '';
		}
		if ( preg_match( '/^[ \t\/*#@]*Version:\s*([0-9A-Za-z\.\-_+]+)/mi', $head, $m ) ) {
			return trim( $m[1] );
		}
		return '';
	}

	/**
	 * Recursive copy src/* to dst/*.
	 *
	 * @param string $src Source directory.
	 * @param string $dst Destination directory.
	 * @return bool
	 */
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
			} elseif ( ! @copy( $src_path, $dst_path ) ) {
				closedir( $dir );
				return false;
			}
		}

		closedir( $dir );
		return true;
	}

	/**
	 * Recursive remove directory.
	 *
	 * @param string $dir Directory path.
	 */
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
