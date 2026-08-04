<?php
/**
 * SEO / Canonical 控制。
 *
 * @package JiuliuRelativeMediaUrls
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * JRMU_SEO
 */
class JRMU_SEO {
	/** @var JRMU_SEO|null */
	private static $instance = null;

	/** 获取单例。 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** 构造函数。 */
	private function __construct() {
		$this->init_hooks();
	}

	/** 初始化钩子。 */
	private function init_hooks() {
		$options = JRMU_Settings::get_options();
		if ( empty( $options['canonical_enabled'] ) || empty( $options['canonical_primary_host'] ) ) {
			return;
		}
		add_filter( 'get_canonical_url', array( $this, 'filter_canonical_url' ), 99, 2 );
		add_filter( 'wpseo_canonical', array( $this, 'filter_seo_plugin_canonical' ), 99 );
		add_filter( 'rank_math/frontend/canonical', array( $this, 'filter_seo_plugin_canonical' ), 99 );
		add_filter( 'aioseo_canonical_url', array( $this, 'filter_seo_plugin_canonical' ), 99 );
	}

	/** Core canonical。 */
	public function filter_canonical_url( $canonical_url, $post = null ) {
		return $this->to_primary_domain( $canonical_url );
	}

	/** SEO 插件 canonical。 */
	public function filter_seo_plugin_canonical( $canonical_url ) {
		return $this->to_primary_domain( $canonical_url );
	}

	/** 转为主域名。 */
	public function to_primary_domain( $url ) {
		$options = JRMU_Settings::get_options();
		$host    = ! empty( $options['canonical_primary_host'] ) ? $options['canonical_primary_host'] : '';
		$scheme  = ! empty( $options['canonical_scheme'] ) ? $options['canonical_scheme'] : 'https';

		if ( ! $host || ! is_string( $url ) || '' === $url ) {
			return $url;
		}

		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = home_url( $url );
		}

		$parts = wp_parse_url( $url );
		if ( empty( $parts['path'] ) ) {
			$parts['path'] = '/';
		}

		$out = $scheme . '://' . $host . $parts['path'];
		if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
			$out .= '?' . $parts['query'];
		}
		if ( isset( $parts['fragment'] ) && '' !== $parts['fragment'] ) {
			$out .= '#' . $parts['fragment'];
		}
		return esc_url_raw( $out );
	}
}
