<?php
/**
 * Module loader for 九流WP助手.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JLWA_Module_Loader {
	/**
	 * Module load status.
	 *
	 * @var array
	 */
	protected static $statuses = array();

	/**
	 * Module definitions.
	 *
	 * @return array
	 */
	public static function modules() {
		return array(
			'page-effects' => array(
				'label'       => '页面美化',
				'slug'        => 'jlwa-page-effects',
				'file'        => JLWA_PLUGIN_DIR . 'modules/page-effects/wp-page-effects.php',
				'class'       => 'XJPE_Plugin',
				'version'     => '1.5.1',
				'repo'        => 'https://github.com/nljie1103/wp-page-effects',
				'standalone'  => array(
					'wp-page-effects/wp-page-effects.php',
					'xiaojie-page-effects/xiaojie-page-effects.php',
				),
			),
			'relative-media-urls' => array(
				'label'       => '媒体相对地址',
				'slug'        => 'jlwa-relative-media-urls',
				'file'        => JLWA_PLUGIN_DIR . 'modules/relative-media-urls/jiuliu-relative-media-urls.php',
				'class'       => 'Jiuliu_Relative_Media_Urls',
				'constant'    => 'JRMU_VERSION',
				'version'     => '4.1.1',
				'repo'        => 'https://github.com/nljie1103/wp-relative-media-urls',
				'standalone'  => array(
					'wp-relative-media-urls/jiuliu-relative-media-urls.php',
					'jiuliu-relative-media-urls/jiuliu-relative-media-urls.php',
				),
			),
			'ai-article-summary' => array(
				'label'       => 'AI 文章摘要',
				'slug'        => 'wpaias-settings',
				'file'        => JLWA_PLUGIN_DIR . 'modules/ai-article-summary/wp-ai-article-summary.php',
				'class'       => 'WPAIAS_Plugin',
				'constant'    => 'WPAIAS_VERSION',
				'version'     => '1.0.9',
				'repo'        => 'https://github.com/nljie1103/WP-AI-Article-Summary',
				'standalone'  => array(
					'WP-AI-Article-Summary/wp-ai-article-summary.php',
					'wp-ai-article-summary/wp-ai-article-summary.php',
				),
			),
			'immersive-preloader' => array(
				'label'       => '沉浸式预加载',
				'slug'        => 'jlwa-immersive-preloader',
				'file'        => JLWA_PLUGIN_DIR . 'modules/immersive-preloader/jiuliu-immersive-preloader.php',
				'class'       => 'Jiuliu_Immersive_Preloader',
				'constant'    => 'JIP_VERSION',
				'version'     => '1.0.6',
				'repo'        => 'https://github.com/nljie1103/wp-immersive-preloader',
				'standalone'  => array(
					'wp-immersive-preloader/jiuliu-immersive-preloader.php',
					'jiuliu-immersive-preloader/jiuliu-immersive-preloader.php',
				),
			),
		);
	}

	/**
	 * Load bundled modules.
	 */
	public static function load_modules() {
		foreach ( self::modules() as $key => $module ) {
			self::$statuses[ $key ] = self::load_module( $key, $module );
		}
	}

	/**
	 * Get module statuses.
	 *
	 * @return array
	 */
	public static function statuses() {
		return self::$statuses;
	}

	/**
	 * Activate bundled module defaults.
	 */
	public static function activate() {
		if ( ! empty( self::$statuses['page-effects']['loaded'] ) && class_exists( 'XJPE_Plugin' ) ) {
			XJPE_Plugin::activate();
		}

		if ( ! empty( self::$statuses['ai-article-summary']['loaded'] ) && class_exists( 'WPAIAS_Plugin' ) && defined( 'WPAIAS_OPTION_KEY' ) ) {
			$current  = get_option( WPAIAS_OPTION_KEY, array() );
			$current  = is_array( $current ) ? $current : array();
			$defaults = WPAIAS_Plugin::get_default_settings();
			update_option( WPAIAS_OPTION_KEY, wp_parse_args( $current, $defaults ) );
		}

		if ( ! empty( self::$statuses['relative-media-urls']['loaded'] ) && class_exists( 'Jiuliu_Relative_Media_Urls' ) ) {
			Jiuliu_Relative_Media_Urls::instance()->on_activate();
		}

		if ( ! empty( self::$statuses['immersive-preloader']['loaded'] ) && class_exists( 'Jiuliu_Immersive_Preloader' ) ) {
			Jiuliu_Immersive_Preloader::instance()->on_activate();
		}
	}

	/**
	 * Load a single module.
	 *
	 * @param string $key Module key.
	 * @param array  $module Module definition.
	 * @return array
	 */
	protected static function load_module( $key, $module ) {
		if ( self::has_active_standalone( $module ) ) {
			return array(
				'loaded'  => false,
				'status'  => 'conflict',
				'message' => '检测到旧独立插件已启用，请停用独立版后再使用套件模块。',
			);
		}

		if ( ! empty( $module['class'] ) && class_exists( $module['class'], false ) ) {
			return array(
				'loaded'  => false,
				'status'  => 'conflict',
				'message' => '检测到同名 PHP 类已存在，已跳过该模块以避免冲突。',
			);
		}

		if ( ! empty( $module['constant'] ) && defined( $module['constant'] ) ) {
			return array(
				'loaded'  => false,
				'status'  => 'conflict',
				'message' => '检测到同名模块常量已存在，已跳过该模块以避免冲突。',
			);
		}

		if ( empty( $module['file'] ) || ! file_exists( $module['file'] ) ) {
			return array(
				'loaded'  => false,
				'status'  => 'missing',
				'message' => '模块文件不存在。',
			);
		}

		require_once $module['file'];

		return array(
			'loaded'  => true,
			'status'  => 'loaded',
			'message' => '已加载。',
		);
	}

	/**
	 * Whether a standalone plugin is active.
	 *
	 * @param array $module Module definition.
	 * @return bool
	 */
	protected static function has_active_standalone( $module ) {
		$active = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$network_active = array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) );
			$active         = array_merge( $active, $network_active );
		}

		foreach ( (array) $module['standalone'] as $basename ) {
			if ( in_array( $basename, $active, true ) ) {
				return true;
			}
		}

		return false;
	}
}
