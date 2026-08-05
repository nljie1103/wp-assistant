<?php
/**
 * Dedicated anti-debug protection feature for 九流WP助手.
 *
 * This feature supports independently configurable detector and response
 * layers, including optional aggressive debugger, reload and close loops.
 * Every aggressive action is disabled by default and keeps administrator bypass.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'JLWA_Anti_Debug_Feature' ) ) {
	final class JLWA_Anti_Debug_Feature {
		const VERSION     = '1.2.0';
		const OPTION_NAME = 'jlwa_anti_debug_options';
		const LOG_OPTION  = 'jlwa_anti_debug_logs';
		const MENU_SLUG   = 'jlwa-anti-debug';

		/** @var JLWA_Anti_Debug_Feature|null */
		private static $instance = null;

		/** @return JLWA_Anti_Debug_Feature */
		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		private function __construct() {
			add_action( 'init', array( $this, 'maybe_upgrade' ), 1 );
			add_action( 'admin_init', array( $this, 'maybe_handle_save' ) );
			add_action( 'admin_post_jlwa_anti_debug_clear_logs', array( $this, 'clear_logs' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ), 2 );
			add_action( 'wp_ajax_jlwa_anti_debug_event', array( $this, 'record_event' ) );
			add_action( 'wp_ajax_nopriv_jlwa_anti_debug_event', array( $this, 'record_event' ) );
		}

		/** Install defaults without autoloading a large configuration object. */
		public static function activate() {
			if ( false === get_option( self::OPTION_NAME, false ) ) {
				$defaults = self::defaults();
				$defaults['scope']['emergency_key'] = wp_generate_password( 18, false, false );
				add_option( self::OPTION_NAME, $defaults, '', false );
			}
			if ( false === get_option( self::LOG_OPTION, false ) ) {
				add_option( self::LOG_OPTION, array(), '', false );
			}
		}

		/** @return array<string,mixed> */
		public static function defaults() {
			return array(
				'version' => self::VERSION,
				'enabled' => 0,
				'mode'    => 'balanced',
				'scope'   => array(
					'posts'            => 1,
					'pages'            => 0,
					'archives'         => 0,
					'home'             => 0,
					'mobile'           => 0,
					'mobile_risky_detectors' => 0,
					'admin_bypass'     => 1,
					'logged_in_bypass' => 0,
					'exclude_paths'    => "/wp-login.php\n/wp-admin/",
					'emergency_key'    => '',
				),
				'detectors' => array(
					'shortcuts'            => 1,
					'viewport'             => 0,
					'debugger_timing'      => 0,
					'console_getter'       => 1,
					'console_performance'  => 0,
					'debug_libraries'      => 1,
					'focus_signal'         => 0,
					'interval_ms'          => 1100,
					'viewport_threshold'   => 220,
					'viewport_ratio'       => 18,
					'debugger_threshold'   => 180,
					'performance_threshold'=> 110,
				),
				'decision' => array(
					'threshold'       => 85,
					'confirm_hits'    => 2,
					'hit_window_ms'   => 4200,
					'score_decay'     => 12,
					'detector_cooldown_ms' => 1800,
				),
				'response' => array(
					'message'          => '检测到调试环境。请关闭开发者工具后继续访问。',
					'detail'           => '本站已启用反调试保护，用于降低批量抓取与恶意分析风险。',
					'blur_px'          => 16,
					'content_selector' => 'article, main, .entry-content, .post-content, .article-content',
					'redirect_url'     => '',
					'auto_recover'     => 1,
					'recover_delay_ms' => 1800,
					'layers' => array(
						'level1' => array(
							'enabled'           => 1,
							'delay_ms'          => 0,
							'overlay'           => 1,
							'blur'              => 1,
							'block_interaction' => 0,
							'block_selection'   => 0,
						),
						'level2' => array(
							'enabled'                   => 0,
							'delay_ms'                  => 2500,
							'replace_content'           => 1,
							'clear_console'             => 0,
							'console_clear_interval_ms' => 500,
							'history_lock'              => 0,
							'clipboard_guard'           => 0,
						),
						'level3' => array(
							'enabled'            => 0,
							'delay_ms'           => 5000,
							'redirect'           => 0,
							'reload_loop'        => 0,
							'reload_interval_ms' => 1200,
							'close_loop'         => 0,
							'close_interval_ms'  => 700,
							'persist_session'    => 0,
							'persist_minutes'    => 10,
						),
						'level4' => array(
							'enabled'                   => 0,
							'delay_ms'                  => 8000,
							'debugger_loop'             => 0,
							'debugger_interval_ms'      => 250,
							'clear_console'             => 0,
							'console_clear_interval_ms' => 250,
							'reload_loop'               => 0,
							'reload_interval_ms'        => 800,
							'close_loop'                => 0,
							'close_interval_ms'         => 350,
							'hard_lock'                 => 0,
							'persist_session'           => 0,
							'persist_minutes'           => 15,
						),
					),
				),
				'logging' => array(
					'enabled'     => 1,
					'max_entries' => 100,
				),
			);
		}

		public function maybe_upgrade() {
			$saved = get_option( self::OPTION_NAME, false );
			if ( false === $saved ) {
				return;
			}
			if ( ! is_array( $saved ) ) {
				update_option( self::OPTION_NAME, self::defaults(), false );
				return;
			}
			$version = isset( $saved['version'] ) ? (string) $saved['version'] : '0.0.0';
			$needs_key = empty( $saved['scope']['emergency_key'] );
			if ( version_compare( $version, self::VERSION, '>=' ) && ! $needs_key ) {
				return;
			}
			$merged = $this->deep_merge( self::defaults(), $saved );
			$merged['version'] = self::VERSION;
			// 1.2.0: previous mobile detection could misclassify iOS viewport/address-bar changes.
			// Disable existing mobile protection once during migration; administrators may opt in again.
			if ( version_compare( $version, '1.2.0', '<' ) ) {
				$merged['scope']['mobile'] = 0;
				$merged['scope']['mobile_risky_detectors'] = 0;
			}
			if ( $needs_key ) {
				$merged['scope']['emergency_key'] = wp_generate_password( 18, false, false );
			}
			if ( isset( $saved['response']['action'] ) && empty( $saved['response']['layers'] ) ) {
				$legacy_action = sanitize_key( $saved['response']['action'] );
				if ( 'observe' === $legacy_action ) {
					$merged['response']['layers']['level1']['enabled'] = 0;
				} elseif ( 'replace' === $legacy_action ) {
					$merged['response']['layers']['level2']['enabled'] = 1;
				} elseif ( 'redirect' === $legacy_action || 'close' === $legacy_action ) {
					$merged['response']['layers']['level3']['enabled'] = 1;
					$merged['response']['layers']['level3'][ 'redirect' === $legacy_action ? 'redirect' : 'close_loop' ] = 1;
				}
			}
			unset( $merged['response']['action'], $merged['response']['close_attempt'], $merged['response']['escalate_after_ms'] );
			update_option( self::OPTION_NAME, $merged, false );
		}

		/** Direct form save inside the unified admin shell. */
		public function maybe_handle_save() {
			if ( ! is_admin() || empty( $_POST['jlwa_anti_debug_save'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				return;
			}
			if ( empty( $_GET['page'] ) || self::MENU_SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return;
			}
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( '权限不足，无法保存反调试设置。', 'jiuliu-wp-assistant' ) );
			}
			check_admin_referer( 'jlwa_anti_debug_save', 'jlwa_anti_debug_nonce' );
			$raw = isset( $_POST[ self::OPTION_NAME ] ) ? wp_unslash( $_POST[ self::OPTION_NAME ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$options = $this->sanitize_options( is_array( $raw ) ? $raw : array() );
			$options['version'] = self::VERSION;
			update_option( self::OPTION_NAME, $options, false );
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'       => self::MENU_SLUG,
						'jlwa_ad_saved' => 1,
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		public function clear_logs() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( '权限不足。', 'jiuliu-wp-assistant' ) );
			}
			check_admin_referer( 'jlwa_anti_debug_clear_logs' );
			update_option( self::LOG_OPTION, array(), false );
			wp_safe_redirect( add_query_arg( array( 'page' => self::MENU_SLUG, 'jlwa_ad_logs_cleared' => 1 ), admin_url( 'admin.php' ) ) );
			exit;
		}

		/** @param string $hook Admin page hook. */
		public function enqueue_admin_assets( $hook ) {
			$allowed = array(
				'jiuliu-wp-assistant_page_' . self::MENU_SLUG,
				JLWA_MENU_SLUG . '_page_' . self::MENU_SLUG,
			);
			if ( ! in_array( $hook, $allowed, true ) ) {
				return;
			}
			wp_enqueue_style( 'jlwa-anti-debug-admin', plugins_url( 'assets/css/admin.css', __FILE__ ), array(), self::VERSION );
			wp_enqueue_script( 'jlwa-anti-debug-admin', plugins_url( 'assets/js/admin.js', __FILE__ ), array(), self::VERSION, true );
		}

		/** Render the dedicated feature settings screen. */
		public function render_admin_page() {
			$options = $this->get_options();
			$logs    = get_option( self::LOG_OPTION, array() );
			$logs    = is_array( $logs ) ? $logs : array();
			?>
			<div class="jlwa-ad-admin">
				<?php if ( ! empty( $_GET['jlwa_ad_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
					<div class="notice notice-success inline"><p>反调试保护设置已保存。</p></div>
				<?php endif; ?>
				<?php if ( ! empty( $_GET['jlwa_ad_logs_cleared'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
					<div class="notice notice-success inline"><p>触发日志已清空。</p></div>
				<?php endif; ?>

				<section class="jlwa-ad-boundary">
					<div><span class="dashicons dashicons-shield-alt"></span></div>
					<div><strong>功能边界</strong><p>该功能通过多种侧信号检测开发者工具并执行前台处置，可以显著提高普通分析和批量转载成本，但不能代替服务器权限控制，也不承诺对专业逆向绝对不可绕过。</p></div>
				</section>

				<form method="post" class="jlwa-ad-form">
					<?php wp_nonce_field( 'jlwa_anti_debug_save', 'jlwa_anti_debug_nonce' ); ?>
					<input type="hidden" name="jlwa_anti_debug_save" value="1">

					<div class="jlwa-ad-toolbar">
						<label class="jlwa-ad-master"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[enabled]" value="1" <?php checked( ! empty( $options['enabled'] ) ); ?>><span></span><strong>启用反调试保护</strong></label>
						<label>运行模式
							<select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[mode]" data-jlwa-ad-mode>
								<option value="balanced" <?php selected( $options['mode'], 'balanced' ); ?>>平衡模式</option>
								<option value="strict" <?php selected( $options['mode'], 'strict' ); ?>>严格模式</option>
								<option value="custom" <?php selected( $options['mode'], 'custom' ); ?>>自定义模式</option>
							</select>
						</label>
						<button type="submit" class="button button-primary">保存全部设置</button>
					</div>

					<nav class="jlwa-ad-tabs" aria-label="反调试设置分区">
						<button type="button" class="is-active" data-tab="overview">总体与范围</button>
						<button type="button" data-tab="detectors">探测器</button>
						<button type="button" data-tab="decision">判定与公共设置</button>
						<button type="button" data-tab="layers">分级防御链</button>
						<button type="button" data-tab="logs">触发日志</button>
					</nav>

					<section class="jlwa-ad-panel is-active" data-panel="overview">
						<div class="jlwa-ad-grid">
							<?php $this->checkbox_card( 'scope[posts]', '保护文章页', '在单篇文章前台运行反调试检测。', ! empty( $options['scope']['posts'] ) ); ?>
							<?php $this->checkbox_card( 'scope[pages]', '保护独立页面', '适用于下载页、会员页和专题页面。', ! empty( $options['scope']['pages'] ) ); ?>
							<?php $this->checkbox_card( 'scope[archives]', '保护归档列表', '分类、标签、作者归档等列表页面。', ! empty( $options['scope']['archives'] ) ); ?>
							<?php $this->checkbox_card( 'scope[home]', '保护首页', '在网站首页和博客首页运行检测。', ! empty( $options['scope']['home'] ) ); ?>
							<?php $this->checkbox_card( 'scope[mobile]', '移动端实验性保护', '默认关闭。仅检测 eruda、vConsole 等页面调试库；iPhone/iPad 会由浏览器端再次识别。', ! empty( $options['scope']['mobile'] ) ); ?>
							<?php $this->checkbox_card( 'scope[mobile_risky_detectors]', '移动端高风险探测器', '实验选项：允许在手机和平板运行视口、Debugger、Console 求值/性能与失焦探测，可能误报。', ! empty( $options['scope']['mobile_risky_detectors'] ), 'dangerous' ); ?>
							<?php $this->checkbox_card( 'scope[admin_bypass]', '管理员自动绕过', '管理员登录后不加载检测脚本。', ! empty( $options['scope']['admin_bypass'] ) ); ?>
							<?php $this->checkbox_card( 'scope[logged_in_bypass]', '全部登录用户绕过', '适合只防外部访客的网站。', ! empty( $options['scope']['logged_in_bypass'] ) ); ?>
						</div>
						<label class="jlwa-ad-field"><span>排除路径</span><textarea name="<?php echo esc_attr( self::OPTION_NAME ); ?>[scope][exclude_paths]" rows="5" placeholder="每行一个路径前缀"><?php echo esc_textarea( $options['scope']['exclude_paths'] ); ?></textarea><small>每行一个站内路径前缀，例如 <code>/checkout/</code>。登录和后台路径始终排除。</small></label>
					</section>

					<section class="jlwa-ad-panel" data-panel="detectors">
						<div class="jlwa-ad-grid">
							<?php $this->checkbox_card( 'detectors[shortcuts]', '快捷键入口', '拦截 F12、Ctrl/Cmd+U、Ctrl/Cmd+Shift+I/J/C 等，并作为高分信号。', ! empty( $options['detectors']['shortcuts'] ), 'shortcuts' ); ?>
							<?php $this->checkbox_card( 'detectors[console_getter]', 'Console 求值', '利用控制台读取对象属性和格式化行为发现菜单打开的 DevTools。', ! empty( $options['detectors']['console_getter'] ), 'console_getter' ); ?>
							<?php $this->checkbox_card( 'detectors[debug_libraries]', '页面调试库', '检测 eruda、vConsole、Firebug 等页面内调试工具。', ! empty( $options['detectors']['debug_libraries'] ), 'debug_libraries' ); ?>
							<?php $this->checkbox_card( 'detectors[viewport]', '窗口差值', '识别停靠在左、右或底部的开发者工具；浏览器侧栏可能造成误判。', ! empty( $options['detectors']['viewport'] ), 'viewport' ); ?>
							<?php $this->checkbox_card( 'detectors[debugger_timing]', 'Debugger 耗时', '低频测量断点造成的异常停顿；严格模式使用。', ! empty( $options['detectors']['debugger_timing'] ), 'debugger_timing' ); ?>
							<?php $this->checkbox_card( 'detectors[console_performance]', 'Console 性能差', '比较控制台处理小型对象组的耗时，默认关闭。', ! empty( $options['detectors']['console_performance'] ), 'console_performance' ); ?>
							<?php $this->checkbox_card( 'detectors[focus_signal]', '窗口失焦辅助', '只提供低分辅助，不会单独判定。', ! empty( $options['detectors']['focus_signal'] ), 'focus_signal' ); ?>
						</div>
						<div class="jlwa-ad-fields-4">
							<?php $this->number_field( 'detectors[interval_ms]', '检测间隔（毫秒）', $options['detectors']['interval_ms'], 450, 5000, 50 ); ?>
							<?php $this->number_field( 'detectors[viewport_threshold]', '窗口差值阈值（px）', $options['detectors']['viewport_threshold'], 120, 600, 10 ); ?>
							<?php $this->number_field( 'detectors[viewport_ratio]', '窗口差值比例（%）', $options['detectors']['viewport_ratio'], 8, 50, 1 ); ?>
							<?php $this->number_field( 'detectors[debugger_threshold]', 'Debugger 耗时阈值（ms）', $options['detectors']['debugger_threshold'], 80, 1500, 10 ); ?>
						</div>
					</section>

					<section class="jlwa-ad-panel" data-panel="decision">
						<div class="jlwa-ad-fields-4">
							<?php $this->number_field( 'decision[threshold]', '触发分数', $options['decision']['threshold'], 30, 200, 5 ); ?>
							<?php $this->number_field( 'decision[confirm_hits]', '连续确认次数', $options['decision']['confirm_hits'], 1, 6, 1 ); ?>
							<?php $this->number_field( 'decision[hit_window_ms]', '确认窗口（ms）', $options['decision']['hit_window_ms'], 1000, 15000, 100 ); ?>
							<?php $this->number_field( 'decision[score_decay]', '每轮分数衰减', $options['decision']['score_decay'], 1, 50, 1 ); ?>
						</div>
						<label class="jlwa-ad-field"><span>警告标题</span><input type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[response][message]" value="<?php echo esc_attr( $options['response']['message'] ); ?>"></label>
						<label class="jlwa-ad-field"><span>补充说明</span><textarea name="<?php echo esc_attr( self::OPTION_NAME ); ?>[response][detail]" rows="3"><?php echo esc_textarea( $options['response']['detail'] ); ?></textarea></label>
						<label class="jlwa-ad-field"><span>正文选择器</span><input type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[response][content_selector]" value="<?php echo esc_attr( $options['response']['content_selector'] ); ?>"><small>用于模糊、禁用交互和替换正文。多个选择器用英文逗号分隔。</small></label>
						<label class="jlwa-ad-field"><span>跳转地址</span><input type="url" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[response][redirect_url]" value="<?php echo esc_attr( $options['response']['redirect_url'] ); ?>" placeholder="https://example.com/copyright/"></label>
						<div class="jlwa-ad-fields-4">
							<?php $this->number_field( 'response[blur_px]', '模糊强度（px）', $options['response']['blur_px'], 0, 60, 1 ); ?>
							<?php $this->number_field( 'response[recover_delay_ms]', '恢复等待（ms）', $options['response']['recover_delay_ms'], 500, 15000, 100 ); ?>
						</div>
						<div class="jlwa-ad-grid">
							<?php $this->checkbox_card( 'response[auto_recover]', '信号消失后自动恢复', '未启用会话持久锁时，关闭开发者工具后恢复正文。', ! empty( $options['response']['auto_recover'] ) ); ?>
							<?php $this->checkbox_card( 'logging[enabled]', '记录触发日志', '只保存时间、原因、页面、浏览器摘要和匿名访客指纹。', ! empty( $options['logging']['enabled'] ) ); ?>
						</div>
						<section class="jlwa-ad-emergency">
							<div><strong>紧急旁路</strong><p>高强度循环启用后，可用下面的地址临时绕过前台反调试。管理员登录状态仍默认自动绕过。</p></div>
							<label class="jlwa-ad-field"><span>旁路密钥</span><input type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[scope][emergency_key]" value="<?php echo esc_attr( $options['scope']['emergency_key'] ); ?>" minlength="8"></label>
							<code><?php echo esc_html( add_query_arg( 'jlwa_safe', $options['scope']['emergency_key'], home_url( '/' ) ) ); ?></code>
						</section>
					</section>

					<section class="jlwa-ad-panel" data-panel="layers">
						<div class="jlwa-ad-danger-note"><strong>可选高强度防御</strong><p>四个层级按延迟依次执行，每个层级都可单独启停和组合动作。持续 debugger、循环刷新和循环关闭会明显影响访客浏览，仅在确认旁路地址可用后启用。</p></div>

						<section class="jlwa-ad-layer jlwa-ad-layer--1">
							<header><span>LEVEL 1</span><div><strong>警告与软阻断</strong><p>先提示、模糊和限制操作，不离开当前页面。</p></div></header>
							<div class="jlwa-ad-fields-4"><?php $this->number_field( 'response[layers][level1][delay_ms]', '触发后延迟（ms）', $options['response']['layers']['level1']['delay_ms'], 0, 30000, 100 ); ?></div>
							<div class="jlwa-ad-grid">
								<?php $this->checkbox_card( 'response[layers][level1][enabled]', '启用第一级', '允许执行本层勾选的动作。', ! empty( $options['response']['layers']['level1']['enabled'] ) ); ?>
								<?php $this->checkbox_card( 'response[layers][level1][overlay]', '警告遮罩', '显示全屏提示卡片。', ! empty( $options['response']['layers']['level1']['overlay'] ) ); ?>
								<?php $this->checkbox_card( 'response[layers][level1][blur]', '模糊正文', '按正文选择器模糊内容。', ! empty( $options['response']['layers']['level1']['blur'] ) ); ?>
								<?php $this->checkbox_card( 'response[layers][level1][block_interaction]', '禁用正文交互', '阻止正文区域点击和触摸。', ! empty( $options['response']['layers']['level1']['block_interaction'] ) ); ?>
								<?php $this->checkbox_card( 'response[layers][level1][block_selection]', '禁用选择', '禁止页面文本选择。', ! empty( $options['response']['layers']['level1']['block_selection'] ) ); ?>
							</div>
						</section>

						<section class="jlwa-ad-layer jlwa-ad-layer--2">
							<header><span>LEVEL 2</span><div><strong>内容与浏览行为锁定</strong><p>替换正文、清空控制台并限制复制和后退。</p></div></header>
							<div class="jlwa-ad-fields-4">
								<?php $this->number_field( 'response[layers][level2][delay_ms]', '触发后延迟（ms）', $options['response']['layers']['level2']['delay_ms'], 0, 30000, 100 ); ?>
								<?php $this->number_field( 'response[layers][level2][console_clear_interval_ms]', '清空控制台间隔（ms）', $options['response']['layers']['level2']['console_clear_interval_ms'], 100, 10000, 50 ); ?>
							</div>
							<div class="jlwa-ad-grid">
								<?php $this->checkbox_card( 'response[layers][level2][enabled]', '启用第二级', '允许执行本层勾选的动作。', ! empty( $options['response']['layers']['level2']['enabled'] ) ); ?>
								<?php $this->checkbox_card( 'response[layers][level2][replace_content]', '替换正文 DOM', '把匹配的正文替换为保护提示。', ! empty( $options['response']['layers']['level2']['replace_content'] ) ); ?>
								<?php $this->checkbox_card( 'response[layers][level2][clear_console]', '循环清空 Console', '按设置间隔反复调用 console.clear。', ! empty( $options['response']['layers']['level2']['clear_console'] ) ); ?>
								<?php $this->checkbox_card( 'response[layers][level2][history_lock]', '锁定后退行为', '触发后持续补回当前历史记录。', ! empty( $options['response']['layers']['level2']['history_lock'] ) ); ?>
								<?php $this->checkbox_card( 'response[layers][level2][clipboard_guard]', '阻止复制与剪切', '捕获 copy/cut 事件并阻止默认行为。', ! empty( $options['response']['layers']['level2']['clipboard_guard'] ) ); ?>
							</div>
						</section>

						<section class="jlwa-ad-layer jlwa-ad-layer--3">
							<header><span>LEVEL 3</span><div><strong>页面驱离</strong><p>跳转、循环刷新和循环关闭均可独立选择。</p></div></header>
							<div class="jlwa-ad-fields-4">
								<?php $this->number_field( 'response[layers][level3][delay_ms]', '触发后延迟（ms）', $options['response']['layers']['level3']['delay_ms'], 0, 60000, 100 ); ?>
								<?php $this->number_field( 'response[layers][level3][reload_interval_ms]', '循环刷新间隔（ms）', $options['response']['layers']['level3']['reload_interval_ms'], 250, 30000, 50 ); ?>
								<?php $this->number_field( 'response[layers][level3][close_interval_ms]', '循环关闭间隔（ms）', $options['response']['layers']['level3']['close_interval_ms'], 150, 10000, 50 ); ?>
								<?php $this->number_field( 'response[layers][level3][persist_minutes]', '会话锁定分钟', $options['response']['layers']['level3']['persist_minutes'], 1, 1440, 1 ); ?>
							</div>
							<div class="jlwa-ad-grid">
								<?php $this->checkbox_card( 'response[layers][level3][enabled]', '启用第三级', '允许执行本层勾选的动作。', ! empty( $options['response']['layers']['level3']['enabled'] ) ); ?>
								<?php $this->checkbox_card( 'response[layers][level3][redirect]', '跳转指定地址', '使用公共设置中的跳转地址。', ! empty( $options['response']['layers']['level3']['redirect'] ) ); ?>
								<?php $this->checkbox_card( 'response[layers][level3][reload_loop]', '死循环刷新', '写入会话锁并按间隔反复刷新页面。', ! empty( $options['response']['layers']['level3']['reload_loop'] ), 'dangerous' ); ?>
								<?php $this->checkbox_card( 'response[layers][level3][close_loop]', '循环尝试关闭', '浏览器可能拒绝，但会持续尝试。', ! empty( $options['response']['layers']['level3']['close_loop'] ), 'dangerous' ); ?>
								<?php $this->checkbox_card( 'response[layers][level3][persist_session]', '持久会话锁', '刷新后继续直接进入防御链。', ! empty( $options['response']['layers']['level3']['persist_session'] ), 'dangerous' ); ?>
							</div>
						</section>

						<section class="jlwa-ad-layer jlwa-ad-layer--4">
							<header><span>LEVEL 4</span><div><strong>极限组合防御</strong><p>持续 debugger、硬锁、刷新与关闭可同时运行。</p></div></header>
							<div class="jlwa-ad-fields-4">
								<?php $this->number_field( 'response[layers][level4][delay_ms]', '触发后延迟（ms）', $options['response']['layers']['level4']['delay_ms'], 0, 60000, 100 ); ?>
								<?php $this->number_field( 'response[layers][level4][debugger_interval_ms]', 'Debugger 循环间隔（ms）', $options['response']['layers']['level4']['debugger_interval_ms'], 80, 5000, 10 ); ?>
								<?php $this->number_field( 'response[layers][level4][console_clear_interval_ms]', '清空 Console 间隔（ms）', $options['response']['layers']['level4']['console_clear_interval_ms'], 80, 10000, 10 ); ?>
								<?php $this->number_field( 'response[layers][level4][reload_interval_ms]', '刷新循环间隔（ms）', $options['response']['layers']['level4']['reload_interval_ms'], 250, 30000, 50 ); ?>
								<?php $this->number_field( 'response[layers][level4][close_interval_ms]', '关闭循环间隔（ms）', $options['response']['layers']['level4']['close_interval_ms'], 150, 10000, 50 ); ?>
								<?php $this->number_field( 'response[layers][level4][persist_minutes]', '会话锁定分钟', $options['response']['layers']['level4']['persist_minutes'], 1, 1440, 1 ); ?>
							</div>
							<div class="jlwa-ad-grid">
								<?php $this->checkbox_card( 'response[layers][level4][enabled]', '启用第四级', '允许执行本层勾选的动作。', ! empty( $options['response']['layers']['level4']['enabled'] ) ); ?>
								<?php $this->checkbox_card( 'response[layers][level4][debugger_loop]', '无限 Debugger 循环', '持续触发 debugger 断点。', ! empty( $options['response']['layers']['level4']['debugger_loop'] ), 'dangerous' ); ?>
								<?php $this->checkbox_card( 'response[layers][level4][clear_console]', '高速清空 Console', '持续清理控制台输出。', ! empty( $options['response']['layers']['level4']['clear_console'] ), 'dangerous' ); ?>
								<?php $this->checkbox_card( 'response[layers][level4][reload_loop]', '死循环刷新', '极限层持续刷新并保持会话锁。', ! empty( $options['response']['layers']['level4']['reload_loop'] ), 'dangerous' ); ?>
								<?php $this->checkbox_card( 'response[layers][level4][close_loop]', '循环关闭页面', '按高频间隔持续尝试关闭窗口。', ! empty( $options['response']['layers']['level4']['close_loop'] ), 'dangerous' ); ?>
								<?php $this->checkbox_card( 'response[layers][level4][hard_lock]', '页面硬锁', '禁用页面滚动、选择、点击和触摸。', ! empty( $options['response']['layers']['level4']['hard_lock'] ), 'dangerous' ); ?>
								<?php $this->checkbox_card( 'response[layers][level4][persist_session]', '持久会话锁', '刷新或重进页面后继续直接执行防御。', ! empty( $options['response']['layers']['level4']['persist_session'] ), 'dangerous' ); ?>
							</div>
						</section>
					</section>

					<section class="jlwa-ad-panel" data-panel="logs">
						<div class="jlwa-ad-log-head"><div><h3>最近触发记录</h3><p>最多保留 <?php echo esc_html( (int) $options['logging']['max_entries'] ); ?> 条，访客地址只保存不可逆摘要。</p></div><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=jlwa_anti_debug_clear_logs' ), 'jlwa_anti_debug_clear_logs' ) ); ?>">清空日志</a></div>
						<div class="jlwa-ad-log-table-wrap">
							<table class="widefat striped jlwa-ad-log-table"><thead><tr><th>时间</th><th>原因</th><th>分数</th><th>页面</th><th>访客</th></tr></thead><tbody>
							<?php if ( ! $logs ) : ?><tr><td colspan="5">暂无触发记录。</td></tr><?php else : ?>
								<?php foreach ( array_reverse( $logs ) as $entry ) : ?>
									<tr><td><?php echo esc_html( isset( $entry['time'] ) ? $entry['time'] : '' ); ?></td><td><?php echo esc_html( isset( $entry['reasons'] ) ? implode( '、', (array) $entry['reasons'] ) : '' ); ?></td><td><?php echo esc_html( isset( $entry['score'] ) ? (int) $entry['score'] : 0 ); ?></td><td><code><?php echo esc_html( isset( $entry['url'] ) ? $entry['url'] : '' ); ?></code></td><td><?php echo esc_html( isset( $entry['visitor'] ) ? $entry['visitor'] : '' ); ?></td></tr>
								<?php endforeach; ?>
							<?php endif; ?>
							</tbody></table>
						</div>
					</section>
				</form>
			</div>
			<?php
		}

		/** @param string $name Field suffix. @param string $title Title. @param string $description Description. @param bool $checked Checked. @param string $detector Detector key. */
		private function checkbox_card( $name, $title, $description, $checked, $detector = '' ) {
			$is_dangerous = 'dangerous' === $detector;
			$attr = $detector && ! $is_dangerous ? ' data-detector="' . esc_attr( $detector ) . '"' : '';
			$class = 'jlwa-ad-option' . ( $is_dangerous ? ' is-dangerous' : '' );
			echo '<label class="' . esc_attr( $class ) . '"' . $attr . '><input type="checkbox" name="' . esc_attr( $this->field_name( $name ) ) . '" value="1" ' . checked( $checked, true, false ) . '><span class="jlwa-ad-option__check"></span><span><strong>' . esc_html( $title ) . '</strong><small>' . esc_html( $description ) . '</small></span></label>';
		}

		/** @param string $name Field suffix. @param string $title Title. @param int|float $value Value. @param int|float $min Min. @param int|float $max Max. @param int|float $step Step. */
		private function number_field( $name, $title, $value, $min, $max, $step ) {
			echo '<label class="jlwa-ad-field"><span>' . esc_html( $title ) . '</span><input type="number" name="' . esc_attr( $this->field_name( $name ) ) . '" value="' . esc_attr( $value ) . '" min="' . esc_attr( $min ) . '" max="' . esc_attr( $max ) . '" step="' . esc_attr( $step ) . '"></label>';
		}

		/** @param string $path Bracket path. @return string */
		private function field_name( $path ) {
			preg_match_all( '/[^\[\]]+/', (string) $path, $matches );
			$name = self::OPTION_NAME;
			foreach ( $matches[0] as $segment ) {
				$name .= '[' . sanitize_key( $segment ) . ']';
			}
			return $name;
		}

		public function enqueue_frontend_assets() {
			$options = $this->get_options();
			if ( empty( $options['enabled'] ) || ! $this->should_load( $options ) ) {
				return;
			}
			wp_enqueue_style( 'jlwa-anti-debug', plugins_url( 'assets/css/frontend.css', __FILE__ ), array(), self::VERSION );
			wp_enqueue_script( 'jlwa-anti-debug', plugins_url( 'assets/js/frontend.js', __FILE__ ), array(), self::VERSION, false );
			$config = array(
				'mode'      => $options['mode'],
				'detectors' => $options['detectors'],
				'decision'  => $options['decision'],
				'response'  => $options['response'],
				'runtime'   => array(
					'allow_mobile'       => ! empty( $options['scope']['mobile'] ),
					'allow_mobile_risky' => ! empty( $options['scope']['mobile_risky_detectors'] ),
				),
				'logging'   => array(
					'enabled' => ! empty( $options['logging']['enabled'] ),
					'url'     => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'jlwa_anti_debug_event' ),
				),
			);
			wp_add_inline_script( 'jlwa-anti-debug', 'window.JLWA_ANTI_DEBUG=' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
		}

		/** @param array<string,mixed> $options Options. */
		private function should_load( $options ) {
			if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_robots() ) {
				return false;
			}
			$scope = $options['scope'];
			if ( ! empty( $scope['admin_bypass'] ) && is_user_logged_in() && current_user_can( 'manage_options' ) ) {
				return false;
			}
			if ( ! empty( $scope['logged_in_bypass'] ) && is_user_logged_in() ) {
				return false;
			}
			if ( ! empty( $scope['emergency_key'] ) && isset( $_GET['jlwa_safe'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$provided = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) wp_unslash( $_GET['jlwa_safe'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( hash_equals( (string) $scope['emergency_key'], (string) $provided ) ) {
					return false;
				}
			}
			if ( empty( $scope['mobile'] ) && wp_is_mobile() ) {
				return false;
			}
			$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			foreach ( preg_split( '/\r\n|\r|\n/', (string) $scope['exclude_paths'] ) as $excluded ) {
				$excluded = trim( $excluded );
				if ( '' !== $excluded && 0 === strpos( (string) $path, $excluded ) ) {
					return false;
				}
			}
			if ( is_singular( 'post' ) ) {
				return ! empty( $scope['posts'] );
			}
			if ( is_page() ) {
				return ! empty( $scope['pages'] );
			}
			if ( is_front_page() || is_home() ) {
				return ! empty( $scope['home'] );
			}
			if ( is_archive() || is_search() ) {
				return ! empty( $scope['archives'] );
			}
			return false;
		}

		/** Public, rate-limited event log endpoint. */
		public function record_event() {
			check_ajax_referer( 'jlwa_anti_debug_event', 'nonce' );
			$options = $this->get_options();
			if ( empty( $options['enabled'] ) || empty( $options['logging']['enabled'] ) ) {
				wp_send_json_success();
			}
			$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
			$visitor = substr( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ), 0, 12 );
			$rate_key = 'jlwa_ad_log_' . $visitor;
			if ( get_transient( $rate_key ) ) {
				wp_send_json_success();
			}
			set_transient( $rate_key, 1, 20 );
			$reasons = isset( $_POST['reasons'] ) ? (array) wp_unslash( $_POST['reasons'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$reasons = array_slice( array_values( array_filter( array_map( 'sanitize_key', $reasons ) ) ), 0, 8 );
			$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
			$score = isset( $_POST['score'] ) ? min( 500, max( 0, absint( $_POST['score'] ) ) ) : 0;
			$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
			$logs = get_option( self::LOG_OPTION, array() );
			$logs = is_array( $logs ) ? $logs : array();
			$logs[] = array(
				'time'    => current_time( 'mysql' ),
				'reasons' => $reasons,
				'score'   => $score,
				'url'     => $url ? wp_make_link_relative( $url ) : '',
				'visitor' => $visitor,
				'ua'      => substr( $user_agent, 0, 180 ),
			);
			$max = min( 500, max( 20, absint( $options['logging']['max_entries'] ) ) );
			if ( count( $logs ) > $max ) {
				$logs = array_slice( $logs, -$max );
			}
			update_option( self::LOG_OPTION, $logs, false );
			wp_send_json_success();
		}

		/** @return array<string,mixed> */
		private function get_options() {
			$saved = get_option( self::OPTION_NAME, array() );
			return $this->deep_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		}

		/** @param array<string,mixed> $raw Raw values. @return array<string,mixed> */
		private function sanitize_options( $raw ) {
			$defaults = self::defaults();
			$out = $defaults;
			$out['enabled'] = empty( $raw['enabled'] ) ? 0 : 1;
			$out['mode'] = in_array( isset( $raw['mode'] ) ? $raw['mode'] : '', array( 'balanced', 'strict', 'custom' ), true ) ? $raw['mode'] : 'balanced';
			foreach ( array( 'posts', 'pages', 'archives', 'home', 'mobile', 'mobile_risky_detectors', 'admin_bypass', 'logged_in_bypass' ) as $key ) {
				$out['scope'][ $key ] = empty( $raw['scope'][ $key ] ) ? 0 : 1;
			}
			$out['scope']['exclude_paths'] = isset( $raw['scope']['exclude_paths'] ) ? sanitize_textarea_field( $raw['scope']['exclude_paths'] ) : '';
			foreach ( array( 'shortcuts', 'viewport', 'debugger_timing', 'console_getter', 'console_performance', 'debug_libraries', 'focus_signal' ) as $key ) {
				$out['detectors'][ $key ] = empty( $raw['detectors'][ $key ] ) ? 0 : 1;
			}
			$out['detectors']['interval_ms'] = $this->bounded_int( $raw, array( 'detectors', 'interval_ms' ), 450, 5000, 1100 );
			$out['detectors']['viewport_threshold'] = $this->bounded_int( $raw, array( 'detectors', 'viewport_threshold' ), 120, 600, 220 );
			$out['detectors']['viewport_ratio'] = $this->bounded_int( $raw, array( 'detectors', 'viewport_ratio' ), 8, 50, 18 );
			$out['detectors']['debugger_threshold'] = $this->bounded_int( $raw, array( 'detectors', 'debugger_threshold' ), 80, 1500, 180 );
			$out['detectors']['performance_threshold'] = $this->bounded_int( $raw, array( 'detectors', 'performance_threshold' ), 40, 1000, 110 );
			$out['decision']['threshold'] = $this->bounded_int( $raw, array( 'decision', 'threshold' ), 30, 200, 85 );
			$out['decision']['confirm_hits'] = $this->bounded_int( $raw, array( 'decision', 'confirm_hits' ), 1, 6, 2 );
			$out['decision']['hit_window_ms'] = $this->bounded_int( $raw, array( 'decision', 'hit_window_ms' ), 1000, 15000, 4200 );
			$out['decision']['score_decay'] = $this->bounded_int( $raw, array( 'decision', 'score_decay' ), 1, 50, 12 );
			$out['decision']['detector_cooldown_ms'] = 1800;
			$key = isset( $raw['scope']['emergency_key'] ) ? preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $raw['scope']['emergency_key'] ) : '';
			$out['scope']['emergency_key'] = strlen( $key ) >= 8 ? substr( $key, 0, 64 ) : wp_generate_password( 18, false, false );
			$out['response']['message'] = isset( $raw['response']['message'] ) ? sanitize_text_field( $raw['response']['message'] ) : $defaults['response']['message'];
			$out['response']['detail'] = isset( $raw['response']['detail'] ) ? sanitize_textarea_field( $raw['response']['detail'] ) : $defaults['response']['detail'];
			$out['response']['content_selector'] = isset( $raw['response']['content_selector'] ) ? sanitize_text_field( $raw['response']['content_selector'] ) : $defaults['response']['content_selector'];
			$out['response']['redirect_url'] = isset( $raw['response']['redirect_url'] ) ? esc_url_raw( $raw['response']['redirect_url'] ) : '';
			$out['response']['blur_px'] = $this->bounded_int( $raw, array( 'response', 'blur_px' ), 0, 60, 16 );
			$out['response']['recover_delay_ms'] = $this->bounded_int( $raw, array( 'response', 'recover_delay_ms' ), 500, 15000, 1800 );
			$out['response']['auto_recover'] = empty( $raw['response']['auto_recover'] ) ? 0 : 1;

			$boolean_layers = array(
				'level1' => array( 'enabled', 'overlay', 'blur', 'block_interaction', 'block_selection' ),
				'level2' => array( 'enabled', 'replace_content', 'clear_console', 'history_lock', 'clipboard_guard' ),
				'level3' => array( 'enabled', 'redirect', 'reload_loop', 'close_loop', 'persist_session' ),
				'level4' => array( 'enabled', 'debugger_loop', 'clear_console', 'reload_loop', 'close_loop', 'hard_lock', 'persist_session' ),
			);
			foreach ( $boolean_layers as $level => $keys ) {
				foreach ( $keys as $layer_key ) {
					$out['response']['layers'][ $level ][ $layer_key ] = empty( $raw['response']['layers'][ $level ][ $layer_key ] ) ? 0 : 1;
				}
			}
			$out['response']['layers']['level1']['delay_ms'] = $this->bounded_int( $raw, array( 'response', 'layers', 'level1', 'delay_ms' ), 0, 30000, 0 );
			$out['response']['layers']['level2']['delay_ms'] = $this->bounded_int( $raw, array( 'response', 'layers', 'level2', 'delay_ms' ), 0, 30000, 2500 );
			$out['response']['layers']['level2']['console_clear_interval_ms'] = $this->bounded_int( $raw, array( 'response', 'layers', 'level2', 'console_clear_interval_ms' ), 100, 10000, 500 );
			$out['response']['layers']['level3']['delay_ms'] = $this->bounded_int( $raw, array( 'response', 'layers', 'level3', 'delay_ms' ), 0, 60000, 5000 );
			$out['response']['layers']['level3']['reload_interval_ms'] = $this->bounded_int( $raw, array( 'response', 'layers', 'level3', 'reload_interval_ms' ), 250, 30000, 1200 );
			$out['response']['layers']['level3']['close_interval_ms'] = $this->bounded_int( $raw, array( 'response', 'layers', 'level3', 'close_interval_ms' ), 150, 10000, 700 );
			$out['response']['layers']['level3']['persist_minutes'] = $this->bounded_int( $raw, array( 'response', 'layers', 'level3', 'persist_minutes' ), 1, 1440, 10 );
			$out['response']['layers']['level4']['delay_ms'] = $this->bounded_int( $raw, array( 'response', 'layers', 'level4', 'delay_ms' ), 0, 60000, 8000 );
			$out['response']['layers']['level4']['debugger_interval_ms'] = $this->bounded_int( $raw, array( 'response', 'layers', 'level4', 'debugger_interval_ms' ), 80, 5000, 250 );
			$out['response']['layers']['level4']['console_clear_interval_ms'] = $this->bounded_int( $raw, array( 'response', 'layers', 'level4', 'console_clear_interval_ms' ), 80, 10000, 250 );
			$out['response']['layers']['level4']['reload_interval_ms'] = $this->bounded_int( $raw, array( 'response', 'layers', 'level4', 'reload_interval_ms' ), 250, 30000, 800 );
			$out['response']['layers']['level4']['close_interval_ms'] = $this->bounded_int( $raw, array( 'response', 'layers', 'level4', 'close_interval_ms' ), 150, 10000, 350 );
			$out['response']['layers']['level4']['persist_minutes'] = $this->bounded_int( $raw, array( 'response', 'layers', 'level4', 'persist_minutes' ), 1, 1440, 15 );
			$out['logging']['enabled'] = empty( $raw['logging']['enabled'] ) ? 0 : 1;
			$out['logging']['max_entries'] = $this->bounded_int( $raw, array( 'logging', 'max_entries' ), 20, 500, 100 );

			if ( 'balanced' === $out['mode'] ) {
				$out['detectors']['shortcuts'] = 1;
				$out['detectors']['console_getter'] = 1;
				$out['detectors']['debug_libraries'] = 1;
				$out['detectors']['viewport'] = 0;
				$out['detectors']['debugger_timing'] = 0;
				$out['detectors']['console_performance'] = 0;
				$out['decision']['threshold'] = max( 80, $out['decision']['threshold'] );
				$out['decision']['confirm_hits'] = max( 2, $out['decision']['confirm_hits'] );
			} elseif ( 'strict' === $out['mode'] ) {
				foreach ( array( 'shortcuts', 'viewport', 'debugger_timing', 'console_getter', 'console_performance', 'debug_libraries', 'focus_signal' ) as $key ) {
					$out['detectors'][ $key ] = 1;
				}
				$out['decision']['threshold'] = min( 80, $out['decision']['threshold'] );
			}
			return $out;
		}

		/** @param array<string,mixed> $raw Raw. @param array<int,string> $path Path. */
		private function bounded_int( $raw, $path, $min, $max, $fallback ) {
			$value = $raw;
			foreach ( $path as $key ) {
				if ( ! is_array( $value ) || ! isset( $value[ $key ] ) ) {
					return $fallback;
				}
				$value = $value[ $key ];
			}
			return min( $max, max( $min, absint( $value ) ) );
		}

		/** @param array<string,mixed> $defaults Defaults. @param array<string,mixed> $saved Saved. @return array<string,mixed> */
		private function deep_merge( $defaults, $saved ) {
			foreach ( $saved as $key => $value ) {
				if ( isset( $defaults[ $key ] ) && is_array( $defaults[ $key ] ) && is_array( $value ) ) {
					$defaults[ $key ] = $this->deep_merge( $defaults[ $key ], $value );
				} else {
					$defaults[ $key ] = $value;
				}
			}
			return $defaults;
		}
	}
}

JLWA_Anti_Debug_Feature::instance();
