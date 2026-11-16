<?php
/**
 * Resolve the target server without ever forwarding a preset provider's key
 * to a leftover custom URL. User-managed endpoints are custom-provider only.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WPAIAS_Endpoint_Policy {
	/**
	 * @param string $provider_key Provider identifier.
	 * @param array  $provider     Provider metadata with the official endpoint.
	 * @param array  $settings     Persisted settings.
	 * @param array  $overrides    One-off test overrides.
	 * @return string
	 */
	public static function resolve( $provider_key, $provider, $settings, $overrides = array() ) {
		if ( 'custom' !== $provider_key ) {
			return isset( $provider['endpoint'] ) ? (string) $provider['endpoint'] : '';
		}

		return isset( $overrides['endpoint'] )
			? (string) $overrides['endpoint']
			: ( isset( $settings['custom_endpoint'] ) ? (string) $settings['custom_endpoint'] : '' );
	}
}
