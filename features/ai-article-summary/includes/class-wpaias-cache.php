<?php
/**
 * 缓存管理（基于 WP Transient + 自建索引）。
 *
 * @package WP_AI_Article_Summary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPAIAS_Cache
 */
class WPAIAS_Cache {

	/**
	 * 索引选项 key（用于记录所有已缓存的 post_id，便于清空与统计）。
	 */
	const INDEX_OPTION = 'wpaias_cache_index';

	/**
	 * 生成缓存键。
	 *
	 * @param int $post_id 文章 ID。
	 * @return string
	 */
	public static function key( $post_id ) {
		return WPAIAS_CACHE_PREFIX . absint( $post_id );
	}

	/**
	 * 获取某文章缓存。
	 *
	 * @param int $post_id 文章 ID。
	 * @return string|false
	 */
	public static function get( $post_id ) {
		$value = get_transient( self::key( $post_id ) );
		return ( false === $value || '' === $value ) ? false : $value;
	}

	/**
	 * 写入文章缓存。
	 *
	 * @param int    $post_id     文章 ID。
	 * @param string $summary     摘要内容。
	 * @param int    $expiration  过期秒数（0 = 永久）。
	 * @return bool
	 */
	public static function set( $post_id, $summary, $expiration = 0 ) {
		$post_id = absint( $post_id );
		if ( ! $post_id || '' === $summary ) {
			return false;
		}

		$result = set_transient( self::key( $post_id ), wp_kses_post( $summary ), absint( $expiration ) );

		if ( $result ) {
			self::add_to_index( $post_id );
			update_post_meta( $post_id, WPAIAS_META_KEY, 1 );
		}

		return $result;
	}

	/**
	 * 清除某文章缓存。
	 *
	 * @param int $post_id 文章 ID。
	 * @return bool
	 */
	public static function delete( $post_id ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return false;
		}

		delete_transient( self::key( $post_id ) );
		self::remove_from_index( $post_id );
		delete_post_meta( $post_id, WPAIAS_META_KEY );
		return true;
	}

	/**
	 * 清空全部缓存。
	 *
	 * @return int 被清空的数量。
	 */
	public static function flush_all() {
		$index = self::get_index();
		$count = 0;

		foreach ( $index as $post_id ) {
			delete_transient( self::key( $post_id ) );
			delete_post_meta( $post_id, WPAIAS_META_KEY );
			$count++;
		}

		update_option( self::INDEX_OPTION, array() );

		// 兜底：根据 transient 前缀彻底清理（防止索引丢失残留）。
		self::purge_orphan_transients();

		return $count;
	}

	/**
	 * 获取索引数组。
	 *
	 * @return int[]
	 */
	public static function get_index() {
		$index = get_option( self::INDEX_OPTION, array() );
		if ( ! is_array( $index ) ) {
			return array();
		}
		return array_values( array_unique( array_map( 'absint', $index ) ) );
	}

	/**
	 * 当前缓存数量。
	 *
	 * @return int
	 */
	public static function count() {
		return count( self::get_index() );
	}

	/**
	 * 追加 post_id 到索引。
	 *
	 * @param int $post_id 文章 ID。
	 * @return void
	 */
	protected static function add_to_index( $post_id ) {
		$index = self::get_index();
		if ( ! in_array( (int) $post_id, $index, true ) ) {
			$index[] = (int) $post_id;
			update_option( self::INDEX_OPTION, $index, false );
		}
	}

	/**
	 * 从索引移除 post_id。
	 *
	 * @param int $post_id 文章 ID。
	 * @return void
	 */
	protected static function remove_from_index( $post_id ) {
		$index   = self::get_index();
		$post_id = (int) $post_id;
		$new     = array();
		foreach ( $index as $id ) {
			if ( $id !== $post_id ) {
				$new[] = $id;
			}
		}
		update_option( self::INDEX_OPTION, $new, false );
	}

	/**
	 * 直接通过 SQL 清理孤立残留的 transient（防止索引意外丢失）。
	 *
	 * @return void
	 */
	protected static function purge_orphan_transients() {
		global $wpdb;

		$like = $wpdb->esc_like( '_transient_' . WPAIAS_CACHE_PREFIX ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore

		$like2 = $wpdb->esc_like( '_transient_timeout_' . WPAIAS_CACHE_PREFIX ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like2 ) ); // phpcs:ignore
	}

	/**
	 * 根据后台设置返回过期秒数。
	 *
	 * @param string $key 过期周期键：forever / 1day / 7days / 30days。
	 * @return int
	 */
	public static function ttl_from_key( $key ) {
		switch ( $key ) {
			case '1day':
				return DAY_IN_SECONDS;
			case '7days':
				return 7 * DAY_IN_SECONDS;
			case '30days':
				return 30 * DAY_IN_SECONDS;
			case 'forever':
			default:
				return 0;
		}
	}
}
