<?php
/** Bounded on-disk abuse counters for a single-host WordPress deployment. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class JLWA_Login_Risk {
    /** Pure policy evaluation, used for deterministic security tests. */
    public static function evaluate( $state, $now, $fingerprint, $settings, $increment ) {
        $state = is_array( $state ) ? $state : array();
        $now = (int) $now;
        $window = 60;
        $ip_limit = max( 2, min( 100, (int) $settings['ip_limit'] ) );
        $global_limit = max( 10, min( 3000, (int) $settings['global_limit'] ) );
        $cooldown = max( 60, min( 3600, (int) $settings['cooldown'] ) );
        if ( ! isset( $state['started'] ) || $now < (int) $state['started'] || $now - (int) $state['started'] >= $window ) {
            $state['started'] = $now;
            $state['total'] = 0;
            $state['clients'] = array();
        }
        if ( ! isset( $state['clients'] ) || ! is_array( $state['clients'] ) ) { $state['clients'] = array(); }
        if ( ! isset( $state['hot'] ) || ! is_array( $state['hot'] ) ) { $state['hot'] = array(); }
        $global_until = isset( $state['global_until'] ) ? (int) $state['global_until'] : 0;
        foreach ( $state['hot'] as $key => $until ) {
            if ( (int) $until <= $now ) { unset( $state['hot'][ $key ] ); }
        }
        $fingerprint = substr( (string) $fingerprint, 0, 64 );
        if ( $increment ) {
            $state['total'] = min( 1000000, (int) $state['total'] + 1 );
            $state['clients'][ $fingerprint ] = isset( $state['clients'][ $fingerprint ] ) ? (int) $state['clients'][ $fingerprint ] + 1 : 1;
            // Bound memory / filesystem writes under distributed attack.
            if ( count( $state['clients'] ) > 512 ) { $state['clients'] = array_slice( $state['clients'], -256, null, true ); }
            if ( (int) $state['total'] >= $global_limit ) { $global_until = max( $global_until, $now + $cooldown ); }
            if ( $state['clients'][ $fingerprint ] >= $ip_limit ) { $state['hot'][ $fingerprint ] = $now + $cooldown; }
        }
        $state['global_until'] = $global_until;
        return array( $state, $now < $global_until || ( isset( $state['hot'][ $fingerprint ] ) && $now < (int) $state['hot'][ $fingerprint ] ) );
    }

    private static function file_path() {
        // Never store IP-derived state in a public WordPress URL.
        $secret = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : '';
        $root = defined( 'ABSPATH' ) ? ABSPATH : '';
        $directory = rtrim( sys_get_temp_dir(), '/\\' ) . '/jlwa-risk-' . substr( hash_hmac( 'sha256', $root, (string) $secret ), 0, 20 );
        if ( ! is_dir( $directory ) && ! @mkdir( $directory, 0700, true ) && ! is_dir( $directory ) ) { return ''; }
        return $directory . '/state.dat';
    }
    /** Store only a keyed hash of the peer IP; ignore untrusted X-Forwarded-For. */
    private static function visitor_key() {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
        return substr( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ), 0, 40 );
    }
    /** Returns true when elevated risk is active, including on storage errors (fail closed). */
    public static function active( $settings, $increment = false ) {
        $path = self::file_path();
        if ( '' === $path ) { return true; }
        $handle = @fopen( $path, 'c+' );
        if ( ! $handle ) { return true; }
        @chmod( $path, 0600 );
        if ( ! flock( $handle, LOCK_EX ) ) { fclose( $handle ); return true; }
        $raw = stream_get_contents( $handle, 131072 );
        $decoded = json_decode( is_string( $raw ) ? $raw : '', true );
        list( $state, $elevated ) = self::evaluate( $decoded, time(), self::visitor_key(), $settings, $increment );
        if ( $increment ) {
            rewind( $handle );
            $json = wp_json_encode( $state );
            if ( ! is_string( $json ) || ! ftruncate( $handle, 0 ) || false === fwrite( $handle, $json ) || ! fflush( $handle ) ) { $elevated = true; }
        }
        flock( $handle, LOCK_UN );
        fclose( $handle );
        return $elevated;
    }
}
