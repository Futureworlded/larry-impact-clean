<?php
require __DIR__ . '/bootstrap.php';
li_test_load( 'shortcodes/helpers.php', array( 'li_service_key', 'li_db_key_or_error', 'li_stripe_transfer_request', 'li_calculate_splits' ) );
li_test_load( 'shortcodes/woo-sync.php', array( 'li_shopify_hmac_valid' ) );
require dirname( __DIR__ ) . '/shortcodes/shopify-refunds.php';

// Fixed cents payout per unit, the way most Larry products are set up.
function li_get_product_rescue_cents( $product ) { return intval( $product['rescue_cents'] ?? 0 ); }
function li_get_split_config( $id ) { return null; }

echo "Database key\n";
check( 'no private key means no key (never the public one)', '' === li_service_key() );
check( 'missing key is an error, not a silent empty read', is_wp_error( li_db_key_or_error() ) );
update_option( 'li_service_key', 'server-key' );
check( 'saved private key is used', 'server-key' === li_service_key() );

echo "Splits\n";
$p = array( 'id' => 'p1', 'price_cents' => 3199, 'cost_cents' => 1000, 'rescue_cents' => 600 );
$s = li_calculate_splits( $p, 3199, null, 1 );
check( '$31.99 with $6.00 payout -> 600 rescue / 2599 Larry', 600 === $s['rescue_cents'] && 2599 === $s['larry_cents'] );
$s = li_calculate_splits( $p, 6398, null, 2 );
check( 'quantity 2 doubles the rescue payout', 1200 === $s['rescue_cents'] && 5198 === $s['larry_cents'] );
$s = li_calculate_splits( $p, 500, null, 1 );
check( 'rescue never gets more than the sale', 500 === $s['rescue_cents'] && 0 === $s['larry_cents'] );

echo "Stripe payouts\n";
$a = li_stripe_transfer_request( 42, 2500, 'acct_x', 'sk' );
$b = li_stripe_transfer_request( 42, 2500, 'acct_x', 'sk' );
check( 'retrying a batch sends the same idempotency key', $a['headers']['Idempotency-Key'] === $b['headers']['Idempotency-Key'] );
check( 'different batches get different keys', $a['headers']['Idempotency-Key'] !== li_stripe_transfer_request( 43, 2500, 'acct_x', 'sk' )['headers']['Idempotency-Key'] );
check( 'amount and destination are sent', 2500 === $a['body']['amount'] && 'acct_x' === $a['body']['destination'] );

echo "Shopify webhook signature\n";
$sig = base64_encode( hash_hmac( 'sha256', '{"a":1}', 'secret', true ) );
check( 'valid signature accepted', li_shopify_hmac_valid( '{"a":1}', 'secret', $sig ) );
check( 'changed body rejected', ! li_shopify_hmac_valid( '{"a":2}', 'secret', $sig ) );
check( 'missing secret rejected', ! li_shopify_hmac_valid( '{"a":1}', '', $sig ) );

echo "Refund math\n";
$orig = array( 'sale_amount_cents' => 3199, 'rescue_split_cents' => 600, 'larry_split_cents' => 2599 );
$r = li_refund_reversal( $orig, 3199 );
check( 'full refund takes back the full rescue share', 3199 === $r['sale_cents'] && 600 === $r['rescue_cents'] && 2599 === $r['larry_cents'] );
$r = li_refund_reversal( $orig, 1600 );
check( 'half refund takes back half the rescue share', 1600 === $r['sale_cents'] && 300 === $r['rescue_cents'] );
$r = li_refund_reversal( $orig, 5000 );
check( 'refund larger than the line is capped at the line', 3199 === $r['sale_cents'] && 600 === $r['rescue_cents'] );
$r = li_refund_reversal( $orig, 1599, 1600, 300 );
check( 'second partial refund reverses only what is left', 1599 === $r['sale_cents'] && 300 === $r['rescue_cents'] );
$r = li_refund_reversal( $orig, 100, 3199, 600 );
check( 'nothing left to reverse after a full refund', 0 === $r['sale_cents'] && 0 === $r['rescue_cents'] );

echo "Refund recording\n";
$GLOBALS['li_test_db']['products'][] = array( 'id' => 'p1', 'sku' => 'RR-2LB' );
$GLOBALS['li_test_db']['orders'][]   = array( 'id' => 'o1', 'shopify_order_id' => 'LR-2000::p1', 'product_id' => 'p1', 'rescue_id' => 'r1', 'sale_amount_cents' => 3199, 'rescue_split_cents' => 600, 'larry_split_cents' => 2599, 'status' => 'paid' );
$refund = array( 'id' => '900', 'order_name' => '#LR-2000', 'created_at' => '2026-11-05T00:00:00Z', 'refund_line_items' => array( array( 'quantity' => 1, 'subtotal' => '31.99', 'line_item' => array( 'sku' => 'RR-2LB', 'title' => 'Rescue Roast' ) ) ) );
$out = li_process_shopify_refund( $refund );
$rev = li_db_get( 'orders?shopify_order_id=eq.' . urlencode( 'LR-2000::p1::refund-900' ) );
check( 'refund of a paid line adds a reversing line', 1 === count( $rev ) && -600 === $rev[0]['rescue_split_cents'] && -3199 === $rev[0]['sale_amount_cents'] && 'pending' === $rev[0]['status'] && 'r1' === $rev[0]['rescue_id'] );
check( 'original line is left as it was', 'paid' === li_db_get( 'orders?id=eq.o1' )[0]['status'] );
check( 'refund goes to the ledger', 'refund' === end( $GLOBALS['li_test_ledger'] )[0] );
li_process_shopify_refund( $refund );
check( 'same refund delivered twice is saved once', 2 === count( $GLOBALS['li_test_db']['orders'] ) );
$pending = array_filter( $GLOBALS['li_test_db']['orders'], function( $o ) { return 'pending' === $o['status'] && 'r1' === $o['rescue_id']; } );
check( "rescue's next payout is reduced by 600", -600 === array_sum( array_column( $pending, 'rescue_split_cents' ) ) );
$out = li_process_shopify_refund( array( 'id' => '901', 'order_name' => 'LR-2000', 'refund_line_items' => array( array( 'quantity' => 1, 'subtotal' => '10.00', 'line_item' => array( 'sku' => 'UNKNOWN', 'title' => 'Mystery' ) ) ) ) );
check( 'refund for an item Larry never credited is flagged, not ignored', 'refund_no_line' === ( $out['skipped'][0]['reason'] ?? '' ) );

echo $GLOBALS['li_test_failures'] ? "\n{$GLOBALS['li_test_failures']} failed\n" : "\nAll passed\n";
exit( $GLOBALS['li_test_failures'] ? 1 : 0 );
