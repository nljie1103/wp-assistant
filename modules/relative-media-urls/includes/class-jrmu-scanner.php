<?php
/**
 * 扫描、预览、修复工具。
 *
 * @package JiuliuRelativeMediaUrls
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * JRMU_Scanner
 */
class JRMU_Scanner {
	/** @var JRMU_Scanner|null */
	private static $instance = null;

	/** 支持的批量动作。 */
	const ACTION_CONVERT_MEDIA_RELATIVE = 'convert_media_relative';
	const ACTION_RESTORE_MEDIA_FULL     = 'restore_media_full';
	const ACTION_INTERNAL_TO_TARGET     = 'internal_to_target';
	const ACTION_INTERNAL_TO_RELATIVE   = 'internal_to_relative';
	const ACTION_FIX_MIXED_HTTPS        = 'fix_mixed_https';

	/** 获取单例。 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** 获取动作标签。 */
	public static function get_actions() {
		return array(
			self::ACTION_CONVERT_MEDIA_RELATIVE => '媒体/静态资源绝对 URL → 根相对',
			self::ACTION_RESTORE_MEDIA_FULL     => '根相对媒体 URL → 完整 URL',
			self::ACTION_INTERNAL_TO_TARGET     => '站内绝对链接 → 目标域名',
			self::ACTION_INTERNAL_TO_RELATIVE   => '站内绝对链接 → 根相对',
			self::ACTION_FIX_MIXED_HTTPS        => '本站 HTTP → HTTPS',
		);
	}

	/** 判断动作是否有效。 */
	public static function is_valid_action( $action ) {
		return array_key_exists( sanitize_key( $action ), self::get_actions() );
	}

