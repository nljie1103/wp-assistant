<?php
/**
 * Suite updater for 九流WP助手.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JLWA_Updater {
	const REPO_OWNER = 'nljie1103';
	const REPO_NAME  = 'wp-assistant';
	const BRANCH     = 'main';
	const MAIN_FILE  = 'jiuliu-wp-assistant.php';

	/**
	 * Singleton.
	 *
	 * @var JLWA_Updater|null
	 */
	protected static $instance = null;

	/**
	 * Get singleton.
	 *
	 * @return JLWA_Updater
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
		add_action( 'wp_ajax_jlwa_check_update', array( $this, 'ajax_check_update' ) );
		add_action( 'wp_ajax_jlwa_do_update', array( $this, 'ajax_do_update' ) );
	}

	/**
	 * Ajax: check update.
	 */
	public function ajax_check_update() {
		$this->verify_request();

		$info = $this->get_remote_info();
		if ( ! empty( $info['error'] ) ) {
			wp_send_json_error( array( 'message' => $info['error'] ) );
		}

		$has_update = ! empty( $info['latest_version'] ) && version_compare( $info['latest_version'], JLWA_VERSION, '>' );

		wp_send_json_success(
			array(
				'current_version' => JLWA_VERSION,
				'latest_version'  => $info['latest_version'],
				'has_update'      => $has_update,
				'changelog'       => $info['changelog'],
				'message'         => $has_update ? '检测到新版本 v' . $info['latest_version'] . '，可一键更新套件。' : '当前已是最新版本。',
			)
		);
	}

	/**
	 * Ajax: update suite.
	 */
	public function ajax_do_update() {
		$this->verify_request();

		$info = $this->get_remote_info();
		if ( ! empty( $info['error'] ) ) {
			wp_send_json_error( array( 'message' => $info['error'] ) );
		}

		if ( empty( $info['latest_version'] ) || ! version_compare( $info['latest_version'], JLWA_VERSION, '>' ) ) {
			wp_send_json_error( array( 'message' => '当前已经是最新版本。' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

		if ( ! WP_Filesystem() ) {
			wp_send_json_error( array( 'message' => '初始化 WP_Filesystem 失败，请检查目录写入权限。' ) );
		}

		global $wp_filesystem;

		$zip_path = download_url( self::zip_url(), 60 );
		if ( is_wp_error( $zip_path ) ) {
			wp_send_json_error( array( 'message' => '下载失败：' . $zip_path->get_error_message() ) );
		}

		$tmp_dir = trailingslashit( get_temp_dir() ) . 'jlwa-update-' . wp_generate_password( 8, false ) . '/';
		if ( ! wp_mkdir_p( $tmp_dir ) ) {
			@unlink( $zip_path );
			wp_send_json_error( array( 'message' => '创建临时目录失败。' ) );
		}

		$unzipped = unzip_file( $zip_path, $tmp_dir );
		@unlink( $zip_path );
		if ( is_wp_error( $unzipped ) ) {
			$this->remove_dir( $tmp_dir );
			wp_send_json_error( array( 'message' => '解压失败：' . $unzipped->get_error_message() ) );
		}

		$inner = $this->find_plugin_dir( $tmp_dir );
		if ( '' === $inner ) {
			$this->remove_dir( $tmp_dir );
			wp_send_json_error( array( 'message' => '压缩包内未找到九流WP助手主插件文件。' ) );
		}

		$new_main = trailingslashit( $inner ) . self::MAIN_FILE;
		$new_ver  = $this->parse_version_from_file( $new_main );
		if ( empty( $new_ver ) || ! version_compare( $new_ver, JLWA_VERSION, '>' ) ) {
			$this->remove_dir( $tmp_dir );
			wp_send_json_error( array( 'message' => '压缩包版本无效或不高于当前版本。' ) );
		}

		$copied = $this->copy_overwrite( $inner, untrailingslashit( JLWA_PLUGIN_DIR ) );
		$this->remove_dir( $tmp_dir );

		if ( ! $copied ) {
			wp_send_json_error( array( 'message' => '复制文件失败，请检查插件目录写入权限。' ) );
		}

		$this->delete_stale_files();

		wp_send_json_success(
			array(
				'message'     => '已成功更新九流WP助手：v' . JLWA_VERSION . ' → v' . $new_ver . '。',
				'old_version' => JLWA_VERSION,
				'new_version' => $new_ver,
			)
		);
	}

	/**
	 * Verify ajax request.
	 */
	protected function verify_request() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_send_json_error( array( 'message' => '权限不足。' ) );
		}

		check_ajax_referer( 'jlwa_update_nonce', 'nonce' );
	}

	/**
	 * Get remote version and changelog.
	 *
	 * @return array
	 */
	protected function get_remote_info() {
		$response = wp_remote_get(
			self::raw_main_url(),
			array(
				'timeout'    => 20,
				'user-agent' => 'Jiuliu-WP-Assistant-Updater/' . JLWA_VERSION,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'error' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return array( 'error' => '远程 HTTP ' . $code );
		}

		$body    = wp_remote_retrieve_body( $response );
		$version = $this->parse_version_from_string( $body );
		if ( '' === $version ) {
			return array( 'error' => '未能解析远程版本号。' );
		}

		return array(
			'latest_version' => $version,
			'changelog'      => $this->get_remote_changelog(),
			'error'          => '',
		);
	}

	/**
	 * Remote changelog.
	 *
	 * @return string
	 */
	protected function get_remote_changelog() {
		$response = wp_remote_get(
			self::raw_readme_url(),
			array(
				'timeout'    => 20,
				'user-agent' => 'Jiuliu-WP-Assistant-Updater/' . JLWA_VERSION,
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		$body = wp_remote_retrieve_body( $response );
		if ( preg_match( '/=\\s*([0-9.]+)\\s*=\\s*(.*?)(?=\\n=\\s*[0-9.]|\\z)/s', $body, $matches ) ) {
			return trim( $matches[0] );
		}

		return '';
	}

	/**
	 * Raw main URL.
	 *
	 * @return string
	 */
	protected static function raw_main_url() {
		return sprintf( 'https://raw.githubusercontent.com/%s/%s/%s/%s', self::REPO_OWNER, self::REPO_NAME, self::BRANCH, self::MAIN_FILE );
	}

	/**
	 * Raw readme URL.
	 *
	 * @return string
	 */
	protected static function raw_readme_url() {
		return sprintf( 'https://raw.githubusercontent.com/%s/%s/%s/readme.txt', self::REPO_OWNER, self::REPO_NAME, self::BRANCH );
	}

	/**
	 * Zip URL.
	 *
	 * @return string
	 */
	protected static function zip_url() {
		return sprintf( 'https://codeload.github.com/%s/%s/zip/refs/heads/%s', self::REPO_OWNER, self::REPO_NAME, self::BRANCH );
	}

	/**
	 * Find plugin directory in extracted zip.
	 *
	 * @param string $tmp_dir Temp directory.
	 * @return string
	 */
	protected function find_plugin_dir( $tmp_dir ) {
		if ( file_exists( trailingslashit( $tmp_dir ) . self::MAIN_FILE ) ) {
			return trailingslashit( $tmp_dir );
		}

		$dirs = glob( trailingslashit( $tmp_dir ) . '*', GLOB_ONLYDIR );
		foreach ( (array) $dirs as $dir ) {
			if ( file_exists( trailingslashit( $dir ) . self::MAIN_FILE ) ) {
				return trailingslashit( $dir );
			}
		}

		return '';
	}

	/**
	 * Copy files recursively.
	 *
	 * @param string $src Source.
	 * @param string $dst Destination.
	 * @return bool
	 */
	protected function copy_overwrite( $src, $dst ) {
		global $wp_filesystem;

		$src = trailingslashit( $src );
		$dst = trailingslashit( $dst );

		if ( ! $wp_filesystem->is_dir( $dst ) && ! $wp_filesystem->mkdir( $dst ) ) {
			return false;
		}

		$items = $wp_filesystem->dirlist( $src );
		if ( ! is_array( $items ) ) {
			return false;
		}

		foreach ( $items as $name => $item ) {
			if ( in_array( $name, array( '.git', '.github' ), true ) ) {
				continue;
			}

			$from = $src . $name;
			$to   = $dst . $name;

			if ( 'd' === $item['type'] ) {
				if ( ! $this->copy_overwrite( $from, $to ) ) {
					return false;
				}
			} elseif ( ! $wp_filesystem->copy( $from, $to, true, FS_CHMOD_FILE ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Remove a directory.
	 *
	 * @param string $dir Directory.
	 */
	protected function remove_dir( $dir ) {
		global $wp_filesystem;
		if ( $wp_filesystem && $wp_filesystem->is_dir( $dir ) ) {
			$wp_filesystem->delete( $dir, true );
		}
	}

	/**
	 * Delete files left by the first suite build that no longer belong in the suite.
	 */
	protected function delete_stale_files() {
		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			return;
		}

		$stale_files = array(
			'modules/page-effects/includes/class-xjpe-updater.php',
			'modules/page-effects/readme.txt',
			'modules/ai-article-summary/includes/class-wpaias-updater.php',
			'modules/ai-article-summary/README.md',
			'modules/ai-article-summary/readme.txt',
			'modules/immersive-preloader/includes/class-jip-updater.php',
			'modules/immersive-preloader/README.md',
			'modules/immersive-preloader/readme.txt',
			'modules/relative-media-urls/README.md',
			'modules/relative-media-urls/readme.txt',
		);

		foreach ( $stale_files as $relative_path ) {
			$path = JLWA_PLUGIN_DIR . $relative_path;
			if ( $wp_filesystem->exists( $path ) && ! $wp_filesystem->is_dir( $path ) ) {
				$wp_filesystem->delete( $path, false );
			}
		}
	}

	/**
	 * Parse version from file.
	 *
	 * @param string $file File path.
	 * @return string
	 */
	protected function parse_version_from_file( $file ) {
		$content = file_exists( $file ) ? file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return is_string( $content ) ? $this->parse_version_from_string( $content ) : '';
	}

	/**
	 * Parse version from plugin header.
	 *
	 * @param string $content File content.
	 * @return string
	 */
	protected function parse_version_from_string( $content ) {
		if ( preg_match( '/^\\s*\\*\\s*Version:\\s*([0-9.]+)/mi', $content, $matches ) ) {
			return trim( $matches[1] );
		}
		return '';
	}
}
