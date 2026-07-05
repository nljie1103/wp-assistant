<?php
/**
 * 插件主类（单例 + 默认配置）。
 *
 * @package WP_AI_Article_Summary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPAIAS_Plugin
 */
class WPAIAS_Plugin {

	/**
	 * 单例。
	 *
	 * @var WPAIAS_Plugin|null
	 */
	protected static $instance = null;

	/**
	 * 后台实例。
	 *
	 * @var WPAIAS_Admin
	 */
	public $admin;

	/**
	 * 前端实例。
	 *
	 * @var WPAIAS_Frontend
	 */
	public $frontend;

	/**
	 * 更新器实例。
	 *
	 * @var WPAIAS_Updater
	 */
	public $updater;

	/**
	 * 获取单例。
	 *
	 * @return WPAIAS_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * 启动。
	 */
	protected function boot() {
		load_plugin_textdomain( 'wp-ai-article-summary', false, dirname( WPAIAS_PLUGIN_BASENAME ) . '/languages' );

		if ( is_admin() ) {
			$this->admin = new WPAIAS_Admin();
			$this->admin->register();

			// 在线更新器（仅后台需要）。
			$this->updater = new WPAIAS_Updater();
			$this->updater->register();
		}

		$this->frontend = new WPAIAS_Frontend();
		$this->frontend->register();
	}

	/**
	 * 获取设置（合并默认值）。
	 *
	 * @return array
	 */
	public static function get_settings() {
		$saved   = get_option( WPAIAS_OPTION_KEY, array() );
		$defaults = self::get_default_settings();
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$settings = wp_parse_args( $saved, $defaults );
		unset( $settings['api_key'] );
		$settings['api_keys'] = self::sanitize_api_keys( isset( $settings['api_keys'] ) ? $settings['api_keys'] : array() );

		return $settings;
	}

	/**
	 * 生成 API Key 绑定槽位：服务商 + 模型。
	 *
	 * @param string $provider 服务商 key。
	 * @param string $model    模型名。
	 * @return string
	 */
	public static function api_key_slot( $provider, $model ) {
		$provider = sanitize_key( $provider );
		$model    = trim( (string) $model );

		if ( '' === $provider || '' === $model ) {
			return '';
		}

		return $provider . '::' . rawurlencode( $model );
	}

	/**
	 * 清洗按模型绑定的 API Key 列表。
	 *
	 * @param mixed $api_keys API Key 映射。
	 * @return array
	 */
	public static function sanitize_api_keys( $api_keys ) {
		$out = array();

		if ( ! is_array( $api_keys ) ) {
			return $out;
		}

		foreach ( $api_keys as $slot => $key ) {
			$slot = (string) $slot;
			if ( ! preg_match( '/^[a-z0-9_-]+::[A-Za-z0-9\-\._~%]+$/', $slot ) ) {
				continue;
			}

			if ( is_array( $key ) || is_object( $key ) ) {
				continue;
			}

			$key = str_replace( array( "\r", "\n" ), '', trim( (string) $key ) );
			if ( '' === $key ) {
				continue;
			}

			$out[ $slot ] = $key;
		}

		return $out;
	}

	/**
	 * 获取当前服务商/模型对应的 API Key。
	 *
	 * @param array  $settings   插件设置。
	 * @param string $provider   服务商 key。
	 * @param string $model      模型名。
	 * @return string
	 */
	public static function get_api_key_for_model( $settings, $provider = '', $model = '' ) {
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$provider = '' !== $provider ? sanitize_key( $provider ) : ( isset( $settings['provider'] ) ? sanitize_key( $settings['provider'] ) : 'openai' );
		if ( '' === trim( (string) $model ) ) {
			$model = ( 'custom' === $provider && ! empty( $settings['custom_model'] ) ) ? $settings['custom_model'] : ( isset( $settings['model'] ) ? $settings['model'] : '' );
		}

		$api_keys = self::sanitize_api_keys( isset( $settings['api_keys'] ) ? $settings['api_keys'] : array() );
		$slot     = self::api_key_slot( $provider, $model );
		if ( '' !== $slot && isset( $api_keys[ $slot ] ) ) {
			return $api_keys[ $slot ];
		}

		return '';
	}

	/**
	 * 默认设置。
	 *
	 * @return array
	 */
	public static function get_default_settings() {
		return array(
			// Tab1.
			'enabled'            => 1,
			'mobile_enable'      => 1,
			'title'              => 'AI 智能摘要',
			'word_limit'         => 150,
			'position'           => 'before_content',
			'post_types'         => array( 'post' ),
			'exclude_categories' => array(),
			'exclude_post_ids'   => '',

			// 注入模式（兼容各种主题）。
			'insert_method'      => 'auto',
			'js_selector'        => '.entry-content, .post-content, .article-content, .single-content, .article__content, .post__content, .post-single .post-content, article .content-area, article.post .content, main article .entry-content, main .post-content, #content article, .single .article-content, .typo, .single-content-wrap, .post .content',
			'js_position'        => 'prepend',

			// Tab2.
			'provider'           => 'openai',
			'model'              => 'gpt-5.4-nano',
			'custom_endpoint'    => '',
			'custom_model'       => '',
			'api_keys'           => array(),
			'temperature'        => 0.7,
			'max_tokens'         => 512,
			'prompt'             => '你是一位专业的中文文章编辑助手，请用简洁、客观、流畅的中文为以下文章生成一段摘要，字数控制在 {WORDS} 字以内，不要使用 Markdown 标记，不要重复标题，直接输出摘要正文：\n\n{CONTENT}',

			// Tab3.
			'animation'          => 'typewriter',
			'anim_duration'      => 800,
			'type_speed'         => 35,
			'anim_delay'         => 0,
			'cursor_enable'      => 1,
			'cursor_color'       => '#ffffff',
			'custom_css'         => '',

			// Tab Style — 卡片预设样式 + 5 颜色自定义。
			'card_style'         => 'dark-minimal',
			'color_bg'           => '#1a1a1a',
			'color_border'       => '#333333',
			'color_title'        => '#ffffff',
			'color_text'         => '#cccccc',
			'color_accent'       => '#ffd95a',

			// Tab4.
			'cache_ttl'          => 'forever',
		);
	}
}