	/** 扫描文章。 */
	public function scan_posts( $args = array() ) {
		$options = JRMU_Settings::get_options();
		$args    = wp_parse_args(
			$args,
			array(
				'post_type'   => 'any',
				'post_status' => 'publish',
				'keyword'     => '',
				'limit'       => ! empty( $options['scan_limit'] ) ? absint( $options['scan_limit'] ) : 100,
				'target_base' => home_url(),
			)
		);

		$post_types = $this->resolve_post_types( $args['post_type'] );
		$post_status = 'any' === $args['post_status'] ? array( 'publish', 'draft', 'private', 'pending', 'future' ) : sanitize_key( $args['post_status'] );

		$query = new WP_Query(
			array(
				'post_type'              => $post_types,
				'post_status'            => $post_status,
				'posts_per_page'         => max( 1, min( 1000, absint( $args['limit'] ) ) ),
				's'                      => sanitize_text_field( $args['keyword'] ),
				'fields'                 => 'ids',
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$rows = array();
		foreach ( $query->posts as $post_id ) {
			$row = $this->scan_single_post( $post_id, $args['target_base'] );
			if ( $row && $row['total'] > 0 ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/** 解析扫描文章类型。 */
	private function resolve_post_types( $post_type ) {
		$post_type = sanitize_key( $post_type );
		if ( 'any' !== $post_type && post_type_exists( $post_type ) && 'attachment' !== $post_type ) {
			return array( $post_type );
		}

		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );
		if ( empty( $types ) ) {
			$types = array( 'post', 'page' );
		}
		return array_values( $types );
	}

	/** 扫描单篇。 */
	public function scan_single_post( $post_id, $target_base = '' ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}

		$content = (string) $post->post_content . "\n" . (string) $post->post_excerpt;
		$conv    = JRMU_Converter::instance();
		$domain  = JRMU_Domain_Adapter::instance();
		$target  = $target_base ? $target_base : home_url();

		$groups = array(
			self::ACTION_CONVERT_MEDIA_RELATIVE => $conv->get_convertible_url_samples( $content, 10 ),
			self::ACTION_RESTORE_MEDIA_FULL     => $conv->get_restorable_url_samples( $content, $target, 10 ),
			self::ACTION_INTERNAL_TO_TARGET     => $domain->get_rewriteable_url_samples( $content, 10, $target ),
			self::ACTION_INTERNAL_TO_RELATIVE   => $domain->get_rewriteable_url_samples( $content, 10, '' ),
			self::ACTION_FIX_MIXED_HTTPS        => $this->get_mixed_content_samples( $content, 10 ),
			'exposure'                         => $this->get_source_exposure_samples( $content, 10 ),
		);

		$total = 0;
		foreach ( $groups as $items ) {
			$total += count( $items );
		}

		return array(
			'id'               => absint( $post_id ),
			'title'            => get_the_title( $post_id ),
			'type'             => $post->post_type,
			'status'           => $post->post_status,
			'edit_link'        => get_edit_post_link( $post_id, 'raw' ),
			'media_count'      => count( $groups[ self::ACTION_CONVERT_MEDIA_RELATIVE ] ),
			'restore_count'    => count( $groups[ self::ACTION_RESTORE_MEDIA_FULL ] ),
			'internal_count'   => max( count( $groups[ self::ACTION_INTERNAL_TO_TARGET ] ), count( $groups[ self::ACTION_INTERNAL_TO_RELATIVE ] ) ),
			'mixed_count'      => count( $groups[ self::ACTION_FIX_MIXED_HTTPS ] ),
			'exposure_count'   => count( $groups['exposure'] ),
			'total'            => $total,
			'sample_groups'    => $groups,
			'samples'          => array_slice( array_merge( $groups[ self::ACTION_CONVERT_MEDIA_RELATIVE ], $groups[ self::ACTION_RESTORE_MEDIA_FULL ], $groups[ self::ACTION_INTERNAL_TO_TARGET ], $groups[ self::ACTION_FIX_MIXED_HTTPS ], $groups['exposure'] ), 0, 8 ),
		);
	}

	/** 扫描 postmeta，只读展示。 */
	public function scan_postmeta( $limit = 100 ) {
		global $wpdb;
		$limit  = max( 1, min( 500, absint( $limit ) ) );
		$hosts  = array_merge( JRMU_Converter::instance()->get_allowed_hosts(), JRMU_Domain_Adapter::instance()->get_rewrite_source_hosts() );
		$hosts  = array_filter( array_unique( $hosts ) );
		$likes  = array();
		$params = array();

		foreach ( $hosts as $host ) {
			$likes[]  = 'meta_value LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $host ) . '%';
		}
		foreach ( JRMU_Converter::instance()->get_allowed_paths() as $path ) {
			$likes[]  = 'meta_value LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $path ) . '%';
		}
		if ( empty( $likes ) ) {
			return array();
		}

		$params[] = $limit;
		$sql      = "SELECT post_id, meta_key, LEFT(meta_value, 260) AS sample FROM {$wpdb->postmeta} WHERE (" . implode( ' OR ', $likes ) . ') LIMIT %d';
		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
	}

	/** 执行文章批量动作。 */
	public function apply_post_action( $post_ids, $action, $target_base = '' ) {
		$post_ids    = array_map( 'absint', (array) $post_ids );
		$post_ids    = array_filter( array_unique( $post_ids ) );
		$action      = sanitize_key( $action );
		$target_base = $target_base ? untrailingslashit( esc_url_raw( $target_base ) ) : home_url();

		if ( ! self::is_valid_action( $action ) ) {
			return array( 'requested' => count( $post_ids ), 'updated' => 0, 'skipped' => count( $post_ids ), 'errors' => array( '无效操作。' ) );
		}
		if ( in_array( $action, array( self::ACTION_RESTORE_MEDIA_FULL, self::ACTION_INTERNAL_TO_TARGET ), true ) && ! preg_match( '#^https?://#i', $target_base ) ) {
			return array( 'requested' => count( $post_ids ), 'updated' => 0, 'skipped' => count( $post_ids ), 'errors' => array( '目标域名无效。' ) );
		}

		$lock_key = 'jrmu_apply_lock_' . get_current_user_id();
		if ( get_transient( $lock_key ) ) {
			return array( 'requested' => count( $post_ids ), 'updated' => 0, 'skipped' => count( $post_ids ), 'errors' => array( '已有处理任务正在进行，请稍后再试。' ) );
		}
		set_transient( $lock_key, 1, 5 * MINUTE_IN_SECONDS );

		$updated = 0;
		$skipped = 0;
		$errors  = array();

		foreach ( $post_ids as $post_id ) {
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				++$skipped;
				$errors[] = '无权限编辑文章 ID ' . $post_id;
				continue;
			}

			$post = get_post( $post_id );
			if ( ! $post ) {
				++$skipped;
				continue;
			}

			$old_content = (string) $post->post_content;
			$old_excerpt = (string) $post->post_excerpt;
			$new_content = $old_content;
			$new_excerpt = $old_excerpt;

			switch ( $action ) {
				case self::ACTION_CONVERT_MEDIA_RELATIVE:
					$new_content = JRMU_Converter::instance()->convert_content( $old_content );
					$new_excerpt = JRMU_Converter::instance()->convert_content( $old_excerpt );
					break;
				case self::ACTION_RESTORE_MEDIA_FULL:
					$new_content = JRMU_Converter::instance()->restore_content_to_domain( $old_content, $target_base );
					$new_excerpt = JRMU_Converter::instance()->restore_content_to_domain( $old_excerpt, $target_base );
					break;
				case self::ACTION_INTERNAL_TO_TARGET:
					$new_content = JRMU_Domain_Adapter::instance()->rewrite_html_to_domain( $old_content, $target_base );
					$new_excerpt = JRMU_Domain_Adapter::instance()->rewrite_html_to_domain( $old_excerpt, $target_base );
					break;
				case self::ACTION_INTERNAL_TO_RELATIVE:
					$new_content = JRMU_Domain_Adapter::instance()->rewrite_html_to_relative( $old_content );
					$new_excerpt = JRMU_Domain_Adapter::instance()->rewrite_html_to_relative( $old_excerpt );
					break;
				case self::ACTION_FIX_MIXED_HTTPS:
					$new_content = $this->fix_mixed_content( $old_content );
					$new_excerpt = $this->fix_mixed_content( $old_excerpt );
					break;
			}

			if ( $new_content === $old_content && $new_excerpt === $old_excerpt ) {
				++$skipped;
				continue;
			}

			if ( function_exists( 'wp_save_post_revision' ) && post_type_supports( $post->post_type, 'revisions' ) ) {
				wp_save_post_revision( $post_id );
			}

			$result = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $new_content,
					'post_excerpt' => $new_excerpt,
				),
				true
			);
			if ( is_wp_error( $result ) ) {
				++$skipped;
				$errors[] = '文章 ID ' . $post_id . ' 更新失败：' . $result->get_error_message();
				continue;
			}
			++$updated;
		}

		delete_transient( $lock_key );
		$this->add_log( $action, count( $post_ids ), $updated, $target_base, $skipped, $errors );
		return array( 'requested' => count( $post_ids ), 'updated' => $updated, 'skipped' => $skipped, 'errors' => $errors );
	}

