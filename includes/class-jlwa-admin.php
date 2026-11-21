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
			'anti-debug'   => 'render_anti_debug',
			'ai-summary'   => 'render_ai_summary',
			'preloader'    => 'render_preloader',
			'media-urls'   => 'render_media_urls',
			'download-page' => 'render_download_page',
			'login-security' => 'render_login_security',
			'anti-scraping' => 'render_anti_scraping',
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

		$feature_key = JLWA_Feature_Registry::key_from_slug( $page );
		if ( '' !== $feature_key ) {
			$this->enqueue_feature_assets( $feature_key );
		}

		wp_enqueue_style( 'jlwa-admin', JLWA_PLUGIN_URL . 'assets/css/admin.css', array(), JLWA_VERSION );
		wp_enqueue_style( 'jlwa-admin-content', JLWA_PLUGIN_URL . 'assets/css/admin-content.css', array( 'jlwa-admin' ), JLWA_VERSION );
		wp_enqueue_script( 'jlwa-admin', JLWA_PLUGIN_URL . 'assets/js/admin.js', array(), JLWA_VERSION, true );
		wp_enqueue_script( 'jlwa-admin-content', JLWA_PLUGIN_URL . 'assets/js/admin-content.js', array( 'jlwa-admin' ), JLWA_VERSION, true );
		// Prebuilt Vue 3 + Reka UI management shell. The original feature forms
		// remain outside Vue roots, so WordPress nonces, POSTs, and feature JS
		// continue working without migration or database changes.
		wp_enqueue_style( 'jlwa-vue-admin', JLWA_PLUGIN_URL . 'assets/vue/admin.css', array( 'jlwa-admin-content' ), JLWA_VERSION );
		wp_enqueue_script( 'jlwa-vue-admin', JLWA_PLUGIN_URL . 'assets/vue/admin.js', array( 'jlwa-admin-content' ), JLWA_VERSION, true );
		wp_localize_script( 'jlwa-vue-admin', 'JLWA_V3', $this->vue_config( $page ) );
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

	/** Public page metadata only: all URLs come from WordPress admin_url(). */
	protected function vue_config( $page ) {
		$features = JLWA_Feature_Registry::features();
		$active = JLWA_Feature_Registry::key_from_slug( $page );
		if ( '' === $active ) {
			$active = 'jlwa-update-center' === $page ? 'system' : 'dashboard';
		}
		$entries = array( array(
			'key' => 'dashboard',
			'label' => '控制台',
			'description' => '站点总览与功能状态',
			'href' => admin_url( 'admin.php?page=' . JLWA_MENU_SLUG ),
		) );
		$modules = array();
		foreach ( $features as $key => $feature ) {
			$state = JLWA_Feature_Registry::state( $key );
			$entry = array(
				'key' => $key,
				'label' => $feature['label'],
				'description' => $feature['description'],
				'eyebrow' => $feature['eyebrow'],
				'href' => admin_url( 'admin.php?page=' . $feature['slug'] ),
				'enabled' => ! empty( $state['enabled'] ),
				'ready' => ! empty( $state['ready'] ),
				'status' => $state['label'],
			);
			$entries[] = $entry;
			$modules[] = $entry;
		}
		$entries[] = array(
			'key' => 'system',
			'label' => '系统与更新',
			'description' => '版本检查、更新与插件完整性',
			'href' => admin_url( 'admin.php?page=jlwa-update-center' ),
		);
		$title = '控制台';
		$subtitle = '管理站点功能与运行状态。';
		if ( 'system' === $active ) {
			$title = '系统与更新';
			$subtitle = '检查版本、更新完整插件和验证安装状态。';
		} elseif ( isset( $features[ $active ] ) ) {
			$title = $features[ $active ]['label'];
			$subtitle = $features[ $active ]['description'];
		}
		return array(
			'active' => $active,
			'title' => $title,
			'subtitle' => $subtitle,
			'version' => JLWA_VERSION,
			'homeUrl' => admin_url( 'admin.php?page=' . JLWA_MENU_SLUG ),
			'updateUrl' => admin_url( 'admin.php?page=jlwa-update-center' ),
			'entries' => $entries,
			'modules' => $modules,
		);
	}

	/**
	 * Force-load each feature's mature assets with the exact submenu hook.
	 * This avoids pages becoming unstyled when a legacy hook suffix changes.
	 *
	 * @param string $key Feature key.
	 */
	protected function enqueue_feature_assets( $key ) {
		$feature = JLWA_Feature_Registry::get( $key );
		if ( ! $feature ) {
			return;
		}
		$feature_hook = JLWA_MENU_SLUG . '_page_' . $feature['slug'];

		switch ( $key ) {
			case 'page-effects':
				if ( class_exists( 'JLWA_Page_Effects_Feature' ) ) {
					JLWA_Page_Effects_Feature::instance()->enqueue_admin_assets( $feature_hook );
				}
				break;
			case 'anti-debug':
				if ( class_exists( 'JLWA_Anti_Debug_Feature' ) ) {
					JLWA_Anti_Debug_Feature::instance()->enqueue_admin_assets( $feature_hook );
				}
				break;
			case 'ai-summary':
				if ( class_exists( 'JLWA_AI_Summary_Feature' ) ) {
					$plugin = JLWA_AI_Summary_Feature::instance();
					if ( isset( $plugin->admin ) && is_object( $plugin->admin ) ) {
						$plugin->admin->enqueue_assets( $feature_hook );
					}
				}
				break;
			case 'preloader':
				if ( class_exists( 'JIP_Admin' ) ) {
					JIP_Admin::instance()->enqueue_assets( $feature_hook );
				}
				break;
			case 'download-page':
				if ( class_exists( 'JLWA_Download_Page_Feature' ) ) {
					JLWA_Download_Page_Feature::instance()->enqueue_admin_assets( $feature_hook );
				}
				break;
			case 'media-urls':
				if ( class_exists( 'JRMU_Admin' ) ) {
					JRMU_Admin::instance()->enqueue_assets( $feature_hook );
				}
				break;
		}
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
		$ready     = 0;
		$enabled   = 0;
		$attention = array();
		$states    = array();
		foreach ( $features as $key => $feature ) {
			$states[ $key ] = JLWA_Feature_Registry::state( $key );
			if ( ! empty( $statuses[ $key ]['loaded'] ) ) {
				++$ready;
			}
			if ( ! empty( $states[ $key ]['enabled'] ) && ! empty( $states[ $key ]['ready'] ) ) {
				++$enabled;
			}
			if ( empty( $states[ $key ]['ready'] ) ) {
				$attention[ $key ] = $states[ $key ];
			}
		}
		$this->shell_start( 'dashboard', '控制台', '查看实际启用情况、依赖检查与快捷配置入口。' );
		?>
		<div id="jlwa-vue-dashboard" aria-label="Vue 3 功能总览"></div>
		<div class="jlwa-v3-dashboard-fallback">
		<section class="jlwa-hero jlwa-hero--overview">
			<div class="jlwa-hero__copy">
				<span class="jlwa-kicker">站点功能概况</span>
				<h2>先看运行状态，再配置功能</h2>
				<p>这里区分“模块已加载”“功能已启用”和“依赖未满足”，避免只看到绿色状态却不知道前台为什么没生效。</p>
				<div class="jlwa-hero__actions">
					<a class="button button-primary" href="#jlwa-feature-list">查看功能与下一步</a>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=jlwa-update-center' ) ); ?>">检查版本与更新</a>
				</div>
			</div>
			<div class="jlwa-hero__stats" aria-label="站点功能状态统计">
				<div><strong><?php echo esc_html( $enabled ); ?></strong><span>正常启用</span></div>
				<div><strong><?php echo esc_html( count( $attention ) ); ?></strong><span>需要关注</span></div>
				<div><strong><?php echo esc_html( $ready ); ?>/<?php echo esc_html( count( $features ) ); ?></strong><span>模块已加载</span></div>
			</div>
		</section>
		<section class="jlwa-attention" aria-labelledby="jlwa-attention-heading">
			<div class="jlwa-attention__heading"><div><span class="jlwa-kicker">配置指引</span><h2 id="jlwa-attention-heading">建议优先处理</h2></div><p>检测结果只基于当前配置及依赖，不会主动修改站点。</p></div>
			<?php if ( $attention ) : ?>
				<div class="jlwa-attention__items">
					<?php foreach ( $attention as $key => $state ) : ?>
						<?php $feature = $features[ $key ]; ?>
						<a class="jlwa-attention__item" href="<?php echo esc_url( $this->feature_url( $key, $feature ) ); ?>">
							<span class="dashicons dashicons-warning"></span><span><strong><?php echo esc_html( $feature['label'] . ' · ' . $state['label'] ); ?></strong><small><?php echo esc_html( $state['hint'] ); ?></small></span><span class="dashicons dashicons-arrow-right-alt2"></span>
						</a>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<p class="jlwa-attention__clear">没有发现阻断性配置问题。未启用的功能会保留关闭状态，可按需要逐个开启。</p>
			<?php endif; ?>
		</section>
		<div class="jlwa-section-title" id="jlwa-feature-list">
			<div><span>功能与下一步</span><h2>全部功能，逐项配置</h2></div>
			<p>查看当前实际启用情况，点击对应入口进入设置；不会因为模块已加载就标记为已启用。</p>
		</div>
		<div class="jlwa-feature-grid">
			<?php foreach ( $features as $key => $feature ) : ?>
				<?php
				$state  = $states[ $key ];
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
					<p class="jlwa-feature-card__next"><strong>下一步：</strong><?php echo esc_html( $this->feature_tip( $key, $state ) ); ?></p>
					<div class="jlwa-feature-card__footer">
						<span><?php echo esc_html( ! empty( $state['enabled'] ) ? '设置已开启' : '按需启用，不影响其它功能' ); ?></span>
						<?php if ( ! empty( $status['loaded'] ) ) : ?>
							<a class="button button-primary" href="<?php echo esc_url( $this->feature_url( $key, $feature ) ); ?>"><?php echo esc_html( empty( $state['ready'] ) ? '检查配置' : ( ! empty( $state['enabled'] ) ? '调整设置' : '启用或设置' ) ); ?></a>
						<?php else : ?>
							<a class="button" href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>">处理冲突</a>
						<?php endif; ?>
					</div>
				</article>
			<?php endforeach; ?>
		</div>
		</div><!-- /jlwa-v3-dashboard-fallback -->
		<?php
		$this->shell_end();
	}

	/** Help text reflects the actual state rather than marketing descriptions. */
	protected function feature_tip( $key, $state ) {
		if ( empty( $state['ready'] ) && ! empty( $state['hint'] ) ) {
			return $state['hint'];
		}
		$steps = array(
			'page-effects' => '先挑选一种效果，保存后用访客窗口查看效果。',
			'anti-debug' => '先检查保护范围和管理员绕过，再用未登录窗口验证。',
			'ai-summary' => '选择服务商并测试连接，再检查文章摘要的展示范围。',
			'preloader' => '选择开屏动画，注意移动端和减少动态效果设置。',
			'download-page' => '确认子比下载接口兼容，再选择资源页模板。',
			'media-urls' => '先运行诊断和扫描预览，确认后再执行永久修改。',
			'anti-scraping' => '默认关闭；开启前确认区块编辑器与 REST 接口兼容性。',
		);
		return isset( $steps[ $key ] ) ? $steps[ $key ] : '打开设置并检查前端效果。';
	}

	/** Direct a broken feature to the tab that can actually fix it. */
	protected function feature_url( $key, $feature ) {
		$url = admin_url( 'admin.php?page=' . $feature['slug'] );
		if ( 'ai-summary' === $key ) {
			$state = JLWA_Feature_Registry::state( $key );
			if ( '待配置密钥' === $state['label'] ) {
				$url = add_query_arg( 'tab', 'api', $url );
			}
		}
		return $url;
	}

	public function render_page_effects() { $this->render_feature( 'page-effects' ); }
	public function render_anti_debug() { $this->render_feature( 'anti-debug' ); }
	public function render_ai_summary() { $this->render_feature( 'ai-summary' ); }
	public function render_preloader() { $this->render_feature( 'preloader' ); }
	public function render_media_urls() { $this->render_feature( 'media-urls' ); }
	public function render_download_page() { $this->render_feature( 'download-page' ); }
	public function render_login_security() { $this->render_feature( 'login-security' ); }
	public function render_anti_scraping() { $this->render_feature( 'anti-scraping' ); }

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
			$state = JLWA_Feature_Registry::state( $key );
			echo '<section class="jlwa-feature-intro jlwa-feature-intro--' . esc_attr( $key ) . '">';
			echo '<span class="jlwa-feature-intro__icon"><span class="dashicons ' . esc_attr( $feature['icon'] ) . '"></span></span>';
			echo '<div><h2>' . esc_html( $feature['label'] ) . '设置中心</h2><p>所有设置都属于九流WP助手本体，保存后继续沿用原有数据，不需要重复配置。</p></div>';
			echo '<div class="jlwa-feature-intro__meta"><span class="jlwa-feature-intro__state is-' . esc_attr( $state['tone'] ) . '">' . esc_html( $state['label'] ) . '</span></div>';
			echo '</section>';
			echo '<div class="jlwa-feature-canvas"><div class="jlwa-feature-host jlwa-feature-host--' . esc_attr( $key ) . '">';
			JLWA_Feature_Registry::render_admin( $key );
			echo '</div></div>';
		}
		$this->shell_end();
	}

	public function render_update_center() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		$this->shell_start( 'system', '系统与更新', '检查完整性、查看主插件版本，并从唯一主仓库安全更新。' );
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
				<h2>统一版本管理</h2>
				<div class="jlwa-system-version"><span class="dashicons dashicons-admin-plugins"></span><div><strong>九流WP助手</strong><span>v<?php echo esc_html( JLWA_VERSION ); ?></span></div></div>
				<div class="jlwa-system-tip"><strong>功能域策略</strong><p>所有功能共用主插件版本，安装、更新与恢复均按完整插件包进行。</p></div>
				<div class="jlwa-system-tip"><strong>数据策略</strong><p>插件升级设计为保留设置、文章和 AI 缓存；正式站点操作前仍建议备份，失败恢复也依赖服务器文件系统状态。</p></div>
				<div class="jlwa-system-tip"><strong>无法在线检查版本？</strong><p>请检查服务器到 <code>api.github.com</code> 的 DNS、HTTPS 与代理设置；也可从 <a href="https://github.com/nljie1103/wp-assistant/releases" target="_blank" rel="noopener noreferrer">GitHub 官方发布页</a> 获取经过校验的 ZIP。</p></div>
			</aside>
		</div>
		<?php
		$this->shell_end();
	}

	/** @param string $active Active key. @param string $title Title. @param string $subtitle Subtitle. */
	protected function shell_start( $active, $title, $subtitle ) {
		?>
		<div class="wrap jlwa-app jlwa-v3 jlwa-app--<?php echo esc_attr( $active ); ?>">
			<div id="jlwa-vue-topnav">
				<nav class="jlwa-v3-fallback-nav" aria-label="九流WP助手功能导航">
					<strong>九流 WP 助手</strong>
					<?php $this->render_nav_item( 'dashboard', JLWA_MENU_SLUG, '控制台', 'dashicons-dashboard', $active ); ?>
					<?php foreach ( JLWA_Feature_Registry::features() as $key => $feature ) : ?>
						<?php $this->render_nav_item( $key, $feature['slug'], $feature['short_label'], $feature['icon'], $active ); ?>
					<?php endforeach; ?>
					<?php $this->render_nav_item( 'system', 'jlwa-update-center', '系统与更新', 'dashicons-update-alt', $active ); ?>
				</nav>
			</div>
			<div class="jlwa-v3-workspace">
				<div id="jlwa-vue-toolbar"></div>
				<div id="jlwa-vue-dialog-host"></div>
				<header class="jlwa-page-heading jlwa-v3-fallback-heading">
					<div><span>JIULIU WORKSPACE</span><h1><?php echo esc_html( $title ); ?></h1><p><?php echo esc_html( $subtitle ); ?></p></div>
				</header>
				<main class="jlwa-main" id="jlwa-main-content">
		<?php
	}

	protected function shell_end() { echo '</main></div></div>'; }

	/** @param string $key Key. @param string $slug Slug. @param string $label Label. @param string $icon Icon. @param string $active Active. */
	protected function render_nav_item( $key, $slug, $label, $icon, $active ) {
		$class = 'jlwa-appnav__item' . ( $key === $active ? ' is-active' : '' );
		echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( admin_url( 'admin.php?page=' . $slug ) ) . '" title="' . esc_attr( $label ) . '"' . ( $key === $active ? ' aria-current="page"' : '' ) . '><span class="dashicons ' . esc_attr( $icon ) . '" aria-hidden="true"></span><span>' . esc_html( $label ) . '</span></a>';
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
