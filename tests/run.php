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

echo "Money balance check\n";
require dirname( __DIR__ ) . '/shortcodes/money-check.php';
$orders = array(
    array( 'shopify_order_id' => 'LR-1::a', 'rescue_id' => 'r1', 'sale_amount_cents' => 3199, 'rescue_split_cents' => 600, 'larry_split_cents' => 2599, 'status' => 'paid' ),
    array( 'shopify_order_id' => 'LR-2::a', 'rescue_id' => 'r1', 'sale_amount_cents' => 3199, 'rescue_split_cents' => 600, 'larry_split_cents' => 2599, 'status' => 'pending' ),
    array( 'shopify_order_id' => 'LR-1::a::refund-9', 'rescue_id' => 'r1', 'sale_amount_cents' => -1600, 'rescue_split_cents' => -300, 'larry_split_cents' => -1300, 'status' => 'pending' ),
);
$batches = array( array( 'id' => 5, 'rescue_id' => 'r1', 'amount_cents' => 600, 'status' => 'completed', 'transfer_id' => 'tr_1', 'created_at' => '2026-11-01T00:00:00Z' ) );
$lines   = array( array( 'payout_id' => 5, 'order_ref' => 'LR-1::a', 'amount_cents' => 600 ) );
$c = li_money_check_problems( $orders, $batches, $lines );
check( 'balanced books have no problems', array() === $c['problems'] );
check( 'earned 900, in payouts 600, owed 300 after the refund', 900 === $c['rescues']['r1']['earned'] && 600 === $c['rescues']['r1']['in_payouts'] && 300 === $c['rescues']['r1']['owed'] );
$c = li_money_check_problems( $orders, array_merge( $batches, array( array( 'id' => 6, 'rescue_id' => 'r1', 'amount_cents' => 600, 'status' => 'completed', 'transfer_id' => 'tr_2', 'created_at' => '' ) ) ), array_merge( $lines, array( array( 'payout_id' => 6, 'order_ref' => 'LR-1::a', 'amount_cents' => 600 ) ) ) );
check( 'an order paid twice is caught', (bool) preg_grep( '/in 2 payout batches/', $c['problems'] ) );
$o2 = $orders; $o2[0]['status'] = 'pending';
$c = li_money_check_problems( $o2, $batches, $lines );
check( 'a paid order put back to pending is caught', (bool) preg_grep( '/could be paid again/', $c['problems'] ) );
$c = li_money_check_problems( $orders, array( array( 'id' => 5, 'rescue_id' => 'r1', 'amount_cents' => 700, 'status' => 'completed', 'transfer_id' => 'tr_1', 'created_at' => '' ) ), $lines );
check( 'a batch that does not match its orders is caught', (bool) preg_grep( '/batch #5 is/', $c['problems'] ) );
$o3 = $orders; $o3[] = array( 'shopify_order_id' => 'LR-1::a::refund-10', 'rescue_id' => 'r1', 'sale_amount_cents' => -2000, 'rescue_split_cents' => -400, 'larry_split_cents' => -1600, 'status' => 'pending' );
$c = li_money_check_problems( $o3, $batches, $lines );
check( 'refunding more than the sale is caught', (bool) preg_grep( '/refunded more than/', $c['problems'] ) );
$o4 = $orders; $o4[1]['rescue_split_cents'] = 3000;
$c = li_money_check_problems( $o4, $batches, $lines );
check( 'shares larger than the sale are caught', (bool) preg_grep( '/more than the sale/', $c['problems'] ) );
$c = li_money_check_problems( $orders, array( array( 'id' => 5, 'rescue_id' => 'r1', 'amount_cents' => 600, 'status' => 'sending', 'transfer_id' => '', 'created_at' => '2026-11-01T00:00:00Z' ) ), $lines, strtotime( '2026-11-01T03:00:00Z' ) );
check( 'a payout stuck sending to Stripe is caught', (bool) preg_grep( '/sending to Stripe/', $c['problems'] ) );

