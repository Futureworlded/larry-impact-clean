<?php
// Minimal WordPress stand-ins so plugin functions can be tested without a site.
define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['li_test_options'] = array();
$GLOBALS['li_test_db']      = array( 'products' => array(), 'orders' => array() );
$GLOBALS['li_test_ledger']  = array();

foreach ( array( 'add_action', 'add_filter', 'add_shortcode', 'register_activation_hook', 'register_rest_route', 'wp_schedule_event', 'wp_clear_scheduled_hook', 'li_audit_log', 'li_notify_admin', 'li_lock_order', 'error_log_stub' ) as $fn ) {
    if ( ! function_exists( $fn ) ) {
        eval( "function $fn() { return null; }" );
    }
}
class WP_Error {
    public $code; public $message;
    function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
    function get_error_message() { return $this->message; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function get_option( $k, $d = false ) { return $GLOBALS['li_test_options'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['li_test_options'][ $k ] = $v; return true; }
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $t = 0 ) { return true; }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function wp_unslash( $x ) { return $x; }
function wp_json_encode( $x ) { return json_encode( $x ); }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function current_user_can( $c ) { return false; }
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code'] ?? 0; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }
function li_log( $m ) {}
function li_record_ledger( $type, $args ) { $GLOBALS['li_test_ledger'][] = array( $type, $args ); return 1; }
function li_order_event_get_by_external( $s, $id ) { return null; }

// In-memory stand-in for the Supabase REST calls, including the unique shopify_order_id.
function li_db_get( $endpoint ) {
    list( $table, $query ) = array_pad( explode( '?', $endpoint, 2 ), 2, '' );
    $rows = $GLOBALS['li_test_db'][ $table ] ?? array();
    foreach ( explode( '&', $query ) as $part ) {
        if ( ! preg_match( '/^([a-z_]+)=(eq|like)\.(.*)$/', $part, $m ) ) continue;
        $val  = urldecode( $m[3] );
        $rows = array_values( array_filter( $rows, function( $r ) use ( $m, $val ) {
            $have = (string) ( $r[ $m[1] ] ?? '' );
            return 'eq' === $m[2] ? $have === $val : fnmatch( $val, $have );
        } ) );
    }
    return $rows;
}
function li_db_post( $table, $row ) {
    foreach ( $GLOBALS['li_test_db'][ $table ] as $r ) {
        if ( isset( $row['shopify_order_id'] ) && ( $r['shopify_order_id'] ?? null ) === $row['shopify_order_id'] ) {
            return array( 'response' => array( 'code' => 409 ) );
        }
    }
    $row['id'] = $row['id'] ?? ( $table . '-' . count( $GLOBALS['li_test_db'][ $table ] ) );
    $GLOBALS['li_test_db'][ $table ][] = $row;
    return array( 'response' => array( 'code' => 201 ) );
}
function li_get_product_by_sku( $sku ) { $d = li_db_get( 'products?sku=eq.' . urlencode( $sku ) ); return $d ? $d[0] : null; }
function li_format_money( $c ) { return '$' . number_format( $c / 100, 2 ); }
function li_currency() { return 'USD'; }

$GLOBALS['li_test_failures'] = 0;
function check( $label, $ok ) {
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
    if ( ! $ok ) $GLOBALS['li_test_failures']++;
}

// Pull single functions out of plugin files that also contain WordPress-only code.
function li_test_load( $file, $names ) {
    $src = file_get_contents( dirname( __DIR__ ) . '/' . $file );
    foreach ( $names as $name ) {
        if ( ! preg_match( '/^function ' . preg_quote( $name, '/' ) . '\(.*?^}\n/ms', $src, $m ) ) {
            throw new Exception( "Function $name not found in $file" );
        }
        eval( $m[0] );
    }
}
