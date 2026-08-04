<?php
/**
 * Safe unified plugin updater for 九流WP助手.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JLWA_Updater {
	const REPO_OWNER    = 'nljie1103';
	const REPO_NAME     = 'wp-assistant';
	const BRANCH        = 'main';
	const MAIN_FILE     = 'jiuliu-wp-assistant.php';
	const INFO_CACHE    = 'jlwa_remote_update_info';
	const UPDATE_LOCK   = 'jlwa_update_lock';
	const CACHE_TTL     = 21600;

	/** @var JLWA_Updater|null */
	protected static $instance = null;

	/** @return JLWA_Updater */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_jlwa_check_update', array( $this, 'ajax_check_update' ) );
		add_action( 'wp_ajax_jlwa_do_update', array( $this, 'ajax_do_update' ) );
	}

	public function ajax_check_update() {
		$this->verify_request();
		$force = ! empty( $_POST['force'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$info  = $this->get_remote_info( $force );

		if ( is_wp_error( $info ) ) {
			wp_send_json_error( array( 'message' => $info->get_error_message() ), 502 );
		}

		$has_update = version_compare( $info['latest_version'], JLWA_VERSION, '>' );
		wp_send_json_success(
			array(
				'current_version' => JLWA_VERSION,
				'latest_version'  => $info['latest_version'],
				'has_update'      => $has_update,
				'changelog'       => $info['changelog'],
				'message'         => $has_update ? '检测到新版本 v' . $info['latest_version'] . '。' : '当前已是最新版本。',
			)
		);
	}

	public function ajax_do_update() {
		$this->verify_request();

		if ( get_transient( self::UPDATE_LOCK ) ) {
			wp_send_json_error( array( 'message' => '另一个更新任务正在执行，请稍后重试。' ), 409 );
		}
		set_transient( self::UPDATE_LOCK, time(), 5 * MINUTE_IN_SECONDS );

		try {
			$info = $this->get_remote_info( true );
			if ( is_wp_error( $info ) ) {
				$result = $info;
			} elseif ( ! version_compare( $info['latest_version'], JLWA_VERSION, '>' ) ) {
				$result = new WP_Error( 'jlwa_not_newer', '当前已经是最新版本。' );
			} else {
				$result = $this->perform_update( $info );
			}
		} finally {
			delete_transient( self::UPDATE_LOCK );
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}
		wp_send_json_success( $result );
	}

	protected function verify_request() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_send_json_error( array( 'message' => '权限不足。' ), 403 );
		}
		check_ajax_referer( 'jlwa_update_nonce', 'nonce' );
	}

	/**
	 * Fetch and cache remote metadata.
	 *
	 * @param bool $force Ignore cache.
	 * @return array<string,string>|WP_Error
	 */
	protected function get_remote_info( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::INFO_CACHE );
			if ( is_array( $cached ) && ! empty( $cached['latest_version'] ) && ! empty( $cached['main_sha256'] ) ) {
				return $cached;
			}
		}

		$response = wp_remote_get(
			self::raw_main_url(),
			array(
				'timeout'     => 20,
				'redirection' => 5,
				'user-agent'  => 'Jiuliu-WP-Assistant-Updater/' . JLWA_VERSION,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'jlwa_remote_http', '远程版本请求返回 HTTP ' . (int) wp_remote_retrieve_response_code( $response ) . '。' );
		}

		$body = wp_remote_retrieve_body( $response );
		if ( ! is_string( $body ) || '' === $body ) {
			return new WP_Error( 'jlwa_remote_empty', '远程主文件为空。' );
		}
		$version = $this->parse_version_from_string( $body );
		if ( '' === $version ) {
			return new WP_Error( 'jlwa_remote_version', '未能解析远程版本号。' );
		}

		$info = array(
			'latest_version' => $version,
			'main_sha256'    => hash( 'sha256', $body ),
			'changelog'      => $this->get_remote_changelog(),
		);
		set_transient( self::INFO_CACHE, $info, self::CACHE_TTL );
		return $info;
	}

	/** @return string */
	protected function get_remote_changelog() {
		$response = wp_remote_get(
			self::raw_readme_url(),
			array(
				'timeout'     => 20,
				'redirection' => 5,
				'user-agent'  => 'Jiuliu-WP-Assistant-Updater/' . JLWA_VERSION,
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}
		$body = wp_remote_retrieve_body( $response );
		if ( preg_match( '/=\s*([0-9.]+)\s*=\s*(.*?)(?=\n=\s*[0-9.]|\z)/s', (string) $body, $matches ) ) {
			return trim( $matches[0] );
		}
		return '';
	}

	/**
	 * Download, validate, back up and atomically replace the plugin directory.
	 *
	 * @param array<string,string> $info Remote metadata.
	 * @return array<string,string>|WP_Error
	 */
	protected function perform_update( $info ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! WP_Filesystem() ) {
			return new WP_Error( 'jlwa_filesystem', '初始化 WP_Filesystem 失败，请检查目录写入权限。' );
		}

		global $wp_filesystem;
		$upgrade_dir = trailingslashit( WP_CONTENT_DIR ) . 'upgrade';
		$work_dir    = trailingslashit( $upgrade_dir ) . 'jlwa_update_' . wp_generate_uuid4();
		$extract_dir = trailingslashit( $work_dir ) . 'extract';
		$backup_dir  = trailingslashit( $work_dir ) . 'backup';
		$plugin_dir  = untrailingslashit( JLWA_PLUGIN_DIR );

		if ( ! $wp_filesystem->is_dir( $upgrade_dir ) && ! $wp_filesystem->mkdir( $upgrade_dir, FS_CHMOD_DIR ) ) {
			return new WP_Error( 'jlwa_upgrade_dir', '无法创建 wp-content/upgrade 目录。' );
		}
		if ( ! $wp_filesystem->mkdir( $work_dir, FS_CHMOD_DIR ) || ! $wp_filesystem->mkdir( $extract_dir, FS_CHMOD_DIR ) ) {
			$this->cleanup( $work_dir );
			return new WP_Error( 'jlwa_work_dir', '无法创建更新临时目录。' );
		}

		$zip_path = download_url( self::zip_url(), 60 );
		if ( is_wp_error( $zip_path ) ) {
			$this->cleanup( $work_dir );
			return new WP_Error( 'jlwa_download', '下载失败：' . $zip_path->get_error_message() );
		}

		$unzipped = unzip_file( $zip_path, $extract_dir );
		@unlink( $zip_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( is_wp_error( $unzipped ) ) {
			$this->cleanup( $work_dir );
			return new WP_Error( 'jlwa_unzip', '解压失败：' . $unzipped->get_error_message() );
		}

		$source_dir = $this->find_plugin_dir( $extract_dir );
		if ( '' === $source_dir ) {
			$this->cleanup( $work_dir );
			return new WP_Error( 'jlwa_package_root', '压缩包内未找到九流WP助手主文件。' );
		}

		$this->remove_package_metadata( $source_dir );
		$validation = $this->validate_package( $source_dir, $info );
		if ( is_wp_error( $validation ) ) {
			$this->cleanup( $work_dir );
			return $validation;
		}

		$backup = copy_dir( $plugin_dir, $backup_dir );
		if ( is_wp_error( $backup ) ) {
			$this->cleanup( $work_dir );
			return new WP_Error( 'jlwa_backup', '备份当前插件失败：' . $backup->get_error_message() );
		}
		if ( ! $wp_filesystem->exists( trailingslashit( $backup_dir ) . self::MAIN_FILE ) ) {
			$this->cleanup( $work_dir );
			return new WP_Error( 'jlwa_backup_incomplete', '插件备份不完整，更新已中止。' );
		}

		$install = $this->install_package( $source_dir, $plugin_dir );
		if ( is_wp_error( $install ) ) {
			$rollback = $this->rollback( $backup_dir, $plugin_dir );
			$this->cleanup( $work_dir );
			$message = '安装失败：' . $install->get_error_message();
			$message .= is_wp_error( $rollback ) ? '；自动回滚也失败：' . $rollback->get_error_message() : '；已自动恢复旧版本。';
			return new WP_Error( 'jlwa_install', $message );
		}

		$installed_main    = trailingslashit( $plugin_dir ) . self::MAIN_FILE;
		$installed_version = $this->parse_version_from_file( $installed_main );
		$installed_hash    = file_exists( $installed_main ) ? hash_file( 'sha256', $installed_main ) : '';
		if ( $installed_version !== $info['latest_version'] || ! is_string( $installed_hash ) || ! hash_equals( $info['main_sha256'], $installed_hash ) ) {
			$rollback = $this->rollback( $backup_dir, $plugin_dir );
			$this->cleanup( $work_dir );
			$message = '安装后的版本或哈希校验失败。';
			$message .= is_wp_error( $rollback ) ? ' 自动回滚也失败：' . $rollback->get_error_message() : ' 已自动恢复旧版本。';
			return new WP_Error( 'jlwa_post_validate', $message );
		}

		$this->cleanup( $work_dir );
		delete_transient( self::INFO_CACHE );
		return array(
			'message'     => '已安全更新九流WP助手：v' . JLWA_VERSION . ' → v' . $installed_version . '。页面即将刷新。',
			'old_version' => JLWA_VERSION,
			'new_version' => $installed_version,
		);
	}

	/** @param string $source Source. @param string $destination Destination. */
	protected function install_package( $source, $destination ) {
		global $wp_filesystem;
		if ( ! $wp_filesystem->delete( $destination, true ) ) {
			return new WP_Error( 'jlwa_delete_old', '无法清理旧插件目录。' );
		}
		if ( ! $wp_filesystem->mkdir( $destination, FS_CHMOD_DIR ) && ! $wp_filesystem->is_dir( $destination ) ) {
			return new WP_Error( 'jlwa_recreate', '无法重新创建插件目录。' );
		}
		$result = copy_dir( $source, $destination );
		return is_wp_error( $result ) ? $result : true;
	}

	/** @param string $backup Backup. @param string $destination Destination. */
	protected function rollback( $backup, $destination ) {
		global $wp_filesystem;
		$wp_filesystem->delete( $destination, true );
		if ( ! $wp_filesystem->mkdir( $destination, FS_CHMOD_DIR ) && ! $wp_filesystem->is_dir( $destination ) ) {
			return new WP_Error( 'jlwa_rollback_dir', '无法重新创建插件目录。' );
		}
		$result = copy_dir( $backup, $destination );
		return is_wp_error( $result ) ? $result : true;
	}

	/** @param string $root Package root. @param array<string,string> $info Remote info. */
	protected function validate_package( $root, $info ) {
		$main = trailingslashit( $root ) . self::MAIN_FILE;
		if ( ! is_readable( $main ) ) {
			return new WP_Error( 'jlwa_missing_main', '更新包缺少主插件文件。' );
		}
		$version = $this->parse_version_from_file( $main );
		if ( $version !== $info['latest_version'] || ! version_compare( $version, JLWA_VERSION, '>' ) ) {
			return new WP_Error( 'jlwa_version_mismatch', '更新包版本与远程版本不一致，或版本没有提升。' );
		}
		$hash = hash_file( 'sha256', $main );
		if ( ! is_string( $hash ) || ! hash_equals( $info['main_sha256'], $hash ) ) {
			return new WP_Error( 'jlwa_hash_mismatch', '更新包主文件与远程主文件 SHA-256 不一致。' );
		}

		$required = array(
			'assets/css/admin.css',
			'assets/css/admin-content.css',
			'assets/js/admin.js',
			'assets/js/admin-content.js',
			'includes/class-jlwa-admin.php',
			'includes/class-jlwa-feature-registry.php',
			'includes/class-jlwa-updater.php',
			'features/page-effects/bootstrap.php',
			'features/page-effects/assets/js/frontend.js',
			'features/relative-media-urls/bootstrap.php',
			'features/ai-article-summary/bootstrap.php',
			'features/immersive-preloader/bootstrap.php',
			'readme.txt',
			'uninstall.php',
		);
		foreach ( $required as $relative ) {
			if ( ! $GLOBALS['wp_filesystem']->exists( trailingslashit( $root ) . $relative ) ) {
				return new WP_Error( 'jlwa_incomplete_package', '更新包缺少必要文件：' . $relative );
			}
		}
		return true;
	}

	/** @param string $root Package root. */
	protected function remove_package_metadata( $root ) {
		global $wp_filesystem;
		foreach ( array( '.git', '.github', '.suite-audit', '.release' ) as $name ) {
			$path = trailingslashit( $root ) . $name;
			if ( $wp_filesystem->exists( $path ) ) {
				$wp_filesystem->delete( $path, true );
			}
		}
	}

	/** @param string $tmp_dir Extraction directory. */
	protected function find_plugin_dir( $tmp_dir ) {
		$direct = trailingslashit( $tmp_dir ) . self::MAIN_FILE;
		if ( file_exists( $direct ) ) {
			return untrailingslashit( $tmp_dir );
		}
		$dirs = glob( trailingslashit( $tmp_dir ) . '*', GLOB_ONLYDIR );
		foreach ( (array) $dirs as $dir ) {
			if ( file_exists( trailingslashit( $dir ) . self::MAIN_FILE ) ) {
				return untrailingslashit( $dir );
			}
		}
		return '';
	}

	/** @param string $path Path. */
	protected function cleanup( $path ) {
		global $wp_filesystem;
		if ( $wp_filesystem && $wp_filesystem->exists( $path ) ) {
			$wp_filesystem->delete( $path, true );
		}
	}

	/** @param string $file Main file. */
	protected function parse_version_from_file( $file ) {
		$content = file_exists( $file ) ? file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return is_string( $content ) ? $this->parse_version_from_string( $content ) : '';
	}

	/** @param string $content Plugin source. */
	protected function parse_version_from_string( $content ) {
		if ( preg_match( '/^\s*\*\s*Version:\s*([0-9.]+)/mi', $content, $matches ) ) {
			return trim( $matches[1] );
		}
		return '';
	}

	protected static function raw_main_url() {
		return sprintf( 'https://raw.githubusercontent.com/%s/%s/%s/%s', self::REPO_OWNER, self::REPO_NAME, self::BRANCH, self::MAIN_FILE );
	}

	protected static function raw_readme_url() {
		return sprintf( 'https://raw.githubusercontent.com/%s/%s/%s/readme.txt', self::REPO_OWNER, self::REPO_NAME, self::BRANCH );
	}

	protected static function zip_url() {
		return sprintf( 'https://codeload.github.com/%s/%s/zip/refs/heads/%s', self::REPO_OWNER, self::REPO_NAME, self::BRANCH );
	}
}
