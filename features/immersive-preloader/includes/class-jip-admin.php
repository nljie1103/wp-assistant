<?php
/**
 * 后台菜单与设置页面
 *
 * @package JiuliuImmersivePreloader
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class JIP_Admin
 */
class JIP_Admin {

	/**
	 * 单例。
	 *
	 * @var JIP_Admin|null
	 */
	private static $instance = null;

	/**
	 * 获取单例。
	 *
	 * @return JIP_Admin
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
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * 注册设置。
	 */
	public function register_settings() {
		register_setting(
			'jip_settings_group',
			JIP_OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'JIP_Settings', 'sanitize' ),
				'default'           => JIP_Settings::get_defaults(),
			)
		);
	}

	/**
	 * 加载后台静态资源。
	 *
	 * @param string $hook 当前后台页面 hook。
	 */
	public function enqueue_assets( $hook ) {
		// 仅在插件设置页加载。
		$allowed_hooks = array(
			'toplevel_page_' . JIP_MENU_SLUG,
			defined( 'JLWA_MENU_SLUG' ) ? JLWA_MENU_SLUG . '_page_' . JIP_MENU_SLUG : '',
		);
		if ( ! in_array( $hook, $allowed_hooks, true ) ) {
			return;
		}

		// 媒体上传器。
		wp_enqueue_media();

		// 颜色选择器。
		wp_enqueue_style( 'wp-color-picker' );

		wp_enqueue_style(
			'jip-admin',
			JIP_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			defined( 'JLWA_VERSION' ) ? JLWA_VERSION : JIP_VERSION
		);

		wp_enqueue_script(
			'jip-admin',
			JIP_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery', 'wp-color-picker' ),
			defined( 'JLWA_VERSION' ) ? JLWA_VERSION : JIP_VERSION,
			true
		);

		wp_localize_script(
			'jip-admin',
			'JIP_ADMIN',
			array(
				'mediaTitle'  => '选择 Logo 图片',
				'mediaButton' => '使用此图片',
				'defaultLogo' => JIP_PLUGIN_URL . 'assets/images/default-logo.svg',
			)
		);
	}

	/**
	 * 生成管理员强制预览地址。
	 *
	 * @return string
	 */
	private function force_preview_url() {
		return add_query_arg(
			array(
				'jip_force_preview' => '1',
				'_jip_nonce'       => wp_create_nonce( 'jip_force_preview' ),
			),
			home_url( '/' )
		);
	}

	/**
	 * 渲染设置页面。
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$options = JIP_Settings::get_options();
		$effects = JIP_Settings::get_effects();
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'basic'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tabs    = array(
			'basic'    => '基础设置',
			'effects'  => '效果选择',
			'logo'     => 'Logo 设置',
			'advanced' => '高级设置',
		);
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'basic';
		}
		?>
		<div class="wrap jip-wrap">
			<div class="jiuliu-admin-header">
				<div>
					<h1><span class="dashicons dashicons-admin-customizer"></span>九流沉浸式预加载</h1>
					<p class="jiuliu-admin-subtitle">为 WordPress 网站提供炫酷的沉浸式预加载效果，实现丝滑的开门式进入体验。</p>
				</div>
				
			</div>

			<h2 class="nav-tab-wrapper jip-tabs">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . JIP_MENU_SLUG . '&tab=' . $key ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</h2>

			<form method="post" action="options.php" class="jip-form">
				<?php settings_fields( 'jip_settings_group' ); ?>

				<div class="jip-tab-panel <?php echo 'basic' === $tab ? 'is-active' : ''; ?>">
				<!-- 1. 基础设置 -->
				<div class="jip-card">
					<h2 class="jip-card-title">基础设置</h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">启用沉浸式预加载</th>
							<td>
								<label class="jip-radio">
									<input type="radio" name="<?php echo esc_attr( JIP_OPTION_KEY ); ?>[enabled]" value="1" <?php checked( 1, (int) $options['enabled'] ); ?> />
									<span>启用</span>
								</label>
								<label class="jip-radio">
									<input type="radio" name="<?php echo esc_attr( JIP_OPTION_KEY ); ?>[enabled]" value="0" <?php checked( 0, (int) $options['enabled'] ); ?> />
									<span>禁用</span>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="jip_min_duration">最小展示时长</label></th>
							<td>
								<input type="number" id="jip_min_duration" name="<?php echo esc_attr( JIP_OPTION_KEY ); ?>[min_duration]" value="<?php echo esc_attr( $options['min_duration'] ); ?>" min="0" step="0.1" class="small-text" /> 秒
								<p class="description">页面就绪后仍至少展示这么长时间；设为 0 表示优先立即退出。非 0 时会从真实首帧开始计算，并给有入场动画的效果一段有限展示时间（排版/幕布约 0.7 秒），不会从网络请求开始就提前算完。</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="jip_max_duration">最大等待时长</label></th>
							<td>
								<input type="number" id="jip_max_duration" name="<?php echo esc_attr( JIP_OPTION_KEY ); ?>[max_duration]" value="<?php echo esc_attr( $options['max_duration'] ); ?>" min="0.1" step="0.1" class="small-text" /> 秒
								<p class="description">这是<strong>防止无限遮挡的安全上限</strong>，不是“到秒强制剪断特效”。页面就绪后等待动效实际首帧及简短入场再退出；极慢网络最多额外容许 0.65 秒入场缓冲，超时仍自动放行。推荐 1.5～2 秒。</p>
							</td>
						</tr>

						<tr>
							<th scope="row">结束时机</th>
							<td><select name="<?php echo esc_attr( JIP_OPTION_KEY ); ?>[completion]"><option value="dom" <?php selected( 'dom', $options['completion'] ); ?>>DOM 可用后结束（最快）</option><option value="paint" <?php selected( 'paint', $options['completion'] ); ?>>首屏完成两帧绘制后结束</option><option value="critical" <?php selected( 'critical', $options['completion'] ); ?>>首屏关键资源就绪后结束（推荐）</option><option value="load" <?php selected( 'load', $options['completion'] ); ?>>等待全站全部资源（最慢，不推荐）</option></select><p class="description">“首屏关键资源”只等待当前视口内少量图片与字体，并受最大等待时长硬限制；不会等待页面底部图片、广告或第三方统计脚本。</p></td>
						</tr>
					</table>
				</div>
				</div>

				<div class="jip-tab-panel <?php echo 'effects' === $tab ? 'is-active' : ''; ?>">
				<!-- 2. 效果选择 -->
				<div class="jip-card">
					<h2 class="jip-card-title">效果选择</h2>
					<p class="description">卡片内图片是<strong>静态示意</strong>，不是动画截图。点击每张卡的“实时预览”可在真实首页直接查看对应开屏，<strong>不用先保存设置</strong>；预览会明确标注演示模式并延长展示以便观察，生产站点仍按真实就绪状态结束。</p>
					<div class="jip-effects-grid">
						<?php foreach ( $effects as $key => $info ) : ?>
							<label class="jip-effect-card <?php echo ( $options['effect'] === $key ) ? 'is-active' : ''; ?>" data-effect="<?php echo esc_attr( $key ); ?>">
								<input type="radio" name="<?php echo esc_attr( JIP_OPTION_KEY ); ?>[effect]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $options['effect'], $key ); ?> />
								<span class="jip-effect-preview jip-effect-preview--<?php echo esc_attr( $key ); ?>">
									<img src="<?php echo esc_url( JIP_PLUGIN_URL . $info['preview'] ); ?>" alt="<?php echo esc_attr( $info['label'] ); ?>" width="120" height="80" data-effect="<?php echo esc_attr( $key ); ?>" />
								</span>
								<span class="jip-effect-label"><?php echo esc_html( $info['label'] ); ?></span>
								<span class="jip-effect-desc"><?php echo esc_html( $info['description'] ); ?></span>
								<button type="button" class="button button-secondary jip-preview-effect" data-preview-url="<?php echo esc_url( add_query_arg( 'jip_effect_preview', $key, $this->force_preview_url() ) ); ?>" aria-label="实时预览：<?php echo esc_attr( $info['label'] ); ?>">▶ 实时预览</button>
							</label>
						<?php endforeach; ?>
					</div>
				</div>
				</div>
			<div class="jip-live-preview-modal" id="jip-live-preview-modal" hidden role="dialog" aria-modal="true" aria-label="开屏动态预览">
				<div class="jip-live-preview-head"><div><strong>开屏实时预览</strong><small>实际首页渲染 · 演示时长约 2.3 秒，正式使用按页面就绪结束</small></div><button type="button" class="button jip-live-preview-close" aria-label="关闭预览">关闭预览 ✕</button></div>
				<iframe title="真实浏览器开屏动态预览" loading="lazy" data-preview-frame="1" referrerpolicy="same-origin"></iframe>
			</div>

				<div class="jip-tab-panel <?php echo 'logo' === $tab ? 'is-active' : ''; ?>">
				<!-- 3. 自定义 Logo 设置（仅核心效果显示） -->
				<div class="jip-card jip-logo-card">
					<h2 class="jip-card-title">自定义 Logo 设置</h2>
					<p class="description">所有使用 Logo 的开屏效果都可以使用此图片；渐变文字等无 Logo 动画不显示图片。</p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">中心 Logo 图</th>
							<td>
								<div class="jip-logo-uploader">
									<div class="jip-logo-preview">
										<?php
										$logo_url = ! empty( $options['logo_url'] ) ? $options['logo_url'] : ( JIP_PLUGIN_URL . 'assets/images/default-logo.svg' );
										?>
										<img id="jip_logo_preview_img" src="<?php echo esc_url( $logo_url ); ?>" alt="Logo 预览" />
									</div>
									<div class="jip-logo-actions">
										<input type="hidden" id="jip_logo_id" name="<?php echo esc_attr( JIP_OPTION_KEY ); ?>[logo_id]" value="<?php echo esc_attr( $options['logo_id'] ); ?>" />
										<input type="hidden" id="jip_logo_url" name="<?php echo esc_attr( JIP_OPTION_KEY ); ?>[logo_url]" value="<?php echo esc_attr( $options['logo_url'] ); ?>" />
										<button type="button" class="button button-primary" id="jip_upload_logo">上传 / 选择 Logo</button>
										<button type="button" class="button" id="jip_reset_logo">恢复默认</button>
										<p class="description">支持 PNG / JPG / WebP，推荐 512×512px 透明背景。未上传时使用内置默认 Logo。</p>
									</div>
								</div>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="jip_logo_size">Logo 显示尺寸</label></th>
							<td>
								<input type="number" id="jip_logo_size" name="<?php echo esc_attr( JIP_OPTION_KEY ); ?>[logo_size]" value="<?php echo esc_attr( $options['logo_size'] ); ?>" min="30" max="100" step="1" class="small-text" /> %
								<p class="description">相对于边框内部的占比（30%-100%）。</p>
							</td>
						</tr>
					</table>
				</div>
				</div>

				<div class="jip-tab-panel <?php echo 'advanced' === $tab ? 'is-active' : ''; ?>">
				<!-- 4. 高级设置 -->
				<div class="jip-card">
					<h2 class="jip-card-title">高级设置</h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="jip_bg_color">预加载背景色</label></th>
							<td>
								<input type="text" id="jip_bg_color" class="jip-color-field" name="<?php echo esc_attr( JIP_OPTION_KEY ); ?>[bg_color]" value="<?php echo esc_attr( $options['bg_color'] ); ?>" data-default-color="#000000" />
							</td>
						</tr>
						<tr>
							<th scope="row">显示站点名称</th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( JIP_OPTION_KEY ); ?>[show_site_title]" value="1" <?php checked( 1, (int) $options['show_site_title'] ); ?> />
									在动画中显示 WordPress 站点名称
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row">允许点击跳过</th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( JIP_OPTION_KEY ); ?>[allow_skip]" value="1" <?php checked( 1, (int) $options['allow_skip'] ); ?> />
									用户点击任意位置可立即结束预加载
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row">仅在首页显示</th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( JIP_OPTION_KEY ); ?>[home_only]" value="1" <?php checked( 1, (int) $options['home_only'] ); ?> />
									只在网站首页显示预加载，其他页面不显示
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row">会话内只显示一次</th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( JIP_OPTION_KEY ); ?>[once_per_session]" value="1" <?php checked( 1, (int) $options['once_per_session'] ); ?> />
									同一浏览器标签首次打开首页才显示，之后在该标签内访问首页不再显示（关闭标签后下次重新打开会再次显示）
								</label>
								<p class="description">底层使用 <code>sessionStorage</code> 实现，标签关闭后自动清除。如需"每次访问首页都显示"，请取消勾选。</p>
							</td>
						</tr>
						<tr><th scope="row">移动端显示</th><td><label><input type="checkbox" name="<?php echo esc_attr( JIP_OPTION_KEY ); ?>[mobile_enable]" value="1" <?php checked( 1, (int) $options['mobile_enable'] ); ?>> 手机上也显示；关闭后由浏览器端判断，兼容页面缓存</label></td></tr>
						<tr><th scope="row">无障碍与省流量</th><td><label><input type="checkbox" name="<?php echo esc_attr( JIP_OPTION_KEY ); ?>[reduce_motion]" value="1" <?php checked( 1, (int) $options['reduce_motion'] ); ?>> 系统要求减少动态效果时自动简化/跳过</label><br><label><input type="checkbox" name="<?php echo esc_attr( JIP_OPTION_KEY ); ?>[skip_save_data]" value="1" <?php checked( 1, (int) $options['skip_save_data'] ); ?>> 浏览器开启省流量模式时跳过</label></td></tr>
						<tr><th scope="row">进度提示</th><td><label><input type="checkbox" name="<?php echo esc_attr( JIP_OPTION_KEY ); ?>[show_progress]" value="1" <?php checked( 1, (int) $options['show_progress'] ); ?>> 显示不确定进度提示（不显示虚假百分比，真实就绪后退出）</label><p><input type="text" class="regular-text" name="<?php echo esc_attr( JIP_OPTION_KEY ); ?>[status_text]" value="<?php echo esc_attr( $options['status_text'] ); ?>" placeholder="正在准备页面…"></p></td></tr>

					</table>
				</div>
				</div>

				<div class="jip-actions">
					<?php submit_button( '保存设置', 'primary', 'submit', false ); ?>
					<a class="button button-secondary" href="<?php echo esc_url( $this->force_preview_url() ); ?>" target="_blank" rel="noopener">强制预览一次</a>
					<span class="description">强制预览不受“会话内一次”、减少动态和省流量设置影响。</span>
				</div>
			</form>

			<div class="jip-footer">
				<p>作者：<a href="https://www.jiuliu.org" target="_blank" rel="noopener">九流</a> · 许可证：GPLv2+</p>
			</div>
		</div>
		<?php
	}
}
