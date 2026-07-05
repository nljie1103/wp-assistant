<?php
/**
 * 在线更新 - 从 GitHub 拉取最新版本并替换本地文件。
 *
 * 仓库：https://github.com/nljie1103/WP-AI-Article-Summary
 *
 * 工作流程：
 *  1. 检查远程 wp-ai-article-summary.php 中的 Version 头；
 *  2. 与本地 WPAIAS_VERSION 对比，给出 has_update + 变更日志摘要；
 *  3. 用户点"立即更新" → 下载 main 分支 zip → 解压 → 用 WP_Filesystem 覆盖本地文件。
 *
 * @package WP_AI_Article_Summary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPAIAS_Updater
 */
class WPAIAS_Updater {

	const REPO_OWNER = 'nljie1103';
	const REPO_NAME  = 'WP-AI-Article-Summary';
	const BRANCH     = 'main';

	const TRANSIENT_KEY = 'wpaias_update_check';
	const TRANSIENT_TTL = 21600; // 6h.

	/**
	 * 注册 hooks。
	 */
	public function register() {
		add_action( 'wp_ajax_wpaias_check_update', array( $this, 'ajax_check_update' ) );
		add_action( 'wp_ajax_wpaias_do_update', array( $this, 'ajax_do_update' ) );
	}

	/**
	 * 获取仓库 raw 主文件 URL。
	 *
	 * @return string
	 */
	protected static function raw_main_url() {
		return sprintf(
			'https://raw.githubusercontent.com/%s/%s/%s/wp-ai-article-summary.php',
			self::REPO_OWNER,
			self::REPO_NAME,
			self::BRANCH
		);
	}

