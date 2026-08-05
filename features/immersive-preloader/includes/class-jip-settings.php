<?php
/**
 * 插件设置类
 *
 * 负责默认值、读取、清洗等通用逻辑。
 *
 * @package JiuliuImmersivePreloader
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class JIP_Settings
 */
class JIP_Settings {

	/**
	 * 内置效果列表。
	 *
	 * @return array
	 */
	public static function get_effects() {
		return array(
			'logo3d'    => array(
				'label'       => '立体 Logo 开场 + 动态蒙版 + 文字弹入 + 荧光扫描',
				'description' => '核心效果，最高优先级。3D 立体边框 + 渐变旋转 + 蒙版 Logo + 文字弹入 + 荧光扫描线。',
				'preview'     => 'assets/previews/preview-logo3d.svg',
			),
			'particles' => array(
				'label'       => '粒子汇聚 Logo 入场',
				'description' => '粒子从四面八方汇聚形成 Logo 形状。',
				'preview'     => 'assets/previews/preview-particles.svg',
			),
			'gradient'  => array(
				'label'       => '渐变色彩穿梭开屏',
				'description' => '多彩渐变光带高速穿梭，最终汇聚为站点名。',
				'preview'     => 'assets/previews/preview-gradient.svg',
			),
			'lines'     => array(
				'label'       => '简约线条勾勒 Logo',
				'description' => 'SVG 描边线条逐步绘制出 Logo。',
				'preview'     => 'assets/previews/preview-lines.svg',
			),
			'glass'     => array(
				'label'       => '玻璃质感边框旋转',
				'description' => '毛玻璃质感方块边框 360° 旋转。',
				'preview'     => 'assets/previews/preview-glass.svg',
			),
			'orbit'     => array(
				'label'       => '星轨环绕沉浸开场',
				'description' => '多层轨道环绕 Logo，纯 CSS 动画，性能开销低。',
				'preview'     => 'assets/previews/preview-orbit.svg',
			),
			'shutters'  => array(
				'label'       => '电影快门揭幕',
				'description' => '上下双层幕布向外揭开，适合品牌首页。',
				'preview'     => 'assets/previews/preview-shutters.svg',
			),
			'ink'       => array(
				'label'       => '水墨晕染开屏',
				'description' => '柔和墨色扩散与 Logo 浮现，减少动态模式会自动简化。',
				'preview'     => 'assets/previews/preview-ink.svg',
			),
		);
	}

	/**
	 * 获取默认配置。
	 *
	 * @return array
	 */
	public static function get_defaults() {
		return array(
			'enabled'          => 0,
			'min_duration'     => 0.45,
			'max_duration'     => 3,
			'completion'       => 'critical',
			'mobile_enable'    => 1,
			'reduce_motion'    => 1,
			'skip_save_data'   => 1,
			'show_progress'    => 1,
			'status_text'      => '正在准备页面…',
			'effect'           => 'logo3d',
			'logo_id'          => 0,
			'logo_url'         => '',
			'logo_size'        => 80,
			'bg_color'         => '#000000',
			'show_site_title'  => 1,
			'allow_skip'       => 1,
			'home_only'        => 1,
			'once_per_session' => 1, // 同一浏览器标签会话内只显示一次
		);
	}

	/**
	 * 获取当前设置（与默认值合并）。
	 *
	 * @return array
	 */
	public static function get_options() {
		$opts = get_option( JIP_OPTION_KEY, array() );
		if ( ! is_array( $opts ) ) {
			$opts = array();
		}
		return wp_parse_args( $opts, self::get_defaults() );
	}

	/**
	 * 清洗输入。
	 *
	 * @param array $input 用户输入。
	 * @return array
	 */
	public static function sanitize( $input ) {
		$defaults = self::get_defaults();
		$effects  = array_keys( self::get_effects() );
		$output   = array();

		$output['enabled']         = ! empty( $input['enabled'] ) ? 1 : 0;
		// 时长不再硬性限制，仅做基本合法性校验：>=0 即可。
		$output['min_duration']    = isset( $input['min_duration'] ) ? max( 0, min( 10, floatval( $input['min_duration'] ) ) ) : $defaults['min_duration'];
		$output['max_duration']    = isset( $input['max_duration'] ) ? max( 0.5, min( 15, floatval( $input['max_duration'] ) ) ) : $defaults['max_duration'];
		if ( $output['max_duration'] < $output['min_duration'] ) {
			$output['max_duration'] = $output['min_duration'] + 0.5;
		}
		$completion = isset( $input['completion'] ) ? sanitize_key( $input['completion'] ) : $defaults['completion'];
		$output['completion']      = in_array( $completion, array( 'dom', 'paint', 'critical', 'load' ), true ) ? $completion : 'dom';
		$output['mobile_enable']   = ! empty( $input['mobile_enable'] ) ? 1 : 0;
		$output['reduce_motion']   = ! empty( $input['reduce_motion'] ) ? 1 : 0;
		$output['skip_save_data']  = ! empty( $input['skip_save_data'] ) ? 1 : 0;
		$output['show_progress']   = ! empty( $input['show_progress'] ) ? 1 : 0;
		$output['status_text']     = sanitize_text_field( $input['status_text'] ?? $defaults['status_text'] );
		$output['effect']          = ( isset( $input['effect'] ) && in_array( $input['effect'], $effects, true ) ) ? $input['effect'] : $defaults['effect'];
		$output['logo_id']         = isset( $input['logo_id'] ) ? absint( $input['logo_id'] ) : 0;
		$output['logo_url']        = '';
		if ( $output['logo_id'] > 0 ) {
			$url = wp_get_attachment_url( $output['logo_id'] );
			if ( $url ) {
				$output['logo_url'] = esc_url_raw( $url );
			}
		} elseif ( ! empty( $input['logo_url'] ) ) {
			$output['logo_url'] = esc_url_raw( $input['logo_url'] );
		}
		$output['logo_size']       = isset( $input['logo_size'] ) ? max( 30, min( 100, intval( $input['logo_size'] ) ) ) : $defaults['logo_size'];
		$output['bg_color']        = isset( $input['bg_color'] ) ? sanitize_hex_color( $input['bg_color'] ) : $defaults['bg_color'];
		if ( empty( $output['bg_color'] ) ) {
			$output['bg_color'] = $defaults['bg_color'];
		}
		$output['show_site_title']  = ! empty( $input['show_site_title'] ) ? 1 : 0;
		$output['allow_skip']       = ! empty( $input['allow_skip'] ) ? 1 : 0;
		$output['home_only']        = ! empty( $input['home_only'] ) ? 1 : 0;
		$output['once_per_session'] = ! empty( $input['once_per_session'] ) ? 1 : 0;

		return $output;
	}
}
