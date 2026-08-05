<?php
/** 九流WP助手：子比资源下载页美化。 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class JLWA_Download_Page_Feature {
	const VERSION = '1.0.0';
	const OPTION_KEY = 'jlwa_download_page_options';
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) { self::$instance = new self(); }
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', array( $this, 'register_setting' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render' ), 1 );
	}

	public static function defaults() {
		return array( 'enabled' => 0, 'template' => 'technology', 'cover_mode' => 'auto', 'default_image_url' => '' );
	}

	public static function options() {
		$value = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( is_array( $value ) ? $value : array(), self::defaults() );
	}

	public static function activate() {
		update_option( self::OPTION_KEY, self::options(), false );
	}

	public function register_setting() {
		register_setting( 'jlwa_download_page', self::OPTION_KEY, array( 'default' => self::defaults(), 'sanitize_callback' => array( $this, 'sanitize' ) ) );
	}

	public function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$templates = array( 'technology', 'business', 'vip' );
		$modes = array( 'auto', 'featured', 'first', 'random', 'default' );
		return array(
			'enabled' => empty( $input['enabled'] ) ? 0 : 1,
			'template' => in_array( $input['template'] ?? '', $templates, true ) ? $input['template'] : 'technology',
			'cover_mode' => in_array( $input['cover_mode'] ?? '', $modes, true ) ? $input['cover_mode'] : 'auto',
			'default_image_url' => esc_url_raw( $input['default_imae_url'] ?? '' ),
		);
	}

	public function enqueue_admin_assets( $hook = '' ) {
		wp_enqueue_media();
		wp_enqueue_style( 'jlwa-download-page-admin', JLWA_PLUGIN_URL . 'assets/css/download-page-admin.css', array(), JLWA_VERSION );
	}

	private function compatible() {
		foreach ( array( 'zibpay_is_paid', 'zibpay_get_post_down_buts' ) as $function ) {
			if ( ! function_exists( $function ) ) { return false; }
		}
		return true;
	}

	private function target_request() {
		if ( is_admin() || wp_doing_ajax() || empty( $_GET['post'] ) ) { return false; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post_id = absint( wp_unslash( $_GET['post'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post = get_post( $post_id );
		$meta = get_post_meta( $post_id, 'posts_zibpay', true );
		if ( ! $post || 'publish' !== $post->post_status || ! is_array( $meta ) || '2' !== (string) ( $meta['pay_type'] ?? '' ) ) { return false; }
		$page_id = get_queried_object_id();
		$slug = $page_id ? str_replace( '\\', '/', (string) get_page_template_slug( $page_id ) ) : '';
		return 'download.php' === basename( $slug ) || false !== strpos( $slug, 'pages/download.php' ) || (bool) apply_filters( 'jlwa_download_page_force_request', false, $post_id );
	}

	public function maybe_render() {
		$options = self::options();
		if ( empty( $options['enabled'] ) || ! $this->compatible() || ! $this->target_request() ) { return; }
		$this->render_page( absint( wp_unslash( $_GET['post'] ) ), $options ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		exit;
	}

	private function content_images( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) { return array(); }
		$images = array();
		if ( preg_match_all( '/<img\b[^>]*>/i', (string) $post->post_content, $tags ) ) {
			foreach ( $tags[0] as $tag ) {
				if ( preg_match( '/\bclass\s*=\s*(["\'])(.*?)\1/i', $tag, $class ) && preg_match( '/(?:avatar|emoji|logo|icon|placeholder|loading|spinner|pixel)/i', $class[2] ) ) { continue; }
				$url = '';
				foreach ( array( 'data-src', 'data-original', 'data-lazy-src', 'src' ) as $attr ) {
					if ( preg_match( '/\b' . preg_quote( $attr, '/' ) . '\s*=\s*(["\'])(.*?)\1/i', $tag, $match ) ) {
						$url = html_entity_decode( trim( $match[2] ), ENT_QUOTES, 'UTF-8' );
						if ( $url && 0 !== strpos( $url, 'data:' ) ){ break; }
					}
				}
				if ( ! $url || preg_match( '/(?:avatar|emoji|logo|icon|placeholder|loading|spinner|pixel)/i', wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) { continue; }
				if ( 0 === strpos( $url, '//' ) ){ $url = ( is_ssl() ? 'https:' : 'http:' ) . $url; }
				elseif ( 0 === strpos( $url, '/' ) ){ $url = home_url( $url ); }
				$url = esc_url_raw( set_url_scheme( $url ) );
				if ( $url ) { $images[] = $url; }
			}
		}
		$attachments = get_children( array( 'post_parent' => $post_id, 'post_type' => 'attachment', 'post_mime_type' => 'image', 'orderby' => 'menu_order ID', 'order' => 'ASC', 'fields' => 'ids' ) );
		foreach ( (array) $attachments as $attachment_id ) {
			$url = wp_get_attachment_image_url( $attachment_id, 'large' );
			if ( $url ) { $images[] = $url; }
		}
		return array_values( array_unique( array_filter( $images ) ) );
	}

	private function cover_url( $post_id, $options ) {
		$featured = get_the_post_thumbnail_url( $post_id, 'large' ) ?: get_the_post_thumbnail_url( $post_id, 'full' );
		$images = $this->content_images( $post_id );
		$first = $images[0] ?? '';
		$random = $images ? $images[ abs( crc32( get_current_blog_id() . ':' . $post_id ) ) % count( $images ) ] : '';
		$default = $options['default_image_url'] ?: JLWA_PLUGIN_URL . 'assets/default-download-cover.svg';
		switch ( $options['cover_mode'] ) {
			case 'featured': return $featured ?: $default;
			case 'first': return $first ?: $default;
			case 'random': return $random ?: $default;
			case 'default': return $default;
			default: return $featured ?: ( $first ?: ( $random ?: $default ) );
		}
	}

	private function render_page( $post_id, $options ) {
		$post = get_post( $post_id );
		$meta = get_post_meta( $post_id, 'posts_zibpay', true );
		$paid = zibpay_is_paid( $post_id );
		$paid_type = $paid && ! empty( $paid['paid_type'] ) ? (string) $paid['paid_type'] : '';
		$title = ! empty( $meta['pay_title'] ) ? $meta['pay_title'] : get_the_title( $post_id );
		$theme = in_array( $options['template'], array( 'technology', 'business', 'vip' ), true ) ? $options['template'] : 'technology';
		$doc = ! empty( $meta['pay_doc'] ) ? $meta['pay_doc'] : wpautop( wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 65 ) );
		$details = ! empty( $meta['pay_details'] ) ? $meta['pay_details'] : '<p>购买状态与下载权限由子比系统自动验证，支付成功后刷新本页即可获取下载线路。</p>';
		$download = $paid ? zibpay_get_post_down_buts( $meta, $paid_type, $post_id ) : '';
		wp_enqueue_style( 'jlwa-download-page', JLWA_PLUGIN_URL . 'assets/css/download-page.css', array(), JLWA_VERSION );
		get_header();
		?>
		<main class="jlwa-dp <?php echo esc_attr( $theme ); ?>"><div class="jlwa-dp-shell">
		<section class="jlwa-dp-hero"><div class="jlwa-dp-cover"><img src="<?php echo esc_url( $this->cover_url( $post_id, $options ) ); ?>" alt="<?php echo esc_attr( wp_strip_all_tags( $title ) ); ?>" loading="eager" fetchpriority="high"></div><div><span class="jlwa-dp-badge">九流网络 · 资源下载中心</span><h1><?php echo esc_html( $title ); ?></h1><p>模板由九流WP助手管理，支付与下载权限继续使用子比原生逻辑。</p><div class="jlwa-dp-actions"><a href="<?php echo esc_url( get_permalink( $post_id ) ); ?>">查看原文章</a><a href="<?php echo esc_url( home_url( '/join-vip/' ) ); ?>">会员权益</a></div></div></section>
		<section class="jlwa-dp-status"><i><?php echo $paid ? '✓' : '🔒'; ?></i><div><h2><?php echo $paid ? '下载权限已解锁' : '尚未获得下载权限'; ?></h2><p><?php echo $paid ? '系统已确认当前访问权限，请在下方选择下载线路。' : '请返回原文章完成购买，支付成功后刷新本页。'; ?></p></div></section>
		<div class="jlwa-dp-grid"><section class="jlwa-dp-card"><h2>资源下载</h2><div class="jlwa-dp-doc"><?php echo wp_kses_post( $doc ); ?></div><div class="jlwa-dp-details"><?php echo wp_kses_post( $details ); ?></div><?php if ( $paid ) : ?><div class="jlwa-dp-download"><?php echo $download; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div><?php else : ?><div class="jlwa-dp-lock"><strong>该资源需要先取得下载权限</strong><a href="<?php echo esc_url( get_permalink( $post_id ) . '#posts-pay' ); ?>">返回文章购买</a></div><?php endif; ?></section><aside class="jlwa-dp-card"><h2>下载说明</h2><ul><li>支付结果由网站系统自动核验。</li><li>请优先选择速度稳定的下载线路。</li><li>链接失效时可联系网站客服。</li><li>关闭功能后恢复子比原下载页。</li></ul></aside></div>
		</div></main>
		<?php get_footer();
	}

	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$options = self::options();
		$templates = array( 'technology' => array( '科技蓝紫版', '科技渐变、醒目状态与资源中心视觉。' ), 'business' => array( '简洁商务版', '浅色商务、清晰信息层级与稳重布局。' ), 'vip' => array( 'VIP 资源中心版', '黑金质感、会员资源与高级交付氛围。' ) );
		$modes = array( 'auto' => '自动模式：特色图 → 正文首图 → 稳定随机图 → 默认图', 'featured' => '固定使用特色图片', 'first' => '固定使用正文首图', 'random' => '固定使用稳定随机图', 'default' => '固定使用默认图片' );
		?>
		<div class="jlwa-download-admin"><form action="options.php" method="post"><?php settings_fields( 'jlwa_download_page' ); ?><section class="jlwa-download-intro"><div><span>DOWNLOAD EXPERIENCE</span><h2>子比下载页美化</h2><p>不改主题文件，后台统一切换三套模板和封面策略；关闭后立即恢复子比原页面。</p></div><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enabled]" value="1" <?php checked( ! empty( $options['enabled'] ) ); ?>><b>启用功能</b></label></section>
		<div class="jlwa-download-section"><h3>选择页面模板</h3><div class="jlwa-template-grid"><?php foreach ( $templates as $key => $item ) : ?><label class="jlwa-template-card is-<?php echo esc_attr( $key ); ?>"><input type="radio" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[template]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $options['template'], $key ); ?>><span class="preview"></span><strong><?php echo esc_html( $item[0] ); ?></strong><small><?php echo esc_html( $item[1] ); ?></small></label><?php endforeach; ?></div></div>
		<div class="jlwa-download-columns"><section class="jlwa-download-section"><h3>左上角封面策略</h3><?php foreach ( $modes as $key => $label ) : ?><label class="jlwa-mode-row"><input type="radio" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[cover_mode]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $options['cover_mode'], $key ); ?>><span><?php echo esc_html( $label ); ?></span></label><?php endforeach; ?></section><section class="jlwa-download-section"><h3>默认图片</h3><input class="regular-text" type="url" id="jlwa-default-cover" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[default_image_url]" value="<?php echo esc_attr( $options['default_image_url'] ); ?>" placeholder="留空使用插件内置默认图"><button type="button" class="button" id="jlwa-select-cover">从媒体库选择</button><p><?php echo $this->compatible() ? '已检测到子比支付与下载接口。' : '未检测到完整子比接口，前台不会强行接管。'; ?></p></section></div><?php submit_button( '保存下载页设置' ); ?></form></div>
		<script>jQuery(function($){var frame;$('#jlwa-select-cover').on('click',function(e){e.preventDefault();if(frame){frame.open();return;}frame=wp.media({title:'选择默认封面',button:{text:'使用这张图片'},multiple:false,library:{type:'image'}});frame.on('select',function(){$('#jlwa-default-cover').val(frame.state().get('selection').first().toJSON().url);});frame.open();});});</script>
		<?php
	}
}
JLWA_Download_Page_Feature::instance();
