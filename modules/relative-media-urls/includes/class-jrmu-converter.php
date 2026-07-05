<?php
/**
 * URL 转换核心类。
 *
 * @package JiuliuRelativeMediaUrls
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * JRMU_Converter
 */
class JRMU_Converter {
	/** @var JRMU_Converter|null */
	private static $instance = null;

	/**
	 * 获取单例。
	 *
	 * @return JRMU_Converter
	 */
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

	/** 初始化转换钩子。 */
	private function init_hooks() {
		$options = JRMU_Settings::get_options();

		if ( ! empty( $options['convert_post_on_save'] ) ) {
			add_filter( 'content_save_pre', array( $this, 'convert_content' ), 99 );
			add_filter( 'excerpt_save_pre', array( $this, 'convert_content' ), 99 );
		}

		if ( ! empty( $options['convert_post_on_frontend'] ) ) {
			add_filter( 'the_content', array( $this, 'convert_content' ), 99 );
			add_filter( 'the_excerpt', array( $this, 'convert_content' ), 99 );
			add_filter( 'widget_text', array( $this, 'convert_content' ), 99 );
			add_filter( 'widget_text_content', array( $this, 'convert_content' ), 99 );
			add_filter( 'render_block', array( $this, 'convert_content' ), 99 );
		}

		if ( ! empty( $options['convert_existing_media_output'] ) || ! empty( $options['convert_future_media_output'] ) ) {
			add_filter( 'wp_get_attachment_url', array( $this, 'convert_attachment_url' ), 99, 2 );
			add_filter( 'wp_get_attachment_image_attributes', array( $this, 'convert_attachment_image_attributes' ), 99, 3 );
			add_filter( 'wp_calculate_image_srcset', array( $this, 'convert_srcset' ), 99, 5 );
			add_filter( 'wp_prepare_attachment_for_js', array( $this, 'convert_attachment_for_js' ), 99, 3 );
			add_filter( 'post_thumbnail_html', array( $this, 'convert_content' ), 99 );
		}

		if ( ! empty( $options['convert_future_media_output'] ) ) {
			add_action( 'add_attachment', array( $this, 'mark_new_attachment' ), 10, 1 );
		}
	}

	/**
	 * 标记新附件。
	 *
	 * @param int $attachment_id 附件 ID。
	 */
	public function mark_new_attachment( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( $attachment_id ) {
			update_post_meta( $attachment_id, JRMU_ATTACHMENT_META_KEY, time() );
		}
	}

	/**
	 * 判断附件是否应转换。
	 *
	 * @param int $attachment_id 附件 ID。
	 * @return bool
	 */
	public function should_convert_attachment( $attachment_id ) {
		$options = JRMU_Settings::get_options();

		if ( ! empty( $options['convert_existing_media_output'] ) ) {
			return true;
		}

		if ( empty( $options['convert_future_media_output'] ) ) {
			return false;
		}

		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id ) {
			return false;
		}

		$marked_at = absint( get_post_meta( $attachment_id, JRMU_ATTACHMENT_META_KEY, true ) );
		if ( $marked_at > 0 ) {
			return true;
		}

		$enabled_at = ! empty( $options['future_media_enabled_at'] ) ? absint( $options['future_media_enabled_at'] ) : 0;
		$post_time  = get_post_time( 'U', true, $attachment_id );

