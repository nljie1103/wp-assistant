<?php
/**
 * Unified admin application for 九流WP助手.
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
		add_action( 'admin_menu', array( $this, 'register_menus' ), 20 );
		add_action( 'admin_menu', array( $this, 'rename_dashboard_submenu' ), 999 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 100 );
		add_action( 'admin_notices', array( $this, 'render_conflict_notices' ) );
		add_filter( 'plugin_action_links_' . JLWA_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );
		add_filter( 'admin_body_class', array( $this, 'admin_body_class' ) );
	}

	/** Register the plugin's only menu tree. */
	public function register_menus() {
		add_menu_page(
			'九流WP助手',
			'九流WP助手',
			'manage_options',
			JLWA_MENU_SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-superhero-alt',
			58.8
		);
		foreach ( JLWA_Feature_Registry::features() as $key => $feature ) {
			add_submenu_page(
				JLWA_MENU_SLUG,
				$feature['label'],
				$feature['short_label'],
				'manage_options',
				$feature['slug'],
				$this->feature_callback( $key )
			);
		}
		add_submenu_page(
			JLWA_MENU_SLUG,
			'系统与更新',
			'系统与更新',
			'update_plugins',
			'jlwa-update-center',
			array( $this, 'render_update_center' )
		);
	}

	/** Rename WordPress' automatic first submenu. */
	public function rename_dashboard_submenu() {
		global $submenu;
		if ( isset( $submenu[ JLWA_MENU_SLUG ][0][0] ) ) {
			$submenu[ JLWA_MENU_SLUG ][0][0] = '助手总览';
		}
	}

	/** @param string $key Feature key. @return array<int,mixed> */
	protected function feature_callback( $key ) {
		$map = array(
			'page-effects' => 'render_page_effects',
			'ai-summary'   => 'render_ai_summary',
			'preloader'    => 'render_preloader',
			'media-urls'   => 'render_media_urls',
		);
		return array( $this, isset( $map[ $key ] ) ? $map[ $key ] : 'render_dashboard' );
	}

	/** @param string $classes Existing classes. @return string */
	public function admin_body_class( $classes ) {
		$page = $this->current_page();
		if ( $this->is_plugin_page( $page ) ) {
			$classes .= ' jlwa-admin-screen';
		}
		return $classes;
	}

	/** @param string $hook Current admin hook. */
	public function enqueue_assets( $hook ) {
		$page = $this->current_page();
		if ( ! $this->is_plugin_page( $page ) ) {
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
				'page'    => $page,
			)
		);
	}

	/** @param array<int,string> $links Plugin links. @return array<int,string> */
	public function plugin_action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . JLWA_MENU_SLUG ) ) . '">打开控制台</a>' );
		return $links;
	}

	/** Display a single coherent notice for legacy-plugin conflicts. */
	public function render_conflict_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$messages = array();
		foreach ( JLWA_Feature_Registry::statuses() as $key => $status ) {
			if ( ! empty( $status['loaded'] ) || empty( $status['message'] ) ) {
				continue;
			}
			$feature    = JLWA_Feature_Registry::get( $key );
			$messages[] = ( $feature ? $feature['label'] : $key ) . '：' . $status['message'];
		}
		if ( ! $messages ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>九流WP助手检测到冲突：</strong>' . esc_html( implode( '；', $messages ) ) . ' <a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">打开插件列表</a></p></div>';
	}

	public function render_dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$features = JLWA_Feature_Registry::features();
		$statuses = JLWA_Feature_Registry::statuses();
		$ready    = 0;
		foreach ( $statuses as $status ) {
			if ( ! empty( $status['loaded'] ) ) {
				++$ready;
			}
		}
		$this->shell_start( 'dashboard', '统一控制台', '一个插件管理四项核心能力，设置、界面与更新全部集中。' );
		?>
		<section class="jlwa-hero">
			<div class="jlwa-hero__copy">
				<span class="jlwa-kicker">JIULIU WORDPRESS ASSISTANT 2.0</span>
				<h2>不是四个插件的集合，<br>而是一套完整的网站增强系统。</h2>
				<p>页面体验、加载体验、内容智能和媒体链接共用同一个插件生命周期、同一个后台应用与同一个更新源；内部按功能域拆分，仅用于保证代码清晰和维护安全。</p>
				<div class="jlwa-hero__actions">
					<a class="button button-primary button-hero" href="<?php echo esc_url( admin_url( 'admin.php?page=jlwa-page-effects' ) ); ?>">开始配置</a>
					<a class="button button-hero" href="<?php echo esc_url( admin_url( 'admin.php?page=jlwa-update-center' ) ); ?>">系统与更新</a>
				</div>
			</div>
			<div class="jlwa-hero__stats">
				<div><strong><?php echo esc_html( $ready ); ?>/<?php echo esc_html( count( $features ) ); ?></strong><span>功能就绪</span></div>
				<div><strong>1</strong><span>插件入口</span></div>
				<div><strong>v<?php echo esc_html( JLWA_VERSION ); ?></strong><span>当前版本</span></div>
			</div>
		</section>
		<div class="jlwa-section-title">
			<div><span>CORE FEATURES</span><h2>四项能力，一套体验</h2></div>
			<p>每个功能都有独立职责，但不再拥有独立插件头、激活流程、菜单树或更新器。</p>
		</div>
		<div class="jlwa-feature-grid">
			<?php foreach ( $features as $key => $feature ) : ?>
				<?php
				$state  = JLWA_Feature_Registry::state( $key );
				$status = isset( $statuses[ $key ] ) ? $statuses[ $key ] : array();
				?>
				<article class="jlwa-feature-card jlwa-feature-card--<?php echo esc_attr( $key ); ?>">
					<div class="jlwa-feature-card__top">
						<span class="jlwa-feature-icon"><span class="dashicons <?php echo esc_attr( $feature['icon'] ); ?>"></span></span>
						<span class="jlwa-state-badge is-<?php echo esc_attr( $state['tone'] ); ?>"><?php echo esc_html( $state['label'] ); ?></span>
					</div>
					<span class="jlwa-feature-eyebrow"><?php echo esc_html( $feature['eyebrow'] ); ?></span>
					<h3><?php echo esc_html( $feature['label'] ); ?></h3>
					<p><?php echo esc_html( $feature['description'] ); ?></p>
					<div class="jlwa-feature-card__footer">
						<span>内部功能版本 v<?php echo esc_html( JLWA_Feature_Registry::version( $key ) ); ?></span>
						<?php if ( ! empty( $status['loaded'] ) ) : ?>
							<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $feature['slug'] ) ); ?>">管理功能</a>
						<?php else : ?>
							<a class="button" href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>">处理冲突</a>
						<?php endif; ?>
					</div>
				</article>
			<?php endforeach; ?>
		</div>
		<section class="jlwa-architecture-note">
			<div class="jlwa-architecture-note__icon"><span class="dashicons dashicons-admin-generic"></span></div>
			<div><span>UNIFIED ARCHITECTURE</span><h2>统一生命周期，功能域内部解耦</h2><p>WordPress 只识别一个“九流WP助手”插件。四项功能由中央注册表启动，后台菜单由统一控制台注册，在线更新只覆盖一个完整发布包。</p></div>
			<div class="jlwa-checklist"><span><i></i>一个插件头</span><span><i></i>一个激活入口</span><span><i></i>一个菜单中心</span><span><i></i>一个安全更新器</span></div>
		</section>
		<?php
		$this->shell_end();
	}

	public function render_page_effects() { $this->render_feature( 'page-effects' ); }
	public function render_ai_summary() { $this->render_feature( 'ai-summary' ); }
	public function render_preloader() { $this->render_feature( 'preloader' ); }
	public function render_media_urls() { $this->render_feature( 'media-urls' ); }

	/** @param string $key Feature key. */
	protected function render_feature( $key ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$feature      = JLWA_Feature_Registry::get( $key );
		$all_statuses = JLWA_Feature_Registry::statuses();
		$status       = isset( $all_statuses[ $key ] ) ? $all_statuses[ $key ] : array();
		if ( ! $feature ) {
			$this->render_dashboard();
			return;
		}
		$this->shell_start( $key, $feature['label'], $feature['description'] );
		if ( empty( $status['loaded'] ) ) {
			echo '<section class="jlwa-empty-state"><span class="dashicons dashicons-warning"></span><h2>该功能暂未启动</h2><p>' . esc_html( isset( $status['message'] ) ? $status['message'] : '状态未知。' ) . '</p><a class="button button-primary" href="' . esc_url( admin_url( 'plugins.php' ) ) . '">检查旧插件冲突</a></section>';
		} else {
			echo '<div class="jlwa-feature-host jlwa-feature-host--' . esc_attr( $key ) . '">';
			JLWA_Feature_Registry::render_admin( $key );
			echo '</div>';
		}
		$this->shell_end();
	}

	public function render_update_center() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		$this->shell_start( 'system', '系统与更新', '检查完整性、查看功能版本，并从唯一主仓库安全更新。' );
		?>
		<div class="jlwa-system-grid">
			<section class="jlwa-update-panel">
				<div class="jlwa-update-panel__head">
					<div><span class="jlwa-kicker">SECURE UPDATE</span><h2>九流WP助手 v<?php echo esc_html( JLWA_VERSION ); ?></h2><p>只从 <code>nljie1103/wp-assistant</code> 获取完整插件包，不再访问任何旧独立仓库。</p></div>
					<div class="jlwa-version-orb">v<?php echo esc_html( JLWA_VERSION ); ?></div>
				</div>
				<div class="jlwa-update-protection"><span><i class="dashicons dashicons-yes-alt"></i>版本校验</span><span><i class="dashicons dashicons-yes-alt"></i>SHA-256 校验</span><span><i class="dashicons dashicons-yes-alt"></i>完整备份</span><span><i class="dashicons dashicons-yes-alt"></i>失败回滚</span></div>
				<div class="jlwa-update-actions"><button type="button" class="button button-secondary" id="jlwa-check-update">立即检查更新</button><button type="button" class="button button-primary" id="jlwa-do-update" disabled>安全更新插件</button></div>
				<div id="jlwa-update-status" class="jlwa-update-status">尚未检查远程版本。</div>
				<pre id="jlwa-update-log" class="jlwa-update-log">（检查后显示版本说明）</pre>
			</section>
			<aside class="jlwa-system-side">
				<h2>功能版本</h2>
				<?php foreach ( JLWA_Feature_Registry::features() as $key => $feature ) : ?>
					<div class="jlwa-system-version"><span class="dashicons <?php echo esc_attr( $feature['icon'] ); ?>"></span><div><strong><?php echo esc_html( $feature['label'] ); ?></strong><span>v<?php echo esc_html( JLWA_Feature_Registry::version( $key ) ); ?></span></div></div>
				<?php endforeach; ?>
				<div class="jlwa-system-tip"><strong>数据策略</strong><p>更新插件不会删除现有设置、AI 缓存、媒体扫描记录或文章数据。</p></div>
			</aside>
		</div>
		<?php
		$this->shell_end();
	}

	/** @param string $active Active key. @param string $title Title. @param string $subtitle Subtitle. */
	protected function shell_start( $active, $title, $subtitle ) {
		?>
		<div class="wrap jlwa-app jlwa-app--<?php echo esc_attr( $active ); ?>">
			<header class="jlwa-appbar">
				<a class="jlwa-brand" href="<?php echo esc_url( admin_url( 'admin.php?page=' . JLWA_MENU_SLUG ) ); ?>"><span class="jlwa-brand__mark"><span class="dashicons dashicons-superhero-alt"></span></span><span><strong>九流WP助手</strong><small>Unified WordPress Toolkit</small></span></a>
				<nav class="jlwa-appnav" aria-label="九流WP助手功能导航">
					<?php $this->render_nav_item( 'dashboard', JLWA_MENU_SLUG, '控制台', 'dashicons-dashboard', $active ); ?>
					<?php foreach ( JLWA_Feature_Registry::features() as $key => $feature ) : ?>
						<?php $this->render_nav_item( $key, $feature['slug'], $feature['short_label'], $feature['icon'], $active ); ?>
					<?php endforeach; ?>
					<?php $this->render_nav_item( 'system', 'jlwa-update-center', '系统', 'dashicons-update-alt', $active ); ?>
				</nav>
				<span class="jlwa-appbar__version">v<?php echo esc_html( JLWA_VERSION ); ?></span>
			</header>
			<div class="jlwa-page-heading"><div><span>JIULIU ASSISTANT</span><h1><?php echo esc_html( $title ); ?></h1><p><?php echo esc_html( $subtitle ); ?></p></div></div>
			<main class="jlwa-main">
		<?php
	}

	protected function shell_end() { echo '</main></div>'; }

	/** @param string $key Key. @param string $slug Slug. @param string $label Label. @param string $icon Icon. @param string $active Active. */
	protected function render_nav_item( $key, $slug, $label, $icon, $active ) {
		$class = 'jlwa-appnav__item' . ( $key === $active ? ' is-active' : '' );
		echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( admin_url( 'admin.php?page=' . $slug ) ) . '"><span class="dashicons ' . esc_attr( $icon ) . '"></span><span>' . esc_html( $label ) . '</span></a>';
	}

	/** @return string */
	protected function current_page() { return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; }

	/** @param string $page Page. @return bool */
	protected function is_plugin_page( $page ) {
		if ( in_array( $page, array( JLWA_MENU_SLUG, 'jlwa-update-center' ), true ) ) {
			return true;
		}
		return '' !== JLWA_Feature_Registry::key_from_slug( $page );
	}
}