	/** 混合内容样本。 */
	public function get_mixed_content_samples( $content, $limit = 5 ) {
		$samples = array();
		if ( ! is_string( $content ) || '' === $content ) {
			return $samples;
		}
		$hosts = array_merge( JRMU_Converter::instance()->get_allowed_hosts(), JRMU_Domain_Adapter::instance()->get_rewrite_source_hosts() );
		$hosts = array_unique( array_filter( $hosts ) );
		preg_replace_callback(
			'#http://[^\s"\'<>)]+#i',
			function ( $matches ) use ( &$samples, $limit, $hosts ) {
				if ( count( $samples ) >= $limit ) {
					return $matches[0];
				}
				$url   = $matches[0];
				$parts = wp_parse_url( $url );
				$host  = ! empty( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
				if ( $host && in_array( $host, $hosts, true ) ) {
					$samples[] = array( 'from' => $url, 'to' => preg_replace( '#^http://#i', 'https://', $url ), 'type' => 'HTTP 混合内容' );
				}
				return $url;
			},
			$content
		);
		return $samples;
	}

	/** 修复混合内容。 */
	private function fix_mixed_content( $content ) {
		$samples = $this->get_mixed_content_samples( $content, 999999 );
		foreach ( $samples as $sample ) {
			$content = str_replace( $sample['from'], $sample['to'], $content );
		}
		return $content;
	}

	/** 源站暴露样本。 */
	public function get_source_exposure_samples( $content, $limit = 5 ) {
		$samples = array();
		if ( ! is_string( $content ) || '' === $content ) {
			return $samples;
		}
		$options = JRMU_Settings::get_options();
		$hosts   = array();
		if ( ! empty( $options['extra_hosts'] ) ) {
			$hosts = preg_split( '/\r\n|\r|\n/', $options['extra_hosts'] );
		}
		foreach ( $hosts as $host ) {
			$host = trim( $host );
			if ( '' === $host ) {
				continue;
			}
			if ( false !== stripos( $content, $host ) ) {
				$samples[] = array( 'from' => $host, 'to' => '建议改为入口域名或根相对地址', 'type' => '源站暴露' );
				if ( count( $samples ) >= $limit ) {
					break;
				}
			}
		}
		preg_replace_callback(
			'#https?://\d{1,3}(?:\.\d{1,3}){3}[^\s"\'<>)]+#',
			function ( $matches ) use ( &$samples, $limit ) {
				if ( count( $samples ) < $limit ) {
					$samples[] = array( 'from' => $matches[0], 'to' => '疑似源站 IP 暴露', 'type' => '源站 IP' );
				}
				return $matches[0];
			},
			$content
		);
		return $samples;
	}

	/** 写日志。 */
	public function add_log( $action, $requested, $updated, $target_base = '', $skipped = 0, $errors = array() ) {
		$logs = get_option( JRMU_LOG_OPTION_KEY, array() );
		$logs = is_array( $logs ) ? $logs : array();
		array_unshift(
			$logs,
			array(
				'time'        => time(),
				'action'      => sanitize_key( $action ),
				'requested'   => absint( $requested ),
				'updated'     => absint( $updated ),
				'skipped'     => absint( $skipped ),
				'errors'      => array_slice( array_map( 'sanitize_text_field', (array) $errors ), 0, 10 ),
				'target_base' => esc_url_raw( $target_base ),
			)
		);
		$logs = array_slice( $logs, 0, 80 );
		update_option( JRMU_LOG_OPTION_KEY, $logs, false );
	}

	/** 获取日志。 */
	public function get_logs() {
		$logs = get_option( JRMU_LOG_OPTION_KEY, array() );
		return is_array( $logs ) ? $logs : array();
	}
}
