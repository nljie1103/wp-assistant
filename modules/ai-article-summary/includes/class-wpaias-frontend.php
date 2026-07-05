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

		$settings = WPAIAS_Plugin::get_settings();
		if ( empty( $settings['enabled'] ) ) {
			return false;
		}

		// 移动端开关。
		if ( wp_is_mobile() && empty( $settings['mobile_enable'] ) ) {
			return false;
		}

		$post = get_post();
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

		$post = get_post();
		if ( ! $post ) {
			return $content;
		}

		// 防止同一文章多次注入（widget、related posts 也用 the_content）。
		if ( isset( $this->rendered[ $post->ID ] ) ) {
			return $content;
		}

		$settings = WPAIAS_Plugin::get_settings();
		$method   = isset( $settings['insert_method'] ) ? $settings['insert_method'] : 'auto';

		// 用户选了 shortcode_only / js / manual 模式时，跳过 the_content 注入。
		if ( in_array( $method, array( 'shortcode_only', 'js', 'manual' ), true ) ) {
			return $content;
		}

		$cached = WPAIAS_Cache::get( $post->ID );
		$html   = $this->build_card_html( $post, false === $cached ? '' : (string) $cached, $settings );

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
		$post = get_post();
		if ( ! $post ) {
			return '';
		}

		// 短代码下，强制视为已渲染，避免与自动注入重复。
		$this->rendered[ $post->ID ] = true;

		$settings = WPAIAS_Plugin::get_settings();
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
		$settings = WPAIAS_Plugin::get_settings();
		$method   = isset( $settings['insert_method'] ) ? $settings['insert_method'] : 'auto';

		// 手动 / shortcode_only 模式，不输出 DOM 注入模板。
		if ( in_array( $method, array( 'manual', 'shortcode_only' ), true ) ) {
			return;
		}

		// content_filter 模式下，如果 the_content 没成功（页面里没找到 .wpaias-summary），JS 也会兜底注入。
		// 这里始终输出模板，让 JS 自己判断。

		$post = get_post();
		if ( ! $post ) {
			return;
		}

		$cached    = WPAIAS_Cache::get( $post->ID );
		$html      = $this->build_card_html( $post, false === $cached ? '' : (string) $cached, $settings );
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

		$state = ( '' === $summary ) ? 'loading' : 'ready';

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

		ob_start();
		?>
		<aside class="<?php echo esc_attr( $class_attr ); ?>"<?php echo $style_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo $data_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
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
						<span class="wpaias-dot"></span><span class="wpaias-dot"></span><span class="wpaias-dot"></span>
						<span class="wpaias-summary__loading-text"><?php esc_html_e( 'AI 摘要生成中…', 'wp-ai-article-summary' ); ?></span>
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

		$post = get_post();
		wp_localize_script(
			'wpaias-frontend',
			'WPAIAS_FRONT',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'wpaias_front_nonce' ),
				'post_id'  => $post ? (int) $post->ID : 0,
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
		$settings = WPAIAS_Plugin::get_settings();
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
			wp_send_json_error( array( 'message' => __( '无效文章。', 'wp-ai-article-summary' ) ) );
		}

		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			wp_send_json_error( array( 'message' => __( '文章不存在或未发布。', 'wp-ai-article-summary' ) ) );
		}

		// 命中缓存直接返回。
		$cached = WPAIAS_Cache::get( $post_id );
		if ( false !== $cached ) {
			wp_send_json_success(
				array(
					'summary' => $cached,
					'cached'  => true,
				)
			);
		}

		$settings = WPAIAS_Plugin::get_settings();

		if ( empty( $settings['enabled'] ) ) {
			wp_send_json_error( array( 'message' => __( '插件未开启。', 'wp-ai-article-summary' ) ) );
		}

		$content = wp_strip_all_tags( (string) $post->post_content );
		$result  = WPAIAS_API::generate_summary( $content, $settings );

		if ( ! empty( $result['success'] ) ) {
			$ttl = WPAIAS_Cache::ttl_from_key( $settings['cache_ttl'] );
			WPAIAS_Cache::set( $post_id, $result['data'], $ttl );
			wp_send_json_success(
				array(
					'summary' => $result['data'],
					'cached'  => false,
				)
			);
		}

		// 失败不缓存。
		wp_send_json_error( array( 'message' => $result['message'] ) );
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
		if ( class_exists( 'WPAIAS_Plugin' ) ) {
			echo do_shortcode( '[wpaias_summary]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
}
