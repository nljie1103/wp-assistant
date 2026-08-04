<?php
/**
 * 多域名访问适配类。
 *
 * @package JiuliuRelativeMediaUrls
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * JRMU_Domain_Adapter
 */
class JRMU_Domain_Adapter {
	/** @var JRMU_Domain_Adapter|null */
	private static $instance = null;

	/** @var bool */
	private $bypass_options = false;

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
		if ( empty( $options['domain_adaptation_enabled'] ) ) {
			return;
		}

		add_filter( 'pre_option_home', array( $this, 'filter_pre_option_home' ), 10, 3 );
		add_filter( 'pre_option_siteurl', array( $this, 'filter_pre_option_siteurl' ), 10, 3 );

		if ( ! empty( $options['domain_rewrite_frontend_links'] ) ) {
			foreach ( array( 'post_link', 'page_link', 'post_type_link', 'term_link', 'category_link', 'tag_link', 'author_link', 'day_link', 'month_link', 'year_link' ) as $filter ) {
				add_filter( $filter, array( $this, 'rewrite_url' ), 99 );
			}
			add_filter( 'nav_menu_link_attributes', array( $this, 'rewrite_nav_menu_link_attributes' ), 99 );
			foreach ( array( 'the_content', 'the_excerpt', 'widget_text', 'widget_text_content', 'render_block' ) as $filter ) {
				add_filter( $filter, array( $this, 'rewrite_html' ), 98 );
			}
		}
	}

	/** 动态 home。 */
	public function filter_pre_option_home( $pre_option = false, $option = 'home', $default = false ) {
		return $this->get_dynamic_base_url_or_false();
	}

	/** 动态 siteurl。 */
	public function filter_pre_option_siteurl( $pre_option = false, $option = 'siteurl', $default = false ) {
		$options = JRMU_Settings::get_options();
		if ( empty( $options['domain_dynamic_siteurl'] ) ) {
			return false;
		}
		return $this->get_dynamic_base_url_or_false();
	}

	/**
	 * 获取动态基础 URL。
	 *
	 * @return string|false
	 */
	public function get_dynamic_base_url_or_false() {
		if ( $this->bypass_options || ! $this->should_adapt_current_request() ) {
			return false;
		}

		$current_host = $this->get_current_host_with_port();
		if ( ! $current_host || ! $this->is_current_host_allowed( $current_host ) ) {
			return false;
		}

		return $this->get_current_scheme() . '://' . $current_host;
	}

	/** 判断当前请求是否适配。 */
	public function should_adapt_current_request() {
		$options = JRMU_Settings::get_options();
		if ( empty( $options['domain_adaptation_enabled'] ) ) {
			return false;
		}
		if ( ! empty( $options['domain_exclude_admin'] ) && is_admin() ) {
			return false;
		}
		if ( ! empty( $options['domain_exclude_login'] ) && $this->is_login_request() ) {
			return false;
		}
		if ( ! empty( $options['domain_skip_system_endpoints'] ) && $this->is_system_endpoint_request() ) {
			return false;
		}
		return true;
	}

	/** 重写单个 URL 到当前域名或根相对。 */
	public function rewrite_url( $url ) {
		if ( ! is_string( $url ) || '' === $url || ! $this->should_adapt_current_request() || ! preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}

		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) || empty( $parts['path'] ) ) {
			return $url;
		}

		if ( ! $this->is_source_host_allowed( $parts['host'] ) ) {
			return $url;
		}

		$options = JRMU_Settings::get_options();
		$path    = $parts['path'];
		if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
			$path .= '?' . $parts['query'];
		}
		if ( isset( $parts['fragment'] ) && '' !== $parts['fragment'] ) {
			$path .= '#' . $parts['fragment'];
		}

		if ( 'root_relative' === $options['domain_rewrite_mode'] ) {
			return $path;
		}

		$current_host = $this->get_current_host_with_port();
		if ( ! $current_host || ! $this->is_current_host_allowed( $current_host ) ) {
			return $url;
		}

		return $this->get_current_scheme() . '://' . $current_host . $path;
	}

	/** 重写 HTML。 */
	public function rewrite_html( $html ) {
		if ( ! is_string( $html ) || '' === $html || ! $this->should_adapt_current_request() ) {
			return $html;
		}
		$rewritten = preg_replace_callback(
			'#https?://[^\s"\'<>)]+#i',
			function ( $matches ) {
				$url = isset( $matches[0] ) ? $matches[0] : '';
				return $this->rewrite_url( $url );
			},
			$html
		);
		return is_string( $rewritten ) ? $rewritten : $html;
	}

	/** 将 HTML 中站内绝对链接改为指定目标域名。 */
	public function rewrite_html_to_domain( $html, $base_url ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		$base_url = untrailingslashit( esc_url_raw( $base_url ) );
		if ( ! preg_match( '#^https?://#i', $base_url ) ) {
			return $html;
		}
		$rewritten = preg_replace_callback(
			'#https?://[^\s"\'<>)]+#i',
			function ( $matches ) use ( $base_url ) {
				$url   = isset( $matches[0] ) ? $matches[0] : '';
				$parts = wp_parse_url( $url );
				if ( empty( $parts['host'] ) || empty( $parts['path'] ) || ! $this->is_source_host_allowed( $parts['host'] ) ) {
					return $url;
				}
				$out = $base_url . $parts['path'];
				if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
					$out .= '?' . $parts['query'];
				}
				if ( isset( $parts['fragment'] ) && '' !== $parts['fragment'] ) {
					$out .= '#' . $parts['fragment'];
				}
				return $out;
			},
			$html
		);
		return is_string( $rewritten ) ? $rewritten : $html;
	}

	/** 将 HTML 中站内绝对链接改为根相对。 */
	public function rewrite_html_to_relative( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		$rewritten = preg_replace_callback(
			'#https?://[^\s"\'<>)]+#i',
			function ( $matches ) {
				$url   = isset( $matches[0] ) ? $matches[0] : '';
				$parts = wp_parse_url( $url );
				if ( empty( $parts['host'] ) || empty( $parts['path'] ) || ! $this->is_source_host_allowed( $parts['host'] ) ) {
					return $url;
				}
				$out = $parts['path'];
				if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
					$out .= '?' . $parts['query'];
				}
				if ( isset( $parts['fragment'] ) && '' !== $parts['fragment'] ) {
					$out .= '#' . $parts['fragment'];
				}
				return $out;
			},
			$html
		);
		return is_string( $rewritten ) ? $rewritten : $html;
	}

	/** 菜单链接属性。 */
	public function rewrite_nav_menu_link_attributes( $atts ) {
		if ( isset( $atts['href'] ) ) {
			$atts['href'] = $this->rewrite_url( $atts['href'] );
		}
		return $atts;
	}

	/** 当前 Host，保留端口。 */
	public function get_current_host_with_port() {
		$raw_host = isset( $_SERVER['HTTP_HOST'] ) ? wp_unslash( $_SERVER['HTTP_HOST'] ) : '';
		$raw_host = trim( (string) $raw_host );
		if ( '' === $raw_host ) {
			return '';
		}
		$raw_host = preg_replace( '/[^a-zA-Z0-9\-\.\[\]:]/', '', $raw_host );
		return strtolower( $raw_host );
	}

	/** 当前 Host，不含端口。 */
	public function get_current_host_without_port() {
		return $this->normalize_host( $this->get_current_host_with_port() );
	}

	/** 当前协议。 */
	public function get_current_scheme() {
		$options = JRMU_Settings::get_options();
		$scheme  = isset( $options['domain_scheme'] ) ? $options['domain_scheme'] : 'https';
		if ( 'http' === $scheme ) {
			return 'http';
		}
		if ( 'auto' !== $scheme ) {
			return 'https';
		}
		if ( is_ssl() ) {
			return 'https';
		}
		$forwarded_proto = isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ? strtolower( (string) wp_unslash( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) : '';
		if ( false !== strpos( $forwarded_proto, 'https' ) ) {
			return 'https';
		}
		$forwarded_ssl = isset( $_SERVER['HTTP_X_FORWARDED_SSL'] ) ? strtolower( (string) wp_unslash( $_SERVER['HTTP_X_FORWARDED_SSL'] ) ) : '';
		return 'on' === $forwarded_ssl ? 'https' : 'http';
	}

	/** 当前 Host 是否允许。 */
	public function is_current_host_allowed( $host ) {
		$options = JRMU_Settings::get_options();
		$host    = $this->normalize_host( $host );
		if ( ! $host ) {
			return false;
		}
		if ( 'any' === $options['domain_mode'] ) {
			return true;
		}
		return in_array( $host, $this->get_allowed_domain_hosts(), true );
	}

	/** 源 Host 是否允许改写。 */
	public function is_source_host_allowed( $host ) {
		$host = $this->normalize_host( $host );
		return $host && in_array( $host, $this->get_rewrite_source_hosts(), true );
	}

	/** 多域名白名单。 */
	public function get_allowed_domain_hosts() {
		$options = JRMU_Settings::get_options();
		$hosts   = array();
		if ( ! empty( $options['domain_allowed_hosts'] ) ) {
			foreach ( preg_split( '/\r\n|\r|\n/', $options['domain_allowed_hosts'] ) as $line ) {
				$host = $this->extract_host_from_line( $line );
				if ( $host ) {
					$hosts[] = $host;
				}
			}
		}
		return apply_filters( 'jrmu_domain_allowed_hosts', array_values( array_unique( array_filter( $hosts ) ) ) );
	}

	/** 可改写来源域名。 */
	public function get_rewrite_source_hosts() {
		$hosts   = $this->get_allowed_domain_hosts();
		$options = JRMU_Settings::get_options();
		if ( ! empty( $options['extra_hosts'] ) ) {
			foreach ( preg_split( '/\r\n|\r|\n/', $options['extra_hosts'] ) as $line ) {
				$host = $this->extract_host_from_line( $line );
				if ( $host ) {
					$hosts[] = $host;
				}
			}
		}
		foreach ( array( 'home', 'siteurl' ) as $option ) {
			$url  = $this->get_raw_option_value( $option );
			$host = wp_parse_url( $url, PHP_URL_HOST );
			if ( $host ) {
				$hosts[] = $this->normalize_host( $host );
			}
		}
		return apply_filters( 'jrmu_domain_rewrite_source_hosts', array_values( array_unique( array_filter( $hosts ) ) ) );
	}

	/** 获取状态。 */
	public function get_debug_info() {
		$current_host = $this->get_current_host_with_port();
		$base_url     = $this->get_dynamic_base_url_or_false();
		return array(
			'current_host'     => $current_host,
			'current_scheme'   => $this->get_current_scheme(),
			'is_allowed'       => $current_host ? $this->is_current_host_allowed( $current_host ) : false,
			'dynamic_base_url' => $base_url ? $base_url : '',
			'allowed_hosts'    => $this->get_allowed_domain_hosts(),
			'source_hosts'     => $this->get_rewrite_source_hosts(),
		);
	}

	/** 统计可改写站内绝对链接。 */
	public function count_rewriteable_urls( $content ) {
		return count( $this->get_rewriteable_url_samples( $content, 999999, 'https://example.com' ) );
	}

	/** 样本。 */
	public function get_rewriteable_url_samples( $content, $limit = 5, $target_base = '' ) {
		$samples = array();
		if ( ! is_string( $content ) || '' === $content ) {
			return $samples;
		}
		$target_base = $target_base ? untrailingslashit( esc_url_raw( $target_base ) ) : '';
		preg_replace_callback(
			'#https?://[^\s"\'<>)]+#i',
			function ( $matches ) use ( &$samples, $limit, $target_base ) {
				if ( count( $samples ) >= $limit ) {
					return isset( $matches[0] ) ? $matches[0] : '';
				}
				$url   = isset( $matches[0] ) ? $matches[0] : '';
				$parts = wp_parse_url( $url );
				if ( empty( $parts['host'] ) || empty( $parts['path'] ) || ! $this->is_source_host_allowed( $parts['host'] ) ) {
					return $url;
				}
				$to = $target_base ? $target_base . $parts['path'] : $parts['path'];
				if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
					$to .= '?' . $parts['query'];
				}
				if ( isset( $parts['fragment'] ) && '' !== $parts['fragment'] ) {
					$to .= '#' . $parts['fragment'];
				}
				$samples[] = array( 'from' => $url, 'to' => $to, 'type' => '站内链接' );
				return $url;
			},
			$content
		);
		return $samples;
	}

	/** 登录页请求。 */
	private function is_login_request() {
		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? (string) wp_unslash( $_SERVER['SCRIPT_NAME'] ) : '';
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		return false !== strpos( $script, 'wp-login.php' ) || false !== strpos( $uri, 'wp-login.php' );
	}

	/** 系统端点。 */
	private function is_system_endpoint_request() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? strtolower( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $uri ) {
			return false;
		}
		$needles = array( '/wp-json', 'rest_route=', '/feed', 'feed=', 'sitemap', '.xml', 'robots.txt', 'wp-cron.php', 'admin-ajax.php' );
		foreach ( $needles as $needle ) {
			if ( false !== strpos( $uri, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/** 提取域名。 */
	private function extract_host_from_line( $line ) {
		$line = trim( strtolower( (string) $line ) );
		if ( '' === $line ) {
			return '';
		}
		if ( false === strpos( $line, '://' ) ) {
			$line = 'https://' . $line;
		}
		$host = wp_parse_url( $line, PHP_URL_HOST );
		return $host ? $this->normalize_host( $host ) : '';
	}

	/** 标准化域名。 */
	private function normalize_host( $host ) {
		$host = strtolower( trim( (string) $host ) );
		$host = preg_replace( '/:\d+$/', '', $host );
		$host = preg_replace( '/[^a-z0-9\-\.]/i', '', $host );
		return $host;
	}

	/** 获取未过滤 option。 */
	private function get_raw_option_value( $option ) {
		$this->bypass_options = true;
		$value                = get_option( $option );
		$this->bypass_options = false;
		return $value;
	}
}
