<?php
/**
 * Admin shell for 九流WP助手.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JLWA_Admin {
	/**
	 * Singleton.
	 *
	 * @var JLWA_Admin|null
	 */
	protected static $instance = null;

	/**
	 * Get singleton.
	 *
	 * @return JLWA_Admin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_parent_menu' ), 5 );
		add_action( 'admin_menu', array( $this, 'register_update_menu' ), 99 );
		add_action( 'admin_menu', array( $this, 'remove_duplicate_submenu' ), 999 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'render_conflict_notices' ) );
		add_filter( 'plugin_action_links_' . JLWA_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );
	}

	/**
	 * Register parent menu at the bottom of the sidebar.
	 */
	public function register_parent_menu() {
		add_menu_page(
			'九流WP助手',
			'九流WP助手',
			'manage_options',
			JLWA_MENU_SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-admin-customizer',
			99.998
		);
	}

	/**
	 * Register update center as the last submenu.
	 */
	public function register_update_menu() {
		add_submenu_page(
			JLWA_MENU_SLUG,
			'更新中心',
			'更新中心',
			'manage_options',
			'jlwa-update-center',
			array( $this, 'render_update_center' )
		);
	}

	/**
	 * Keep the submenu list focused on modules + update center.
	 */
	public function remove_duplicate_submenu() {
		remove_submenu_page( JLWA_MENU_SLUG, JLWA_MENU_SLUG );
	}

	/**
	 * Enqueue shell assets.
	 *
	 * @param string $hook Current admin hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( (string) $hook, JLWA_MENU_SLUG ) && 'toplevel_page_' . JLWA_MENU_SLUG !== $hook ) {
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

	/**
	 * Add settings link on plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . JLWA_MENU_SLUG ) ) . '">进入助手</a>' );
		return $links;
	}

	/**
	 * Render conflict notices.
	 */
	public function render_conflict_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		foreach ( JLWA_Module_Loader::statuses() as $key => $status ) {
			if ( empty( $status['loaded'] ) && ! empty( $status['message'] ) ) {
				$module = $this->get_module( $key );
				$label  = $module ? $module['label'] : $key;
				echo '<div class="notice notice-warning"><p><strong>九流WP助手：</strong>' . esc_html( $label ) . ' 模块未加载。' . esc_html( $status['message'] ) . '</p></div>';
			}
		}
	}

	/**
	 * Render dashboard.
	 */
	public function render_dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap jlwa-wrap">
			<?php $this->render_header( '九流WP助手', '一个入口管理页面美化、媒体链接、AI 文章摘要和沉浸式预加载。' ); ?>

			<div class="jlwa-module-grid">
				<?php foreach ( JLWA_Module_Loader::modules() as $key => $module ) : ?>
					<?php $status = $this->get_status( $key ); ?>
					<section class="jlwa-module-card">
						<div class="jlwa-module-card__head">
							<h2><?php echo esc_html( $module['label'] ); ?></h2>
							<span class="jlwa-status-badge <?php echo ! empty( $status['loaded'] ) ? 'is-on' : 'is-off'; ?>">
								<?php echo ! empty( $status['loaded'] ) ? '已加载' : '未加载'; ?>
							</span>
						</div>
						<p><?php echo esc_html( $this->module_description( $key ) ); ?></p>
						<div class="jlwa-module-card__meta">
							<span>当前版本：<?php echo esc_html( $this->module_version( $key, $module ) ); ?></span>
						</div>
						<div class="jlwa-module-card__actions">
							<?php if ( ! empty( $status['loaded'] ) ) : ?>
								<a class="button button-primary" href="<?php echo esc_url( $this->module_admin_url( $module ) ); ?>">打开设置</a>
							<?php endif; ?>
							<a class="button" href="<?php echo esc_url( $module['repo'] ); ?>" target="_blank" rel="noopener noreferrer">GitHub</a>
						</div>
					</section>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render update center.
	 */
	public function render_update_center() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap jlwa-wrap">
			<?php $this->render_header( '更新中心', '九流WP助手只从 nljie1103/wp-assistant 主仓库更新整个套件。' ); ?>

			<section class="jlwa-update-box">
				<div class="jlwa-update-box__main">
					<div class="jlwa-version-tile">
						<span>当前版本</span>
						<strong>v<?php echo esc_html( JLWA_VERSION ); ?></strong>
					</div>
					<div class="jlwa-update-source">
						<h2>主仓库更新源</h2>
						<p><a href="https://github.com/nljie1103/wp-assistant" target="_blank" rel="noopener noreferrer">github.com/nljie1103/wp-assistant</a></p>
						<p>更新会覆盖九流WP助手整个插件目录，四个模块随主仓库一起发布，不再分别更新。</p>
					</div>
				</div>
				<div class="jlwa-update-actions">
					<button type="button" class="button button-secondary" id="jlwa-check-update">立即检查更新</button>
					<button type="button" class="button button-primary" id="jlwa-do-update" disabled>一键更新套件</button>
					<a class="button" href="https://github.com/nljie1103/wp-assistant/releases" target="_blank" rel="noopener noreferrer">打开 Releases</a>
				</div>
				<div id="jlwa-update-status" class="jlwa-update-status">点击“立即检查更新”来对比本地与主仓库版本。</div>
				<pre id="jlwa-update-log" class="jlwa-update-log">（暂未获取，请先点击“立即检查更新”）</pre>
			</section>

			<section class="jlwa-module-versions">
				<h2>套件内模块版本</h2>
				<div class="jlwa-version-grid">
					<?php foreach ( JLWA_Module_Loader::modules() as $key => $module ) : ?>
						<div class="jlwa-version-card">
							<strong><?php echo esc_html( $module['label'] ); ?></strong>
							<span>v<?php echo esc_html( $this->module_version( $key, $module ) ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			</section>
		</div>
		<?php
	}

	/**
	 * Render shared page header.
	 *
	 * @param string $title Title.
	 * @param string $subtitle Subtitle.
	 */
	protected function render_header( $title, $subtitle ) {
		?>
		<div class="jiuliu-admin-header">
			<div>
				<h1><span class="dashicons dashicons-admin-customizer"></span><?php echo esc_html( $title ); ?></h1>
				<p class="jiuliu-admin-subtitle"><?php echo esc_html( $subtitle ); ?></p>
			</div>
			<span class="jiuliu-version-badge">v<?php echo esc_html( JLWA_VERSION ); ?></span>
		</div>
		<?php
	}

	/**
	 * Get status.
	 *
	 * @param string $key Module key.
	 * @return array
	 */
	protected function get_status( $key ) {
		$statuses = JLWA_Module_Loader::statuses();
		return isset( $statuses[ $key ] ) ? $statuses[ $key ] : array( 'loaded' => false );
	}

	/**
	 * Get module definition.
	 *
	 * @param string $key Module key.
	 * @return array|null
	 */
	protected function get_module( $key ) {
		$modules = JLWA_Module_Loader::modules();
		return isset( $modules[ $key ] ) ? $modules[ $key ] : null;
	}

	/**
	 * Module admin URL.
	 *
	 * @param array $module Module definition.
	 * @return string
	 */
	protected function module_admin_url( $module ) {
		return admin_url( 'admin.php?page=' . $module['slug'] );
	}

	/**
	 * Module version.
	 *
	 * @param string $key Module key.
	 * @param array  $module Module definition.
	 * @return string
	 */
	protected function module_version( $key, $module ) {
		if ( 'page-effects' === $key && class_exists( 'XJPE_Plugin', false ) ) {
			return XJPE_Plugin::VERSION;
		}

		if ( 'relative-media-urls' === $key && defined( 'JRMU_VERSION' ) ) {
			return JRMU_VERSION;
		}

		if ( 'ai-article-summary' === $key && defined( 'WPAIAS_VERSION' ) ) {
			return WPAIAS_VERSION;
		}

		if ( 'immersive-preloader' === $key && defined( 'JIP_VERSION' ) ) {
			return JIP_VERSION;
		}

		return isset( $module['version'] ) ? $module['version'] : '-';
	}

	/**
	 * Module description.
	 *
	 * @param string $key Module key.
	 * @return string
	 */
	protected function module_description( $key ) {
		$descriptions = array(
			'page-effects'        => '樱花、雪花、灯笼、粒子、右键菜单、背景音乐等页面氛围与交互增强。',
			'relative-media-urls' => '反向代理、多入口域名、媒体相对地址、历史内容扫描与 Nginx 配置辅助。',
			'ai-article-summary'  => '文章 AI 摘要生成、模型选择、样式预览、缓存管理和主题兼容设置。',
			'immersive-preloader' => '首页沉浸式预加载动画、自定义 Logo、加载时长与跳过策略。',
		);

		return isset( $descriptions[ $key ] ) ? $descriptions[ $key ] : '';
	}
}
