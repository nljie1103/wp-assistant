<?php
/**
 * Internal feature registry for 九流WP助手.
 *
 * The plugin has one lifecycle and one admin application. Individual features
 * only provide focused runtime services and settings screens.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JLWA_Feature_Registry {
	/** @var array<string,array<string,mixed>> */
	protected static $statuses = array();

	/** @var bool */
	protected static $booted = false;

	/**
	 * Feature definitions.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function features() {
		return array(
			'page-effects' => array(
				'label'       => '页面美化',
				'short_label' => '页面美化',
				'icon'        => 'dashicons-art',
				'slug'        => 'jlwa-page-effects',
				'version'     => '1.8.0',
				'entry_class' => 'JLWA_Page_Effects_Feature',
				'bootstrap'   => JLWA_PLUGIN_DIR . 'features/page-effects/bootstrap.php',
				'description' => '樱花、雪花、落叶、气泡、页脚波浪、鼠标轨迹、内容保护、复制版权与全站视觉特效。',
				'eyebrow'     => 'VISUAL EXPERIENCE',
				'standalone'  => array(
					'wp-page-effects/wp-page-effects.php',
					'xiaojie-page-effects/xiaojie-page-effects.php',
				),
			),

			'anti-debug' => array(
				'label'       => '反调试保护',
				'short_label' => '反调试',
				'icon'        => 'dashicons-shield-alt',
				'slug'        => 'jlwa-anti-debug',
				'version'     => '1.1.0',
				'entry_class' => 'JLWA_Anti_Debug_Feature',
				'bootstrap'   => JLWA_PLUGIN_DIR . 'features/anti-debug/bootstrap.php',
				'description' => '多探测器评分、四级可组合防御链、可选持续 debugger/刷新/关闭循环与匿名日志。',
				'eyebrow'     => 'ANTI DEBUG & ANALYSIS',
				'standalone'  => array(),
			),
			'ai-summary' => array(
				'label'       => 'AI 文章摘要',
				'short_label' => 'AI 摘要',
				'icon'        => 'dashicons-welcome-write-blog',
				'slug'        => 'wpaias-settings',
				'version'     => '1.1.0',
				'entry_class' => 'JLWA_AI_Summary_Feature',
				'bootstrap'   => JLWA_PLUGIN_DIR . 'features/ai-article-summary/bootstrap.php',
				'description' => '多服务商模型、安全摘要生成、文章元数据缓存、装饰图标、动画与额度保护。',
				'eyebrow'     => 'CONTENT INTELLIGENCE',
				'standalone'  => array(
					'WP-AI-Article-Summary/wp-ai-article-summary.php',
					'wp-ai-article-summary/wp-ai-article-summary.php',
				),
			),
			'preloader' => array(
				'label'       => '沉浸式预加载',
				'short_label' => '预加载',
				'icon'        => 'dashicons-image-rotate',
				'slug'        => 'jlwa-immersive-preloader',
				'version'     => '1.1.0',
				'entry_class' => 'JLWA_Immersive_Preloader_Feature',
				'bootstrap'   => JLWA_PLUGIN_DIR . 'features/immersive-preloader/bootstrap.php',
				'description' => '性能优先的沉浸开屏、自定义 Logo、真实完成策略、节流跳过与完整动画清理。',
				'eyebrow'     => 'LOADING EXPERIENCE',
				'standalone'  => array(
					'wp-immersive-preloader/jiuliu-immersive-preloader.php',
					'jiuliu-immersive-preloader/jiuliu-immersive-preloader.php',
				),
			),
			'media-urls' => array(
				'label'       => '媒体与链接',
				'short_label' => '媒体与链接',
				'icon'        => 'dashicons-admin-links',
				'slug'        => 'jlwa-relative-media-urls',
				'version'     => '4.2.0',
				'entry_class' => 'JLWA_Media_Urls_Feature',
				'bootstrap'   => JLWA_PLUGIN_DIR . 'features/relative-media-urls/bootstrap.php',
				'description' => '相对媒体地址、安全域名白名单、明确源站、反向代理诊断、扫描预览与修复。',
				'eyebrow'     => 'DELIVERY & ROUTING',
				'standalone'  => array(
					'wp-relative-media-urls/jiuliu-relative-media-urls.php',
					'jiuliu-relative-media-urls/jiuliu-relative-media-urls.php',
				),
			),
		);
	}

	/** Boot all internal features. */
	public static function boot() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		foreach ( self::features() as $key => $feature ) {
			self::$statuses[ $key ] = self::boot_feature( $feature );
		}
	}

	/** Activate defaults for every available feature. */
	public static function activate() {
		self::boot();
		if ( ! empty( self::$statuses['page-effects']['loaded'] ) && class_exists( 'JLWA_Page_Effects_Feature' ) ) {
			JLWA_Page_Effects_Feature::activate();
		}
		if ( ! empty( self::$statuses['anti-debug']['loaded'] ) && class_exists( 'JLWA_Anti_Debug_Feature' ) ) {
			JLWA_Anti_Debug_Feature::activate();
		}
		if ( ! empty( self::$statuses['ai-summary']['loaded'] ) && class_exists( 'JLWA_AI_Summary_Feature' ) ) {
			$current  = get_option( WPAIAS_OPTION_KEY, array() );
			$current  = is_array( $current ) ? $current : array();
			$defaults = JLWA_AI_Summary_Feature::get_default_settings();
			$merged   = wp_parse_args( $current, $defaults );
			unset( $merged['api_key'] );
			update_option( WPAIAS_OPTION_KEY, $merged );
		}
		if ( ! empty( self::$statuses['preloader']['loaded'] ) && class_exists( 'JLWA_Immersive_Preloader_Feature' ) ) {
			JLWA_Immersive_Preloader_Feature::instance()->on_activate();
		}
		if ( ! empty( self::$statuses['media-urls']['loaded'] ) && class_exists( 'JLWA_Media_Urls_Feature' ) ) {
			JLWA_Media_Urls_Feature::instance()->on_activate();
		}
		update_option( 'jlwa_schema_version', JLWA_VERSION, false );
	}

	/** @return array<string,array<string,mixed>> */
	public static function statuses() {
		return self::$statuses;
	}

	/** @param string $key Feature key. @return array<string,mixed>|null */
	public static function get( $key ) {
		$features = self::features();
		return isset( $features[ $key ] ) ? $features[ $key ] : null;
	}

	/** @param string $slug Page slug. @return string */
	public static function key_from_slug( $slug ) {
		foreach ( self::features() as $key => $feature ) {
			if ( $feature['slug'] === $slug ) {
				return $key;
			}
		}
		return '';
	}

	/** @param string $key Feature key. @return string */
	public static function version( $key ) {
		$feature = self::get( $key );
		if ( ! $feature ) {
			return '-';
		}
		switch ( $key ) {
			case 'page-effects':
				return class_exists( 'JLWA_Page_Effects_Feature', false ) ? (string) JLWA_Page_Effects_Feature::VERSION : $feature['version'];
			case 'anti-debug':
				return class_exists( 'JLWA_Anti_Debug_Feature', false ) ? (string) JLWA_Anti_Debug_Feature::VERSION : $feature['version'];
			case 'ai-summary':
				return defined( 'WPAIAS_VERSION' ) ? (string) WPAIAS_VERSION : $feature['version'];
			case 'preloader':
				return defined( 'JIP_VERSION' ) ? (string) JIP_VERSION : $feature['version'];
			case 'media-urls':
				return defined( 'JRMU_VERSION' ) ? (string) JRMU_VERSION : $feature['version'];
			default:
				return $feature['version'];
		}
	}

	/** @param string $key Feature key. */
	public static function render_admin( $key ) {
		switch ( $key ) {
			case 'page-effects':
				if ( class_exists( 'JLWA_Page_Effects_Feature' ) ) {
					JLWA_Page_Effects_Feature::instance()->render_admin_page();
				}
				break;
			case 'anti-debug':
				if ( class_exists( 'JLWA_Anti_Debug_Feature' ) ) {
					JLWA_Anti_Debug_Feature::instance()->render_admin_page();
				}
				break;
			case 'ai-summary':
				if ( class_exists( 'JLWA_AI_Summary_Feature' ) ) {
					$plugin = JLWA_AI_Summary_Feature::instance();
					if ( isset( $plugin->admin ) && is_object( $plugin->admin ) ) {
						$plugin->admin->render_settings_page();
					}
				}
				break;
			case 'preloader':
				if ( class_exists( 'JIP_Admin' ) ) {
					JIP_Admin::instance()->render_settings_page();
				}
				break;
			case 'media-urls':
				if ( class_exists( 'JRMU_Admin' ) ) {
					JRMU_Admin::instance()->render_page();
				}
				break;
		}
	}

	/** @param string $key Feature key. @return array<string,string|bool> */
	public static function state( $key ) {
		$status = isset( self::$statuses[ $key ] ) ? self::$statuses[ $key ] : array();
		if ( empty( $status['loaded'] ) ) {
			return array( 'ready' => false, 'label' => '需要处理', 'tone' => 'danger' );
		}
		$enabled = self::is_enabled( $key );
		return array( 'ready' => true, 'label' => $enabled ? '已启用' : '可用', 'tone' => $enabled ? 'success' : 'neutral' );
	}

	/** @param string $key Feature key. @return bool */
	protected static function is_enabled( $key ) {
		switch ( $key ) {
			case 'page-effects':
				$options = get_option( 'xjpe_options', array() );
				return ! empty( $options['global']['enabled'] );
			case 'anti-debug':
				$options = get_option( 'jlwa_anti_debug_options', array() );
				return ! empty( $options['enabled'] );
			case 'ai-summary':
				$options = get_option( 'wpaias_settings', array() );
				return ! empty( $options['enabled'] );
			case 'preloader':
				$options = get_option( 'jiuliu_immersive_preloader_options', array() );
				return ! empty( $options['enabled'] );
			case 'media-urls':
				$options = get_option( 'jiuliu_relative_media_urls_options', array() );
				foreach ( array( 'convert_existing_media_output', 'convert_future_media_output', 'convert_post_on_save', 'convert_post_on_frontend', 'domain_adaptation_enabled', 'canonical_enabled' ) as $setting ) {
					if ( ! empty( $options[ $setting ] ) ) {
						return true;
					}
				}
				return false;
		}
		return false;
	}

	/** @param array<string,mixed> $feature Feature definition. @return array<string,mixed> */
	protected static function boot_feature( $feature ) {
		$standalone = self::active_standalone( $feature );
		if ( $standalone ) {
			return array(
				'loaded'     => false,
				'status'     => 'conflict',
				'message'    => '旧独立插件仍在启用：' . $standalone . '。为避免重复输出，本功能暂未启动。',
				'standalone' => $standalone,
			);
		}
		if ( empty( $feature['bootstrap'] ) || ! is_readable( $feature['bootstrap'] ) ) {
			return array( 'loaded' => false, 'status' => 'missing', 'message' => '内部功能文件不存在或不可读。' );
		}
		try {
			require_once $feature['bootstrap'];
		} catch ( Throwable $exception ) {
			return array( 'loaded' => false, 'status' => 'error', 'message' => '功能启动失败：' . $exception->getMessage() );
		}
		if ( empty( $feature['entry_class'] ) || ! class_exists( $feature['entry_class'], false ) ) {
			return array( 'loaded' => false, 'status' => 'invalid', 'message' => '内部功能入口未正确注册。' );
		}
		return array( 'loaded' => true, 'status' => 'ready', 'message' => '功能已正常启动。' );
	}

	/** @param array<string,mixed> $feature Feature definition. @return string */
	protected static function active_standalone( $feature ) {
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		foreach ( (array) $feature['standalone'] as $basename ) {
			if ( in_array( $basename, $active, true ) ) {
				return $basename;
			}
		}
		return '';
	}
}
