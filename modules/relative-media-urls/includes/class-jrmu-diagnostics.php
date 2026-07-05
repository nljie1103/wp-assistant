<?php
/**
 * 诊断工具。
 *
 * @package JiuliuRelativeMediaUrls
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * JRMU_Diagnostics
 */
class JRMU_Diagnostics {
	/** 获取环境信息。 */
	public static function get_environment() {
		$server_keys = array(
			'HTTP_HOST',
			'HTTPS',
			'REQUEST_SCHEME',
			'SERVER_PORT',
			'HTTP_X_FORWARDED_PROTO',
			'HTTP_X_FORWARDED_SSL',
			'HTTP_X_FORWARDED_HOST',
			'HTTP_X_REAL_IP',
			'HTTP_X_FORWARDED_FOR',
		);
		$server = array();
		foreach ( $server_keys as $key ) {
			$server[ $key ] = isset( $_SERVER[ $key ] ) ? sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) : '';
		}
		$uploads = wp_get_upload_dir();
		return array(
			'home_url'          => home_url(),
			'site_url'          => site_url(),
			'content_url'       => content_url(),
			'upload_baseurl'    => isset( $uploads['baseurl'] ) ? $uploads['baseurl'] : '',
			'is_ssl'            => is_ssl() ? 'yes' : 'no',
			'php_version'       => PHP_VERSION,
			'wp_version'        => get_bloginfo( 'version' ),
			'permalink'         => get_option( 'permalink_structure' ),
			'upload_url_path'   => get_option( 'upload_url_path' ),
			'domain_debug'      => JRMU_Domain_Adapter::instance()->get_debug_info(),
			'server'            => $server,
		);
	}

	/** 检测 URL 响应头。 */
	public static function check_url_headers( $url ) {
		$url = esc_url_raw( trim( (string) $url ) );
		if ( ! $url || ! preg_match( '#^https?://#i', $url ) ) {
			return new WP_Error( 'invalid_url', '请输入有效的 http/https URL。' );
		}
		if ( ! self::is_url_host_allowed_for_check( $url ) ) {
			return new WP_Error( 'host_not_allowed', '为安全起见，只允许检测当前站点、额外源站域名或多域名白名单中的 URL。' );
		}
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 3,
				'sslverify'   => apply_filters( 'jrmu_diagnostics_sslverify', true ),
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$headers = wp_remote_retrieve_headers( $response );
		$out     = array();
		foreach ( $headers as $key => $value ) {
			$out[ strtolower( $key ) ] = is_array( $value ) ? implode( ', ', $value ) : $value;
		}
		return array(
			'code'    => wp_remote_retrieve_response_code( $response ),
			'message' => wp_remote_retrieve_response_message( $response ),
			'headers' => $out,
		);
	}

	/** URL 检测白名单。 */
	private static function is_url_host_allowed_for_check( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return false;
		}
		$host = strtolower( preg_replace( '/:\d+$/', '', $host ) );
		$allowed = array_merge(
			JRMU_Converter::instance()->get_allowed_hosts(),
			JRMU_Domain_Adapter::instance()->get_allowed_domain_hosts(),
			JRMU_Domain_Adapter::instance()->get_rewrite_source_hosts()
		);
		$current = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( preg_replace( '/:\d+$/', '', sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) ) : '';
		if ( $current ) {
			$allowed[] = $current;
		}
		$allowed = array_unique( array_filter( $allowed ) );
		return in_array( $host, $allowed, true );
	}

	/** 生成 Nginx 反代缓存配置建议。 */
	public static function generate_nginx_config() {
		$origin = site_url();
		$host   = wp_parse_url( $origin, PHP_URL_HOST );
		$origin_display = $host ? 'https://' . $host : 'https://origin.example.com';
		return "# 九流媒体相对地址：Nginx 反代缓存示例\n" .
		"# 先在 http {} 内添加缓存区：\n" .
		"proxy_cache_path /www/wwwcache/jrmu levels=1:2 keys_zone=jrmu_cache:100m inactive=7d max_size=10g use_temp_path=off;\n\n" .
		"# 在 server {} 内添加或合并以下规则。请把 proxy_pass 改成你的美国源站：\n" .
		"set \$skip_cache 0;\n" .
		"if (\$request_method = POST) { set \$skip_cache 1; }\n" .
		"if (\$query_string != \"\") { set \$skip_cache 1; }\n" .
		"if (\$request_uri ~* \"/wp-admin/|/wp-login.php|/wp-json/|xmlrpc.php\") { set \$skip_cache 1; }\n" .
		"if (\$http_cookie ~* \"wordpress_logged_in|comment_author|wp-postpass|woocommerce_items_in_cart\") { set \$skip_cache 1; }\n\n" .
		"location ~* \\.(jpg|jpeg|png|gif|webp|avif|svg|ico|css|js|woff|woff2|ttf|eot)$ {\n" .
		"    proxy_pass {$origin_display};\n" .
		"    proxy_set_header Host \$host;\n" .
		"    proxy_set_header X-Real-IP \$remote_addr;\n" .
		"    proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;\n" .
		"    proxy_set_header X-Forwarded-Proto \$scheme;\n" .
		"    proxy_cache jrmu_cache;\n" .
		"    proxy_cache_valid 200 301 302 30d;\n" .
		"    proxy_cache_valid 404 1m;\n" .
		"    proxy_cache_lock on;\n" .
		"    proxy_cache_use_stale error timeout updating http_500 http_502 http_503 http_504;\n" .
		"    add_header X-Cache-Status \$upstream_cache_status always;\n" .
		"    expires 30d;\n" .
		"    add_header Cache-Control \"public, max-age=2592000\";\n" .
		"}\n\n" .
		"location / {\n" .
		"    proxy_pass {$origin_display};\n" .
		"    proxy_set_header Host \$host;\n" .
		"    proxy_set_header X-Real-IP \$remote_addr;\n" .
		"    proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;\n" .
		"    proxy_set_header X-Forwarded-Proto \$scheme;\n" .
		"    proxy_cache jrmu_cache;\n" .
		"    proxy_cache_valid 200 5m;\n" .
		"    proxy_cache_bypass \$skip_cache;\n" .
		"    proxy_no_cache \$skip_cache;\n" .
		"    add_header X-Cache-Status \$upstream_cache_status always;\n" .
		"}\n";
	}
}
