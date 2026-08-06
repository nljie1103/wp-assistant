<?php
/** 九流WP助手：下载页热门资源封面回退与主封面横竖图真实比例修复。 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'JLWA_Download_Page_Cover_Fix' ) ) {
	final class JLWA_Download_Page_Cover_Fix {
		const OPTION_KEY = 'jlwa_download_page_options';

		/** @var JLWA_Download_Page_Cover_Fix|null */
		private static $instance = null;

		/** @var bool */
		private $active = false;

		/** @return JLWA_Download_Page_Cover_Fix */
		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		private function __construct() {
			add_action( 'template_redirect', array( $this, 'prepare_download_page' ), 0 );
		}

		public function prepare_download_page() {
			$options = $this->options();
			if ( empty( $options['enabled'] ) || ! $this->is_download_request() ) {
				return;
			}

			$this->active = true;
			add_filter( 'post_thumbnail_html', array( $this, 'filter_hot_resource_thumbnail' ), 10, 5 );

			wp_enqueue_style(
				'jlwa-download-cover-fix',
				JLWA_PLUGIN_URL . 'assets/css/download-page-cover-fix.css',
				array(),
				JLWA_VERSION
			);
			wp_enqueue_script(
				'jlwa-download-cover-fix',
				JLWA_PLUGIN_URL . 'assets/js/download-page-cover-fix.js',
				array(),
				JLWA_VERSION,
				true
			);
		}

		/**
		 * 热门资源仍调用 get_the_post_thumbnail()。没有特色图时，在这里补上与主封面一致的回退图片。
		 *
		 * @param string       $html              原缩略图 HTML。
		 * @param int|WP_Post  $post_id           文章 ID。
		 * @param int          $post_thumbnail_id 特色图 ID。
		 * @param string|array $size              图片尺寸。
		 * @param string|array $attr              图片属性。
		 * @return string
		 */
		public function filter_hot_resource_thumbnail( $html, $post_id, $post_thumbnail_id, $size, $attr ) {
			if ( ! $this->active || '' !== trim( (string) $html ) ) {
				return $html;
			}

			$post_id = is_object( $post_id ) && isset( $post_id->ID ) ? absint( $post_id->ID ) : absint( $post_id );
			if ( ! $post_id ) {
				return $html;
			}

			$cover = $this->resolve_cover( $post_id );
			if ( empty( $cover['url'] ) ) {
				return $html;
			}

			$title  = get_the_title( $post_id );
			$width  = ! empty( $cover['width'] ) ? ' width="' . absint( $cover['width'] ) . '"' : '';
			$height = ! empty( $cover['height'] ) ? ' height="' . absint( $cover['height'] ) . '"' : '';

			return '<img class="attachment-thumbnail size-thumbnail wp-post-image jlwa-hot-fallback-image" src="' . esc_url( $cover['url'] ) . '" alt="' . esc_attr( $title ) . '" loading="lazy" decoding="async" data-jlwa-cover-source="' . esc_attr( $cover['source'] ) . '"' . $width . $height . '>';
		}

		/** @return array<string,mixed> */
		private function options() {
			$defaults = array(
				'enabled'           => 0,
				'cover_mode'        => 'auto',
				'default_image_url' => '',
			);
			$saved = get_option( self::OPTION_KEY, array() );
			return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
		}

		/** @return bool */
		private function is_download_request() {
			if ( is_admin() || wp_doing_ajax() || empty( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return false;
			}

			$post_id = absint( wp_unslash( $_GET['post'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$post    = get_post( $post_id );
			$meta    = get_post_meta( $post_id, 'posts_zibpay', true );
			if ( ! $post || 'publish' !== $post->post_status || ! is_array( $meta ) || '2' !== (string) ( $meta['pay_type'] ?? '' ) ) {
				return false;
			}

			$page_id = get_queried_object_id();
			$slug    = $page_id ? str_replace( '\\', '/', (string) get_page_template_slug( $page_id ) ) : '';
			return 'download.php' === basename( $slug ) || false !== strpos( $slug, 'pages/download.php' ) || (bool) apply_filters( 'jlwa_download_page_force_request', false, $post_id );
		}

		/** @return array<string,mixed> */
		private function resolve_cover( $post_id ) {
			$options = $this->options();
			$mode    = isset( $options['cover_mode'] ) ? sanitize_key( $options['cover_mode'] ) : 'auto';
			if ( ! in_array( $mode, array( 'auto', 'featured', 'first', 'random', 'default' ), true ) ) {
				$mode = 'auto';
			}

			$cover = array();
			if ( in_array( $mode, array( 'auto', 'featured' ), true ) ) {
				$featured_id = get_post_thumbnail_id( $post_id );
				if ( $featured_id ) {
					$cover = $this->attachment_data( $featured_id, 'featured' );
				}
			}

			if ( empty( $cover['url'] ) && in_array( $mode, array( 'auto', 'first' ), true ) ) {
				$candidates = $this->content_candidates( $post_id, false );
				if ( $candidates ) {
					$cover = $candidates[0];
					$cover['source'] = 'first';
				}
			}

			if ( empty( $cover['url'] ) && in_array( $mode, array( 'auto', 'random' ), true ) ) {
				$candidates = $this->content_candidates( $post_id, true );
				if ( $candidates ) {
					$index = abs( (int) crc32( get_current_blog_id() . ':' . absint( $post_id ) ) ) % count( $candidates );
					$cover = $candidates[ $index ];
					$cover['source'] = 'random';
				}
			}

			if ( empty( $cover['url'] ) ) {
				$cover = $this->default_cover_data( $options );
			}

			return $cover;
		}

		/** @return array<string,mixed> */
		private function default_cover_data( $options ) {
			$url = ! empty( $options['default_image_url'] ) ? $this->normalize_url( $options['default_image_url'] ) : '';
			if ( ! $url ) {
				$url = JLWA_PLUGIN_URL . 'assets/default-download-cover.svg';
			}

			$attachment_id = function_exists( 'attachment_url_to_postid' ) ? absint( attachment_url_to_postid( $url ) ) : 0;
			if ( $attachment_id ) {
				$data = $this->attachment_data( $attachment_id, 'default' );
				if ( ! empty( $data['url'] ) ) {
					return $data;
				}
			}

			return array(
				'id'     => 0,
				'url'    => $url,
				'width'  => 1200,
				'height' => 900,
				'source' => 'default',
			);
		}

		/** @return array<string,mixed> */
		private function attachment_data( $attachment_id, $source = 'attachment' ) {
			$attachment_id = absint( $attachment_id );
			if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
				return array();
			}

			foreach ( array( 'large', 'full' ) as $size ) {
				$data = wp_get_attachment_image_src( $attachment_id, $size );
				$url  = ! empty( $data[0] ) ? $this->normalize_url( $data[0] ) : '';
				if ( $url ) {
					return array(
						'id'     => $attachment_id,
						'url'    => $url,
						'width'  => ! empty( $data[1] ) ? absint( $data[1] ) : 0,
						'height' => ! empty( $data[2] ) ? absint( $data[2] ) : 0,
						'source' => $source,
					);
				}
			}

			$url = $this->normalize_url( wp_get_attachment_url( $attachment_id ) );
			return $url ? array( 'id' => $attachment_id, 'url' => $url, 'width' => 0, 'height' => 0, 'source' => $source ) : array();
		}

		/** @return array<int,array<string,mixed>> */
		private function content_candidates( $post_id, $include_attached ) {
			$content    = (string) get_post_field( 'post_content', absint( $post_id ) );
			$candidates = array();

			$push = function ( $url, $attachment_id = 0, $width = 0, $height = 0 ) use ( &$candidates ) {
				$url = $this->normalize_url( $url );
				if ( ! $url ) {
					return;
				}
				$key = strtolower( $url );
				if ( ! isset( $candidates[ $key ] ) ) {
					$candidates[ $key ] = array(
						'id'     => absint( $attachment_id ),
						'url'    => $url,
						'width'  => absint( $width ),
						'height' => absint( $height ),
						'source' => 'content',
					);
				}
			};

			if ( $content && preg_match_all( '/<img\b[^>]*>/i', $content, $matches ) ) {
				foreach ( $matches[0] as $tag ) {
					if ( preg_match( '/\bclass=["\'][^"\']*(?:avatar|emoji|logo|icon|placeholder|loading|spinner|pixel)[^"\']*["\']/i', $tag ) ) {
						continue;
					}

					$attachment_id = 0;
					if ( preg_match( '/\bwp-image-(\d+)\b/i', $tag, $id_match ) || preg_match( '/\bdata-(?:attachment-)?id=["\'](\d+)["\']/i', $tag, $id_match ) ) {
						$attachment_id = absint( $id_match[1] );
					}
					if ( $attachment_id ) {
						$data = $this->attachment_data( $attachment_id, 'content' );
						if ( ! empty( $data['url'] ) ) {
							$push( $data['url'], $attachment_id, $data['width'], $data['height'] );
							continue;
						}
					}

					$url = '';
					foreach ( array( 'data-src', 'data-lazy-src', 'data-original', 'data-url', 'src' ) as $attribute ) {
						if ( preg_match( '/\b' . preg_quote( $attribute, '/' ) . '=["\']([^"\']+)["\']/i', $tag, $url_match ) ) {
							$url = $this->normalize_url( $url_match[1] );
							if ( $url ) {
								break;
							}
						}
					}
					if ( ! $url && preg_match( '/\bsrcset=["\']([^"\']+)["\']/i', $tag, $srcset_match ) ) {
						$url = $this->srcset_url( $srcset_match[1] );
					}
					if ( $url ) {
						$mapped_id = function_exists( 'attachment_url_to_postid' ) ? absint( attachment_url_to_postid( $url ) ) : 0;
						if ( $mapped_id ) {
							$data = $this->attachment_data( $mapped_id, 'content' );
							$push( $data['url'], $mapped_id, $data['width'], $data['height'] );
						} else {
							$push( $url );
						}
					}
				}
			}

			if ( $include_attached ) {
				foreach ( (array) get_attached_media( 'image', absint( $post_id ) ) as $attachment ) {
					$data = $this->attachment_data( $attachment->ID, 'attachment' );
					if ( ! empty( $data['url'] ) ) {
						$push( $data['url'], $attachment->ID, $data['width'], $data['height'] );
					}
				}
			}

			return array_values( $candidates );
		}

		/** @return string */
		private function srcset_url( $srcset ) {
			$best_url   = '';
			$best_width = -1;
			foreach ( explode( ',', (string) $srcset ) as $candidate ) {
				$parts = preg_split( '/\s+/', trim( $candidate ) );
				$url   = ! empty( $parts[0] ) ? $this->normalize_url( $parts[0] ) : '';
				if ( ! $url ) {
					continue;
				}
				$width = 0;
				if ( ! empty( $parts[1] ) && preg_match( '/^(\d+)w$/i', $parts[1], $match ) ) {
					$width = absint( $match[1] );
				}
				if ( $width >= $best_width ) {
					$best_width = $width;
					$best_url   = $url;
				}
			}
			return $best_url;
		}

		/** @return string */
		private function normalize_url( $url ) {
			$url = html_entity_decode( trim( (string) $url ), ENT_QUOTES, 'UTF-8' );
			$url = trim( $url, " \t\n\r\0\x0B\"'" );
			if ( ! $url || 0 === stripos( $url, 'data:' ) || 0 === stripos( $url, 'blob:' ) ) {
				return '';
			}
			if ( 0 === strpos( $url, '//' ) ) {
				$url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
			} elseif ( 0 === strpos( $url, '/' ) ) {
				$url = home_url( $url );
			}
			if ( ! preg_match( '#^https?://#i', $url ) ) {
				return '';
			}
			if ( preg_match( '#(?:transparent|spacer|blank|loading|placeholder|spinner|pixel)(?:[-_.][^/?#]*)?\.(?:gif|png|webp|svg)(?:[?#]|$)#i', $url ) ) {
				return '';
			}
			return esc_url_raw( $url );
		}
	}
}

JLWA_Download_Page_Cover_Fix::instance();