echo "Shopify product page check\n";
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); } }
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $s ) { return trim( strip_tags( $s ) ); } }
li_test_load( 'shortcodes/order-health.php', array( 'li_shopify_listing_issues' ) );
$tee = array(
    'options'   => array( array( 'name' => 'Color', 'values' => array( 'Black', 'Dark Grey Heather' ) ), array( 'name' => 'Size', 'values' => array( 'S', 'M' ) ) ),
    'body_html' => '<p>At least 55% goes to the rescue you choose.</p>',
    'images'    => array(
        array( 'src' => 'https://cdn.test/files/unisex-long-sleeve-tee-black-front-6a5a.jpg?v=1', 'variant_ids' => array( 1 ) ),
        array( 'src' => 'https://cdn.test/files/unisex-long-sleeve-tee-dark-grey-heather-front-6a5b.jpg', 'variant_ids' => array() ),
    ),
);
check( 'a good apparel page has no problems', array() === li_shopify_listing_issues( $tee ) );
$bad = $tee; $bad['images'][] = array( 'src' => 'https://cdn.test/files/unisex-long-sleeve-tee-citron-front-6a5c.jpg', 'variant_ids' => array() );
check( 'pictures of a color not for sale are caught', (bool) preg_grep( '/1 pictures of colors not for sale/', li_shopify_listing_issues( $bad ) ) );
$bad = $tee; array_unshift( $bad['images'], array( 'src' => 'https://cdn.test/files/unisex-long-sleeve-tee-black-back-6a5d.jpg', 'variant_ids' => array() ) );
check( 'a back view as the main picture is caught', (bool) preg_grep( '/not a front view/', li_shopify_listing_issues( $bad ) ) );
$bad = $tee; $bad['body_html'] = '<p>This tee is an instant crowd favorite.</p>';
check( 'stock garment text is caught', (bool) preg_grep( '/stock garment text/', li_shopify_listing_issues( $bad ) ) );
check( 'products without colors are skipped', array() === li_shopify_listing_issues( array( 'options' => array( array( 'name' => 'Size', 'values' => array( '12oz' ) ) ), 'body_html' => 'Coffee', 'images' => array() ) ) );

echo "Private W-9 files\n";
li_test_load( 'shortcodes/w9-private.php', array( 'li_w9_legacy_path', 'li_w9_can_view', 'li_w9_is_private_link' ) );
$base = 'https://larryimpact.com/wp-content/uploads';
check( 'old public W-9 link maps to its file', '/srv/up/2026/10/w9_form.pdf' === li_w9_legacy_path( $base . '/2026/10/w9_form.pdf', $base, '/srv/up' ) );
check( 'http link with a space in the name maps too', '/srv/up/2026/10/w9_a b.pdf' === li_w9_legacy_path( 'http://larryimpact.com/wp-content/uploads/2026/10/w9_a%20b.pdf', $base, '/srv/up' ) );
check( 'link outside the uploads folder is not touched', '' === li_w9_legacy_path( 'https://other.test/w9.pdf', $base, '/srv/up' ) );
check( 'path tricks are refused', '' === li_w9_legacy_path( $base . '/../wp-config.php', $base, '/srv/up' ) );
check( 'already-private files are not moved again', '' === li_w9_legacy_path( $base . '/li-private/w9/x.pdf', $base, '/srv/up' ) );
check( 'admin can open any W-9', li_w9_can_view( 'r1', true, '' ) );
check( 'rescue can open its own W-9', li_w9_can_view( 'r1', false, 'r1' ) );
check( "rescue cannot open another rescue's W-9", ! li_w9_can_view( 'r2', false, 'r1' ) );
check( 'signed-in user with no rescue is refused', ! li_w9_can_view( 'r1', false, '' ) );
check( 'missing rescue id is refused, even for admin', ! li_w9_can_view( '', true, '' ) );
check( 'private link is recognised', li_w9_is_private_link( 'https://x.test/wp-admin/admin-ajax.php?action=li_w9_download&rescue_id=r1' ) && ! li_w9_is_private_link( $base . '/2026/10/w9.pdf' ) );

echo "Merch page design tiles\n";
li_test_load( 'shortcodes/shopify-storefront.php', array( 'li_storefront_swap_category_tiles' ) );
$woo = '<ul class="products columns-4"><li class="product-category product first"><a href="x">Old A</a></li>
<li class="product-category product"><a href="y">Old B</a></li>';
$new = li_storefront_swap_category_tiles( $woo, '<li class="product-category product">Seasonal</li>' );
check( 'old WooCommerce tiles are replaced by the Shopify tiles', false === strpos( $new, 'Old ' ) && false !== strpos( $new, 'Seasonal' ) );
check( 'the product list wrapper is kept', 0 === strpos( $new, '<ul class="products columns-4">' ) );

echo $GLOBALS['li_test_failures'] ? "\n{$GLOBALS['li_test_failures']} failed\n" : "\nAll passed\n";
exit( $GLOBALS['li_test_failures'] ? 1 : 0 );
