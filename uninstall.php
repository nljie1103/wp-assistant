<?php
/**
 * Uninstall handler for 九流WP助手.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'jlwa_last_update_check' );
