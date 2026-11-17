<?php
/**
 * Private OpenList delivery after Zibll has granted a paid download.
 *
 * This is NOT a substitute for denying public access at the OpenList Nginx vhost.
 * It never creates a public proxy or asks the browser to fetch the origin.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class JLWA_Private_Download {
    const DEFAULT_HOSTS = "openlist-blog-cdn.jiuliu.org\nopenlist-blog.jiuliu.org";
    const DEFAULT_SOURCE = '/d/google/付费资源/';
    const DEFAULT_INTERNAL = '/_jlwa_private_openlist/';

    public static function defaults() {
        return array(
            'private_enabled' => 0,
            'private_hosts' => self::DEFAULT_HOSTS,
            'private_source_prefix' => self::DEFAULT_SOURCE,
            'private_internal_prefix' => self::DEFAULT_INTERNAL,
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
        // Never accept an enabled state with invalid host or path configuration.
        if ( ! $valid && function_exists( 'add_settings_error' ) ) {
            add_settings_error( 'jlwa_download_page_options', 'jlwa_private_invalid', '私有交付域名或路径无效：已保持安全交付关闭，请修正配置再启用。', 'error' );
        }
        return array(
            'private_enabled' => ! empty( $options['private_enabled'] ) && $valid ? 1 : 0,
            'private_hosts' => $hosts ? implode( "\n", $hosts ) : $defaults['private_hosts'],
            'private_source_prefix' => $source ? $source : $defaults['private_source_prefix'],
            'private_internal_prefix' => $internal ? $internal : $defaults['private_internal_prefix'],
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
        self::deliver( $match );
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
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Referrer-Policy: no-referrer' );
        header( 'X-Robots-Tag: noindex, nofollow' );
        status_header( 200 );
    }
}
JLWA_Private_Download::boot();
