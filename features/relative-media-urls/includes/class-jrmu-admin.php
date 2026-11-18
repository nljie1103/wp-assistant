<?php
/**
 * 后台管理类。
 *
 * @package JiuliuRelativeMediaUrls
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * JRMU_Admin
 */
class JRMU_Admin {
	/** @var JRMU_Admin|null */
	private static $instance = null;

	/** 获取单例。 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** 构造。 */
	private function __construct() {
		$this->init_hooks();
	}

	/** Hooks。 */
	private function init_hooks() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_jrmu_apply_post_action', array( $this, 'handle_apply_post_action' ) );
		add_action( 'admin_post_jrmu_clear_logs', array( $this, 'handle_clear_logs' ) );
	}

	/** 注册设置。 */
	public function register_settings() {
		register_setting(
			'jrmu_settings_group',
			JRMU_OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'JRMU_Settings', 'sanitize' ),
				'default'           => JRMU_Settings::get_defaults(),
			)
		);
	}

	/** 资源。 */
	public function enqueue_assets( $hook ) {
		$allowed_hooks = array(
			'toplevel_page_' . JRMU_MENU_SLUG,
			defined( 'JLWA_MENU_SLUG' ) ? JLWA_MENU_SLUG . '_page_' . JRMU_MENU_SLUG : '',
		);
		if ( ! in_array( $hook, $allowed_hooks, true ) ) {
			return;
		}
		wp_enqueue_style( 'jrmu-admin', JRMU_PLUGIN_URL . 'assets/css/admin.css', array(), defined( 'JLWA_VERSION' ) ? JLWA_VERSION : JRMU_VERSION );
		wp_enqueue_script( 'jrmu-admin', JRMU_PLUGIN_URL . 'assets/js/admin.js', array(), defined( 'JLWA_VERSION' ) ? JLWA_VERSION : JRMU_VERSION, true );
	}

	/** 渲染页面。 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tab = isset( $_GET['tab'] ) && is_scalar( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'media';
		if ( 'settings' === $tab || 'help' === $tab ) { $tab = 'media'; }
		$allowed_tabs = array( 'media', 'content', 'domains', 'seo', 'scope', 'scanner', 'diagnostics', 'nginx', 'logs' );
		if ( ! in_array( $tab, $allowed_tabs, true ) ) {
			$tab = 'media';
		}
		?>
		<div class="wrap jrmu-wrap">
			<div class="jiuliu-admin-header">
				<div>
					<h1><span class="dashicons dashicons-admin-customizer"></span>九流媒体相对地址</h1>
					<p class="jiuliu-admin-subtitle">WordPress 反向代理与多域名链接助手。默认不启用任何转换；所有永久修改都必须先扫描、预览、勾选并确认。</p>
				</div>
				
			</div>
			<?php $this->render_notices(); ?>
			<?php $this->render_tabs( $tab ); ?>
			<div class="jrmu-panel">
				<?php
				switch ( $tab ) {
					case 'scanner':
						$this->render_scanner_tab();
						break;
					case 'diagnostics':
						$this->render_diagnostics_tab();
						break;
					case 'nginx':
						$this->render_nginx_tab();
						break;
					case 'logs':
						$this->render_logs_tab();
						break;
					default:
						$this->render_settings_tab( $tab );
				}
				?>
			</div>
		</div>
		<?php
	}

	/** Tabs。 */
	private function render_tabs( $active ) {
		$tabs = array(
			'media'       => '媒体库地址',
			'content'     => '文章内容',
			'domains'     => '多域名适配',
			'seo'         => 'SEO Canonical',
			'scope'       => '转换范围',
			'scanner'     => '扫描 / 预览 / 修复',
			'diagnostics' => '反代与缓存检测',
			'nginx'       => 'Nginx 可选配置',
			'logs'        => '转换日志',
		);
		echo '<h2 class="nav-tab-wrapper jrmu-tabs">';
		foreach ( $tabs as $key => $label ) {
			$url = admin_url( 'admin.php?page=' . JRMU_MENU_SLUG . '&tab=' . $key );
			echo '<a class="nav-tab ' . esc_attr( $active === $key ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</h2>';
	}

	/** Notices。 */
	private function render_notices() {
		$notice = isset( $_GET['jrmu_notice'] ) ? sanitize_key( wp_unslash( $_GET['jrmu_notice'] ) ) : '';
		if ( 'done' === $notice ) {
			$requested = isset( $_GET['requested'] ) ? absint( $_GET['requested'] ) : 0;
			$updated   = isset( $_GET['updated'] ) ? absint( $_GET['updated'] ) : 0;
			$skipped   = isset( $_GET['skipped'] ) ? absint( $_GET['skipped'] ) : max( 0, $requested - $updated );
			echo '<div class="notice notice-success is-dismissible"><p>操作完成：选择 ' . esc_html( $requested ) . ' 篇，实际更新 ' . esc_html( $updated ) . ' 篇，跳过 ' . esc_html( $skipped ) . ' 篇。已在日志中记录，支持修订版本的文章会在更新前创建修订。</p></div>';
		} elseif ( 'security_failed' === $notice ) {
			echo '<div class="notice notice-error is-dismissible"><p>安全验证失败，请刷新页面后重试。</p></div>';
		} elseif ( 'no_posts' === $notice ) {
			echo '<div class="notice notice-info is-dismissible"><p>没有选择任何文章，没有执行修改。</p></div>';
		} elseif ( 'invalid_action' === $notice ) {
			echo '<div class="notice notice-error is-dismissible"><p>无效操作，没有执行修改。</p></div>';
		} elseif ( 'logs_cleared' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>日志已清空。</p></div>';
		}
	}

	/** 设置页。 */
	private function render_settings_tab( $tab = 'media' ) {
		$options = JRMU_Settings::get_options();
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'jrmu_settings_group' ); ?>
			<input type="hidden" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[jrmu_settings_scope]" value="<?php echo esc_attr( $tab ); ?>">
			<div class="jrmu-grid">
				<div class="jrmu-main-card">
					<?php if ( 'media' === $tab ) : ?>
					<h2>一、媒体库地址</h2>
					<table class="form-table" role="presentation">
						<tr><th>已上传媒体</th><td><label><input type="checkbox" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[convert_existing_media_output]" value="1" <?php checked( $options['convert_existing_media_output'], 1 ); ?>> 转换已经上传媒体的输出地址</label><p class="description">影响媒体库、编辑器、附件 URL API、特色图、srcset 的输出，不移动文件，不改核心。关闭即可恢复输出。</p></td></tr>
						<tr><th>未来上传媒体</th><td><label><input type="checkbox" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[convert_future_media_output]" value="1" <?php checked( $options['convert_future_media_output'], 1 ); ?>> 从开启后，新上传媒体默认相对地址输出</label><p class="description">只影响开启之后的新附件。插件会给新附件打标记。</p></td></tr>
					</table>

					<?php endif; ?>
					<?php if ( 'content' === $tab ) : ?>
					<h2>二、文章内容地址</h2>
					<table class="form-table" role="presentation">
						<tr><th>未来保存文章</th><td><label><input type="checkbox" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[convert_post_on_save]" value="1" <?php checked( $options['convert_post_on_save'], 1 ); ?>> 保存文章/页面时自动转换内容中的媒体地址</label><p class="description">会永久保存到文章正文/摘要，只影响开启后保存的内容。</p></td></tr>
						<tr><th>前台临时输出</th><td><label><input type="checkbox" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[convert_post_on_frontend]" value="1" <?php checked( $options['convert_post_on_frontend'], 1 ); ?>> 前台显示文章时临时转换旧内容</label><p class="description">不修改数据库，适合先测试旧文章图片是否走反代。</p></td></tr>
					</table>

					<?php endif; ?>
					<?php if ( 'domains' === $tab ) : ?>
					<h2>三、多域名访问适配</h2>
					<table class="form-table" role="presentation">
						<tr><th>总开关</th><td><label><input type="checkbox" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[domain_adaptation_enabled]" value="1" <?php checked( $options['domain_adaptation_enabled'], 1 ); ?>> 启用选定前台链接与静态资源的白名单适配</label><p class="description">默认不修改 WordPress 的 home / siteurl，也不会改写子比登录、支付和 AJAX 等敏感请求；旧版动态站点身份可在下方高级选项单独选择。</p></td></tr>
						<tr><th>静态资源同源改写<br><small>可选 · 默认关闭</small></th><td><label><input type="checkbox" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[domain_asset_rewrite_enabled]" value="1" <?php checked( $options['domain_asset_rewrite_enabled'], 1 ); ?>> 让主题 CSS、JS、字体及插件静态资源跟随当前白名单入口域名</label><p class="description"><strong>高级兼容功能：</strong>可用于解决 Font Awesome 跨域字体显示方块，但会改变资源请求来源，可能影响 CDN 缓存、CSP 或主题依赖。先确认每个入口均能提供相同资源再开启；关闭即可恢复原有资源地址，不改数据库或主题文件。</p></td></tr>
						<tr><th>允许域名</th><td><textarea name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[domain_allowed_hosts]" rows="5" class="large-text code" placeholder="blog.jiuliu.org&#10;hk-blog.jiuliu.org&#10;origin-blog.jiuliu.org"><?php echo esc_textarea( $options['domain_allowed_hosts'] ); ?></textarea><p class="description">白名单模式下，只有这里列出的域名才会触发动态链接。</p></td></tr>
						<tr><th>适配模式</th><td><input type="hidden" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[domain_mode]" value="whitelist"><strong>仅白名单域名</strong><p class="description">为防止伪造 Host 请求头污染站点 URL，4.2.0 起不再提供“任意 Host”模式。数据库中的原始 home/siteurl 会自动加入可信列表。</p></td></tr>
						<tr><th>协议</th><td><label><input type="radio" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[domain_scheme]" value="https" <?php checked( $options['domain_scheme'], 'https' ); ?>> 强制 HTTPS</label><br><label><input type="radio" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[domain_scheme]" value="auto" <?php checked( $options['domain_scheme'], 'auto' ); ?>> 自动识别 HTTPS / X-Forwarded-Proto</label><br><label><input type="radio" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[domain_scheme]" value="http" <?php checked( $options['domain_scheme'], 'http' ); ?>> 强制 HTTP</label></td></tr>
						<tr><th>前台兜底改写</th><td><label><input type="checkbox" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[domain_rewrite_frontend_links]" value="1" <?php checked( $options['domain_rewrite_frontend_links'], 1 ); ?>> 前台 HTML 兜底替换站内绝对链接</label><br><label><input type="radio" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[domain_rewrite_mode]" value="current_full" <?php checked( $options['domain_rewrite_mode'], 'current_full' ); ?>> 替换为当前域名完整 URL</label><br><label><input type="radio" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[domain_rewrite_mode]" value="root_relative" <?php checked( $options['domain_rewrite_mode'], 'root_relative' ); ?>> 替换为根相对路径</label></td></tr>
						<tr><th>旧版动态站点身份<br><small>高风险 · 默认关闭</small></th><td>
                            <label><input type="checkbox" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[domain_legacy_identity_override]" value="1" <?php checked( $options['domain_legacy_identity_override'], 1 ); ?>> <strong>我了解风险，仍要允许前台动态修改 WordPress home</strong></label>
                            <p class="description"><strong>子比授权风险：</strong>会影响主题和插件通过 get_option('home') 获得的站点域名，可能导致域名授权状态异常。普通多域名资源改写不需要开启此项；从旧版本升级后即使原设置里“动态 siteurl”已开启，此项也默认保持关闭。</p>
                            <label><input type="checkbox" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[domain_dynamic_siteurl]" value="1" <?php checked( $options['domain_dynamic_siteurl'], 1 ); ?>> 开启上面旧版模式时，同时允许动态修改 siteurl</label>
                            <p class="description">未开启第一个选项时，本项不生效。若开启，会使域名授权、登录回调与资源地址受到更广泛影响；请仅在独立测试环境验证后使用。关闭第一个选项即可恢复原始身份。</p>
                        </td></tr>
                        <tr><th>系统安全边界</th><td><strong>后台、AJAX、REST、子比登录 /user-sign、订单及支付接口始终排除</strong>；旧版允许关闭的敏感请求排除规则不再适用。<p class="description">即使启用高风险动态站点身份，也只对受信任白名单入口的非敏感前台请求生效。</p><label><input type="checkbox" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[domain_skip_system_endpoints]" value="1" <?php checked( $options['domain_skip_system_endpoints'], 1 ); ?>> 额外排除 RSS、Sitemap、XML 等普通系统输出</label></td></tr>
					</table>

					<?php endif; ?>
					<?php if ( 'seo' === $tab ) : ?>
					<h2>四、SEO Canonical 主域名</h2>
					<table class="form-table" role="presentation">
						<tr><th>Canonical</th><td><label><input type="checkbox" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[canonical_enabled]" value="1" <?php checked( $options['canonical_enabled'], 1 ); ?>> Canonical 统一使用主域名</label><p class="description">多入口访问时，用户点击可跟随当前域名，但 SEO 规范地址可以统一到主域名。</p></td></tr>
						<tr><th>主域名</th><td><input type="text" class="regular-text" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[canonical_primary_host]" value="<?php echo esc_attr( $options['canonical_primary_host'] ); ?>" placeholder="blog.jiuliu.org"> <label><input type="radio" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[canonical_scheme]" value="https" <?php checked( $options['canonical_scheme'], 'https' ); ?>> https</label> <label><input type="radio" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[canonical_scheme]" value="http" <?php checked( $options['canonical_scheme'], 'http' ); ?>> http</label></td></tr>
					</table>

					<?php endif; ?>
					<?php if ( 'scope' === $tab ) : ?>
					<h2>五、转换范围与扫描</h2>
					<table class="form-table" role="presentation">
						<tr><th>路径范围</th><td><label><input type="checkbox" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[target_uploads]" value="1" <?php checked( $options['target_uploads'], 1 ); ?>> /wp-content/uploads/</label><br><label><input type="checkbox" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[target_themes]" value="1" <?php checked( $options['target_themes'], 1 ); ?>> /wp-content/themes/</label><br><label><input type="checkbox" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[target_plugins]" value="1" <?php checked( $options['target_plugins'], 1 ); ?>> /wp-content/plugins/</label></td></tr>
						<tr><th>额外源站域名</th><td><textarea name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[extra_hosts]" rows="5" class="large-text code" placeholder="origin-blog.jiuliu.org&#10;旧域名.com"><?php echo esc_textarea( $options['extra_hosts'] ); ?></textarea><p class="description">用于识别旧源站链接、源站暴露和可转换媒体地址。</p></td></tr>
						<tr><th>扫描限制</th><td><input type="number" min="10" max="1000" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[scan_limit]" value="<?php echo esc_attr( $options['scan_limit'] ); ?>"> <label><input type="checkbox" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[scan_postmeta]" value="1" <?php checked( $options['scan_postmeta'], 1 ); ?>> 扫描 postmeta，只读提示，不自动修改</label></td></tr>
					</table>
					<?php endif; ?>
					<?php submit_button( '保存当前分类设置' ); ?>
				</div>
				<div class="jrmu-side-card">
					<h2>当前状态</h2>
					<?php $this->render_status_list( $options ); ?>
					<h2>识别范围</h2>
					<p><strong>允许媒体域名：</strong></p><?php $this->render_code_list( JRMU_Converter::instance()->get_allowed_hosts() ); ?>
					<p><strong>允许路径：</strong></p><?php $this->render_code_list( JRMU_Converter::instance()->get_allowed_paths() ); ?>
					<p><strong>可改写站内来源域名：</strong></p><?php $this->render_code_list( JRMU_Domain_Adapter::instance()->get_rewrite_source_hosts() ); ?>
				</div>
			</div>
		</form>
		<?php
	}

	/** 状态列表。 */
	private function render_status_list( $options ) {
		$items = array(
			'已上传媒体输出转换' => ! empty( $options['convert_existing_media_output'] ),
			'未来上传媒体相对输出' => ! empty( $options['convert_future_media_output'] ),
			'保存文章时转换' => ! empty( $options['convert_post_on_save'] ),
			'前台文章临时转换' => ! empty( $options['convert_post_on_frontend'] ),
			'多域名访问适配' => ! empty( $options['domain_adaptation_enabled'] ),
			'主题 CSS/JS 与图标字体同源适配' => ! empty( $options['domain_adaptation_enabled'] ) && ! empty( $options['domain_asset_rewrite_enabled'] ),
			'Canonical 主域名' => ! empty( $options['canonical_enabled'] ),
		);
		echo '<ul class="jrmu-status-list">';
		foreach ( $items as $label => $enabled ) {
			echo '<li><span class="jrmu-badge ' . esc_attr( $enabled ? 'is-on' : 'is-off' ) . '">' . esc_html( $enabled ? '开启' : '关闭' ) . '</span> ' . esc_html( $label ) . '</li>';
		}
		echo '</ul>';
		if ( ! array_filter( $items ) ) {
			echo '<p class="jrmu-warning">当前没有开启任何转换。插件已安装，但不会改变媒体库、前台输出或数据库内容。</p>';
		}
	}

	/** code list。 */
	private function render_code_list( $items ) {
		echo '<ul class="jrmu-list">';
		if ( empty( $items ) ) {
			echo '<li><em>空</em></li>';
		} else {
			foreach ( $items as $item ) {
				echo '<li><code>' . esc_html( $item ) . '</code></li>';
			}
		}
		echo '</ul>';
	}

	/** 扫描页。 */
	private function render_scanner_tab() {
		$options      = JRMU_Settings::get_options();
		$should_scan  = ! empty( $_GET['jrmu_scan'] );
		$post_type    = isset( $_GET['jrmu_post_type'] ) ? sanitize_key( wp_unslash( $_GET['jrmu_post_type'] ) ) : 'any';
		$post_status  = isset( $_GET['jrmu_post_status'] ) ? sanitize_key( wp_unslash( $_GET['jrmu_post_status'] ) ) : 'publish';
		$keyword      = isset( $_GET['jrmu_keyword'] ) ? sanitize_text_field( wp_unslash( $_GET['jrmu_keyword'] ) ) : '';
		$target_base  = isset( $_GET['jrmu_target_base'] ) ? JRMU_Settings::sanitize_rewrite_target( wp_unslash( $_GET['jrmu_target_base'] ) ) : home_url();
		$preview_action = isset( $_GET['jrmu_preview_action'] ) ? sanitize_key( wp_unslash( $_GET['jrmu_preview_action'] ) ) : JRMU_Scanner::ACTION_CONVERT_MEDIA_RELATIVE;
		if ( ! JRMU_Scanner::is_valid_action( $preview_action ) ) {
			$preview_action = JRMU_Scanner::ACTION_CONVERT_MEDIA_RELATIVE;
		}
		$results = $should_scan ? JRMU_Scanner::instance()->scan_posts( array( 'post_type' => $post_type, 'post_status' => $post_status, 'keyword' => $keyword, 'target_base' => $target_base ) ) : array();
		$actions = JRMU_Scanner::get_actions();
		?>
		<h2>扫描 / 预览 / 修复</h2>
		<div class="notice notice-warning inline"><p><strong>重要：</strong>扫描和预览不改数据库；“执行勾选文章”会永久修改勾选文章的正文/摘要。执行前请备份数据库。支持修订版本的文章会在更新前创建修订。</p></div>
		<form method="get" class="jrmu-scanner">
			<input type="hidden" name="page" value="<?php echo esc_attr( JRMU_MENU_SLUG ); ?>"><input type="hidden" name="tab" value="scanner"><input type="hidden" name="jrmu_scan" value="1">
			<p><label>类型 <select name="jrmu_post_type"><option value="any" <?php selected( $post_type, 'any' ); ?>>公开文章类型</option><option value="post" <?php selected( $post_type, 'post' ); ?>>文章</option><option value="page" <?php selected( $post_type, 'page' ); ?>>页面</option></select></label>
			<label>状态 <select name="jrmu_post_status"><option value="publish" <?php selected( $post_status, 'publish' ); ?>>已发布</option><option value="draft" <?php selected( $post_status, 'draft' ); ?>>草稿</option><option value="private" <?php selected( $post_status, 'private' ); ?>>私密</option><option value="any" <?php selected( $post_status, 'any' ); ?>>全部</option></select></label>
			<label>关键词 <input type="search" name="jrmu_keyword" value="<?php echo esc_attr( $keyword ); ?>"></label></p>
			<p><label>预览动作 <select name="jrmu_preview_action"><?php foreach ( $actions as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $preview_action, $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label></p>
			<p><label>恢复/改写目标域名 <input type="url" class="regular-text" name="jrmu_target_base" value="<?php echo esc_attr( $target_base ); ?>" placeholder="https://blog.jiuliu.org"></label> <?php submit_button( '扫描链接', 'secondary', 'submit', false ); ?></p>
		</form>
		<?php if ( $should_scan ) : ?>
			<?php if ( empty( $results ) ) : ?>
				<div class="notice notice-info inline"><p>没有发现匹配内容。</p></div>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return window.JLWA_DIALOG ? window.JLWA_DIALOG.confirmSubmit(event, this, '建议先备份数据库。本操作只会修改勾选文章的正文/摘要，并会尽量创建文章修订。确认继续吗？', { title: '确认批量修改文章', danger: true, confirmLabel: '确认修改' }) : confirm('建议先备份数据库。本操作只会修改勾选文章的正文/摘要，并会尽量创建文章修订。确认继续吗？');">
					<input type="hidden" name="action" value="jrmu_apply_post_action"><?php wp_nonce_field( 'jrmu_apply_post_action' ); ?>
					<input type="hidden" name="target_base" value="<?php echo esc_attr( $target_base ); ?>">
					<div class="jrmu-bulkbar"><select name="bulk_action"><?php foreach ( $actions as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $preview_action, $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select> <?php submit_button( '执行勾选文章', 'primary', 'submit', false ); ?></div>
					<table class="widefat striped jrmu-results-table"><thead><tr><td class="check-column"><input type="checkbox" class="jrmu-check-all"></td><th>标题</th><th>统计</th><th>当前动作 Dry Run 预览</th></tr></thead><tbody>
					<?php foreach ( $results as $row ) : ?>
					<?php $samples = isset( $row['sample_groups'][ $preview_action ] ) ? $row['sample_groups'][ $preview_action ] : array(); ?>
					<tr><th class="check-column"><input type="checkbox" class="jrmu-post-check" name="post_ids[]" value="<?php echo esc_attr( $row['id'] ); ?>"></th><td><strong><?php echo esc_html( $row['title'] ); ?></strong><br><code>ID: <?php echo esc_html( $row['id'] ); ?> / <?php echo esc_html( $row['type'] . ' / ' . $row['status'] ); ?></code><br><?php if ( $row['edit_link'] ) : ?><a target="_blank" href="<?php echo esc_url( $row['edit_link'] ); ?>">编辑</a><?php endif; ?></td><td>媒体：<?php echo esc_html( $row['media_count'] ); ?><br>可恢复：<?php echo esc_html( $row['restore_count'] ); ?><br>站内：<?php echo esc_html( $row['internal_count'] ); ?><br>HTTP：<?php echo esc_html( $row['mixed_count'] ); ?><br>源站暴露：<?php echo esc_html( $row['exposure_count'] ); ?></td><td><?php if ( empty( $samples ) ) : ?><span class="description">当前动作没有可处理样本。</span><?php else : ?><?php foreach ( array_slice( $samples, 0, 8 ) as $sample ) : ?><div class="jrmu-sample"><span class="jrmu-sample-type"><?php echo esc_html( $sample['type'] ); ?></span><br><code><?php echo esc_html( $this->shorten( $sample['from'] ) ); ?></code><br>↓<br><code><?php echo esc_html( $this->shorten( $sample['to'] ) ); ?></code></div><?php endforeach; ?><?php endif; ?></td></tr>
					<?php endforeach; ?>
					</tbody></table>
				</form>
			<?php endif; ?>
		<?php endif; ?>
		<?php if ( ! empty( $options['scan_postmeta'] ) ) : ?>
			<h3>Postmeta 只读扫描</h3><p class="description">此区域只提示可能写死 URL 的自定义字段，不自动修改，避免破坏序列化数据。</p>
			<?php $this->render_postmeta_scan(); ?>
		<?php endif; ?>
		<?php
	}

	/** postmeta 扫描。 */
	private function render_postmeta_scan() {
		$rows = JRMU_Scanner::instance()->scan_postmeta( 100 );
		if ( empty( $rows ) ) {
			echo '<p>未发现明显匹配的 postmeta。</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>文章 ID</th><th>meta_key</th><th>样本</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>' . esc_html( $row['post_id'] ) . '</td><td><code>' . esc_html( $row['meta_key'] ) . '</code></td><td><code>' . esc_html( $this->shorten( $row['sample'], 160 ) ) . '</code></td></tr>';
		}
		echo '</tbody></table>';
	}

	/** 诊断页。 */
	private function render_diagnostics_tab() {
		$env = JRMU_Diagnostics::get_environment();
		$url = isset( $_GET['jrmu_check_url'] ) ? esc_url_raw( wp_unslash( $_GET['jrmu_check_url'] ) ) : '';
		$check = null;
		if ( $url ) {
			if ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'jrmu_check_headers' ) ) {
				$check = JRMU_Diagnostics::check_url_headers( $url );
			} else {
				$check = new WP_Error( 'bad_nonce', '安全验证失败，请重新提交检测表单。' );
			}
		}
		?>
		<h2>反代环境检测</h2>
		<table class="widefat striped jrmu-env-table"><tbody>
		<?php foreach ( array( 'home_url', 'site_url', 'content_url', 'upload_baseurl', 'is_ssl', 'php_version', 'wp_version', 'permalink', 'upload_url_path' ) as $key ) : ?>
			<tr><th><?php echo esc_html( $key ); ?></th><td><code><?php echo esc_html( (string) $env[ $key ] ); ?></code></td></tr>
		<?php endforeach; ?>
		<?php foreach ( $env['server'] as $key => $value ) : ?>
			<tr><th><?php echo esc_html( $key ); ?></th><td><code><?php echo esc_html( $value ); ?></code></td></tr>
		<?php endforeach; ?>
		</tbody></table>
		<h2>缓存响应头检测</h2>
		<p class="description">为了避免后台被滥用为任意请求工具，检测 URL 仅允许当前站点、额外源站域名或多域名白名单中的域名。</p>
		<form method="get"><input type="hidden" name="page" value="<?php echo esc_attr( JRMU_MENU_SLUG ); ?>"><input type="hidden" name="tab" value="diagnostics"><?php wp_nonce_field( 'jrmu_check_headers' ); ?><input type="url" class="large-text" name="jrmu_check_url" placeholder="https://blog.jiuliu.org/wp-content/uploads/example.jpg" value="<?php echo esc_attr( $url ); ?>"><?php submit_button( '检测 URL 响应头', 'secondary', 'submit', false ); ?></form>
		<?php if ( $check ) : ?>
			<?php if ( is_wp_error( $check ) ) : ?><div class="notice notice-error inline"><p><?php echo esc_html( $check->get_error_message() ); ?></p></div><?php else : ?>
				<p>状态：<strong><?php echo esc_html( $check['code'] . ' ' . $check['message'] ); ?></strong></p>
				<table class="widefat striped"><tbody><?php foreach ( $check['headers'] as $k => $v ) : ?><tr><th><?php echo esc_html( $k ); ?></th><td><code><?php echo esc_html( $v ); ?></code></td></tr><?php endforeach; ?></tbody></table>
				<p class="description">重点看 <code>x-cache-status</code>、<code>cache-control</code>、<code>expires</code>、<code>content-type</code>。</p>
			<?php endif; ?>
		<?php endif; ?>
		<?php
	}

	/** Generate only whitelisted, safely shell-quoted root terminal commands. */
	public static function media_nginx_command( $action, $settings, $plugin_root = '' ) {
		if ( ! in_array( $action, array( 'preview', 'apply', 'rollback' ), true ) || ! is_array( $settings ) ) {
			return '';
		}
		$target = JRMU_Settings::sanitize_single_host( $settings['media_nginx_target_host'] ?? '' );
		$origin = JRMU_Settings::sanitize_origin_url( $settings['origin_url'] ?? '' );
		if ( ! $target || ! preg_match( '/^[a-z0-9.-]+$/D', $target ) || ! $origin ) {
			return '';
		}
		$origin_parts = wp_parse_url( $origin );
		if ( ! is_array( $origin_parts ) || empty( $origin_parts['host'] ) || strtolower( $origin_parts['host'] ) === $target || ! in_array( $origin_parts['scheme'] ?? '', array( 'https', 'http' ), true )
			|| ! empty( $origin_parts['query'] ) || ! empty( $origin_parts['fragment'] ) || ! in_array( $origin_parts['path'] ?? '', array( '', '/' ), true ) || ( isset( $origin_parts['port'] ) && ! in_array( (int) $origin_parts['port'], array( 80, 443 ), true ) ) || ! empty( $origin_parts['user'] ) || ! empty( $origin_parts['pass'] ) ) {
			return '';
		}
		$allowed = JRMU_Settings::sanitize_host_lines( $settings['domain_allowed_hosts'] ?? '' );
		if ( ! in_array( $target, array_filter( explode( "\n", $allowed ) ), true ) ) {
			return '';
		}
		$plugin_root = $plugin_root ? $plugin_root : ( defined( 'JLWA_PLUGIN_DIR' ) ? JLWA_PLUGIN_DIR : dirname( JRMU_PLUGIN_DIR, 2 ) . '/' );
		if ( ! is_string( $plugin_root ) || '' === $plugin_root || '/' !== $plugin_root[0] ) { return ''; }
		$script = rtrim( $plugin_root, '/' ) . '/ops/jlwa-media-nginx-helper.py';
		$command = 'sudo python3 ' . escapeshellarg( $script ) . ' ' . $action . ' --host ' . escapeshellarg( $target );
		if ( 'rollback' !== $action ) {
			$command .= ' --origin ' . escapeshellarg( $origin );
		}
		return $command;
	}

	/** Optional standalone reverse-proxy static cache (not required for URL rewriting). */
	private function render_nginx_tab() {
		$options = JRMU_Settings::get_options();
		$commands = array(
			'preview' => self::media_nginx_command( 'preview', $options ),
			'apply' => self::media_nginx_command( 'apply', $options ),
			'rollback' => self::media_nginx_command( 'rollback', $options ),
		);
		?>
		<h2>Nginx 静态缓存：可选，不影响媒体地址转换</h2>
		<p><strong>目前网站正常，就不用配置。</strong>“多域名适配”和“媒体 URL 转换”工作在 WordPress 层，Nginx 缓存只是给<strong>独立的反向代理入口</strong>优化静态 CSS、JS、图片和字体。Cloudflare 已经做静态缓存、或者多个域名直接共用同一个 WordPress 站点时，不要重复部署这套规则。</p>
		<div class="notice notice-warning inline"><p><strong>自动部署严格限制：</strong>只接受已经明确代理到指定源站、没有 PHP/复杂静态规则的独立宝塔反代 vhost；只缓存无 Cookie、无授权、无查询参数的公开静态文件。不会自动改写整个 <code>location /</code>，更不会对登录、文章、订单、私有下载或 WebDAV 开启缓存。任何不符合条件的站点都会拒绝配置。</p></div>
		<form method="post" action="options.php" class="jrmu-media-nginx-settings">
			<?php settings_fields( 'jrmu_settings_group' ); ?>
			<input type="hidden" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[jrmu_settings_scope]" value="nginx">
			<table class="form-table"><tbody>
			<tr><th>独立反代入口域名</th><td><input type="text" class="regular-text code" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[media_nginx_target_host]" value="<?php echo esc_attr( $options['media_nginx_target_host'] ); ?>" placeholder="proxy.example.com"><p class="description">先在“多域名适配”中把此域名加入白名单。该域名必须有自己独立的 Nginx 反代站点配置，不能是主 WordPress PHP 站点。</p></td></tr>
			<tr><th>实际源站 URL</th><td><input type="url" class="regular-text code" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[origin_url]" value="<?php echo esc_attr( $options['origin_url'] ); ?>" placeholder="https://origin.example.com"><p class="description">真正的源站首页，不可填写反代入口自身，不能包含路径或查询参数；脚本会验证原 Nginx 的 proxy_pass 与它相同。</p></td></tr>
			</tbody></table>
			<?php submit_button( '保存反代站点参数' ); ?>
		</form>
		<?php if ( $commands['preview'] ) : ?>
			<h3>1. 复制一行预览命令（不改动服务器）</h3>
			<textarea id="jrmu-media-nginx-preview" class="large-text code" rows="2" readonly><?php echo esc_textarea( $commands['preview'] ); ?></textarea><p><button type="button" class="button jrmu-copy-command" data-copy-id="jrmu-media-nginx-preview">复制预览命令</button></p>
			<h3>2. 通过预览后，可选执行自动备份部署命令</h3>
			<textarea id="jrmu-media-nginx-apply" class="large-text code" rows="2" readonly><?php echo esc_textarea( $commands['apply'] ); ?></textarea><p><button type="button" class="button button-secondary jrmu-copy-command" data-copy-id="jrmu-media-nginx-apply">复制自动部署命令</button></p>
			<h3>3. 如需恢复，复制一行回滚命令</h3>
			<textarea id="jrmu-media-nginx-rollback" class="large-text code" rows="2" readonly><?php echo esc_textarea( $commands['rollback'] ); ?></textarea><p><button type="button" class="button jrmu-copy-command" data-copy-id="jrmu-media-nginx-rollback">复制回滚命令</button></p>
			<p class="description">命令由插件自动填入当前安装路径及已保存参数，必须在站点服务器 sudo 终端执行。执行前先确认脚本来源可靠。自动部署会备份原 Nginx 配置并运行 <code>nginx -t</code>，失败恢复原文件；源站、反代指向相同主机或已有复杂规则时不会自动改。</p>
		<?php else : ?>
			<p class="notice notice-info inline" style="padding:12px">无需配置也可使用媒体与多域名功能。若要为独立 Nginx 反向代理站点加静态缓存，请先填写已加入白名单的反代域名和不同的源站 URL 并保存，页面才会生成一键命令。</p>
		<?php endif; ?>
		<p><a href="https://github.com/nljie1103/wp-assistant/blob/main/docs/media-nginx-optional.md" target="_blank" rel="noopener noreferrer">查看独立反代缓存适用条件与回滚说明</a>。原先包含整站动态反代的长代码不再作为新手部署推荐。</p>
		<?php
	}

	/** 日志页。 */
	private function render_logs_tab() {
		$logs = JRMU_Scanner::instance()->get_logs();
		?>
		<h2>转换日志</h2>
		<?php if ( empty( $logs ) ) : ?><p>暂无转换日志。</p><?php else : ?>
		<table class="widefat striped"><thead><tr><th>时间</th><th>动作</th><th>选择</th><th>更新</th><th>跳过</th><th>目标域名</th><th>错误</th></tr></thead><tbody>
		<?php foreach ( $logs as $log ) : ?><tr><td><?php echo esc_html( wp_date( 'Y-m-d H:i:s', absint( $log['time'] ) ) ); ?></td><td><code><?php echo esc_html( $log['action'] ); ?></code></td><td><?php echo esc_html( isset( $log['requested'] ) ? $log['requested'] : 0 ); ?></td><td><?php echo esc_html( isset( $log['updated'] ) ? $log['updated'] : 0 ); ?></td><td><?php echo esc_html( isset( $log['skipped'] ) ? $log['skipped'] : 0 ); ?></td><td><code><?php echo esc_html( isset( $log['target_base'] ) ? $log['target_base'] : '' ); ?></code></td><td><?php if ( ! empty( $log['errors'] ) ) : ?><code><?php echo esc_html( implode( '；', (array) $log['errors'] ) ); ?></code><?php else : ?>-<?php endif; ?></td></tr><?php endforeach; ?>
		</tbody></table>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return window.JLWA_DIALOG ? window.JLWA_DIALOG.confirmSubmit(event, this, '确定清空日志吗？', { title: '确认清空操作日志', danger: true, confirmLabel: '清空日志' }) : confirm('确定清空日志吗？');"><input type="hidden" name="action" value="jrmu_clear_logs"><?php wp_nonce_field( 'jrmu_clear_logs' ); ?><?php submit_button( '清空日志', 'delete' ); ?></form>
		<?php endif; ?>
		<?php
	}

	/** 批量处理。 */
	public function handle_apply_post_action() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'jrmu_apply_post_action' ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . JRMU_MENU_SLUG . '&tab=scanner&jrmu_notice=security_failed' ) );
			exit;
		}
		$post_ids = isset( $_POST['post_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['post_ids'] ) ) : array();
		if ( empty( $post_ids ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . JRMU_MENU_SLUG . '&tab=scanner&jrmu_notice=no_posts' ) );
			exit;
		}
		$action      = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
		if ( ! JRMU_Scanner::is_valid_action( $action ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . JRMU_MENU_SLUG . '&tab=scanner&jrmu_notice=invalid_action' ) );
			exit;
		}
		$target_base = isset( $_POST['target_base'] ) ? JRMU_Settings::sanitize_rewrite_target( wp_unslash( $_POST['target_base'] ) ) : home_url();
		$result      = JRMU_Scanner::instance()->apply_post_action( $post_ids, $action, $target_base );
		$url         = add_query_arg( array( 'page' => JRMU_MENU_SLUG, 'tab' => 'scanner', 'jrmu_notice' => 'done', 'requested' => $result['requested'], 'updated' => $result['updated'], 'skipped' => isset( $result['skipped'] ) ? $result['skipped'] : 0 ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $url );
		exit;
	}

	/** 清空日志。 */
	public function handle_clear_logs() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'jrmu_clear_logs' ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . JRMU_MENU_SLUG . '&tab=logs&jrmu_notice=security_failed' ) );
			exit;
		}
		delete_option( JRMU_LOG_OPTION_KEY );
		wp_safe_redirect( admin_url( 'admin.php?page=' . JRMU_MENU_SLUG . '&tab=logs&jrmu_notice=logs_cleared' ) );
		exit;
	}

	/** shorten。 */
	private function shorten( $text, $length = 100 ) {
		$text = (string) $text;
		if ( strlen( $text ) <= $length ) {
			return $text;
		}
		return substr( $text, 0, (int) ( $length * 0.6 ) ) . '...' . substr( $text, - (int) ( $length * 0.3 ) );
	}
}
