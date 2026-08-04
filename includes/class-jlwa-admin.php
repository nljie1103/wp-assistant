<?php
/**
 * Unified admin shell for 九流WP助手.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JLWA_Admin {
	/** @var JLWA_Admin|null */
	protected static $instance = null;

	/** @return JLWA_Admin */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_parent_menu' ), 5 );
		add_action( 'admin_menu', array( $this, 'register_update_menu' ), 99 );
		add_action( 'admin_menu', array( $this, 'rename_dashboard_submenu' ), 999 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'render_conflict_notices' ) );
		add_filter( 'plugin_action_links_' . JLWA_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );
	}

	public function register_parent_menu() {
		add_menu_page(
			'九流WP助手',
			'九流WP助手',
			'manage_options',
			JLWA_MENU_SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-superhero-alt',
			58.8
		);
	}

	public function register_update_menu() {
		add_submenu_page(
			JLWA_MENU_SLUG,
			'系统与更新',
			'系统与更新',
			'update_plugins',
			'jlwa-update-center',
			array( $this, 'render_update_center' )
		);
	}

	public function rename_dashboard_submenu() {
		global $submenu;
		if ( isset( $submenu[ JLWA_MENU_SLUG ][0][0] ) ) {
			$submenu[ JLWA_MENU_SLUG ][0][0] = '助手总览';
		}
	}

	/** @param string $hook Current admin hook. */
	public function enqueue_assets( $hook ) {
		$hook = (string) $hook;
		if ( false === strpos( $hook, JLWA_MENU_SLUG ) && 'toplevel_page_' . JLWA_MENU_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style( 'jlwa-admin', JLWA_PLUGIN_URL . 'assets/css/admin.css', array(), JLWA_VERSION );
		wp_enqueue_script( 'jlwa-admin', JLWA_PLUGIN_URL . 'assets/js/admin.js', array(), JLWA_VERSION, true );
		wp_localize_script(
			'jlwa-admin',
			'JLWA_ADMIN',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'jlwa_update_nonce' ),
			)
		);
	}

	/** @param array<int,string> $links Plugin links. */
	public function plugin_action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . JLWA_MENU_SLUG ) ) . '">助手总览</a>' );
		return $links;
	}

	public function render_conflict_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		foreach ( JLWA_Module_Loader::statuses() as $key => $status ) {
			if ( ! empty( $status['loaded'] ) || empty( $status['message'] ) ) {
				continue;
			}
			$module = $this->get_module( $key );
			$label  = $module ? $module['label'] : $key;
			echo '<div class="notice notice-warning"><p><strong>九流WP助手：</strong>' . esc_html( $label ) . ' 未加载。' . esc_html( $status['message'] ) . ' <a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">打开插件列表</a></p></div>';
		}
	}

	public function render_dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$modules      = JLWA_Module_Loader::modules();
		$statuses     = JLWA_Module_Loader::statuses();
		$loaded_count = 0;
		$issue_count  = 0;
		foreach ( $statuses as $status ) {
			if ( ! empty( $status['loaded'] ) ) {
				++$loaded_count;
			} else {
				++$issue_count;
			}
		}
		?>
		<div class="wrap jlwa-wrap">
			<?php $this->render_header( '九流WP助手', '四个站点工具、一个后台入口、一个统一更新源。' ); ?>

			<section class="jlwa-hero">
				<div class="jlwa-hero__content">
					<span class="jlwa-eyebrow">JIULIU WORDPRESS TOOLKIT</span>
					<h2>以后只维护这一个插件</h2>
					<p>页面美化、媒体地址、AI 摘要和预加载已经作为内置模块统一发布。独立仓库停止功能更新，设置数据仍沿用原来的 option key，迁移时无需重新配置。</p>
					<div class="jlwa-hero__actions">
						<a class="button button-primary button-hero" href="<?php echo esc_url( admin_url( 'admin.php?page=jlwa-update-center' ) ); ?>">系统与更新</a>
						<a class="button button-hero" href="https://github.com/nljie1103/wp-assistant" target="_blank" rel="noopener noreferrer">查看主仓库</a>
					</div>
				</div>
				<div class="jlwa-health">
					<div><strong><?php echo esc_html( $loaded_count ); ?>/<?php echo esc_html( count( $modules ) ); ?></strong><span>模块正常</span></div>
					<div><strong>v<?php echo esc_html( JLWA_VERSION ); ?></strong><span>套件版本</span></div>
					<div><strong><?php echo esc_html( $issue_count ); ?></strong><span>待处理冲突</span></div>
				</div>
			</section>

			<?php if ( $issue_count > 0 ) : ?>
				<div class="notice notice-warning inline jlwa-inline-notice"><p><strong>检测到模块冲突。</strong> 常见原因是对应的旧独立插件仍在启用。请先到插件列表停用独立版，再刷新本页。</p></div>
			<?php endif; ?>

			<div class="jlwa-section-heading">
				<div><span>内置模块</span><h2>选择要管理的功能</h2></div>
				<p>模块共享同一个发布包，但各自设置和前台行为仍彼此独立。</p>
			</div>

			<div class="jlwa-module-grid">
				<?php foreach ( $modules as $key => $module ) : ?>
					<?php
					$status      = isset( $statuses[ $key ] ) ? $statuses[ $key ] : array( 'loaded' => false, 'status' => 'missing', 'message' => '状态未知。' );
					$is_loaded   = ! empty( $status['loaded'] );
					$status_text = $is_loaded ? ( 'version_mismatch' === $status['status'] ? '版本异常' : '运行正常' ) : ( 'conflict' === $status['status'] ? '存在冲突' : '未加载' );
					?>
					<section class="jlwa-module-card <?php echo $is_loaded ? 'is-ready' : 'has-issue'; ?>">
						<div class="jlwa-module-card__top">
							<span class="jlwa-module-icon"><span class="dashicons <?php echo esc_attr( $module['icon'] ); ?>"></span></span>
							<span class="jlwa-status-badge <?php echo $is_loaded ? 'is-on' : 'is-off'; ?>"><?php echo esc_html( $status_text ); ?></span>
						</div>
						<h3><?php echo esc_html( $module['label'] ); ?></h3>
						<p><?php echo esc_html( $this->module_description( $key ) ); ?></p>
						<div class="jlwa-module-meta">
							<span>内置版本</span>
							<strong>v<?php echo esc_html( JLWA_Module_Loader::module_version( $key, $module ) ); ?></strong>
						</div>
						<?php if ( ! $is_loaded ) : ?>
							<p class="jlwa-module-error"><?php echo esc_html( $status['message'] ); ?></p>
						<?php endif; ?>
						<div class="jlwa-module-card__actions">
							<?php if ( $is_loaded ) : ?>
								<a class="button button-primary" href="<?php echo esc_url( $this->module_admin_url( $module ) ); ?>">打开设置</a>
							<?php else : ?>
								<a class="button" href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>">检查插件冲突</a>
							<?php endif; ?>
							<span class="jlwa-bundled-label">已整合</span>
						</div>
					</section>
				<?php endforeach; ?>
			</div>

			<section class="jlwa-maintenance-note">
				<span class="dashicons dashicons-lock"></span>
				<div><h2>统一维护规则</h2><p>以后功能修复、界面优化和在线更新都只进入 <code>nljie1103/wp-assistant</code>。内置模块不会再访问各自的旧更新源。</p></div>
			</section>
		</div>
		<?php
	}

	public function render_update_center() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		?>
		<div class="wrap jlwa-wrap">
			<?php $this->render_header( '系统与更新', '从唯一主仓库安全更新整个助手套件。' ); ?>

			<div class="jlwa-update-layout">
				<section class="jlwa-update-box">
					<div class="jlwa-update-box__main">
						<div class="jlwa-version-tile"><span>当前套件版本</span><strong>v<?php echo esc_html( JLWA_VERSION ); ?></strong></div>
						<div class="jlwa-update-source">
							<span class="jlwa-eyebrow">SINGLE SOURCE</span>
							<h2>nljie1103/wp-assistant</h2>
							<p>更新器会先校验远程版本和主文件 SHA-256，再完整备份当前插件目录。安装失败会自动回滚，不再进行半覆盖更新。</p>
						</div>
					</div>
					<div class="jlwa-update-actions">
						<button type="button" class="button button-secondary" id="jlwa-check-update">立即检查更新</button>
						<button type="button" class="button button-primary" id="jlwa-do-update" disabled>安全更新套件</button>
					</div>
					<div id="jlwa-update-status" class="jlwa-update-status">尚未检查远程版本。</div>
					<pre id="jlwa-update-log" class="jlwa-update-log">（检查更新后显示版本说明）</pre>
				</section>

				<aside class="jlwa-safety-card">
					<h2>更新保护</h2>
					<ul>
						<li><span class="dashicons dashicons-yes-alt"></span>远程版本与压缩包版本一致</li>
						<li><span class="dashicons dashicons-yes-alt"></span>主文件 SHA-256 完整性校验</li>
						<li><span class="dashicons dashicons-yes-alt"></span>插件目录完整备份</li>
						<li><span class="dashicons dashicons-yes-alt"></span>失败自动恢复旧版本</li>
						<li><span class="dashicons dashicons-yes-alt"></span>清理旧文件后重新安装</li>
					</ul>
				</aside>
			</div>

			<section class="jlwa-module-versions">
				<div class="jlwa-section-heading compact"><div><span>版本清单</span><h2>随套件一起发布</h2></div></div>
				<div class="jlwa-version-grid">
					<?php foreach ( JLWA_Module_Loader::modules() as $key => $module ) : ?>
						<div class="jlwa-version-card"><span class="dashicons <?php echo esc_attr( $module['icon'] ); ?>"></span><div><strong><?php echo esc_html( $module['label'] ); ?></strong><span>v<?php echo esc_html( JLWA_Module_Loader::module_version( $key, $module ) ); ?></span></div></div>
					<?php endforeach; ?>
				</div>
			</section>
		</div>
		<?php
	}

	/** @param string $title Title. @param string $subtitle Subtitle. */
	protected function render_header( $title, $subtitle ) {
		?>
		<div class="jiuliu-admin-header">
			<div><h1><span class="dashicons dashicons-superhero-alt"></span><?php echo esc_html( $title ); ?></h1><p class="jiuliu-admin-subtitle"><?php echo esc_html( $subtitle ); ?></p></div>
			<span class="jiuliu-version-badge">v<?php echo esc_html( JLWA_VERSION ); ?></span>
		</div>
		<?php
	}

	/** @param string $key Module key. @return array<string,mixed>|null */
	protected function get_module( $key ) {
		$modules = JLWA_Module_Loader::modules();
		return isset( $modules[ $key ] ) ? $modules[ $key ] : null;
	}

	/** @param array<string,mixed> $module Module. */
	protected function module_admin_url( $module ) {
		return admin_url( 'admin.php?page=' . $module['slug'] );
	}

	/** @param string $key Module key. */
	protected function module_description( $key ) {
		$descriptions = array(
			'page-effects'        => '樱花、雪花、灯笼、粒子、右键菜单、节日欢迎和轻量背景音乐。',
			'relative-media-urls' => '多入口域名适配、媒体相对地址、历史内容扫描、修复与诊断。',
			'ai-article-summary'  => '文章 AI 摘要、模型选择、主题兼容、缓存和外观预设。',
			'immersive-preloader' => '沉浸式页面加载动画、自定义 Logo、时长和跳过策略。',
		);
		return isset( $descriptions[ $key ] ) ? $descriptions[ $key ] : '';
	}
}
