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
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_jrmu_apply_post_action', array( $this, 'handle_apply_post_action' ) );
		add_action( 'admin_post_jrmu_clear_logs', array( $this, 'handle_clear_logs' ) );
	}

	/** 菜单。 */
	public function add_admin_menu() {
		if ( defined( 'JLWA_MENU_SLUG' ) ) {
			add_submenu_page(
				JLWA_MENU_SLUG,
				'九流媒体相对地址',
				'媒体相对地址',
				'manage_options',
				JRMU_MENU_SLUG,
				array( $this, 'render_page' )
			);
			return;
		}

		add_menu_page(
			'九流媒体相对地址',
			'媒体相对地址',
			'manage_options',
			JRMU_MENU_SLUG,
			array( $this, 'render_page' ),
			'dashicons-admin-customizer',
			58
		);
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
		wp_enqueue_style( 'jrmu-admin', JRMU_PLUGIN_URL . 'assets/css/admin.css', array(), JRMU_VERSION );
		wp_enqueue_script( 'jrmu-admin', JRMU_PLUGIN_URL . 'assets/js/admin.js', array(), JRMU_VERSION, true );
	}

	/** 渲染页面。 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
		$allowed_tabs = array( 'settings', 'scanner', 'diagnostics', 'nginx', 'logs', 'help' );
		if ( ! in_array( $tab, $allowed_tabs, true ) ) {
			$tab = 'settings';
		}
		?>
		<div class="wrap jrmu-wrap">
			<div class="jiuliu-admin-header">
				<div>
					<h1><span class="dashicons dashicons-admin-customizer"></span>九流媒体相对地址</h1>
					<p class="jiuliu-admin-subtitle">WordPress 反向代理与多域名链接助手。默认不启用任何转换；所有永久修改都必须先扫描、预览、勾选并确认。</p>
				</div>
				<span class="jiuliu-version-badge">v<?php echo esc_html( JRMU_VERSION ); ?></span>
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
					case 'help':
						$this->render_help_tab();
						break;
					default:
						$this->render_settings_tab();
				}
				?>
			</div>
		</div>
		<?php
	}

	/** Tabs。 */
	private function render_tabs( $active ) {
		$tabs = array(
			'settings'    => '功能设置',
			'scanner'     => '扫描 / 预览 / 修复',
			'diagnostics' => '反代与缓存检测',
			'nginx'       => 'Nginx 配置建议',
			'logs'        => '转换日志',
			'help'        => '说明',
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
	private function render_settings_tab() {
		$options = JRMU_Settings::get_options();
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'jrmu_settings_group' ); ?>
			<div class="jrmu-grid">
				<div class="jrmu-main-card">
					<h2>一、媒体库地址</h2>
					<table class="form-table" role="presentation">
						<tr><th>已上传媒体</th><td><label><input type="checkbox" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[convert_existing_media_output]" value="1" <?php checked( $options['convert_existing_media_output'], 1 ); ?>> 转换已经上传媒体的输出地址</label><p class="description">影响媒体库、编辑器、附件 URL API、特色图、srcset 的输出，不移动文件，不改核心。关闭即可恢复输出。</p></td></tr>
						<tr><th>未来上传媒体</th><td><label><input type="checkbox" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[convert_future_media_output]" value="1" <?php checked( $options['convert_future_media_output'], 1 ); ?>> 从开启后，新上传媒体默认相对地址输出</label><p class="description">只影响开启之后的新附件。插件会给新附件打标记。</p></td></tr>
					</table>

					<h2>二、文章内容地址</h2>
					<table class="form-table" role="presentation">
						<tr><th>未来保存文章</th><td><label><input type="checkbox" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[convert_post_on_save]" value="1" <?php checked( $options['convert_post_on_save'], 1 ); ?>> 保存文章/页面时自动转换内容中的媒体地址</label><p class="description">会永久保存到文章正文/摘要，只影响开启后保存的内容。</p></td></tr>
						<tr><th>前台临时输出</th><td><label><input type="checkbox" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[convert_post_on_frontend]" value="1" <?php checked( $options['convert_post_on_frontend'], 1 ); ?>> 前台显示文章时临时转换旧内容</label><p class="description">不修改数据库，适合先测试旧文章图片是否走反代。</p></td></tr>
					</table>

					<h2>三、多域名访问适配</h2>
					<table class="form-table" role="presentation">
						<tr><th>总开关</th><td><label><input type="checkbox" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[domain_adaptation_enabled]" value="1" <?php checked( $options['domain_adaptation_enabled'], 1 ); ?>> 启用站内链接跟随当前访问域名</label><p class="description">解决从香港入口进入首页，点击文章又跳回源站固定域名的问题。</p></td></tr>
						<tr><th>允许域名</th><td><textarea name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[domain_allowed_hosts]" rows="5" class="large-text code" placeholder="blog.jiuliu.org&#10;hk-blog.jiuliu.org&#10;origin-blog.jiuliu.org"><?php echo esc_textarea( $options['domain_allowed_hosts'] ); ?></textarea><p class="description">白名单模式下，只有这里列出的域名才会触发动态链接。</p></td></tr>
						<tr><th>适配模式</th><td><label><input type="radio" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[domain_mode]" value="whitelist" <?php checked( $options['domain_mode'], 'whitelist' ); ?>> 白名单域名，推荐</label><br><label><input type="radio" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[domain_mode]" value="any" <?php checked( $options['domain_mode'], 'any' ); ?>> 任意 Host，高级模式</label></td></tr>
						<tr><th>协议</th><td><label><input type="radio" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[domain_scheme]" value="https" <?php checked( $options['domain_scheme'], 'https' ); ?>> 强制 HTTPS</label><br><label><input type="radio" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[domain_scheme]" value="auto" <?php checked( $options['domain_scheme'], 'auto' ); ?>> 自动识别 HTTPS / X-Forwarded-Proto</label><br><label><input type="radio" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[domain_scheme]" value="http" <?php checked( $options['domain_scheme'], 'http' ); ?>> 强制 HTTP</label></td></tr>
						<tr><th>前台兜底改写</th><td><label><input type="checkbox" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[domain_rewrite_frontend_links]" value="1" <?php checked( $options['domain_rewrite_frontend_links'], 1 ); ?>> 前台 HTML 兜底替换站内绝对链接</label><br><label><input type="radio" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[domain_rewrite_mode]" value="current_full" <?php checked( $options['domain_rewrite_mode'], 'current_full' ); ?>> 替换为当前域名完整 URL</label><br><label><input type="radio" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[domain_rewrite_mode]" value="root_relative" <?php checked( $options['domain_rewrite_mode'], 'root_relative' ); ?>> 替换为根相对路径</label></td></tr>
						<tr><th>排除项</th><td><label><input type="checkbox" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[domain_dynamic_siteurl]" value="1" <?php checked( $options['domain_dynamic_siteurl'], 1 ); ?>> 同时动态适配 siteurl</label><br><label><input type="checkbox" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[domain_exclude_admin]" value="1" <?php checked( $options['domain_exclude_admin'], 1 ); ?>> 后台 wp-admin 保持原始站点地址</label><br><label><input type="checkbox" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[domain_exclude_login]" value="1" <?php checked( $options['domain_exclude_login'], 1 ); ?>> 登录页保持原始站点地址</label><br><label><input type="checkbox" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[domain_skip_system_endpoints]" value="1" <?php checked( $options['domain_skip_system_endpoints'], 1 ); ?>> REST API、Feed、Sitemap、XML 等保持原始站点地址</label></td></tr>
					</table>

					<h2>四、SEO Canonical 主域名</h2>
					<table class="form-table" role="presentation">
						<tr><th>Canonical</th><td><label><input type="checkbox" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[canonical_enabled]" value="1" <?php checked( $options['canonical_enabled'], 1 ); ?>> Canonical 统一使用主域名</label><p class="description">多入口访问时，用户点击可跟随当前域名，但 SEO 规范地址可以统一到主域名。</p></td></tr>
						<tr><th>主域名</th><td><input type="text" class="regular-text" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[canonical_primary_host]" value="<?php echo esc_attr( $options['canonical_primary_host'] ); ?>" placeholder="blog.jiuliu.org"> <label><input type="radio" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[canonical_scheme]" value="https" <?php checked( $options['canonical_scheme'], 'https' ); ?>> https</label> <label><input type="radio" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[canonical_scheme]" value="http" <?php checked( $options['canonical_scheme'], 'http' ); ?>> http</label></td></tr>
					</table>

					<h2>五、转换范围与扫描</h2>
					<table class="form-table" role="presentation">
						<tr><th>路径范围</th><td><label><input type="checkbox" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[target_uploads]" value="1" <?php checked( $options['target_uploads'], 1 ); ?>> /wp-content/uploads/</label><br><label><input type="checkbox" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[target_themes]" value="1" <?php checked( $options['target_themes'], 1 ); ?>> /wp-content/themes/</label><br><label><input type="checkbox" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[target_plugins]" value="1" <?php checked( $options['target_plugins'], 1 ); ?>> /wp-content/plugins/</label></td></tr>
						<tr><th>额外源站域名</th><td><textarea name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[extra_hosts]" rows="5" class="large-text code" placeholder="origin-blog.jiuliu.org&#10;旧域名.com"><?php echo esc_textarea( $options['extra_hosts'] ); ?></textarea><p class="description">用于识别旧源站链接、源站暴露和可转换媒体地址。</p></td></tr>
						<tr><th>扫描限制</th><td><input type="number" min="10" max="1000" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[scan_limit]" value="<?php echo esc_attr( $options['scan_limit'] ); ?>"> <label><input type="checkbox" name="<?php echo esc_attr( JRMU_OPTION_KEY ); ?>[scan_postmeta]" value="1" <?php checked( $options['scan_postmeta'], 1 ); ?>> 扫描 postmeta，只读提示，不自动修改</label></td></tr>
					</table>
					<?php submit_button( '保存设置' ); ?>
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
		$target_base  = isset( $_GET['jrmu_target_base'] ) ? esc_url_raw( wp_unslash( $_GET['jrmu_target_base'] ) ) : home_url();
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
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('建议先备份数据库。本操作只会修改勾选文章的正文/摘要，并会尽量创建文章修订。确认继续吗？');">
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

	/** Nginx 页。 */
	private function render_nginx_tab() {
		$config = JRMU_Diagnostics::generate_nginx_config();
		?>
		<h2>Nginx 反代缓存配置建议</h2>
		<p>这是复制参考，不会自动修改服务器配置。宝塔里请先备份站点配置，再按你的源站域名调整 <code>proxy_pass</code>。</p>
		<textarea class="large-text code jrmu-nginx" rows="36" readonly><?php echo esc_textarea( $config ); ?></textarea>
		<p><button type="button" class="button jrmu-copy-nginx">复制配置</button></p>
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
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('确定清空日志吗？');"><input type="hidden" name="action" value="jrmu_clear_logs"><?php wp_nonce_field( 'jrmu_clear_logs' ); ?><?php submit_button( '清空日志', 'delete' ); ?></form>
		<?php endif; ?>
		<?php
	}

	/** 帮助页。 */
	private function render_help_tab() {
		?>
		<h2>推荐使用顺序</h2>
		<ol><li>先在“功能设置”里填写入口域名、源站域名，不要急着开启永久保存转换。</li><li>打开“扫描 / 预览 / 修复”，确认哪些链接会被处理。</li><li>先开启“前台临时输出”或“多域名访问适配”测试效果。</li><li>需要永久改库时，先备份数据库，再勾选文章执行。</li><li>如果改错，可用“根相对媒体 URL → 完整 URL”恢复。</li></ol>
		<h2>边界说明</h2><p>插件不移动媒体文件、不接管存储、不改 WordPress 核心、不默认改数据库、不默认改 GUID。postmeta 只做只读提示，避免破坏序列化数据。</p>
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
		$target_base = isset( $_POST['target_base'] ) ? esc_url_raw( wp_unslash( $_POST['target_base'] ) ) : home_url();
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
