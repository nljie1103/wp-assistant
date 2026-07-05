<?php
/**
 * 设置管理类。
 *
 * @package JiuliuRelativeMediaUrls
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * JRMU_Settings
 */
class JRMU_Settings {
	/**
	 * 获取默认设置。
	 *
	 * @return array
	 */
	public static function get_defaults() {
		return array(
			'settings_version'              => JRMU_VERSION,

			// 媒体库模块。
			'convert_existing_media_output' => 0,
			'convert_future_media_output'   => 0,
			'future_media_enabled_at'       => 0,

			// 文章内容模块。
			'convert_post_on_save'          => 0,
			'convert_post_on_frontend'      => 0,

			// 多域名访问适配模块。
			'domain_adaptation_enabled'     => 0,
			'domain_dynamic_siteurl'        => 1,
			'domain_rewrite_frontend_links' => 0,
			'domain_rewrite_mode'           => 'current_full',
			'domain_exclude_admin'          => 1,
			'domain_exclude_login'          => 1,
			'domain_skip_system_endpoints'  => 1,
			'domain_mode'                   => 'whitelist',
			'domain_scheme'                 => 'https',
			'domain_allowed_hosts'          => '',

			// SEO / canonical。
			'canonical_enabled'             => 0,
			'canonical_primary_host'        => '',
			'canonical_scheme'              => 'https',

			// 转换范围。
			'target_uploads'                => 1,
			'target_themes'                 => 0,
			'target_plugins'                => 0,
			'extra_hosts'                   => '',

			// 扫描工具。
			'scan_limit'                    => 100,
			'scan_postmeta'                 => 0,
		);
	}

	/**
	 * 布尔设置键。
	 *
	 * @return array
	 */
	public static function get_boolean_keys() {
		return array(
			'convert_existing_media_output',
			'convert_future_media_output',
			'convert_post_on_save',
			'convert_post_on_frontend',
			'domain_adaptation_enabled',
			'domain_dynamic_siteurl',
			'domain_rewrite_frontend_links',
			'domain_exclude_admin',
			'domain_exclude_login',
			'domain_skip_system_endpoints',
			'canonical_enabled',
			'target_uploads',
			'target_themes',
			'target_plugins',
			'scan_postmeta',
		);
	}

	/**
	 * 获取设置。
	 *
	 * @return array
	 */
	public static function get_options() {
		$options = get_option( JRMU_OPTION_KEY, array() );

		if ( ! is_array( $options ) ) {
			$options = array();
		}

		return wp_parse_args( $options, self::get_defaults() );
	}

	/**
	 * 清洗设置。
	 *
	 * @param array $input 原始输入。
	 * @return array
	 */
	public static function sanitize( $input ) {
		$old      = self::get_options();
		$defaults = self::get_defaults();
		$output   = array();
		$input    = is_array( $input ) ? $input : array();

		foreach ( self::get_boolean_keys() as $key ) {
			$output[ $key ] = ! empty( $input[ $key ] ) ? 1 : 0;
		}

		if ( empty( $output['target_uploads'] ) && empty( $output['target_themes'] ) && empty( $output['target_plugins'] ) ) {
			$output['target_uploads'] = 1;
		}

		if ( ! empty( $output['convert_future_media_output'] ) ) {
			$output['future_media_enabled_at'] = ! empty( $old['convert_future_media_output'] ) && ! empty( $old['future_media_enabled_at'] ) ? absint( $old['future_media_enabled_at'] ) : time();
		} else {
			$output['future_media_enabled_at'] = 0;
		}

		$domain_mode = isset( $input['domain_mode'] ) ? sanitize_key( wp_unslash( $input['domain_mode'] ) ) : $defaults['domain_mode'];
		$output['domain_mode'] = in_array( $domain_mode, array( 'whitelist', 'any' ), true ) ? $domain_mode : $defaults['domain_mode'];

		$domain_scheme = isset( $input['domain_scheme'] ) ? sanitize_key( wp_unslash( $input['domain_scheme'] ) ) : $defaults['domain_scheme'];
		$output['domain_scheme'] = in_array( $domain_scheme, array( 'https', 'http', 'auto' ), true ) ? $domain_scheme : $defaults['domain_scheme'];

		$domain_rewrite_mode = isset( $input['domain_rewrite_mode'] ) ? sanitize_key( wp_unslash( $input['domain_rewrite_mode'] ) ) : $defaults['domain_rewrite_mode'];
		$output['domain_rewrite_mode'] = in_array( $domain_rewrite_mode, array( 'current_full', 'root_relative' ), true ) ? $domain_rewrite_mode : $defaults['domain_rewrite_mode'];

		$canonical_scheme = isset( $input['canonical_scheme'] ) ? sanitize_key( wp_unslash( $input['canonical_scheme'] ) ) : $defaults['canonical_scheme'];
		$output['canonical_scheme'] = in_array( $canonical_scheme, array( 'https', 'http' ), true ) ? $canonical_scheme : $defaults['canonical_scheme'];

		$output['domain_allowed_hosts']   = isset( $input['domain_allowed_hosts'] ) ? self::sanitize_host_lines( wp_unslash( $input['domain_allowed_hosts'] ) ) : '';
		$output['extra_hosts']            = isset( $input['extra_hosts'] ) ? self::sanitize_host_lines( wp_unslash( $input['extra_hosts'] ) ) : '';
		$output['canonical_primary_host'] = isset( $input['canonical_primary_host'] ) ? self::sanitize_single_host( wp_unslash( $input['canonical_primary_host'] ) ) : '';
		$output['scan_limit']             = isset( $input['scan_limit'] ) ? max( 10, min( 1000, absint( $input['scan_limit'] ) ) ) : $defaults['scan_limit'];
		$output['settings_version']       = JRMU_VERSION;

		return $output;
	}

	/**
	 * 清洗多行域名。
	 *
	 * @param string $raw 原始文本。
	 * @return string
	 */
	public static function sanitize_host_lines( $raw ) {
		$raw   = (string) $raw;
		$lines = preg_split( '/\r\n|\r|\n/', $raw );
		$hosts = array();

		foreach ( $lines as $line ) {
			$host = self::sanitize_single_host( $line );
			if ( $host ) {
				$hosts[] = $host;
			}
		}

		return implode( "\n", array_values( array_unique( $hosts ) ) );
	}

	/**
	 * 清洗单个域名。
	 *
	 * @param string $raw 原始域名或 URL。
	 * @return string
	 */
	public static function sanitize_single_host( $raw ) {
		$raw = strtolower( trim( (string) $raw ) );

		if ( '' === $raw ) {
			return '';
		}

		if ( false === strpos( $raw, '://' ) ) {
			$raw = 'https://' . $raw;
		}

		$host = wp_parse_url( $raw, PHP_URL_HOST );

		if ( ! $host ) {
			return '';
		}

		$host = strtolower( $host );
		$host = preg_replace( '/[^a-z0-9\-\.]/i', '', $host );

		return $host ? $host : '';
	}

	/**
	 * 获取当前请求 URL。
	 *
	 * @return string
	 */
	public static function current_admin_url() {
		$scheme = is_ssl() ? 'https' : 'http';
		$host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		return $host ? $scheme . '://' . $host . $uri : admin_url( 'admin.php?page=' . JRMU_MENU_SLUG );
	}
}
