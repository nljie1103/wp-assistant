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
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * 注册后台菜单。
	 *
	 * 期望位置：外观（60）和插件（65）之间，使用 61.5 让它落在中间。
	 */
	public function register_menu() {
		if ( defined( 'JLWA_MENU_SLUG' ) ) {
			add_submenu_page(
				JLWA_MENU_SLUG,
				'九流沉浸式预加载',
				'沉浸式预加载',
				'manage_options',
				JIP_MENU_SLUG,
				array( $this, 'render_settings_page' )
			);
			return;
		}

		add_menu_page(
			'九流沉浸式预加载',
			'沉浸式预加载',
			'manage_options',
			JIP_MENU_SLUG,
			array( $this, 'render_settings_page' ),
			'dashicons-admin-customizer',
			61.5
		);
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
			JIP_VERSION
		);

		wp_enqueue_script(
			'jip-admin',
			JIP_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery', 'wp-color-picker' ),
			JIP_VERSION,
			true
		);

		wp_localize_script(
			'jip-admin',
			'JIP_ADMIN',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'jip_admin_nonce' ),
				'mediaTitle'  => '选择 Logo 图片',
				'mediaButton' => '使用此图片',
				'defaultLogo' => JIP_PLUGIN_URL . 'assets/images/default-logo.svg',
			)
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
		?>
		<div class="wrap jip-wrap">
			<div class="jiuliu-admin-header">
				<div>
					<h1><span class="dashicons dashicons-admin-customizer"></span>九流沉浸式预加载</h1>
					<p class="jiuliu-admin-subtitle">为 WordPress 网站提供炫酷的沉浸式预加载效果，实现丝滑的开门式进入体验。</p>
				</div>
				<span class="jiuliu-version-badge">v<?php echo esc_html( JIP_VERSION ); ?></span>
			</div>

			<form method="post" action="options.php" class="jip-form">
				<?php settings_fields( 'jip_settings_group' ); ?>

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
								<p class="description">即使页面加载完成，也至少让动画播放这么长时间。推荐值：1 秒（可填任意正数）。</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="jip_max_duration">最大等待时长</label></th>
							<td>
								<input type="number" id="jip_max_duration" name="<?php echo esc_attr( JIP_OPTION_KEY ); ?>[max_duration]" value="<?php echo esc_attr( $options['max_duration'] ); ?>" min="0.1" step="0.1" class="small-text" /> 秒
								<p class="description">超过这个时间页面还没加载完，自动结束预加载。推荐值：8 秒（可填任意正数，0 以下无效）。</p>
							</td>
						</tr>
					</table>
				</div>

				<!-- 2. 效果选择 -->
				<div class="jip-card">
					<h2 class="jip-card-title">效果选择</h2>
					<p class="description">选择您喜欢的预加载效果。第一个为推荐核心效果。</p>
					<div class="jip-effects-grid">
						<?php foreach ( $effects as $key => $info ) : ?>
							<label class="jip-effect-card <?php echo ( $options['effect'] === $key ) ? 'is-active' : ''; ?>" data-effect="<?php echo esc_attr( $key ); ?>">
								<input type="radio" name="<?php echo esc_attr( JIP_OPTION_KEY ); ?>[effect]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $options['effect'], $key ); ?> />
								<span class="jip-effect-preview">
									<img src="<?php echo esc_url( JIP_PLUGIN_URL . $info['preview'] ); ?>" alt="<?php echo esc_attr( $info['label'] ); ?>" width="120" height="80" />
								</span>
								<span class="jip-effect-label"><?php echo esc_html( $info['label'] ); ?></span>
								<span class="jip-effect-desc"><?php echo esc_html( $info['description'] ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				</div>

				<!-- 3. 自定义 Logo 设置（仅核心效果显示） -->
				<div class="jip-card jip-logo-card" data-show-when-effect="logo3d" style="<?php echo ( 'logo3d' === $options['effect'] ) ? '' : 'display:none;'; ?>">
					<h2 class="jip-card-title">自定义 Logo 设置</h2>
					<p class="description">仅核心效果（立体 Logo 开场）使用此 Logo。</p>
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
					</table>
				</div>

				<?php submit_button( '保存设置' ); ?>
			</form>

			<div class="jip-card jip-update-card">
				<h2 class="jip-card-title">在线更新</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">当前版本</th>
						<td><strong>v<?php echo esc_html( JIP_VERSION ); ?></strong></td>
					</tr>
					<tr>
						<th scope="row">远程仓库</th>
						<td>
							<a href="https://github.com/nljie1103/wp-immersive-preloader" target="_blank" rel="noopener">github.com/nljie1103/wp-immersive-preloader</a>
						</td>
					</tr>
					<tr>
						<th scope="row">检查更新</th>
						<td>
							<button type="button" class="button button-secondary" id="jip-check-update">
								<span class="dashicons dashicons-update"></span>
								立即检查更新
							</button>
							<button type="button" class="button" id="jip-do-update" disabled>
								<span class="dashicons dashicons-download"></span>
								一键在线更新
							</button>
							<div id="jip-update-status" class="jip-update-status">点击“立即检查更新”来对比本地与远程版本。</div>
						</td>
					</tr>
					<tr>
						<th scope="row">变更日志（最新）</th>
						<td>
							<pre id="jip-changelog" class="jip-changelog">（暂未获取，请先点击“立即检查更新”）</pre>
						</td>
					</tr>
					<tr>
						<th scope="row">数据保留说明</th>
						<td>
							<div class="jip-safety-note">
								<strong>设置会保留。</strong><br>
								插件设置存储在 WordPress 数据库的 <code>jiuliu_immersive_preloader_options</code> 中；在线更新只覆盖插件目录下的代码文件，不会删除数据库设置。更新前还会自动快照到 <code>jip_settings_backup</code>。
							</div>
						</td>
					</tr>
				</table>
			</div>

			<div class="jip-footer">
				<p>作者：<a href="https://www.jiuliu.org" target="_blank" rel="noopener">九流</a> · 许可证：GPLv2+ · 版本：<?php echo esc_html( JIP_VERSION ); ?></p>
			</div>
		</div>
		<?php
	}
}
