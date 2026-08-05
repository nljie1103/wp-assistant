<?php
/**
 * Reliable AI summary entrance animations.
 *
 * Loaded after the original frontend assets. The companion script takes over
 * animation playback while keeping the original renderer and AJAX flow intact.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'wp_enqueue_scripts',
	static function () {
		if ( ! wp_script_is( 'wpaias-frontend', 'enqueued' ) ) {
			return;
		}

		wp_enqueue_style(
			'wpaias-animation-fix',
			WPAIAS_PLUGIN_URL . 'assets/css/animation-fix.css',
			array(),
			defined( 'JLWA_VERSION' ) ? JLWA_VERSION : WPAIAS_VERSION
		);

		wp_enqueue_script(
			'wpaias-animation-fix',
			WPAIAS_PLUGIN_URL . 'assets/js/animation-fix.js',
			array( 'wpaias-frontend' ),
			defined( 'JLWA_VERSION' ) ? JLWA_VERSION : WPAIAS_VERSION,
			true
		);
	},
	100
);