		return $enabled_at > 0 && $post_time && $post_time >= $enabled_at;
	}

	/**
	 * 转换附件 URL。
	 *
	 * @param string $url URL。
	 * @param int    $attachment_id 附件 ID。
	 * @return string
	 */
	public function convert_attachment_url( $url, $attachment_id = 0 ) {
		return $this->should_convert_attachment( $attachment_id ) ? $this->convert_url( $url ) : $url;
	}

	/**
	 * 转换内容中的绝对媒体 URL 为根相对 URL。
	 *
	 * @param string $content 内容。
	 * @return string
	 */
	public function convert_content( $content ) {
		if ( ! is_string( $content ) || '' === $content ) {
			return $content;
		}

		$converted = preg_replace_callback(
			'#https?://[^\s"\'<>)]+#i',
			function ( $matches ) {
				$url = isset( $matches[0] ) ? $matches[0] : '';
				return $this->convert_url( $url );
			},
			$content
		);

		return is_string( $converted ) ? $converted : $content;
	}

	/**
	 * 将绝对 URL 转根相对。
	 *
	 * @param string $url 原始 URL。
	 * @return string
	 */
	public function convert_url( $url ) {
		if ( ! is_string( $url ) || '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}

		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) || empty( $parts['path'] ) ) {
			return $url;
		}

		$host = strtolower( $parts['host'] );
		if ( ! $this->is_allowed_host( $host ) || ! $this->is_allowed_path( $parts['path'] ) ) {
			return $url;
		}

		$relative = $parts['path'];
		if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
			$relative .= '?' . $parts['query'];
		}
		if ( isset( $parts['fragment'] ) && '' !== $parts['fragment'] ) {
			$relative .= '#' . $parts['fragment'];
		}

		return $relative;
	}

	/**
	 * 恢复内容中的根相对媒体 URL 为完整 URL。
	 *
	 * @param string $content 内容。
	 * @param string $base_url 目标基础 URL。
	 * @return string
	 */
	public function restore_content_to_domain( $content, $base_url ) {
		if ( ! is_string( $content ) || '' === $content ) {
			return $content;
		}

		$base_url = untrailingslashit( esc_url_raw( $base_url ) );
		if ( ! $base_url || ! preg_match( '#^https?://#i', $base_url ) ) {
			return $content;
		}

		$paths = array();
		foreach ( $this->get_allowed_paths() as $path_prefix ) {
			$paths[] = preg_quote( ltrim( $path_prefix, '/' ), '#' );
		}
		if ( empty( $paths ) ) {
			return $content;
		}

		$pattern = '#(^|["\'\s\(,=])(/(?:' . implode( '|', $paths ) . ')[^"\'\s<>)]+)#i';
		$restored = preg_replace_callback(
			$pattern,
			function ( $matches ) use ( $base_url ) {
				$prefix = isset( $matches[1] ) ? $matches[1] : '';
				$path   = isset( $matches[2] ) ? $matches[2] : '';
				if ( preg_match( '#^https?://#i', $path ) ) {
					return $matches[0];
				}
				return $prefix . $base_url . $path;
			},
			$content
		);

		return is_string( $restored ) ? $restored : $content;
	}

	/**
	 * 转换 srcset。
	 */
	public function convert_srcset( $sources, $size_array = array(), $image_src = '', $image_meta = array(), $attachment_id = 0 ) {
		if ( ! is_array( $sources ) || ! $this->should_convert_attachment( $attachment_id ) ) {
			return $sources;
		}
		foreach ( $sources as &$source ) {
			if ( isset( $source['url'] ) ) {
				$source['url'] = $this->convert_url( $source['url'] );
			}
		}
		return $sources;
	}

	/**
	 * 转换图片属性。
	 */
	public function convert_attachment_image_attributes( $attr, $attachment = null, $size = '' ) {
		$attachment_id = isset( $attachment->ID ) ? absint( $attachment->ID ) : 0;
		if ( ! is_array( $attr ) || ! $this->should_convert_attachment( $attachment_id ) ) {
			return $attr;
		}

		foreach ( array( 'src', 'data-src', 'data-lazy-src', 'data-original' ) as $key ) {
			if ( isset( $attr[ $key ] ) ) {
				$attr[ $key ] = $this->convert_url( $attr[ $key ] );
			}
		}
		if ( isset( $attr['srcset'] ) && is_string( $attr['srcset'] ) ) {
			$attr['srcset'] = $this->convert_content( $attr['srcset'] );
		}
		return $attr;
	}

	/**
	 * 转换媒体库 JS 响应。
	 */
	public function convert_attachment_for_js( $response, $attachment = null, $meta = array() ) {
		$attachment_id = isset( $attachment->ID ) ? absint( $attachment->ID ) : 0;
		if ( ! is_array( $response ) || ! $this->should_convert_attachment( $attachment_id ) ) {
			return $response;
		}
		foreach ( array( 'url', 'link' ) as $key ) {
			if ( isset( $response[ $key ] ) ) {
				$response[ $key ] = $this->convert_url( $response[ $key ] );
			}
		}
		if ( isset( $response['sizes'] ) && is_array( $response['sizes'] ) ) {
			foreach ( $response['sizes'] as &$size ) {
				if ( isset( $size['url'] ) ) {
					$size['url'] = $this->convert_url( $size['url'] );
				}
			}
		}
		return $response;
	}

	/**
	 * 允许转换的域名。
	 *
	 * @return array
	 */
	public function get_allowed_hosts() {
		$options = JRMU_Settings::get_options();
		$hosts   = array();

		foreach ( array( home_url(), site_url(), content_url(), includes_url(), network_home_url() ) as $url ) {
			$host = wp_parse_url( $url, PHP_URL_HOST );
			if ( $host ) {
				$hosts[] = strtolower( $host );
			}
		}

		// 同时读取数据库原始 home/siteurl，避免多域名动态适配开启后只识别当前入口域名。
		$alloptions = wp_load_alloptions();
		foreach ( array( 'home', 'siteurl' ) as $option_key ) {
			if ( ! empty( $alloptions[ $option_key ] ) ) {
				$host = wp_parse_url( $alloptions[ $option_key ], PHP_URL_HOST );
				if ( $host ) {
					$hosts[] = strtolower( $host );
				}
			}
		}

		foreach ( array( 'extra_hosts', 'domain_allowed_hosts' ) as $key ) {
			if ( ! empty( $options[ $key ] ) ) {
				$lines = preg_split( '/\r\n|\r|\n/', $options[ $key ] );
				foreach ( $lines as $line ) {
					$line = strtolower( trim( $line ) );
					if ( $line ) {
						$hosts[] = $line;
					}
				}
			}
		}

		return apply_filters( 'jrmu_allowed_hosts', array_values( array_filter( array_unique( $hosts ) ) ) );
	}

	/**
	 * 判断域名是否允许。
	 */
	public function is_allowed_host( $host ) {
		return in_array( strtolower( (string) $host ), $this->get_allowed_hosts(), true );
	}

	/**
	 * 允许转换路径。
	 *
	 * @return array
	 */
	public function get_allowed_paths() {
		$options = JRMU_Settings::get_options();
		$paths   = array();

		if ( ! empty( $options['target_uploads'] ) ) {
			$uploads = wp_get_upload_dir();
			if ( ! empty( $uploads['baseurl'] ) ) {
				$paths[] = wp_parse_url( $uploads['baseurl'], PHP_URL_PATH );
			}
			$paths[] = '/wp-content/uploads';
		}
		if ( ! empty( $options['target_themes'] ) ) {
			$paths[] = wp_parse_url( content_url( 'themes' ), PHP_URL_PATH );
			$paths[] = '/wp-content/themes';
		}
		if ( ! empty( $options['target_plugins'] ) ) {
			$paths[] = wp_parse_url( plugins_url(), PHP_URL_PATH );
			$paths[] = '/wp-content/plugins';
		}

		$paths = array_filter( array_map( array( $this, 'normalize_path_prefix' ), $paths ) );
		return apply_filters( 'jrmu_allowed_paths', array_values( array_unique( $paths ) ) );
	}

	/**
	 * 判断路径是否允许。
	 */
	public function is_allowed_path( $path ) {
		$path = '/' . ltrim( (string) $path, '/' );
		foreach ( $this->get_allowed_paths() as $prefix ) {
			if ( 0 === strpos( $path, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * 统计可转换绝对媒体 URL。
	 */
	public function count_convertible_urls( $content ) {
		return count( $this->get_convertible_url_samples( $content, 999999 ) );
	}

	/**
	 * 获取可转换 URL 样本。
	 */
	public function get_convertible_url_samples( $content, $limit = 5 ) {
		$samples = array();
		if ( ! is_string( $content ) || '' === $content ) {
			return $samples;
		}

		preg_replace_callback(
			'#https?://[^\s"\'<>)]+#i',
			function ( $matches ) use ( &$samples, $limit ) {
				if ( count( $samples ) >= $limit ) {
					return isset( $matches[0] ) ? $matches[0] : '';
				}
				$url       = isset( $matches[0] ) ? $matches[0] : '';
				$converted = $this->convert_url( $url );
				if ( $url && $converted !== $url ) {
					$samples[] = array( 'from' => $url, 'to' => $converted, 'type' => '媒体/静态资源' );
				}
				return $url;
			},
			$content
		);

		return $samples;
	}

	/**
	 * 获取可恢复的根相对媒体 URL 样本。
	 */
	public function get_restorable_url_samples( $content, $base_url, $limit = 5 ) {
		$samples = array();
		if ( ! is_string( $content ) || '' === $content ) {
			return $samples;
		}
		$base_url = untrailingslashit( esc_url_raw( $base_url ) );
		foreach ( $this->get_allowed_paths() as $prefix ) {
			$pattern = '#(^|["\'\s\(,=])(' . preg_quote( $prefix, '#' ) . '/[^"\'\s<>)]+)#i';
			preg_replace_callback(
				$pattern,
				function ( $matches ) use ( &$samples, $limit, $base_url ) {
					if ( count( $samples ) >= $limit ) {
						return $matches[0];
					}
					$path = isset( $matches[2] ) ? $matches[2] : '';
					$samples[] = array( 'from' => $path, 'to' => $base_url . $path, 'type' => '恢复媒体 URL' );
					return $matches[0];
				},
				$content
			);
		}
		return $samples;
	}

	/**
	 * 标准化路径前缀。
	 */
	private function normalize_path_prefix( $path ) {
		$path = '/' . trim( (string) $path, '/' );
		return untrailingslashit( $path );
	}
}
