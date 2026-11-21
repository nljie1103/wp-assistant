<?php
/**
 * Run the real AI settings normalization and feature activation against in-memory
 * WordPress option doubles. No database, credentials or production site used.
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'JLWA_VERSION', '3.1.8' );
define( 'WPAIAS_OPTION_KEY', 'wpaias_settings' );
$GLOBALS['jlwa_activation_options'] = array();

function get_option( $key, $fallback = false ) {
    return array_key_exists( $key, $GLOBALS['jlwa_activation_options'] ) ? $GLOBALS['jlwa_activation_options'][ $key ] : $fallback;
}
function update_option( $key, $value, $autoload = false ) {
    $GLOBALS['jlwa_activation_options'][ $key ] = $value;
    return true;
}
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, $args ); }
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $key ) ); }

require_once dirname( __DIR__ ) . '/features/ai-article-summary/includes/class-wpaias-plugin.php';
require_once dirname( __DIR__ ) . '/includes/class-jlwa-feature-registry.php';

// The module was loaded by WordPress before the activation hook. Avoid booting
// unrelated modules (and their activation side effects) in this isolated test.
$registry = new ReflectionClass( 'JLWA_Feature_Registry' );
$booted = $registry->getProperty( 'booted' );
$booted->setAccessible( true );
$booted->setValue( null, true );
$statuses = $registry->getProperty( 'statuses' );
$statuses->setAccessible( true );
$statuses->setValue( null, array( 'ai-summary' => array( 'loaded' => true ) ) );

function check( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL: ' . $message . "\n" );
        exit( 1 );
    }
}
function activate_with( $options ) {
    $GLOBALS['jlwa_activation_options'] = array( WPAIAS_OPTION_KEY => $options );
    JLWA_Feature_Registry::activate();
    return get_option( WPAIAS_OPTION_KEY );
}

$previous = array(
    'provider' => 'openai',
    'model' => 'gpt-5.4-nano',
    'api_key' => "  FAKE_LEGACY_API_KEY\n",
    'enabled' => 0,
    'title' => 'Preserved title',
);
$after = activate_with( $previous );
$model_slot = JLWA_AI_Summary_Feature::api_key_slot( 'openai', 'gpt-5.4-nano' );
$provider_slot = JLWA_AI_Summary_Feature::api_key_provider_slot( 'openai' );
check( ! array_key_exists( 'api_key', $after ), 'legacy field must be migrated, not stored' );
check( ( $after['api_keys'][ $model_slot ] ?? null ) === 'FAKE_LEGACY_API_KEY', 'model slot was lost on activation' );
check( ( $after['api_keys'][ $provider_slot ] ?? null ) === 'FAKE_LEGACY_API_KEY', 'provider fallback was lost on activation' );
check( $after['enabled'] === 0 && $after['title'] === 'Preserved title', 'existing non-secret settings changed' );
check( JLWA_AI_Summary_Feature::get_api_key_for_model( $after ) === 'FAKE_LEGACY_API_KEY', 'migrated key is not usable' );

// Already-migrated keys take precedence, and a key for another provider stays.
$after = activate_with( array(
    'provider' => 'openai', 'model' => 'gpt-5.4-nano', 'api_key' => 'LEGACY',
    'api_keys' => array( $model_slot => 'NEW_MODEL', $provider_slot => 'NEW_PROVIDER', 'gemini::__provider__' => 'GEMINI' ),
) );
check( $after['api_keys'][ $model_slot ] === 'NEW_MODEL', 'new model key overwritten by legacy secret' );
check( $after['api_keys'][ $provider_slot ] === 'NEW_PROVIDER', 'new provider key overwritten by legacy secret' );
check( $after['api_keys']['gemini::__provider__'] === 'GEMINI', 'unrelated provider key lost' );

$again = activate_with( $after );
check( $again === $after, 'repeated activation should preserve all migrated settings' );

$empty = activate_with( array() );
check( empty( $empty['api_keys'] ) && ! isset( $empty['api_key'] ), 'empty activation must not invent a secret' );
check( ! empty( $empty['provider'] ) && ! empty( $empty['model'] ), 'defaults were lost' );
echo "AI activation legacy-key preservation, existing mappings, idempotency, defaults: OK\n";
