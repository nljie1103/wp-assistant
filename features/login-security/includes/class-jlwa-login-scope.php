<?php
/** Safe dynamic-route path rules; system endpoints are never generic challenge targets. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class JLWA_Login_Scope {
    public static function normalize( $raw ) {
        if ( ! is_string( $raw ) || strlen( $raw ) > 4096 ) { return false; }
        $lines = preg_split( '/\r\n|\r|\n/', $raw );
        if ( ! is_array( $lines ) || count( $lines ) > 25 ) { return false; }
        $out = array();
        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( '' === $line ) { continue; }
            // WordPress permalinks often have a trailing slash. Normalize exact rules.
            if ( '/' === substr( $line, -1 ) ) { $line = rtrim( $line, '/' ); }
            // Exact path: /member/secret ; directory: /member/* ; no regex.
            if ( strlen( $line ) > 120 || ! preg_match( '~\A/(?:[A-Za-z0-9._-]+/)*[A-Za-z0-9._-]+(?:/\*)?\z~D', $line ) || false !== strpos( $line, '..' ) ) { return false; }
            $root = strtolower( explode( '/', ltrim( $line, '/' ) )[0] );
            $reserved = array( 'wp-admin', 'wp-login.php', 'wp-json', 'wp-cron.php', 'admin-ajax.php', 'admin-post.php', 'xmlrpc.php', 'oauth', 'checkout', 'zibpay', 'pay', 'order', 'download', 'pay-download' );
            if ( in_array( $root, $reserved, true ) ) { return false; }
            $out[] = $line;
        }
        $out = array_unique( $out );
        if ( count( $out ) > 16 ) { return false; }
        return implode( "\n", $out );
    }
    public static function match( $uri, $rules ) {
        if ( ! is_string( $uri ) || ! is_string( $rules ) ) { return ''; }
        $path = wp_parse_url( $uri, PHP_URL_PATH );
        if ( ! is_string( $path ) || '' === $path || strlen( $path ) > 2048 ) { return ''; }
        // Web servers and WP rewrites may decode an encoded slash before routing.
        // Match both the literal and decoded form to avoid easy path bypasses.
        for ( $i = 0; $i < 3; ++$i ) {
            $decoded = rawurldecode( $path );
            if ( $decoded === $path ) { break; }
            $path = $decoded;
        }
        $path = strtolower( $path );
        foreach ( explode( "\n", $rules ) as $rule ) {
            $rule = trim( $rule );
            if ( '' === $rule ) { continue; }
            $lower = strtolower( $rule );
            if ( substr( $lower, -2 ) === '/*' ) {
                $prefix = substr( $lower, 0, -1 );
                if ( 0 === strpos( $path, $prefix ) ) { return $rule; }
            } elseif ( $lower === rtrim( $path, '/' ) ) { return $rule; }
        }
        return '';
    }
}
