<?php
/**
 * Module loader for 九流WP助手.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JLWA_Module_Loader {
	/** @var array<string,array<string,mixed>> */
	protected static $statuses = array();

	/**
	 * Bundled module definitions.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function modules() {
		return array(
			'page-effects' => array(
				'label'      => '页面美化',
				'icon'       => 'dashicons-art',
				'slug'       => 'jlwa-page-effects',
				'file'       => JLWA_PLUGIN_DIR . 'modules/page-effects/wp-page-effects.php',
				'class'      => 'XJPE_Plugin',
				'version'    => '1.6.0',
				'standalone' => array(
					'wp-page-effects/wp-page-effects.php',
					'xiaojie-page-effects/xiaojie-page-effects.php',
				),
			),
			'relative-media-urls' => array(
				'label'      => '媒体相对地址',
				'icon'       => 'dashicons-admin-links',
				'slug'       => 'jlwa-relative-media-urls',
				'file'       => JLWA_PLUGIN_DIR . 'modules/relative-media-urls/jiuliu-relative-media-urls.php',
				'class'      => 'Jiuliu_Relative_Media_Urls',
				'constant'   => 'JRMU_VERSION',
				'version'    => '4.1.1',
				'standalone' => array(
					'wp-relative-media-urls/jiuliu-relative-media-urls.php',
					'jiuliu-relative-media-urls/jiuliu-relative-media-urls.php',
				),
			),
			'ai-article-summary' => array(
				'label'      => 'AI 文章摘要',
				'icon'       => 'dashicons-welcome-write-blog',
				'slug'       => 'wpaias-settings',
				'file'       => JLWA_PLUGIN_DIR . 'modules/ai-article-summary/wp-ai-article-summary.php',
				'class'      => 'WPAIAS_Plugin',
				'constant'   => 'WPAIAS_VERSION',
				'version'    => '1.0.9',
				'standalone' => array(
					'WP-AI-Article-Summary/wp-ai-article-summary.php',
					'wp-ai-article-summary/wp-ai-article-summary.php',
				),
			),
			'immersive-preloader' => array(
				'label'      => '沉浸式预加载',
				'icon'       => 'dashicons-image-rotate',
				'slug'       => 'jlwa-immersive-preloader',
				'file'       => JLWA_PLUGIN_DIR . 'modules/immersive-preloader/jiuliu-immersive-preloader.php',
				'class'      => 'Jiuliu_Immersive_Preloader',
				'constant'   => 'JIP_VERSION',
				'version'    => '1.0.6',
				'standalone' => array(
					'wp-immersive-preloader/jiuliu-immersive-preloader.php',
					'jiuliu-immersive-preloader/jiuliu-immersive-preloader.php',
				),
			),
		);
	}

	/** Load all bundled modules. */
	public static function load_modules() {
		foreach ( self::modules() as $key => $module ) {
			self::$statuses[ $key ] = self::load_module( $module );
		}
	}

	/** @return array<string,array<string,mixed>> */
	public static function statuses() {
		return self::$statuses;
	}

	/**
	 * Return the runtime module version.
	 *
	 * @param string              $key Module key.
	 * @param array<string,mixed> $module Definition.
	 * @return string
	 */
	public static function module_version( $key, $module = array() ) {
		if ( empty( $module ) ) {
			$modules = self::modules();
			$module  = isset( $modules[ $key ] ) ? $modules[ $key ] : array();
		}

		if ( 'page-effects' === $key && class_exists( 'XJPE_Plugin', false ) ) {
			return (string) XJPE_Plugin::VERSION;
		}
		if ( ! empty( $module['constant'] ) && defined( $module['constant'] ) ) {
			return (string) constant( $module['constant'] );
		}
		return isset( $module['version'] ) ? (string) $module['version'] : '-';
	}

	/** Activate defaults for bundled modules. */
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
	 * Load one module and return a detailed status.
	 *
	 * @param array<string,mixed> $module Module definition.
	 * @return array<string,mixed>
	 */
	protected static function load_module( $module ) {
		$active_standalone = self::active_standalone( $module );
		if ( $active_standalone ) {
			return array(
				'loaded'     => false,
				'status'     => 'conflict',
				'message'    => '检测到独立版插件仍在启用：' . $active_standalone . '。请先停用独立版。',
				'standalone' => $active_standalone,
			);
		}

		if ( ! empty( $module['class'] ) && class_exists( $module['class'], false ) ) {
			return array(
				'loaded'  => false,
				'status'  => 'conflict',
				'message' => '同名 PHP 类已经存在，已跳过模块以避免致命冲突。',
			);
		}

		if ( ! empty( $module['constant'] ) && defined( $module['constant'] ) ) {
			return array(
				'loaded'  => false,
				'status'  => 'conflict',
				'message' => '同名版本常量已经存在，已跳过模块以避免冲突。',
			);
		}

		if ( empty( $module['file'] ) || ! is_readable( $module['file'] ) ) {
			return array(
				'loaded'  => false,
				'status'  => 'missing',
				'message' => '模块主文件不存在或不可读。',
			);
		}

		require_once $module['file'];

		if ( ! empty( $module['class'] ) && ! class_exists( $module['class'], false ) ) {
			return array(
				'loaded'  => false,
				'status'  => 'invalid',
				'message' => '模块文件已载入，但入口类未注册。',
			);
		}

		$version  = self::detect_version( $module );
		$expected = isset( $module['version'] ) ? (string) $module['version'] : '';
		$mismatch = '' !== $version && '' !== $expected && version_compare( $version, $expected, '!=' );

		return array(
			'loaded'           => true,
			'status'           => $mismatch ? 'version_mismatch' : 'loaded',
			'message'          => $mismatch ? '模块已加载，但运行版本与套件清单不一致。' : '模块已正常加载。',
			'actual_version'   => $version,
			'expected_version' => $expected,
		);
	}

	/** @param array<string,mixed> $module Module definition. */
	protected static function detect_version( $module ) {
		if ( isset( $module['class'] ) && 'XJPE_Plugin' === $module['class'] && class_exists( 'XJPE_Plugin', false ) ) {
			return (string) XJPE_Plugin::VERSION;
		}
		if ( ! empty( $module['constant'] ) && defined( $module['constant'] ) ) {
			return (string) constant( $module['constant'] );
		}
		return '';
	}

	/**
	 * Return the active standalone basename, or an empty string.
	 *
	 * @param array<string,mixed> $module Module definition.
	 * @return string
	 */
	protected static function active_standalone( $module ) {
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		foreach ( (array) $module['standalone'] as $basename ) {
			if ( in_array( $basename, $active, true ) ) {
				return $basename;
			}
		}
		return '';
	}
}
