<?php
/**
 * 卡片外观预设样式管理
 *
 * 每个预设包含 5 种核心颜色（bg / border / title / text / accent）
 * 加上一个可选的装饰 class（实现玻璃磨砂 / 渐变 / 霓虹 / 笔记本横线 等特殊效果）。
 *
 * @package WP_AI_Article_Summary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPAIAS_Styles
 */
class WPAIAS_Styles {

	/**
	 * 所有预设。
	 *
	 * @return array
	 */
	public static function presets() {
		return array(
			'dark-minimal'   => array(
				'label'  => __( '深色极简（默认）', 'wp-ai-article-summary' ),
				'class'  => '',
				'colors' => array(
					'bg'     => '#1a1a1a',
					'border' => '#333333',
					'title'  => '#ffffff',
					'text'   => '#cccccc',
					'accent' => '#ffd95a',
				),
			),
			'light-minimal'  => array(
				'label'  => __( '浅色极简', 'wp-ai-article-summary' ),
				'class'  => '',
				'colors' => array(
					'bg'     => '#ffffff',
					'border' => '#e6e6e6',
					'title'  => '#111111',
					'text'   => '#444444',
					'accent' => '#2271b1',
				),
			),
			'glass'          => array(
				'label'  => __( '玻璃磨砂', 'wp-ai-article-summary' ),
				'class'  => 'wpaias-style-glass',
				'colors' => array(
					'bg'     => '#ffffff',
					'border' => '#e6e6e6',
					'title'  => '#1a1a1a',
					'text'   => '#333333',
					'accent' => '#6366f1',
				),
			),
			'gradient-blue'  => array(
				'label'  => __( '蓝紫渐变', 'wp-ai-article-summary' ),
				'class'  => 'wpaias-style-gradient-blue',
				'colors' => array(
					'bg'     => '#1e3a8a',
					'border' => '#3b82f6',
					'title'  => '#ffffff',
					'text'   => '#e0e7ff',
					'accent' => '#fbbf24',
				),
			),
			'gradient-pink'  => array(
				'label'  => __( '粉橙渐变', 'wp-ai-article-summary' ),
				'class'  => 'wpaias-style-gradient-pink',
				'colors' => array(
					'bg'     => '#fb7185',
					'border' => '#f43f5e',
					'title'  => '#ffffff',
					'text'   => '#fce7f3',
					'accent' => '#fde68a',
				),
			),
			'gradient-cyan'  => array(
				'label'  => __( '青绿渐变', 'wp-ai-article-summary' ),
				'class'  => 'wpaias-style-gradient-cyan',
				'colors' => array(
					'bg'     => '#0891b2',
					'border' => '#06b6d4',
					'title'  => '#ffffff',
					'text'   => '#cffafe',
					'accent' => '#fef08a',
				),
			),
			'outline'        => array(
				'label'  => __( '细描边（透明）', 'wp-ai-article-summary' ),
				'class'  => 'wpaias-style-outline',
				'colors' => array(
					'bg'     => '#ffffff',
					'border' => '#2271b1',
					'title'  => '#2271b1',
					'text'   => '#444444',
					'accent' => '#2271b1',
				),
			),
			'paper'          => array(
				'label'  => __( '米色纸张', 'wp-ai-article-summary' ),
				'class'  => 'wpaias-style-paper',
				'colors' => array(
					'bg'     => '#fdf6e3',
					'border' => '#d4c69a',
					'title'  => '#5a4a1a',
					'text'   => '#3a2e10',
					'accent' => '#a3781e',
				),
			),
			'neon-cyber'     => array(
				'label'  => __( '赛博朋克霓虹', 'wp-ai-article-summary' ),
				'class'  => 'wpaias-style-neon-cyber',
				'colors' => array(
					'bg'     => '#0a0a14',
					'border' => '#00ffff',
					'title'  => '#00ffff',
					'text'   => '#a5f3fc',
					'accent' => '#ff00ff',
				),
			),
			'notebook'       => array(
				'label'  => __( '笔记本横线', 'wp-ai-article-summary' ),
				'class'  => 'wpaias-style-notebook',
				'colors' => array(
					'bg'     => '#fffef5',
					'border' => '#e3d3a9',
					'title'  => '#5a3a2a',
					'text'   => '#3a2a1a',
					'accent' => '#c0392b',
				),
			),
			'card-shadow'    => array(
				'label'  => __( '白卡浮起阴影', 'wp-ai-article-summary' ),
				'class'  => 'wpaias-style-card-shadow',
				'colors' => array(
					'bg'     => '#ffffff',
					'border' => '#f0f0f0',
					'title'  => '#1a1a1a',
					'text'   => '#555555',
					'accent' => '#7c3aed',
				),
			),
			'forest'         => array(
				'label'  => __( '森系绿', 'wp-ai-article-summary' ),
				'class'  => '',
				'colors' => array(
					'bg'     => '#f0fdf4',
					'border' => '#bbf7d0',
					'title'  => '#14532d',
					'text'   => '#15803d',
					'accent' => '#22c55e',
				),
			),
			'sunset'         => array(
				'label'  => __( '日落橙', 'wp-ai-article-summary' ),
				'class'  => '',
				'colors' => array(
					'bg'     => '#fff7ed',
					'border' => '#fed7aa',
					'title'  => '#7c2d12',
					'text'   => '#9a3412',
					'accent' => '#f97316',
				),
			),
			'lavender'       => array(
				'label'  => __( '薰衣草', 'wp-ai-article-summary' ),
				'class'  => '',
				'colors' => array(
					'bg'     => '#faf5ff',
					'border' => '#e9d5ff',
					'title'  => '#581c87',
					'text'   => '#6b21a8',
					'accent' => '#a855f7',
				),
			),
			'midnight-blue'  => array(
				'label'  => __( '午夜蓝', 'wp-ai-article-summary' ),
				'class'  => '',
				'colors' => array(
					'bg'     => '#0f172a',
					'border' => '#1e293b',
					'title'  => '#e2e8f0',
					'text'   => '#94a3b8',
					'accent' => '#60a5fa',
				),
			),
			'custom'         => array(
				'label'  => __( '完全自定义（清空预设）', 'wp-ai-article-summary' ),
				'class'  => '',
				'colors' => array(
					'bg'     => '#1a1a1a',
					'border' => '#333333',
					'title'  => '#ffffff',
					'text'   => '#cccccc',
					'accent' => '#ffd95a',
				),
			),
		);
	}

