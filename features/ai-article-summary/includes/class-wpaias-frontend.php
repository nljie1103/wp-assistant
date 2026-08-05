<?php
/**
 * 前端展示：
 *  - 通过 the_content 自动注入（绝大多数主题）
 *  - 通过 wp_footer 输出 <template> + JS DOM 注入（兼容 Zibll / Astra / Divi / Elementor / FSE 等绕过 the_content 的主题）
 *  - 提供 [wpaias_summary] 短代码（手动放置）
 *  - 提供 PHP 模板函数 wpaias_render_summary() （主题作者可调用）。
 *
 * @package WP_AI_Article_Summary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPAIAS_Frontend
 */
class WPAIAS_Frontend {

	/**
	 * 已经渲染过摘要的 post_id 列表，防止重复输出。
	 *
	 * @var array<int,bool>
	 */
	protected $rendered = array();


	/** Return the queried main post instead of a related-post loop item. */
	protected function current_post() {
		$post_id = absint( get_queried_object_id() );
		if ( $post_id ) {
			$post = get_post( $post_id );
			if ( $post ) {
				return $post;
			}
		}
		return get_post();
	}

	/** Extract readable text from classic content and custom HTML articles. */
	protected function extract_source_text( $post, $max_chars ) {
		$content = $post instanceof WP_Post ? (string) $post->post_content : '';
		if ( '' === trim( $content ) ) {
			return '';
		}

		$content = preg_replace( '#<(script|style|noscript|iframe|svg)\\b[^>]*>.*?</\\1>#is', ' ', $content );
		$content = strip_shortcodes( $content );
		$content = preg_replace( '#</(?:p|div|section|article|header|footer|h[1-6]|li|ul|ol|blockquote|pre|table|tr|td|th)>#i', "\\n", $content );
		$content = preg_replace( '#<br\\s*/?>#i', "\\n", $content );
		$content = wp_strip_all_tags( $content, true );
		$content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' );
		$content = preg_replace( '/[\\t\\x{00A0} ]+/u', ' ', $content );
		$content = preg_replace( '/\\n{3,}/u', "\\n\\n", $content );
		$content = trim( (string) $content );

		$max_chars = max( 2000, min( 100000, (int) $max_chars ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $content, 0, $max_chars ) : substr( $content, 0, $max_chars );
	}

	/**
	 * 注册 hooks。
	 */
	public function register() {
		// 主入口：the_content（兼容性最佳，覆盖大多数主题）。
		add_filter( 'the_content', array( $this, 'inject_summary' ), 9 );

		// 资源 & 样式。
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_head', array( $this, 'print_inline_styles' ), 99 );

		// 终极兼容：footer 输出 <template>，JS 智能找位置注入。
		add_action( 'wp_footer', array( $this, 'print_dom_inject_template' ), 1 );

		// 短代码 / 模板函数。
		add_shortcode( 'wpaias_summary', array( $this, 'shortcode_handler' ) );

		// 前端 Ajax 兜底（首次访问自动生成；非登录用户也可使用）。
		add_action( 'wp_ajax_wpaias_front_generate', array( $this, 'ajax_front_generate' ) );
		add_action( 'wp_ajax_nopriv_wpaias_front_generate', array( $this, 'ajax_front_generate' ) );
	}

	/**
	 * 是否应该展示。
	 *
	 * @return bool
	 */
	public function should_show() {
		if ( is_admin() || is_feed() || is_search() ) {
			return false;
		}
		if ( ! is_singular() ) {
			return false;
		}

		$settings = JLWA_AI_Summary_Feature::get_settings();
		if ( empty( $settings['enabled'] ) ) {
			return false;
		}

		$post = $this->current_post();
		if ( ! $post ) {
			return false;
		}

		if ( ! in_array( $post->post_type, (array) $settings['post_types'], true ) ) {
			return false;
		}

		// 排除文章 ID。
		$exclude_ids = array_map( 'intval', array_filter( array_map( 'trim', explode( ',', (string) $settings['exclude_post_ids'] ) ) ) );
		if ( in_array( (int) $post->ID, $exclude_ids, true ) ) {
			return false;
		}

		// 排除分类。
		if ( ! empty( $settings['exclude_categories'] ) ) {
			$cats = wp_get_post_categories( $post->ID );
			if ( array_intersect( array_map( 'intval', $cats ), array_map( 'intval', (array) $settings['exclude_categories'] ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * 注入文章顶部摘要（the_content 模式）。
	 *
	 * @param string $content 文章内容。
	 * @return string
	 */
	public function inject_summary( $content ) {
		if ( ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		if ( ! $this->should_show() ) {
			return $content;
		}

		$post = $this->current_post();
		if ( ! $post ) {
			return $content;
		}

		// 防止同一文章多次注入（widget、related posts 也用 the_content）。
		if ( isset( $this->rendered[ $post->ID ] ) ) {
			return $content;
		}

		$settings = JLWA_AI_Summary_Feature::get_settings();
		$method   = isset( $settings['insert_method'] ) ? $settings['insert_method'] : 'auto';

		// 用户选了 shortcode_only / js / manual 模式时，跳过 the_content 注入。
		if ( in_array( $method, array( 'shortcode_only', 'js', 'manual' ), true ) ) {
			return $content;
		}

		$cached = WPAIAS_Cache::get( $post->ID );
		$html   = $this->build_card_html( $post, false === $cached ? '' : (string) $cached, $settings );
		if ( '' === $html ) {
			return $content;
		}

		$this->rendered[ $post->ID ] = true;

		$position = isset( $settings['position'] ) ? $settings['position'] : 'before_content';
		switch ( $position ) {
			case 'after_first_paragraph':
				$pos = stripos( $content, '</p>' );
				if ( false !== $pos ) {
					return substr( $content, 0, $pos + 4 ) . $html . substr( $content, $pos + 4 );
				}
				return $html . $content;

			case 'after_title':
			case 'before_content':
			default:
				return $html . $content;
		}
	}

	/**
	 * 短代码：[wpaias_summary]
	 *
	 * @param array|string $atts shortcode atts.
	 * @return string
	 */
	public function shortcode_handler( $atts ) {
		if ( is_feed() ) {
			return '';
		}
		$post = $this->current_post();
		if ( ! $post ) {
			return '';
		}

		// 短代码下，强制视为已渲染，避免与自动注入重复。
		$this->rendered[ $post->ID ] = true;

		$settings = JLWA_AI_Summary_Feature::get_settings();
		$cached   = WPAIAS_Cache::get( $post->ID );

		return $this->build_card_html( $post, false === $cached ? '' : (string) $cached, $settings );
	}

	/**
	 * footer 输出兼容性兜底模板：
	 *   - 当主题完全绕过 the_content（如 Zibll / Elementor / Divi / FSE 等）时，
	 *     由 JS 把模板内容根据 CSS 选择器插入到文章容器中。
	 *   - 当 the_content 已成功注入时，本逻辑会被 JS 检测到 .wpaias-summary 已存在而跳过。
	 *
	 * @return void
	 */
	public function print_dom_inject_template() {
		if ( ! $this->should_show() ) {
			return;
		}
		$settings = JLWA_AI_Summary_Feature::get_settings();
		$method   = isset( $settings['insert_method'] ) ? $settings['insert_method'] : 'auto';

		// 手动 / shortcode_only 模式，不输出 DOM 注入模板。
		if ( in_array( $method, array( 'manual', 'shortcode_only' ), true ) ) {
			return;
		}

		// content_filter 模式下，如果 the_content 没成功（页面里没找到 .wpaias-summary），JS 也会兜底注入。
		// 这里始终输出模板，让 JS 自己判断。

		$post = $this->current_post();
		if ( ! $post ) {
			return;
		}

		$cached    = WPAIAS_Cache::get( $post->ID );
		$html      = $this->build_card_html( $post, false === $cached ? '' : (string) $cached, $settings );
		if ( '' === $html ) {
			return;
		}
		$selectors = isset( $settings['js_selector'] ) ? $settings['js_selector'] : '';
		if ( '' === trim( $selectors ) ) {
			$selectors = '.entry-content, .post-content, .article-content, .single-content';
		}
		$position = isset( $settings['js_position'] ) ? $settings['js_position'] : 'prepend';
		if ( ! in_array( $position, array( 'prepend', 'append', 'before', 'after' ), true ) ) {
			$position = 'prepend';
		}

		// 输出隐藏的 template 元素，由 frontend.js 负责注入。
		?>
		<template id="wpaias-summary-template"
			data-selectors="<?php echo esc_attr( $selectors ); ?>"
			data-position="<?php echo esc_attr( $position ); ?>"
			data-method="<?php echo esc_attr( $method ); ?>"><?php echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — already escaped in build_card_html ?></template>
		<?php
	}

	/**
	 * 构建卡片 HTML。
	 *
	 * @param WP_Post $post     文章。
	 * @param string  $summary  已有缓存（空表示需要前端 ajax 拉取）。
	 * @param array   $settings 设置。
	 * @return string
	 */
	protected function build_card_html( $post, $summary, $settings ) {
		$title    = $settings['title'] !== '' ? $settings['title'] : __( 'AI 智能摘要', 'wp-ai-article-summary' );
		$anim     = $settings['animation'];
		$duration = (int) $settings['anim_duration'];
		$speed    = (int) $settings['type_speed'];
		$delay    = (int) $settings['anim_delay'];
		$cursor   = (int) $settings['cursor_enable'];
		$color    = $settings['cursor_color'];

		$can_generate = ! empty( $settings['public_generation'] ) || current_user_can( 'edit_posts' );
		$state = ( '' === $summary ) ? ( $can_generate ? 'loading' : 'waiting' ) : 'ready';

		$data_attrs = sprintf(
			'data-post-id="%d" data-anim="%s" data-duration="%d" data-speed="%d" data-delay="%d" data-cursor="%d" data-color="%s" data-state="%s"',
			(int) $post->ID,
			esc_attr( $anim ),
			$duration,
			$speed,
			$delay,
			$cursor,
			esc_attr( $color ),
			esc_attr( $state )
		);

		// 卡片预设样式 class + 内联 CSS 变量（颜色自定义）。
		$style_class = '';
		$inline_vars = '';
		if ( class_exists( 'WPAIAS_Styles' ) ) {
			$style_class = WPAIAS_Styles::get_decoration_class( $settings );
			$inline_vars = WPAIAS_Styles::build_inline_vars( $settings );
		}
		$class_attr = 'wpaias-summary wpaias-anim-' . sanitize_html_class( $anim );
		if ( $style_class ) {
			$class_attr .= ' ' . sanitize_html_class( $style_class );
		}
		$style_attr = $inline_vars ? ' style="' . esc_attr( $inline_vars ) . '"' : '';

		$decoration_type = isset( $settings['decoration_type'] ) ? $settings['decoration_type'] : 'none';
		$decoration_position = isset( $settings['decoration_position'] ) ? sanitize_html_class( $settings['decoration_position'] ) : 'top-right';
		$decoration_style = sprintf(
			'--wpaias-decoration-size:%dpx;--wpaias-decoration-opacity:%s;--wpaias-decoration-x:%dpx;--wpaias-decoration-y:%dpx;',
			max( 20, min( 240, (int) $settings['decoration_size'] ) ),
			esc_attr( (string) max( 0.05, min( 1, (float) $settings['decoration_opacity'] ) ) ),
			max( -200, min( 200, (int) $settings['decoration_offset_x'] ) ),
			max( -200, min( 200, (int) $settings['decoration_offset_y'] ) )
		);
		$builtin_icons = array( 'sparkles' => '✨', 'robot' => '🤖', 'brain' => '🧠', 'quill' => '🪶', 'lightbulb' => '💡', 'stars' => '🌟' );
		$builtin_key = isset( $settings['decoration_builtin'] ) ? $settings['decoration_builtin'] : 'sparkles';
		$builtin_icon = isset( $builtin_icons[ $builtin_key ] ) ? $builtin_icons[ $builtin_key ] : '✨';

		ob_start();
		?>
		<aside class="<?php echo esc_attr( $class_attr ); ?>"<?php echo $style_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo $data_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<?php if ( 'none' !== $decoration_type ) : ?>
				<span class="wpaias-summary__decoration is-<?php echo esc_attr( $decoration_position ); ?>" style="<?php echo esc_attr( $decoration_style ); ?>" aria-hidden="true">
					<?php if ( 'image' === $decoration_type && ! empty( $settings['decoration_image_url'] ) ) : ?><img src="<?php echo esc_url( $settings['decoration_image_url'] ); ?>" alt="" loading="lazy" decoding="async"><?php else : ?><span><?php echo esc_html( $builtin_icon ); ?></span><?php endif; ?>
				</span>
			<?php endif; ?>
			<div class="wpaias-summary__header">
				<span class="wpaias-summary__icon" aria-hidden="true">
					<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 14.39 8.26 21 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.61-1.01z"/></svg>
				</span>
				<span class="wpaias-summary__title"><?php echo esc_html( $title ); ?></span>
				<span class="wpaias-summary__badge"><?php esc_html_e( '由 AI 生成', 'wp-ai-article-summary' ); ?></span>
			</div>
			<div class="wpaias-summary__body">
				<?php if ( '' === $summary ) : ?>
					<div class="wpaias-summary__placeholder">
						<?php if ( $can_generate ) : ?>
							<span class="wpaias-dot"></span><span class="wpaias-dot"></span><span class="wpaias-dot"></span>
							<span class="wpaias-summary__loading-text"><?php esc_html_e( 'AI 摘要生成中…', 'wp-ai-article-summary' ); ?></span>
						<?php else : ?>
							<span class="wpaias-summary__loading-text"><?php esc_html_e( '摘要暂未生成，管理员生成后会自动显示。', 'wp-ai-article-summary' ); ?></span>
						<?php endif; ?>
					</div>
					<div class="wpaias-summary__text" data-pending="1"></div>
				<?php else : ?>
					<div class="wpaias-summary__text"><?php echo esc_html( $summary ); ?></div>
				<?php endif; ?>
			</div>
		</aside>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * 入队前端资源。
	 */
	public function enqueue_assets() {
		if ( ! $this->should_show() ) {
			return;
		}

		wp_enqueue_style(
			'wpaias-frontend',
			WPAIAS_PLUGIN_URL . 'assets/css/frontend.css',
			array(),
			WPAIAS_VERSION
		);

		wp_enqueue_script(
			'wpaias-frontend',
			WPAIAS_PLUGIN_URL . 'assets/js/frontend.js',
			array(),
			WPAIAS_VERSION,
			true
		);

		$post = $this->current_post();
		wp_localize_script(
			'wpaias-frontend',
			'WPAIAS_FRONT',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'wpaias_front_nonce' ),
				'post_id'       => $post ? (int) $post->ID : 0,
				'mobile_enable' => ! empty( $settings['mobile_enable'] ),
			)
		);
	}

	/**
	 * 内联自定义 CSS / 光标变量。
	 */
	public function print_inline_styles() {
		if ( ! $this->should_show() ) {
			return;
		}
		$settings = JLWA_AI_Summary_Feature::get_settings();
		$color    = $settings['cursor_color'];
		$duration = max( 100, (int) $settings['anim_duration'] );
		$delay    = max( 0, (int) $settings['anim_delay'] );

		$css  = ':root{--wpaias-cursor-color:' . esc_attr( $color ) . ';--wpaias-anim-duration:' . $duration . 'ms;--wpaias-anim-delay:' . $delay . 'ms;}';
		$css .= "\n" . (string) $settings['custom_css'];

		echo '<style id="wpaias-inline-css">' . wp_strip_all_tags( $css ) . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Ajax：前端首次访问生成摘要。
	 */
	public function ajax_front_generate() {
		check_ajax_referer( 'wpaias_front_nonce', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => __( '无效文章。', 'wp-ai-article-summary' ) ), 400 );
		}

		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			wp_send_json_error( array( 'message' => __( '文章不存在或未发布。', 'wp-ai-article-summary' ) ), 404 );
		}

		$cached = WPAIAS_Cache::get( $post_id );
		if ( false !== $cached ) {
			wp_send_json_success( array( 'summary' => $cached, 'cached' => true ) );
		}

		$settings = JLWA_AI_Summary_Feature::get_settings();
		if ( empty( $settings['enabled'] ) ) {
			wp_send_json_error( array( 'message' => __( '插件未开启。', 'wp-ai-article-summary' ) ), 403 );
		}
		if ( empty( $settings['public_generation'] ) && ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( '本站未开放访客实时生成摘要。', 'wp-ai-article-summary' ) ), 403 );
		}
		if ( ! in_array( $post->post_type, (array) $settings['post_types'], true ) ) {
			wp_send_json_error( array( 'message' => __( '该文章类型未启用摘要。', 'wp-ai-article-summary' ) ), 403 );
		}

		$exclude_ids = array_map( 'intval', array_filter( array_map( 'trim', explode( ',', (string) $settings['exclude_post_ids'] ) ) ) );
		if ( in_array( $post_id, $exclude_ids, true ) ) {
			wp_send_json_error( array( 'message' => __( '该文章已被排除。', 'wp-ai-article-summary' ) ), 403 );
		}
		if ( ! empty( $settings['exclude_categories'] ) ) {
			$categories = wp_get_post_categories( $post_id );
			if ( array_intersect( array_map( 'intval', $categories ), array_map( 'intval', (array) $settings['exclude_categories'] ) ) ) {
				wp_send_json_error( array( 'message' => __( '该文章分类已被排除。', 'wp-ai-article-summary' ) ), 403 );
			}
		}

		$is_privileged = current_user_can( 'edit_posts' );
		if ( ! $is_privileged ) {
			$global_key  = 'wpaias_public_global_rate';
			$global_rate = get_transient( $global_key );
			$global_rate = is_array( $global_rate ) ? $global_rate : array( 'count' => 0, 'reset' => time() + HOUR_IN_SECONDS );
			if ( time() >= (int) $global_rate['reset'] ) {
				$global_rate = array( 'count' => 0, 'reset' => time() + HOUR_IN_SECONDS );
			}
			$global_limit = max( 1, min( 1000, (int) $settings['public_generation_hourly_limit'] ) );
			if ( (int) $global_rate['count'] >= $global_limit ) {
				wp_send_json_error( array( 'message' => __( '本站本小时的访客摘要生成额度已用完，请稍后再试。', 'wp-ai-article-summary' ) ), 429 );
			}

			$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
			$rate_key    = 'wpaias_rate_' . md5( $remote_addr . '|' . wp_salt( 'nonce' ) );
			$rate        = get_transient( $rate_key );
			$rate        = is_array( $rate ) ? $rate : array( 'count' => 0, 'reset' => time() + 10 * MINUTE_IN_SECONDS );
			if ( time() >= (int) $rate['reset'] ) {
				$rate = array( 'count' => 0, 'reset' => time() + 10 * MINUTE_IN_SECONDS );
			}
			$limit = max( 1, (int) apply_filters( 'wpaias_public_generation_rate_limit', 6 ) );
			if ( (int) $rate['count'] >= $limit ) {
				wp_send_json_error( array( 'message' => __( '请求过于频繁，请稍后再试。', 'wp-ai-article-summary' ) ), 429 );
			}
			$rate['count'] = (int) $rate['count'] + 1;
			set_transient( $rate_key, $rate, max( 60, (int) $rate['reset'] - time() ) );
			$global_rate['count'] = (int) $global_rate['count'] + 1;
			set_transient( $global_key, $global_rate, max( 60, (int) $global_rate['reset'] - time() ) );
		}

		$lock_key = 'wpaias_generate_lock_' . $post_id;
		if ( get_transient( $lock_key ) ) {
			wp_send_json_error( array( 'message' => __( '该文章的摘要正在生成，请稍后刷新。', 'wp-ai-article-summary' ) ), 429 );
		}
		set_transient( $lock_key, 1, 2 * MINUTE_IN_SECONDS );

		try {
			$cached_after_lock = WPAIAS_Cache::get( $post_id );
			if ( false !== $cached_after_lock ) {
				$result = array( 'success' => true, 'data' => $cached_after_lock, 'cached' => true );
			} else {
				$max_chars = max( 2000, min( 100000, (int) $settings['max_source_chars'] ) );
				$content   = $this->extract_source_text( $post, $max_chars );
				$result = WPAIAS_API::generate_summary( $content, $settings );
				if ( ! empty( $result['success'] ) ) {
					$ttl = WPAIAS_Cache::ttl_from_key( $settings['cache_ttl'] );
					WPAIAS_Cache::set( $post_id, $result['data'], $ttl );
					clean_post_cache( $post_id );
					$result['cached'] = false;
				}
			}
		} catch ( Throwable $exception ) {
			error_log( 'WPAIAS frontend generation error: ' . $exception->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			$result = array( 'success' => false, 'message' => __( '摘要生成发生异常，请稍后重试。', 'wp-ai-article-summary' ) );
		} finally {
			delete_transient( $lock_key );
		}

		if ( ! empty( $result['success'] ) ) {
			wp_send_json_success( array( 'summary' => $result['data'], 'cached' => ! empty( $result['cached'] ) ) );
		}
		wp_send_json_error( array( 'message' => isset( $result['message'] ) ? $result['message'] : __( '摘要生成失败。', 'wp-ai-article-summary' ) ), 502 );
	}
}

/**
 * 模板函数：主题作者可在模板中直接调用打印 AI 摘要。
 *
 * 用法： <?php if ( function_exists( 'wpaias_render_summary' ) ) wpaias_render_summary(); ?>
 *
 * @return void
 */
if ( ! function_exists( 'wpaias_render_summary' ) ) {
	function wpaias_render_summary() {
		if ( class_exists( 'JLWA_AI_Summary_Feature' ) ) {
			echo do_shortcode( '[wpaias_summary]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
}