	/**
	 * 获取仓库 raw readme.txt URL（用于变更日志预览）。
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
	 * 获取仓库 zip 下载地址。
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
	 * 拉取远程版本（带缓存）。
	 *
	 * @param bool $force 强制刷新缓存。
	 * @return array { latest_version, changelog, error }
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
				'user-agent' => 'WP-AI-Article-Summary-Updater/' . WPAIAS_VERSION,
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
				'error'          => sprintf( /* translators: %d HTTP code */ __( '远程 HTTP %d', 'wp-ai-article-summary' ), $code ),
			);
		}

		$latest = '';
		if ( preg_match( '/^[ \t\/*#@]*Version:\s*([0-9A-Za-z\.\-_+]+)/mi', $body, $m ) ) {
			$latest = trim( $m[1] );
		}

		// 顺便拉取 readme.txt 的变更日志摘要。
		$changelog = $this->fetch_changelog_snippet();

		$info = array(
			'latest_version' => $latest,
			'changelog'      => $changelog,
			'error'          => '',
			'checked_at'     => time(),
		);

		set_transient( self::TRANSIENT_KEY, $info, self::TRANSIENT_TTL );
		return $info;
	}

	/**
	 * 抓取 readme.txt 的 Changelog 段落。
	 *
	 * @return string
	 */
	protected function fetch_changelog_snippet() {
		$response = wp_remote_get(
			self::raw_readme_url(),
			array(
				'timeout'    => 12,
				'user-agent' => 'WP-AI-Article-Summary-Updater/' . WPAIAS_VERSION,
			)
		);
		if ( is_wp_error( $response ) ) {
			return '';
		}
		$body = wp_remote_retrieve_body( $response );
		if ( ! is_string( $body ) || '' === $body ) {
			return '';
		}

		// 抓取 "== Changelog ==" 后到下一个二级标题前的部分，最多 2000 字符。
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
	 * Ajax：检查更新。
	 */
	public function ajax_check_update() {
		check_ajax_referer( 'wpaias_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足。', 'wp-ai-article-summary' ) ) );
		}

		$force = ! empty( $_POST['force'] );
		$info  = $this->fetch_remote_info( $force );

		if ( ! empty( $info['error'] ) ) {
			wp_send_json_error( array( 'message' => $info['error'] ) );
		}

		$has_update = ! empty( $info['latest_version'] ) && version_compare( $info['latest_version'], WPAIAS_VERSION, '>' );

		wp_send_json_success(
			array(
				'current_version' => WPAIAS_VERSION,
				'latest_version'  => $info['latest_version'],
				'has_update'      => (bool) $has_update,
				'changelog'       => $info['changelog'],
				'checked_at'      => isset( $info['checked_at'] ) ? (int) $info['checked_at'] : time(),
				'message'         => $has_update
					? sprintf( /* translators: %s = latest version */ __( '检测到新版本 v%s，可一键更新。', 'wp-ai-article-summary' ), $info['latest_version'] )
					: __( '当前已是最新版本。', 'wp-ai-article-summary' ),
			)
		);
	}

	/**
	 * Ajax：执行更新。
	 */
	public function ajax_do_update() {
		check_ajax_referer( 'wpaias_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足。', 'wp-ai-article-summary' ) ) );
		}

		$result = $this->run_update();
		if ( ! empty( $result['success'] ) ) {
			// 清缓存，强制下次读最新版本号。
			delete_transient( self::TRANSIENT_KEY );
			wp_send_json_success( $result );
		}
		wp_send_json_error( $result );
	}

	/**
	 * 执行更新流程。
	 *
	 * @return array
	 */
	protected function run_update() {
		@set_time_limit( 0 );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		// 安全网：开始前先把当前设置 + 缓存索引快照到一个 option。
		// 由于"更新"只覆盖文件、不动数据库，这一步实际是双重保险。
		$settings_snapshot = get_option( WPAIAS_OPTION_KEY, array() );
		update_option(
			'wpaias_settings_backup',
			array(
				'time'     => time(),
				'version'  => WPAIAS_VERSION,
				'settings' => $settings_snapshot,
			),
			false
		);

		// 准备文件系统。
		global $wp_filesystem;
		if ( ! WP_Filesystem() ) {
			return array(
				'success' => false,
				'message' => __( '初始化 WP_Filesystem 失败，请检查目录写入权限。', 'wp-ai-article-summary' ),
			);
		}

		// 下载 zip。
		$zip_path = download_url( self::zip_url(), 60 );
		if ( is_wp_error( $zip_path ) ) {
			return array(
				'success' => false,
				'message' => sprintf( /* translators: %s = error */ __( '下载失败：%s', 'wp-ai-article-summary' ), $zip_path->get_error_message() ),
			);
		}

		// 解压到临时目录。
		$tmp_dir = trailingslashit( get_temp_dir() ) . 'wpaias_update_' . wp_generate_password( 8, false );
		if ( ! wp_mkdir_p( $tmp_dir ) ) {
			@unlink( $zip_path );
			return array(
				'success' => false,
				'message' => __( '创建临时目录失败。', 'wp-ai-article-summary' ),
			);
		}

		$unzipped = unzip_file( $zip_path, $tmp_dir );
		@unlink( $zip_path );
		if ( is_wp_error( $unzipped ) ) {
			self::rrmdir( $tmp_dir );
			return array(
				'success' => false,
				'message' => sprintf( /* translators: %s = error */ __( '解压失败：%s', 'wp-ai-article-summary' ), $unzipped->get_error_message() ),
			);
		}

		// 找到解压后的根目录（GitHub zip 默认目录为 RepoName-Branch/）。
		$inner = $this->find_extracted_root( $tmp_dir );
		if ( ! $inner ) {
			self::rrmdir( $tmp_dir );
			return array(
				'success' => false,
				'message' => __( '未在压缩包中找到插件目录。', 'wp-ai-article-summary' ),
			);
		}

		// 检查新版本号。
		$new_main = trailingslashit( $inner ) . 'wp-ai-article-summary.php';
		if ( ! file_exists( $new_main ) ) {
			self::rrmdir( $tmp_dir );
			return array(
				'success' => false,
				'message' => __( '压缩包内未找到主插件文件，更新中止。', 'wp-ai-article-summary' ),
			);
		}
		$new_version = $this->parse_version_from_file( $new_main );

		// 复制覆盖（递归）。
		$plugin_dir = untrailingslashit( WPAIAS_PLUGIN_DIR );
		$copied     = $this->recursive_copy_overwrite( $inner, $plugin_dir );

		// 清理临时目录。
		self::rrmdir( $tmp_dir );

		if ( ! $copied ) {
			return array(
				'success' => false,
				'message' => __( '复制文件失败，请检查插件目录写入权限。', 'wp-ai-article-summary' ),
			);
		}

		// 二次防御：如果出于任何不可预知原因 wpaias_settings 被清空，则用快照恢复。
		$after = get_option( WPAIAS_OPTION_KEY, null );
		if ( ! is_array( $after ) || empty( $after ) ) {
			if ( is_array( $settings_snapshot ) && ! empty( $settings_snapshot ) ) {
				update_option( WPAIAS_OPTION_KEY, $settings_snapshot );
			}
		}

		return array(
			'success'         => true,
			'message'         => sprintf( /* translators: 1: old, 2: new */ __( '已成功更新：v%1$s → v%2$s（你的所有设置与缓存均已保留）', 'wp-ai-article-summary' ), WPAIAS_VERSION, $new_version ?: 'unknown' ),
			'old_version'     => WPAIAS_VERSION,
			'new_version'     => $new_version,
		);
	}

	/**
	 * 在临时目录中查找包含 wp-ai-article-summary.php 的子目录。
	 *
	 * @param string $tmp_dir 临时目录。
	 * @return string|false 子目录路径，找不到则 false。
	 */
	protected function find_extracted_root( $tmp_dir ) {
		// 直接看一层。
		$tmp_dir = trailingslashit( $tmp_dir );
		if ( file_exists( $tmp_dir . 'wp-ai-article-summary.php' ) ) {
			return untrailingslashit( $tmp_dir );
		}
		$dirs = glob( $tmp_dir . '*', GLOB_ONLYDIR );
		if ( ! $dirs ) {
			return false;
		}
		foreach ( $dirs as $d ) {
			if ( file_exists( trailingslashit( $d ) . 'wp-ai-article-summary.php' ) ) {
				return $d;
			}
		}
		return false;
	}

	/**
	 * 从指定 PHP 文件中解析 Version 头。
	 *
	 * @param string $file 文件路径。
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
	 * 递归覆盖复制：src/* → dst/*。
	 *
	 * @param string $src 源目录。
	 * @param string $dst 目标目录。
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
			// 跳过 .git、.github 等。
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

	/**
	 * 递归删除目录。
	 *
	 * @param string $dir 目录。
	 * @return void
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
