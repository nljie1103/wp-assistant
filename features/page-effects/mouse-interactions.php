<?php
/**
 * Mouse interaction center for the Page Effects feature.
 *
 * Adds three independent systems without changing theme files:
 * - custom cursor shapes;
 * - pointer trails;
 * - click burst particles.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'JLWA_Mouse_Interactions' ) ) {
	final class JLWA_Mouse_Interactions {
		const OPTION_NAME = 'jlwa_mouse_interactions';
		const VERSION     = '1.0.0';

		/** @var JLWA_Mouse_Interactions|null */
		private static $instance = null;

		/** @return JLWA_Mouse_Interactions */
		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		private function __construct() {
			add_action( 'init', array( $this, 'maybe_migrate_legacy_cursor' ), 2 );
			add_action( 'admin_init', array( $this, 'save_with_page_effects_form' ), 1 );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ), 20 );
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ), 20 );
		}

		/** @return array<string,mixed> */
		public static function defaults() {
			return array(
				'cursor_shape' => array(
					'enabled'     => 0,
					'preset'      => 'neon_arrow',
					'size'        => 32,
					'link_variant'=> 1,
				),
				'trail' => array(
					'enabled'    => 0,
					'preset'     => 'star',
					'symbol'     => '✦',
					'color_mode' => 'rainbow',
					'color'      => '#ff5ba7',
					'size'       => 14,
					'density'    => 2,
					'duration'   => 760,
				),
				'click_burst' => array(
					'enabled'    => 0,
					'preset'     => 'stars',
					'symbol'     => '✦',
					'color_mode' => 'rainbow',
					'color'      => '#ffd166',
					'count'      => 14,
					'size'       => 18,
					'spread'     => 92,
					'duration'   => 780,
					'gravity'    => 0.55,
					'mobile'     => 0,
				),
			);
		}

		/** @return array<string,mixed> */
		private function options() {
			$saved = get_option( self::OPTION_NAME, array() );
			$saved = is_array( $saved ) ? $saved : array();
			return $this->deep_merge( self::defaults(), $saved );
		}

		/** @param array<string,mixed> $defaults @param array<string,mixed> $saved @return array<string,mixed> */
		private function deep_merge( $defaults, $saved ) {
			foreach ( $defaults as $key => $value ) {
				if ( is_array( $value ) ) {
					$saved[ $key ] = isset( $saved[ $key ] ) && is_array( $saved[ $key ] ) ? $this->deep_merge( $value, $saved[ $key ] ) : $value;
				} elseif ( ! array_key_exists( $key, $saved ) ) {
					$saved[ $key ] = $value;
				}
			}
			return $saved;
		}

		/** Migrate the old single “鼠标跟随” option once and disable the duplicate renderer. */
		public function maybe_migrate_legacy_cursor() {
			if ( false !== get_option( self::OPTION_NAME, false ) ) {
				return;
			}
			$options = self::defaults();
			$page_effects = get_option( 'xjpe_options', array() );
			if ( is_array( $page_effects ) && ! empty( $page_effects['effects']['cursor'] ) && is_array( $page_effects['effects']['cursor'] ) ) {
				$legacy = $page_effects['effects']['cursor'];
				$options['trail']['enabled']    = empty( $legacy['enabled'] ) ? 0 : 1;
				$options['trail']['preset']     = $this->sanitize_trail_preset( $legacy['preset'] ?? 'star' );
				$options['trail']['symbol']     = $this->short_symbol( $legacy['symbol'] ?? '✦' );
				$options['trail']['color_mode'] = in_array( $legacy['color_mode'] ?? '', array( 'rainbow', 'fixed' ), true ) ? $legacy['color_mode'] : 'rainbow';
				$options['trail']['color']      = sanitize_hex_color( $legacy['color'] ?? '#ff5ba7' ) ?: '#ff5ba7';
				$options['trail']['size']       = $this->bounded_int( $legacy['size'] ?? 14, 6, 48, 14 );
				$options['trail']['density']    = $this->bounded_int( $legacy['density'] ?? 2, 1, 8, 2 );
				$options['trail']['duration']   = $this->bounded_int( $legacy['duration'] ?? 760, 200, 2400, 760 );
				$page_effects['effects']['cursor']['enabled'] = 0;
				update_option( 'xjpe_options', $page_effects, false );
			}
			update_option( self::OPTION_NAME, $options, false );
		}

		/** Save fields that are injected inside the existing Page Effects form. */
		public function save_with_page_effects_form() {
			if ( ! is_admin() || empty( $_POST['xjpe_direct_save'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				return;
			}
			$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'jlwa-page-effects' !== $page || ! current_user_can( 'manage_options' ) ) {
				return;
			}
			check_admin_referer( 'xjpe_save_options', 'xjpe_nonce' );
			$raw = isset( $_POST[ self::OPTION_NAME ] ) ? wp_unslash( $_POST[ self::OPTION_NAME ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			update_option( self::OPTION_NAME, $this->sanitize( $raw ), false );

			// The original cursor renderer is replaced by this center. Prevent duplicate trails.
			if ( isset( $_POST['xjpe_options']['effects']['cursor'] ) ) {
				$_POST['xjpe_options']['effects']['cursor']['enabled'] = 0;
			}
		}

		/** @param mixed $raw @return array<string,mixed> */
		private function sanitize( $raw ) {
			$raw = is_array( $raw ) ? $raw : array();
			$out = self::defaults();
			$shape = isset( $raw['cursor_shape'] ) && is_array( $raw['cursor_shape'] ) ? $raw['cursor_shape'] : array();
			$trail = isset( $raw['trail'] ) && is_array( $raw['trail'] ) ? $raw['trail'] : array();
			$burst = isset( $raw['click_burst'] ) && is_array( $raw['click_burst'] ) ? $raw['click_burst'] : array();

			$out['cursor_shape']['enabled']      = empty( $shape['enabled'] ) ? 0 : 1;
			$out['cursor_shape']['preset']       = $this->sanitize_cursor_preset( $shape['preset'] ?? 'neon_arrow' );
			$out['cursor_shape']['size']         = $this->bounded_int( $shape['size'] ?? 32, 20, 48, 32 );
			$out['cursor_shape']['link_variant'] = empty( $shape['link_variant'] ) ? 0 : 1;

			$out['trail']['enabled']    = empty( $trail['enabled'] ) ? 0 : 1;
			$out['trail']['preset']     = $this->sanitize_trail_preset( $trail['preset'] ?? 'star' );
			$out['trail']['symbol']     = $this->short_symbol( $trail['symbol'] ?? '✦' );
			$out['trail']['color_mode'] = in_array( $trail['color_mode'] ?? '', array( 'rainbow', 'fixed' ), true ) ? $trail['color_mode'] : 'rainbow';
			$out['trail']['color']      = sanitize_hex_color( $trail['color'] ?? '#ff5ba7' ) ?: '#ff5ba7';
			$out['trail']['size']       = $this->bounded_int( $trail['size'] ?? 14, 6, 48, 14 );
			$out['trail']['density']    = $this->bounded_int( $trail['density'] ?? 2, 1, 8, 2 );
			$out['trail']['duration']   = $this->bounded_int( $trail['duration'] ?? 760, 200, 2400, 760 );

			$out['click_burst']['enabled']    = empty( $burst['enabled'] ) ? 0 : 1;
			$out['click_burst']['preset']     = $this->sanitize_burst_preset( $burst['preset'] ?? 'stars' );
			$out['click_burst']['symbol']     = $this->short_symbol( $burst['symbol'] ?? '✦' );
			$out['click_burst']['color_mode'] = in_array( $burst['color_mode'] ?? '', array( 'rainbow', 'fixed' ), true ) ? $burst['color_mode'] : 'rainbow';
			$out['click_burst']['color']      = sanitize_hex_color( $burst['color'] ?? '#ffd166' ) ?: '#ffd166';
			$out['click_burst']['count']      = $this->bounded_int( $burst['count'] ?? 14, 4, 36, 14 );
			$out['click_burst']['size']       = $this->bounded_int( $burst['size'] ?? 18, 8, 44, 18 );
			$out['click_burst']['spread']     = $this->bounded_int( $burst['spread'] ?? 92, 24, 180, 92 );
			$out['click_burst']['duration']   = $this->bounded_int( $burst['duration'] ?? 780, 280, 1800, 780 );
			$out['click_burst']['gravity']    = $this->bounded_float( $burst['gravity'] ?? 0.55, -1.5, 2.5, 0.55 );
			$out['click_burst']['mobile']     = empty( $burst['mobile'] ) ? 0 : 1;
			return $out;
		}

		/** @return string */
		private function sanitize_cursor_preset( $value ) {
			$value = sanitize_key( $value );
			$allowed = array( 'neon_arrow', 'web_hero', 'pixel_plumber', 'cat_paw', 'magic_wand', 'rocket', 'ghost', 'rainbow' );
			return in_array( $value, $allowed, true ) ? $value : 'neon_arrow';
		}

		/** @return string */
		private function sanitize_trail_preset( $value ) {
			$value = sanitize_key( $value );
			$allowed = array( 'star', 'heart', 'firefly', 'petal', 'bubble', 'comet', 'snow', 'music', 'rainbow', 'pixel', 'paw', 'web', 'custom' );
			return in_array( $value, $allowed, true ) ? $value : 'star';
		}

		/** @return string */
		private function sanitize_burst_preset( $value ) {
			$value = sanitize_key( $value );
			$allowed = array( 'stars', 'hearts', 'sparks', 'petals', 'snow', 'confetti', 'bubbles', 'music', 'paw', 'web', 'pixel', 'ripple', 'custom' );
			return in_array( $value, $allowed, true ) ? $value : 'stars';
		}

		/** @param mixed $value @return string */
		private function short_symbol( $value ) {
			$value = sanitize_text_field( $value );
			return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 4 ) : substr( $value, 0, 12 );
		}

		/** @param mixed $value @return int */
		private function bounded_int( $value, $min, $max, $fallback ) {
			$value = is_numeric( $value ) ? (int) $value : (int) $fallback;
			return max( $min, min( $max, $value ) );
		}

		/** @param mixed $value @return float */
		private function bounded_float( $value, $min, $max, $fallback ) {
			$value = is_numeric( $value ) ? (float) $value : (float) $fallback;
			return max( $min, min( $max, $value ) );
		}

		/** @param string $hook */
		public function enqueue_admin_assets( $hook ) {
			$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'jlwa-page-effects' !== $page ) {
				return;
			}
			wp_enqueue_style( 'jlwa-mouse-admin', JLWA_PLUGIN_URL . 'features/page-effects/assets/css/mouse-admin.css', array(), JLWA_VERSION );
			wp_enqueue_script( 'jlwa-mouse-admin', JLWA_PLUGIN_URL . 'features/page-effects/assets/js/mouse-admin.js', array(), JLWA_VERSION, true );
			wp_localize_script(
				'jlwa-mouse-admin',
				'JLWA_MOUSE_ADMIN',
				array(
					'optionName' => self::OPTION_NAME,
					'options'    => $this->options(),
				)
			);
		}

		public function enqueue_frontend_assets() {
			if ( is_admin() || wp_doing_ajax() ) {
				return;
			}
			$options = $this->options();
			if ( empty( $options['cursor_shape']['enabled'] ) && empty( $options['trail']['enabled'] ) && empty( $options['click_burst']['enabled'] ) ) {
				return;
			}
			wp_enqueue_style( 'jlwa-mouse-interactions', JLWA_PLUGIN_URL . 'features/page-effects/assets/css/mouse-interactions.css', array(), JLWA_VERSION );
			wp_enqueue_script( 'jlwa-mouse-interactions', JLWA_PLUGIN_URL . 'features/page-effects/assets/js/mouse-interactions.js', array(), JLWA_VERSION, true );
			wp_localize_script( 'jlwa-mouse-interactions', 'JLWA_MOUSE', $options );
		}
	}

	JLWA_Mouse_Interactions::instance();
}
