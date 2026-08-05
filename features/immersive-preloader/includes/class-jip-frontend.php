<?php
/**
 * 前台预加载注入类
 *
 * 双重注入保险：
 *  1. PHP 端通过 wp_body_open 钩子直接输出预加载 HTML（主路径）。
 *  2. JS 端兜底：通过 wp_head 输出的关键脚本监听 body 出现并在那一刻注入 HTML。
 *  3. 任何一路成功，JS 会检测重复，避免双重注入。
 *
 * 这样保证 Zibll / Divi / Astra 子主题等"没调用 wp_body_open()"的主题也能正常显示效果。
 *
 * @package JiuliuImmersivePreloader
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class JIP_Frontend
 */
class JIP_Frontend {

	/**
	 * 单例。
	 *
	 * @var JIP_Frontend|null
	 */
	private static $instance = null;

	/**
	 * 已合并默认值的当前选项。
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * 获取单例。
	 *
	 * @return JIP_Frontend
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * 构造函数。
	 */
	private function __construct() {
		// 入队前台静态资源（仅前台）。
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ), 1 );
		// 在 <head> 内尽早输出关键 CSS + JS（避免 FOUC，并作 JS 兜底注入）。
		add_action( 'wp_head', array( $this, 'output_inline_critical_css' ), 1 );
		// 在 <body> 起始处注入预加载层骨架（WordPress 5.2+，主路径）。
		add_action( 'wp_body_open', array( $this, 'output_preloader_markup' ), 1 );
		add_filter( 'script_loader_tag', array( $this, 'add_defer_attribute' ), 10, 3 );
	}

	/** Ensure defer also works on WordPress versions older than the script strategy API. */
	public function add_defer_attribute( $tag, $handle, $src ) {
		if ( 'jip-preloader' !== $handle || false !== strpos( $tag, ' defer' ) ) {
			return $tag;
		}
		return str_replace( '<script ', '<script defer ', $tag );
	}

	/**
	 * 是否应在当前请求中显示预加载。
	 *
	 * @return bool
	 */
	private function should_display() {
		// 后台、Ajax、Feed、REST、登录页等不显示。
		if ( is_admin() || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || is_feed() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
			return false;
		}

		if ( empty( $this->options ) ) {
			$this->options = JIP_Settings::get_options();
		}
		if ( empty( $this->options['enabled'] ) ) {
			return false;
		}
		if ( ! empty( $this->options['home_only'] ) && ! ( is_front_page() || is_home() ) ) {
			return false;
		}
		return true;
	}

	/**
	 * 生成预加载层 HTML 字符串。
	 *
	 * @return string
	 */
	private function get_preloader_html() {
		$opts      = $this->options;
		$effect    = $opts['effect'];
		$site_name = get_bloginfo( 'name' );
		$logo_url  = ! empty( $opts['logo_url'] ) ? $opts['logo_url'] : ( JIP_PLUGIN_URL . 'assets/images/default-logo.svg' );

		ob_start();
		?>
<div id="jip-preloader" class="jip-effect-<?php echo esc_attr( $effect ); ?>"
	data-effect="<?php echo esc_attr( $effect ); ?>"
	data-min="<?php echo esc_attr( $opts['min_duration'] ); ?>"
	data-max="<?php echo esc_attr( $opts['max_duration'] ); ?>"
	data-completion="<?php echo esc_attr( $opts['completion'] ); ?>"
	data-skip="<?php echo (int) $opts['allow_skip']; ?>"
	data-show-title="<?php echo (int) $opts['show_site_title']; ?>"
	data-logo-size="<?php echo (int) $opts['logo_size']; ?>">
	<?php if ( 'logo3d' === $effect ) : ?>
		<div class="jip-stage">
			<div class="jip-frame" aria-hidden="true">
				<div class="jip-frame-border"></div>
				<div class="jip-frame-inner">
					<div class="jip-logo-mask" style="--jip-logo-size: <?php echo esc_attr( $opts['logo_size'] ); ?>%;">
						<img class="jip-logo-img" src="<?php echo esc_url( $logo_url ); ?>" alt="" />
					</div>
				</div>
				<div class="jip-scanline"></div>
			</div>
			<?php if ( ! empty( $opts['show_site_title'] ) && $site_name ) : ?>
				<div class="jip-site-title"><?php echo esc_html( $site_name ); ?></div>
			<?php endif; ?>
		</div>
	<?php elseif ( 'particles' === $effect ) : ?>
		<canvas class="jip-particles-canvas" aria-hidden="true"></canvas>
		<div class="jip-stage">
			<img class="jip-particles-target" src="<?php echo esc_url( $logo_url ); ?>" alt="" crossorigin="anonymous" />
			<?php if ( ! empty( $opts['show_site_title'] ) && $site_name ) : ?>
				<div class="jip-site-title"><?php echo esc_html( $site_name ); ?></div>
			<?php endif; ?>
		</div>
	<?php elseif ( 'gradient' === $effect ) : ?>
		<div class="jip-gradient-layer jip-g1"></div>
		<div class="jip-gradient-layer jip-g2"></div>
		<div class="jip-gradient-layer jip-g3"></div>
		<div class="jip-stage">
			<?php if ( ! empty( $opts['show_site_title'] ) && $site_name ) : ?>
				<div class="jip-site-title jip-gradient-title"><?php echo esc_html( $site_name ); ?></div>
			<?php endif; ?>
		</div>
	<?php elseif ( 'lines' === $effect ) : ?>
		<div class="jip-stage">
			<svg class="jip-lines-svg" viewBox="0 0 200 200" aria-hidden="true">
				<circle class="jip-line-path" cx="100" cy="100" r="80" fill="none" stroke="currentColor" stroke-width="2"/>
				<rect class="jip-line-path" x="50" y="50" width="100" height="100" fill="none" stroke="currentColor" stroke-width="2"/>
				<polyline class="jip-line-path" points="50,150 100,50 150,150" fill="none" stroke="currentColor" stroke-width="2"/>
			</svg>
			<?php if ( ! empty( $opts['show_site_title'] ) && $site_name ) : ?>
				<div class="jip-site-title"><?php echo esc_html( $site_name ); ?></div>
			<?php endif; ?>
		</div>
	<?php elseif ( 'glass' === $effect ) : ?>
		<div class="jip-stage">
			<div class="jip-glass-box" aria-hidden="true"><div class="jip-glass-inner"></div></div>
			<?php if ( ! empty( $opts['show_site_title'] ) && $site_name ) : ?><div class="jip-site-title"><?php echo esc_html( $site_name ); ?></div><?php endif; ?>
		</div>
	<?php elseif ( 'orbit' === $effect ) : ?>
		<div class="jip-stage"><div class="jip-orbit" aria-hidden="true"><i></i><i></i><i></i><span><img src="<?php echo esc_url( $logo_url ); ?>" alt=""></span></div><?php if ( ! empty( $opts['show_site_title'] ) && $site_name ) : ?><div class="jip-site-title"><?php echo esc_html( $site_name ); ?></div><?php endif; ?></div>
	<?php elseif ( 'shutters' === $effect ) : ?>
		<div class="jip-shutter jip-shutter-top" aria-hidden="true"></div><div class="jip-shutter jip-shutter-bottom" aria-hidden="true"></div><div class="jip-stage"><img class="jip-simple-logo" src="<?php echo esc_url( $logo_url ); ?>" alt=""><?php if ( ! empty( $opts['show_site_title'] ) && $site_name ) : ?><div class="jip-site-title"><?php echo esc_html( $site_name ); ?></div><?php endif; ?></div>
	<?php elseif ( 'ink' === $effect ) : ?>
		<div class="jip-ink-cloud" aria-hidden="true"><i></i><i></i><i></i></div><div class="jip-stage"><img class="jip-simple-logo" src="<?php echo esc_url( $logo_url ); ?>" alt=""><?php if ( ! empty( $opts['show_site_title'] ) && $site_name ) : ?><div class="jip-site-title"><?php echo esc_html( $site_name ); ?></div><?php endif; ?></div>
	<?php endif; ?>
	<?php if ( ! empty( $opts['show_progress'] ) ) : ?><div class="jip-progress" role="progressbar" aria-label="页面准备进度"><span></span><em><?php echo esc_html( $opts['status_text'] ); ?></em></div><?php endif; ?>
	<div class="jip-skip-tip"><?php echo ! empty( $opts['allow_skip'] ) ? '点击任意位置跳过' : '&nbsp;'; ?></div>
</div>
		<?php
		return trim( ob_get_clean() );
	}

	/**
	 * 输出关键内联 CSS + JS 兜底注入（在 head 最早期）。
	 *
	 * 关键设计：
	 *  - 第一段 <script> 在解析 critical CSS 之前就给 html 加 jip-loading 类，
	 *    让 critical CSS 中"锁定 html/body 背景色"的规则立即生效，
	 *    确保浏览器渲染第一帧就是预加载背景色，绝不闪现白屏或主题内容。
	 *  - critical CSS 同时锁定 html、body、html ::before 等所有可能露出的层级。
	 *  - JS 通过 sessionStorage 实现"标签会话内只显示一次"。
	 *  - JS 用 MutationObserver 监听 body 出现，50ms 内 PHP 路径若未输出则尽快注入。
	 */
	public function output_inline_critical_css() {
		if ( ! $this->should_display() ) {
			return;
		}
		$bg          = $this->options['bg_color'];
		$html        = $this->get_preloader_html();
		$once        = ! empty( $this->options['once_per_session'] ) ? 'true' : 'false';
		$home_only   = ! empty( $this->options['home_only'] ) ? 'true' : 'false';
		$mobile      = ! empty( $this->options['mobile_enable'] ) ? 'true' : 'false';
		$reduce      = ! empty( $this->options['reduce_motion'] ) ? 'true' : 'false';
		$save_data   = ! empty( $this->options['skip_save_data'] ) ? 'true' : 'false';
		$max_ms      = max( 500, (int) round( floatval( $this->options['max_duration'] ) * 1000 ) );
		// 将 HTML 编码为 JSON 字符串，安全注入到 JS 字符串字面量中。
		$html_json = wp_json_encode( $html );
		if ( false === $html_json ) {
			$html_json = '""';
		}
		?>
<script id="jip-critical-pre">
/* 在所有样式应用前立即决定是否启用预加载，并加 jip-loading 类，
 * 让后面的 critical CSS 一应用即生效（避免白屏闪现）。*/
(function(){
	window.__JIP_START__ = Date.now();
	window.__JIP_MAX_MS__ = <?php echo (int) $max_ms; ?>;
	try {
		var ONCE = <?php echo $once; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
		var HOME_ONLY = <?php echo $home_only; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
		var MOBILE = <?php echo $mobile; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
		var REDUCE = <?php echo $reduce; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
		var SAVE_DATA = <?php echo $save_data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
		var isMobile = (window.matchMedia && window.matchMedia('(max-width:782px)').matches) || /Android|iPhone|iPad|iPod|IEMobile|Opera Mini/i.test(navigator.userAgent || '');
		var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion:reduce)').matches;
		if ((!MOBILE && isMobile) || (REDUCE && reduceMotion) || (SAVE_DATA && navigator.connection && navigator.connection.saveData)) { window.__JIP_SKIP__ = 1; return; }
		// 标签会话级跳过：sessionStorage 中已有标记则不再显示
		if (ONCE && window.sessionStorage) {
			var key = 'jip_shown_' + (HOME_ONLY ? 'home' : 'all');
			if (sessionStorage.getItem(key) === '1') {
				window.__JIP_SKIP__ = 1;
				return;
			}
			sessionStorage.setItem(key, '1');
		}
		document.documentElement.classList.add('jip-loading');
	} catch(e) {
		document.documentElement.classList.add('jip-loading');
	}
})();
</script>
<style id="jip-critical-css">
/* 锁定 html/body 背景色，彻底消除白屏：从浏览器渲染第一帧就是预加载背景色 */
html.jip-loading { background: <?php echo esc_attr( $bg ); ?> !important; overflow: hidden !important; }
html.jip-loading body { overflow: hidden !important; }
html.jip-fade-in, html.jip-fade-in body { overflow: auto !important; }
/* 正文始终在遮罩后正常解析和绘制，不再通过 visibility:hidden 制造虚假等待。 */
#jip-preloader{position:fixed;inset:0;width:100%;height:100%;background:<?php echo esc_attr( $bg ); ?>;z-index:999999;display:flex;align-items:center;justify-content:center;overflow:hidden;will-change:opacity;transition:opacity .36s ease, transform .36s ease;}
#jip-preloader.jip-hide{opacity:0;transform:translateY(-6px);pointer-events:none;}
/* 会话级跳过时，html 不会带 jip-loading 类，立即隐藏 PHP 已输出的预加载层，避免一闪而过 */
html:not(.jip-loading) #jip-preloader{display:none !important;}
</style>
<script id="jip-critical-js">
(function(){
	if (window.__JIP_SKIP__) return; // 会话级跳过

	window.JIP_HTML = <?php echo $html_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 已通过 wp_json_encode 编码。 ?>;

	var inserted = false;
	function tryFind(){
		if (inserted) return true;
		var existing = document.getElementById('jip-preloader'); if (existing) { inserted = true; prepareNode(existing); return true; }
		return false;
	}
	function prepareNode(node){
		if (!node || node.__jipPrepared) return;
		node.__jipPrepared = true;
		requestAnimationFrame(function(){ requestAnimationFrame(function(){ if (node && node.classList) node.classList.add('jip-ready'); }); });
	}
	function forceInsert(){
		if (tryFind()) { prepareNode(document.getElementById('jip-preloader')); return true; }
		if (!document.body) return false;
		try {
			var wrap = document.createElement('div');
			wrap.innerHTML = window.JIP_HTML;
			var node = wrap.firstElementChild;
			if (node) {
				document.body.insertBefore(node, document.body.firstChild);
				inserted = true;
				prepareNode(node);
				return true;
			}
		} catch(e) {}
		return false;
	}
	function emergencyEnd(){
		var node = document.getElementById('jip-preloader');
		document.documentElement.classList.remove('jip-loading');
		if (!node) return;
		node.classList.add('jip-hide');
		setTimeout(function(){ if (node && node.parentNode) node.parentNode.removeChild(node); }, 420);
	}
	window.__JIP_HARD_TIMER__ = setTimeout(emergencyEnd, Math.max(500, window.__JIP_MAX_MS__ || 3000));

	// 立即尝试一次（body 已存在的极少数情况）
	if (tryFind()) return;

	// MutationObserver 监听 body 出现 / PHP 输出 #jip-preloader
	if (window.MutationObserver) {
		var ob = new MutationObserver(function(){
			if (tryFind()) ob.disconnect();
		});
		try { ob.observe(document.documentElement, { childList: true, subtree: true }); } catch(e) {}
		// 50ms 内如果 PHP 路径仍未输出，由 JS 强制注入（兜底）。
		// 如果 body 还没出现，继续保留 observer，等 body 出现后再插入。
		setTimeout(function(){
			if (!inserted && forceInsert()) {
				try { ob.disconnect(); } catch(e) {}
			}
		}, 50);
	}
	// 最终兜底：DOMContentLoaded
	document.addEventListener('DOMContentLoaded', forceInsert);
})();
</script>
		<?php
	}

	/**
	 * wp_body_open 钩子：PHP 主路径直接输出预加载 HTML。
	 *
	 * 如果主题没调用 wp_body_open()，此函数不会被触发，由 JS 兜底接管。
	 */
	public function output_preloader_markup() {
		if ( ! $this->should_display() ) {
			return;
		}
		// 因为 JS 也会插入相同 HTML，但 JS 会先检查是否存在；
		// 但反过来：如果 PHP 先输出了，JS 检测到存在就跳过 —— 没问题。
		echo $this->get_preloader_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 内部已逐项 esc_*。
	}

	/**
	 * 入队前台 CSS / JS。
	 */
	public function enqueue_assets() {
		if ( ! $this->should_display() ) {
			return;
		}

		wp_enqueue_style(
			'jip-preloader',
			JIP_PLUGIN_URL . 'assets/css/preloader.css',
			array(),
			JIP_VERSION
		);

		wp_enqueue_script(
			'jip-preloader',
			JIP_PLUGIN_URL . 'assets/js/preloader.js',
			array(),
			JIP_VERSION,
			false // 在 head 下载，并通过 defer 并行执行；避免等到主题页脚才开始生命周期。
		);
		wp_script_add_data( 'jip-preloader', 'strategy', 'defer' );

		wp_localize_script(
			'jip-preloader',
			'JIP_CFG',
			array(
				'minDuration' => floatval( $this->options['min_duration'] ),
				'maxDuration' => floatval( $this->options['max_duration'] ),
				'effect'      => $this->options['effect'],
				'completion'  => $this->options['completion'],
				'allowSkip'   => (int) $this->options['allow_skip'],
				'logoUrl'     => ! empty( $this->options['logo_url'] ) ? $this->options['logo_url'] : ( JIP_PLUGIN_URL . 'assets/images/default-logo.svg' ),
			)
		);
	}
}
