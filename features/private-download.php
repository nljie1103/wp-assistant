<?php
/**
 * Private OpenList delivery after Zibll has granted a paid download.
 *
 * This is NOT a substitute for denying public access at the OpenList Nginx vhost.
 * It never creates a public proxy or asks the browser to fetch the origin.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class JLWA_Private_Download {
    const DEFAULT_HOSTS = '';
    const DEFAULT_SOURCE = '/d/storage/private/';
    const DEFAULT_INTERNAL = '/_jlwa_private_openlist/';

    public static function defaults() {
        return array(
            'private_enabled' => 0,
            'private_hosts' => self::DEFAULT_HOSTS,
            'private_source_prefix' => self::DEFAULT_SOURCE,
            'private_internal_prefix' => self::DEFAULT_INTERNAL,
            'delivery_host' => '',
            'delivery_enabled' => 0,
            'ticket_lifetime' => 180,
            'audit_enabled' => 1,
            'rate_enabled' => 0,
            'rate_limit' => 30,
        );
    }

    public static function hosts( $value ) {
        $hosts = preg_split( '/[\s,;]+/', strtolower( (string) $value ) );
        $clean = array();
        foreach ( $hosts as $host ) {
            if ( '' === $host ) { continue; }
            if ( strlen( $host ) > 253 || ! preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $host ) ) {
                return array();
            }
            $clean[ $host ] = $host;
        }
        return array_values( $clean );
    }

    /** Strict absolute URL path, never user-provided URL or arbitrary internal location. */
    public static function prefix( $value, $internal = false ) {
        $value = (string) $value;
        if ( ! strlen( $value ) || strlen( $value ) > 512 || '/' !== $value[0] || '/' !== substr( $value, -1 ) ) { return ''; }
        if ( false !== strpos( $value, '%' ) || false !== strpos( $value, '//' ) || false !== strpos( $value, '\\' ) || preg_match( '/[\x00-\x1F\x7F?#]/', $value ) ) { return ''; }
        foreach ( explode( '/', trim( $value, '/' ) ) as $segment ) {
            if ( '' === $segment || '.' === $segment || '..' === $segment ) { return ''; }
        }
        // Internal destinations must be self-contained (not /d, /wp-admin or /api).
        if ( $internal && ! preg_match( '#^/_[a-zA-Z0-9_-]+/$#D', $value ) ) { return ''; }
        return $value;
    }

    public static function sanitized( $options ) {
        $options = is_array( $options ) ? $options : array();
        $defaults = self::defaults();
        $hosts = self::hosts( $options['private_hosts'] ?? $defaults['private_hosts'] );
        $source = self::prefix( $options['private_source_prefix'] ?? $defaults['private_source_prefix'] );
        $internal = self::prefix( $options['private_internal_prefix'] ?? $defaults['private_internal_prefix'], true );
        $valid = (bool) ( $hosts && $source && $internal );
        $delivery = strtolower( trim( (string) ( $options['delivery_host'] ?? '' ) ) );
        $delivery_valid = '' === $delivery || ( self::hosts( $delivery ) === array( $delivery ) );
        $ticket_lifetime = min( 600, max( 60, (int) ( $options['ticket_lifetime'] ?? 180 ) ) );
        $rate_limit = min( 120, max( 10, (int) ( $options['rate_limit'] ?? 30 ) ) );
        // Never accept an enabled state with invalid host or path configuration.
        if ( ! $valid && function_exists( 'add_settings_error' ) ) {
            add_settings_error( 'jlwa_download_page_options', 'jlwa_private_invalid', '私有交付域名或路径无效：已保持安全交付关闭，请修正配置再启用。', 'error' );
        }
        return array(
            'private_enabled' => ! empty( $options['private_enabled'] ) && $valid ? 1 : 0,
            'private_hosts' => $hosts ? implode( "\n", $hosts ) : $defaults['private_hosts'],
            'private_source_prefix' => $source ? $source : $defaults['private_source_prefix'],
            'private_internal_prefix' => $internal ? $internal : $defaults['private_internal_prefix'],
            'delivery_host' => $delivery_valid ? $delivery : '',
            'delivery_enabled' => ! empty( $options['delivery_enabled'] ) && $delivery_valid && '' !== $delivery && $valid ? 1 : 0,
            'ticket_lifetime' => $ticket_lifetime,
            'audit_enabled' => ! empty( $options['audit_enabled'] ) ? 1 : 0,
            'rate_enabled' => ! empty( $options['rate_enabled'] ) ? 1 : 0,
            'rate_limit' => $rate_limit,
        );
    }

    /**
     * Returns null for unrelated URLs, false for protected-but-malformed URLs,
     * otherwise an internal-only Nginx URI and a sanitized filename.
     *
     * @param string $url Source stored in Zibll paid file settings.
     * @param array $settings Validated options.
     * @return array|false|null
     */
    public static function resolve( $url, $settings ) {
        $hosts = self::hosts( $settings['private_hosts'] ?? '' );
        $base = self::prefix( $settings['private_source_prefix'] ?? '' );
        $internal = self::prefix( $settings['private_internal_prefix'] ?? '', true );
        if ( ! $hosts || ! $base || ! $internal ) { return false; }
        if ( ! is_string( $url ) || strlen( $url ) > 4096 ) { return null; }
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || ! isset( $parts['host'], $parts['path'] ) ) { return null; }
        if ( ! in_array( strtolower( $parts['host'] ), $hosts, true ) ) { return null; }
        // Reject authenticated URLs and non-HTTP(S) schemes even for an allowed host.
        $scheme = strtolower( isset( $parts['scheme'] ) ? $parts['scheme'] : '' );
        if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) ) { return false; }

        // Encoded separators can cross the intended directory boundary after upstream decoding.
        if ( preg_match( '/%(?:2f|5c|00)/i', $parts['path'] ) ) { return false; }
        $decoded = rawurldecode( $parts['path'] );
        if ( 0 !== strpos( $decoded, $base ) ) {
            // Also deny malformed near-matches, e.g. the protected folder itself.
            if ( rtrim( $decoded, '/' ) === rtrim( $base, '/' ) ) { return false; }
            return null;
        }
        // A query may carry credentials, but must never be forwarded to the origin.
        if ( isset( $parts['query'] ) || isset( $parts['fragment'] ) ) { return false; }
        $relative = substr( $decoded, strlen( $base ) );
        if ( '' === $relative || strlen( $relative ) > 3000 ) { return false; }
        $segments = explode( '/', $relative );
        $safe = array();
        foreach ( $segments as $segment ) {
            if ( '' === $segment || '.' === $segment || '..' === $segment || false !== strpos( $segment, '%' ) || false !== strpos( $segment, '\\' ) || preg_match( '/[\x00-\x1F\x7F]/', $segment ) ) { return false; }
            $safe[] = rawurlencode( $segment );
        }
        $filename = end( $segments );
        return array( 'uri' => $internal . implode( '/', $safe ), 'filename' => $filename );
    }

    public static function boot() {
        // This hook is called by Zibll only after its paid/limit gate in download.php.
        // Keep normal downloads unchanged until the administrator explicitly enables it.
        add_filter( 'zibpay_download_file_local', array( __CLASS__, 'intercept' ), 30, 5 );
        add_action( 'init', array( __CLASS__, 'redeem_ticket' ), 1 );
    }

    public static function intercept( $file_local, $file_url, $post_id, $down_id, $paid ) {
        $settings = class_exists( 'JLWA_Download_Page_Feature' ) ? JLWA_Download_Page_Feature::options() : array();
        if ( empty( $settings['private_enabled'] ) ) { return $file_local; }
        $match = self::resolve( $file_url, $settings );
        if ( null === $match ) { return $file_local; }
        if ( false === $match || empty( $paid ) || ! is_numeric( $post_id ) || (int) $post_id < 1 || ! is_numeric( $down_id ) || (int) $down_id < 0 || ! function_exists( 'zibpay_is_paid' ) ) {
            self::unavailable();
        }
        // The Zibll hook has already run after a successful authorization check.
        // Never let a matching protected URL fall through to wp_redirect.
        if ( ! empty( $settings['rate_enabled'] ) && ! self::rate_permits( $post_id, $settings ) ) {
            self::audit( $post_id, $down_id, 'rate_limited', $settings );
            status_header( 429 );
            nocache_headers();
            wp_die( '下载请求过于频繁，请稍后重新从文章下载按钮进入。', '请求过于频繁', array( 'response' => 429 ) );
            exit;
        }
        self::audit( $post_id, $down_id, 'authorized', $settings );
        if ( ! empty( $settings['delivery_enabled'] ) && ! empty( $settings['delivery_host'] ) ) {
            self::issue_ticket( $match, $settings );
            exit;
        }
        self::deliver( $match );
        exit;
    }

    /** Only count new paid-link activations; range requests to a ticket are not counted. */
    public static function rate_permits( $post_id, $settings ) {
        $user = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
        $key = 'jlwa_rl_' . hash( 'sha256', $user . ':' . (int) $post_id );
        $count = (int) get_transient( $key );
        if ( $count >= (int) $settings['rate_limit'] ) { return false; }
        set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
        return true;
    }

    /** Bounded event log, never stores file URLs, raw IPs, tokens or cookies. */
    public static function audit( $post_id, $down_id, $outcome, $settings ) {
        if ( empty( $settings['audit_enabled'] ) ) { return; }
        $rows = get_option( 'jlwa_private_download_events', array() );
        if ( ! is_array( $rows ) ) { $rows = array(); }
        $rows[] = array(
            'time' => time(),
            'post_id' => (int) $post_id,
            'down_id' => (int) $down_id,
            'user_id' => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
            'result' => in_array( $outcome, array( 'authorized', 'rate_limited' ), true ) ? $outcome : 'unknown',
        );
        update_option( 'jlwa_private_download_events', array_slice( $rows, -120 ), false );
    }

    /** The host is a user-configured alias of the SAME WordPress origin, not OpenList. */
    public static function ticket_url( $ticket, $host ) {
        if ( ! preg_match( '/^[a-f0-9]{48}$/D', (string) $ticket ) || self::hosts( $host ) !== array( $host ) ) { return ''; }
        return 'https://' . $host . '/?jlwa_dl=' . $ticket;
    }

    protected static function issue_ticket( $match, $settings ) {
        if ( headers_sent() ) { self::unavailable(); }
        $ticket = bin2hex( random_bytes( 24 ) );
        $life = (int) $settings['ticket_lifetime'];
        $url = self::ticket_url( $ticket, $settings['delivery_host'] );
        if ( ! $url || ! set_transient( 'jlwa_dl_' . $ticket, array(
            'uri' => $match['uri'], 'filename' => $match['filename'],
            'expires' => time() + $life, 'host' => $settings['delivery_host'],
        ), $life ) ) { self::unavailable(); }
        nocache_headers();
        header( 'Referrer-Policy: no-referrer' );
        header( 'Cache-Control: private, no-store, max-age=0' );
        header( 'CDN-Cache-Control: no-store' );
        header( 'Cloudflare-CDN-Cache-Control: no-store' );
        // Domain is validated and HTTPS-only. No source address or file path in URL.
        wp_redirect( $url, 302, 'Jiuliu WP Assistant' );
        exit;
    }

    /** Short-lived opaque bearer. Reuse during TTL permits Range; no edge cache allowed. */
    public static function redeem_ticket() {
        if ( ! isset( $_GET['jlwa_dl'] ) ) { return; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $settings = class_exists( 'JLWA_Download_Page_Feature' ) ? JLWA_Download_Page_Feature::options() : array();
        $ticket = is_string( $_GET['jlwa_dl'] ) ? wp_unslash( $_GET['jlwa_dl'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $host = strtolower( (string) ( $_SERVER['HTTP_HOST'] ?? '' ) );
        $allowed = ! empty( $settings['private_enabled'] ) && ! empty( $settings['delivery_enabled'] ) && ! empty( $settings['delivery_host'] ) && $host === $settings['delivery_host'];
        if ( ! $allowed || ! preg_match( '/^[a-f0-9]{48}$/D', $ticket ) ) { self::missing_ticket(); }
        $data = get_transient( 'jlwa_dl_' . $ticket );
        if ( ! is_array( $data ) || ! isset( $data['uri'], $data['filename'], $data['expires'], $data['host'] ) || $data['host'] !== $host || (int) $data['expires'] < time() ) { self::missing_ticket(); }
        $prefix = self::prefix( $settings['private_internal_prefix'] ?? '', true );
        if ( ! $prefix || 0 !== strpos( $data['uri'], $prefix ) || ! preg_match( '#^/_[a-zA-Z0-9_-]+/[a-zA-Z0-9%._~/-]+$#D', $data['uri'] ) || preg_match( '#(?:\.\.|//|%2f|%5c)#i', $data['uri'] ) ) { self::missing_ticket(); }
        self::deliver( $data );
        exit;
    }

    protected static function missing_ticket() {
        nocache_headers();
        header( 'CDN-Cache-Control: no-store' );
        wp_die( '此下载链接已无效或过期，请返回原文章重新获取。', '下载链接不可用', array( 'response' => 404 ) );
        exit;
    }

    protected static function unavailable() {
        status_header( 503 );
        nocache_headers();
        wp_die( '私有下载暂不可用，请联系网站管理员；文件原始地址不会公开。', '安全下载不可用', array( 'response' => 503 ) );
        exit;
    }

    protected static function deliver( $match ) {
        if ( headers_sent() ) { self::unavailable(); }
        $name = (string) $match['filename'];
        $ext = pathinfo( $name, PATHINFO_EXTENSION );
        $fallback = ( $ext && preg_match( '/^[A-Za-z0-9]{1,10}$/D', $ext ) ) ? 'download.' . $ext : 'download';
        header( 'X-Accel-Redirect: ' . $match['uri'] );
        header( 'Content-Type: application/octet-stream' );
        header( 'Content-Disposition: attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode( $name ) );
        header( 'Cache-Control: private, no-store, max-age=0' );
        header( 'CDN-Cache-Control: no-store' );
        header( 'Cloudflare-CDN-Cache-Control: no-store' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Referrer-Policy: no-referrer' );
        header( 'X-Robots-Tag: noindex, nofollow' );
        status_header( 200 );
    }
}
JLWA_Private_Download::boot();