	/**
	 * 获取某个预设；不存在时返回默认。
	 *
	 * @param string $key 预设 key。
	 * @return array
	 */
	public static function get( $key ) {
		$presets = self::presets();
		if ( isset( $presets[ $key ] ) ) {
			return $presets[ $key ];
		}
		return $presets['dark-minimal'];
	}

	/**
	 * 给 JS 用的扁平化数据。
	 *
	 * @return array
	 */
	public static function js_map() {
		$out = array();
		foreach ( self::presets() as $key => $preset ) {
			$out[ $key ] = array(
				'label'  => $preset['label'],
				'class'  => $preset['class'],
				'colors' => $preset['colors'],
			);
		}
		return $out;
	}

	/**
	 * 根据用户设置生成应用到 .wpaias-summary 的内联 CSS 变量。
	 *
	 * @param array $settings 已合并默认的设置数组。
	 * @return string 形如 "--wpaias-bg:#xxx;--wpaias-border:#xxx;..."
	 */
	public static function build_inline_vars( $settings ) {
		$keys = array( 'bg', 'border', 'title', 'text', 'accent' );
		$css  = array();
		foreach ( $keys as $k ) {
			$opt = 'color_' . $k;
			if ( ! empty( $settings[ $opt ] ) ) {
				$css[] = '--wpaias-' . $k . ':' . self::sanitize_color( $settings[ $opt ] );
			}
		}
		return implode( ';', $css );
	}

	/**
	 * 颜色 sanitize：允许 #rgb / #rrggbb / #rrggbbaa / rgba(...) / transparent。
	 *
	 * @param string $c 输入。
	 * @return string
	 */
	public static function sanitize_color( $c ) {
		$c = trim( (string) $c );
		if ( '' === $c ) {
			return '';
		}
		if ( 'transparent' === strtolower( $c ) ) {
			return 'transparent';
		}
		if ( preg_match( '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{4}|[A-Fa-f0-9]{6}|[A-Fa-f0-9]{8})$/', $c ) ) {
			return $c;
		}
		if ( preg_match( '/^rgba?\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*(,\s*[0-9.]+\s*)?\)$/', $c ) ) {
			return $c;
		}
		return '';
	}

	/**
	 * 获取预设对应的装饰 class（基于 settings 的 card_style）。
	 *
	 * @param array $settings 设置。
	 * @return string
	 */
	public static function get_decoration_class( $settings ) {
		$key = isset( $settings['card_style'] ) ? $settings['card_style'] : 'dark-minimal';
		$p   = self::get( $key );
		return ! empty( $p['class'] ) ? $p['class'] : '';
	}
}
