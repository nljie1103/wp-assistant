<?php
/**
 * AI 摘要缓存管理。
 *
 * 1.1.0 起使用 post meta 保存摘要，避免“永久 transient”成为 autoload option。
 * 读取时兼容并迁移旧 transient。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAIAS_Cache {
	const INDEX_OPTION = 'wpaias_cache_index';
	const SUMMARY_META = '_wpaias_summary_text';
	const EXPIRES_META = '_wpaias_summary_expires';

	public static function key( $post_id ) {
		return WPAIAS_CACHE_PREFIX . absint( $post_id );
	}

	/** @return string|false */
	public static function get( $post_id ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return false;
		}

		$value   = get_post_meta( $post_id, self::SUMMARY_META, true );
		$expires = (int) get_post_meta( $post_id, self::EXPIRES_META, true );
		if ( is_string( $value ) && '' !== $value ) {
			if ( $expires > 0 && time() >= $expires ) {
				self::delete( $post_id );
				return false;
			}
			return $value;
		}

		// 兼容旧版 transient，并在首次命中时迁移。
		$legacy = get_transient( self::key( $post_id ) );
		if ( false !== $legacy && '' !== $legacy ) {
			self::set( $post_id, (string) $legacy, 30 * DAY_IN_SECONDS );
			delete_transient( self::key( $post_id ) );
			return (string) $legacy;
		}
		return false;
	}

	public static function set( $post_id, $summary, $expiration = 0 ) {
		$post_id = absint( $post_id );
		$summary = wp_kses_post( (string) $summary );
		if ( ! $post_id || '' === $summary ) {
			return false;
		}

		update_post_meta( $post_id, self::SUMMARY_META, $summary );
		$result = (string) get_post_meta( $post_id, self::SUMMARY_META, true ) === $summary;
		$expires = absint( $expiration ) > 0 ? time() + absint( $expiration ) : 0;
		update_post_meta( $post_id, self::EXPIRES_META, $expires );
		delete_transient( self::key( $post_id ) );
		if ( $result ) {
			self::add_to_index( $post_id );
			update_post_meta( $post_id, WPAIAS_META_KEY, 1 );
		}
		return $result;
	}

	public static function delete( $post_id ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return false;
		}
		delete_transient( self::key( $post_id ) );
		delete_post_meta( $post_id, self::SUMMARY_META );
		delete_post_meta( $post_id, self::EXPIRES_META );
		delete_post_meta( $post_id, WPAIAS_META_KEY );
		self::remove_from_index( $post_id );
		return true;
	}

	public static function flush_all() {
		$count = count( self::get_index() );
		delete_metadata( 'post', 0, self::SUMMARY_META, '', true );
		delete_metadata( 'post', 0, self::EXPIRES_META, '', true );
		delete_metadata( 'post', 0, WPAIAS_META_KEY, '', true );
		update_option( self::INDEX_OPTION, array(), false );
		self::purge_orphan_transients();
		return $count;
	}

	public static function get_index() {
		$index = get_option( self::INDEX_OPTION, array() );
		if ( ! is_array( $index ) ) {
			return array();
		}
		return array_values( array_filter( array_unique( array_map( 'absint', $index ) ) ) );
	}

	public static function count() {
		return count( self::get_index() );
	}

	protected static function add_to_index( $post_id ) {
		$index = self::get_index();
		if ( ! in_array( (int) $post_id, $index, true ) ) {
			$index[] = (int) $post_id;
			update_option( self::INDEX_OPTION, $index, false );
		}
	}

	protected static function remove_from_index( $post_id ) {
		$index   = self::get_index();
		$post_id = (int) $post_id;
		update_option(
			self::INDEX_OPTION,
			array_values( array_filter( $index, static function ( $id ) use ( $post_id ) { return (int) $id !== $post_id; } ) ),
			false
		);
	}

	protected static function purge_orphan_transients() {
		global $wpdb;
		$like = $wpdb->esc_like( '_transient_' . WPAIAS_CACHE_PREFIX ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$like2 = $wpdb->esc_like( '_transient_timeout_' . WPAIAS_CACHE_PREFIX ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like2 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	public static function ttl_from_key( $key ) {
		switch ( $key ) {
			case '1day': return DAY_IN_SECONDS;
			case '7days': return 7 * DAY_IN_SECONDS;
			case '30days': return 30 * DAY_IN_SECONDS;
			case 'forever':
			default: return 0;
		}
	}
}
