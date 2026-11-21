<?php
/** Canonical, nonrecursive return URLs for Zibll's Rainbow OAuth flow. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class JLWA_OAuth_Return {
    /** Only user-facing same-origin content URLs are valid post-login destinations. */
    public static function sanitize( $url, $home ) {
        if ( ! is_string( $url ) || ! is_string( $home ) || '' === $home || strlen( $url ) > 2048 ) { return $home; }
        $url = trim( $url );
        if ( '' === $url || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) { return $home; }
        $home_host = wp_parse_url( $home, PHP_URL_HOST );
        if ( ! is_string( $home_host ) || '' === $home_host ) { return $home; }
        $decoded = $url;
        for ( $i = 0; $i < 7; ++$i ) {
            if ( self::contains_login_path( $decoded ) || preg_match( '/[\x00-\x1f\x7f\\\\]/', $decoded ) ) { return $home; }
            $more = rawurldecode( $decoded );
            if ( $more === $decoded ) { break; }
            $decoded = $more;
        }
        if ( self::contains_login_path( $decoded ) || preg_match( '/[\x00-\x1f\x7f\\\\]/', $decoded ) ) { return $home; }
        // Repeatedly encoded absolute/protocol-relative destinations must not be laundered through a relative URL.
        if ( 0 === strpos( $decoded, '//' ) || 0 === strpos( $url, '//' ) ) { return $home; }
        $parsed = wp_parse_url( $url );
        $parsed_decoded = wp_parse_url( $decoded );
        if ( ! is_array( $parsed ) || ! is_array( $parsed_decoded ) ) { return $home; }
        if ( isset( $parsed['user'] ) || isset( $parsed['pass'] ) || isset( $parsed_decoded['user'] ) || isset( $parsed_decoded['pass'] ) ) { return $home; }
        if ( isset( $parsed_decoded['scheme'] ) && strtolower( $parsed_decoded['scheme'] ) !== 'https' ) { return $home; }
        if ( isset( $parsed['scheme'] ) ) {
            if ( strtolower( $parsed['scheme'] ) !== 'https' || ! isset( $parsed['host'] ) ) { return $home; }
            if ( strtolower( $parsed['host'] ) !== strtolower( $home_host ) ) { return $home; }
            if ( isset( $parsed_decoded['host'] ) && strtolower( $parsed_decoded['host'] ) !== strtolower( $home_host ) ) { return $home; }
            if ( isset( $parsed['port'] ) || isset( $parsed_decoded['port'] ) ) { return $home; }
        } else {
            // A path must be root relative, not a query-only or a browser-dependent relative reference.
            if ( '/' !== substr( $url, 0, 1 ) || '/' !== substr( $decoded, 0, 1 ) ) { return $home; }
            if ( isset( $parsed_decoded['scheme'] ) || isset( $parsed_decoded['host'] ) ) { return $home; }
        }
        return $url;
    }

    public static function contains_recursive_login( $url ) {
        if ( ! is_string( $url ) || strlen( $url ) > 2048 ) { return true; }
        for ( $i = 0; $i < 8; ++$i ) {
            if ( self::contains_login_path( $url ) ) { return true; }
            $decoded = rawurldecode( $url );
            if ( $decoded === $url ) { break; }
            $url = $decoded;
        }
        return self::contains_login_path( $url );
    }
    public static function contains_login_path( $url ) {
        if ( ! is_string( $url ) ) { return true; }
        // Search within the entire URL: recursive login links can appear inside nested query parameters.
        return (bool) preg_match( '~(?:^|[/?=&])(?:oauth/(?:clogin|agent)(?:[/?&#]|$)|user-sign(?:[/?&#]|$)|wp-login\\.php(?:[/?&#]|$))~i', $url );
    }
}
