<?php
/**
 * Page Effects admin save compatibility fix.
 *
 * The page contains settings for every effect, including disabled effects.
 * Several historical decimal defaults do not align with their HTML step value,
 * so native browser validation can block the entire form before WordPress gets
 * a chance to sanitize it. The server-side sanitizer is authoritative here.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'admin_enqueue_scripts',
	static function () {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'jlwa-page-effects' !== $page ) {
			return;
		}

		$script = <<<'JS'
(function () {
    'use strict';

    function applySaveCompatibility() {
        var form = document.getElementById('xjpe-settings-form');
        if (!form) return;

        // WordPress/PHP performs the real range sanitization. Do not let hidden
        // or disabled-effect fields block the whole settings form in the browser.
        form.noValidate = true;
        form.setAttribute('novalidate', 'novalidate');

        form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (button) {
            button.formNoValidate = true;
            button.setAttribute('formnovalidate', 'formnovalidate');
        });

        // Historical decimal defaults such as 1.25 / 0.48 / 0.42 are valid for
        // the effect engine but not multiples of the old HTML step attributes.
        // Keep integer controls strict and allow arbitrary decimals for floats.
        form.querySelectorAll('input[type="number"]').forEach(function (input) {
            var value = String(input.value || '');
            var min = String(input.getAttribute('min') || '');
            var max = String(input.getAttribute('max') || '');
            var step = String(input.getAttribute('step') || '');
            var isDecimal = value.indexOf('.') !== -1 || min.indexOf('.') !== -1 || max.indexOf('.') !== -1 || step.indexOf('.') !== -1;
            if (isDecimal) input.setAttribute('step', 'any');
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', applySaveCompatibility, { once: true });
    } else {
        applySaveCompatibility();
    }
})();
JS;

		wp_add_inline_script( 'xjpe-admin', $script, 'after' );
	},
	100
);
