<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Larry Impact - Stripe webhook endpoint.
 *
 * Handles:
 * - transfer.paid / payout.paid: confirms a Stripe Transfer succeeded and marks
 *   the matching orders + local payout record as completed.
 * - charge.dispute.created: records a chargeback ledger entry, flags fraud,
 *   and updates the matching order status to disputed.
 */

add_action( 'rest_api_init', function() {
    register_rest_route( 'larry-impact/v1', '/stripe-webhook', array(
        'methods'             => 'POST',
        'callback'            => 'li_handle_stripe_webhook',
        'permission_callback' => '__return_true',
    ) );
} );

function li_handle_stripe_webhook( WP_REST_Request $request ) {
    $secret = li_stripe_webhook_secret();
    if ( ! $secret ) {
        li_log( 'Stripe webhook received but no secret configured' );
        return new WP_Error( 'unauthorized', 'Webhook secret not configured', array( 'status' => 401 ) );
    }

    $payload    = $request->get_body();
    $sig_header = $request->get_header( 'stripe-signature' );
    if ( ! $sig_header || ! li_verify_stripe_signature( $payload, $sig_header, $secret ) ) {
        li_log( 'Stripe webhook signature verification failed' );
        return new WP_Error( 'unauthorized', 'Signature verification failed', array( 'status' => 401 ) );
    }

    $event = json_decode( $payload, true );
    if ( ! $event || empty( $event['type'] ) ) {
        return new WP_REST_Response( array( 'status' => 'ignored' ), 200 );
    }

    $type   = $event['type'];
    $object = $event['data']['object'] ?? array();

    switch ( $type ) {
        case 'transfer.paid':
        case 'payout.paid':
            li_handle_stripe_payout_paid( $object );
            break;
        case 'charge.dispute.created':
            li_handle_stripe_dispute_created( $object );
            break;
        case 'charge.refunded':
            li_handle_stripe_charge_refunded( $object );
            break;
        default:
            li_log( 'Stripe webhook ignored: ' . sanitize_text_field( $type ) );
    }

    return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
}

function li_verify_stripe_signature( $payload, $sig_header, $secret ) {
    $timestamp  = null;
    $signatures = array();
    $items      = explode( ',', $sig_header );
    foreach ( $items as $item ) {
        $item = trim( $item );
        if ( strpos( $item, 't=' ) === 0 ) {
            $timestamp = substr( $item, 2 );
        } elseif ( strpos( $item, 'v1=' ) === 0 ) {
            $signatures[] = substr( $item, 3 );
        }
    }
    if ( ! $timestamp || empty( $signatures ) ) {
        return false;
    }
    if ( abs( time() - intval( $timestamp ) ) > 300 ) {
        return false;
    }
    $signed_payload = $timestamp . '.' . $payload;
    $expected       = hash_hmac( 'sha256', $signed_payload, $secret );
    foreach ( $signatures as $sig ) {
        if ( hash_equals( $expected, $sig ) ) {
            return true;
        }
    }
    return false;
}

function li_handle_stripe_payout_paid( $object ) {
    $transfer_id  = sanitize_text_field( $object['id'] ?? '' );
    $amount_cents = isset( $object['amount'] ) ? intval( $object['amount'] ) : 0;
    $account_id   = sanitize_text_field( $object['destination'] ?? '' );

    if ( ! $transfer_id || $amount_cents <= 0 ) {
        li_log( 'Stripe payout webhook missing transfer id or amount' );
        return;
    }

    global $wpdb;
    $payout = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}li_payouts WHERE transfer_id = %s LIMIT 1", $transfer_id ), ARRAY_A );

    if ( $payout ) {
        if ( $payout['status'] !== 'completed' ) {
            $wpdb->update(
                $wpdb->prefix . 'li_payouts',
                array( 'status' => 'completed' ),
                array( 'id' => $payout['id'] ),
                array( '%s' ),
                array( '%d' )
            );
            li_audit_log( 'payout_confirmed_by_stripe', array( 'payout_id' => $payout['id'], 'transfer_id' => $transfer_id, 'rescue_id' => $payout['rescue_id'], 'amount_cents' => $amount_cents ), 'payout', $payout['id'] );
        }
        $order_refs = $wpdb->get_col( $wpdb->prepare( "SELECT order_ref FROM {$wpdb->prefix}li_payout_lines WHERE payout_id = %d", $payout['id'] ) );
        $rescue_id  = $payout['rescue_id'];
    } else {
        $rescue = null;
        if ( $account_id ) {
            $rescues = li_db_get( 'rescues?stripe_account_id=eq.' . urlencode( $account_id ) . '&select=*&limit=1' );
            if ( is_array( $rescues ) && ! empty( $rescues ) ) {
                $rescue = $rescues[0];
            }
        }
        if ( ! $rescue ) {
            li_log( 'Stripe payout webhook: no rescue found for account ' . $account_id );
            return;
        }
        $rescue_id  = $rescue['id'] ?? '';
        $order_refs = array( 'bulk_' . $transfer_id );
        $payout_id  = li_create_payout( $rescue_id, $order_refs, $amount_cents, $transfer_id, 'completed' );
        li_record_ledger( 'payout', array(
            'order_ref'    => 'payout_' . $payout_id,
            'rescue_id'    => $rescue_id,
            'amount_cents' => -1 * $amount_cents,
            'net_cents'    => -1 * $amount_cents,
            'meta'         => array( 'payout_id' => $payout_id, 'transfer_id' => $transfer_id, 'stripe_account_id' => $account_id ),
            'source'       => 'stripe_webhook',
        ) );
        li_audit_log( 'payout_confirmed_by_stripe', array( 'payout_id' => $payout_id, 'transfer_id' => $transfer_id, 'rescue_id' => $rescue_id, 'amount_cents' => $amount_cents ), 'payout', $payout_id );
    }

    if ( ! $rescue_id ) {
        return;
    }

    $rescue_for_email = li_get_rescue_by_id( $rescue_id );
    $rescue_name      = $rescue_for_email ? ( $rescue_for_email['name'] ?? 'Your rescue' ) : 'Your rescue';
    $amount_dollars   = number_format( $amount_cents / 100, 2 );

    li_notify_rescue( $rescue_id, "Payout sent: \$$amount_dollars from Larry Impact", "Hi,\n\nA payout of \$$amount_dollars has been sent to your rescue ($rescue_name). Transfer ID: $transfer_id\n\nThank you,\nLarry Impact" );
    li_notify_admin( "Payout confirmed: $rescue_name", "Rescue: $rescue_name\nAmount: \$$amount_dollars\nTransfer ID: $transfer_id\n\nReview payouts: " . admin_url( 'admin.php?page=li-payouts' ) );

    if ( $amount_cents > 50000 ) {
        li_notify_admin( "Payout over $500 sent: $rescue_name", "Rescue: $rescue_name\nAmount: \$$amount_dollars\nTransfer ID: $transfer_id" );
    }

    if ( is_array( $order_refs ) && ! empty( $order_refs ) ) {
        foreach ( $order_refs as $ref ) {
            $ref = sanitize_text_field( $ref );
            if ( ! $ref || strpos( $ref, 'bulk_' ) === 0 ) {
                continue;
            }
            $orders = li_db_get( 'orders?shopify_order_id=eq.' . urlencode( $ref ) . '&select=id' );
            if ( is_array( $orders ) && ! empty( $orders ) ) {
                foreach ( $orders as $o ) {
                    li_db_patch( 'orders?id=eq.' . urlencode( $o['id'] ), array( 'status' => 'paid' ) );
                }
            }
        }
    } else {
        $orders = li_db_get( 'orders?rescue_id=eq.' . urlencode( $rescue_id ) . '&status=eq.pending&select=*' );
        if ( is_array( $orders ) ) {
            foreach ( $orders as $o ) {
                li_db_patch( 'orders?id=eq.' . urlencode( $o['id'] ), array( 'status' => 'paid' ) );
            }
        }
    }

    li_audit_log( 'orders_marked_paid_by_stripe', array( 'transfer_id' => $transfer_id, 'rescue_id' => $rescue_id ), 'payout', $transfer_id );
}

function li_handle_stripe_dispute_created( $object ) {
    $charge_id    = sanitize_text_field( $object['charge'] ?? '' );
    $amount_cents = isset( $object['amount'] ) ? intval( $object['amount'] ) : 0;
    $reason       = sanitize_text_field( $object['reason'] ?? '' );

    if ( ! $charge_id || $amount_cents <= 0 ) {
        li_log( 'Stripe dispute webhook missing charge id or amount' );
        return;
    }

    $wc_order = li_find_wc_order_by_stripe_charge( $charge_id );
    if ( ! $wc_order ) {
        li_log( 'Stripe dispute webhook: no WooCommerce order for charge ' . $charge_id );
        return;
    }

    li_record_stripe_reversal( $wc_order, $amount_cents, 'chargeback', $charge_id, array( 'reason' => $reason ) );
    li_flag_fraud( 'chargeback', array(
        'order_ref'  => strval( $wc_order->get_order_number() ),
        'details'    => array( 'charge_id' => $charge_id, 'reason' => $reason ),
    ) );
}

function li_handle_stripe_charge_refunded( $object ) {
    $charge_id    = sanitize_text_field( $object['id'] ?? '' );
    $amount_cents = isset( $object['amount_refunded'] ) ? intval( $object['amount_refunded'] ) : ( isset( $object['amount'] ) ? intval( $object['amount'] ) : 0 );

    if ( ! $charge_id || $amount_cents <= 0 ) {
        return;
    }

    $wc_order = li_find_wc_order_by_stripe_charge( $charge_id );
    if ( ! $wc_order ) {
        li_log( 'Stripe refund webhook: no WooCommerce order for charge ' . $charge_id );
        return;
    }

    // Avoid double-recording a refund that was already handled by the WooCommerce refund hook.
    global $wpdb;
    $existing = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}li_ledger WHERE entry_type = %s AND meta LIKE %s",
        'refund',
        $wpdb->esc_like( wp_json_encode( array( 'charge_id' => $charge_id ) ) ) . '%'
    ) );
    if ( $existing ) {
        return;
    }

    li_record_stripe_reversal( $wc_order, $amount_cents, 'refund', $charge_id, array() );
}

function li_find_wc_order_by_stripe_charge( $charge_id ) {
    if ( ! function_exists( 'wc_get_orders' ) ) {
        return false;
    }
    $orders = wc_get_orders( array(
        'limit'      => 10,
        'meta_key'   => '_transaction_id',
        'meta_value' => $charge_id,
        'return'     => 'ids',
    ) );
    if ( empty( $orders ) ) {
        $orders = wc_get_orders( array(
            'limit'      => 10,
            'meta_key'   => '_stripe_charge_id',
            'meta_value' => $charge_id,
            'return'     => 'ids',
        ) );
    }
    if ( empty( $orders ) ) {
        return false;
    }
    return wc_get_order( $orders[0] );
}

function li_record_stripe_reversal( $wc_order, $total_cents, $entry_type, $charge_id, $extra_meta = array() ) {
    $order_number = strval( $wc_order->get_order_number() );
    $rescue_slug  = $wc_order->get_meta( '_li_rescue_slug' );
    $rescue       = $rescue_slug ? li_get_rescue_by_slug( $rescue_slug ) : null;
    $rescue_id    = $rescue ? ( $rescue['id'] ?? '' ) : '';

    $order_total_cents = max( 1, intval( round( floatval( $wc_order->get_total() ) * 100 ) ) );
    $remaining_cents   = $total_cents;
    $items             = $wc_order->get_items();
    $item_count        = count( $items );
    $item_index        = 0;

    foreach ( $items as $item ) {
        $item_index++;
        $product = $item->get_product();
        if ( ! $product ) continue;

        $sku = $product->get_sku() ? $product->get_sku() : 'WC-' . $product->get_id();
        $sp  = li_get_product_by_sku( $sku );
        $product_id = $sp ? ( $sp['id'] ?? '' ) : '';

        $line_cents = max( 0, intval( round( floatval( $item->get_total() ) * 100 ) ) );
        if ( $line_cents <= 0 ) continue;

        // Proportional allocation across line items.
        if ( $item_index === $item_count ) {
            $allocated = $remaining_cents;
        } else {
            $allocated = intval( round( $total_cents * ( $line_cents / $order_total_cents ) ) );
        }
        $allocated = min( $allocated, $remaining_cents );
        $remaining_cents -= $allocated;

        if ( $allocated <= 0 ) continue;

        $line_order_ref = $order_number . '::' . $product_id;

        li_record_ledger( $entry_type, array(
            'order_ref'    => $line_order_ref,
            'product_id'   => $product_id,
            'rescue_id'    => $rescue_id,
            'amount_cents' => -1 * $allocated,
            'net_cents'    => -1 * $allocated,
            'meta'         => array_merge( array( 'charge_id' => $charge_id, 'woocommerce_order_id' => $wc_order->get_id() ), $extra_meta ),
            'source'       => 'stripe',
        ) );

        $orders = li_db_get( 'orders?shopify_order_id=eq.' . urlencode( $line_order_ref ) . '&select=id' );
        if ( is_array( $orders ) && ! empty( $orders ) ) {
            $new_status = $entry_type === 'chargeback' ? 'disputed' : 'refunded';
            foreach ( $orders as $o ) {
                li_db_patch( 'orders?id=eq.' . urlencode( $o['id'] ), array( 'status' => $new_status ) );
            }
        }
    }

    $dollars       = number_format( $total_cents / 100, 2 );
    $rescue_name   = $rescue ? ( $rescue['name'] ?? 'Rescue' ) : 'Rescue';
    $subject_label = $entry_type === 'chargeback' ? 'Chargeback' : 'Refund';

    li_notify_admin(
        "$subject_label received: Order #$order_number",
        "$subject_label of \$$dollars received for order #$order_number.\nCharge ID: $charge_id\nRescue: $rescue_name\n\nReview orders: " . admin_url( 'admin.php?page=li-splits' )
    );

    if ( $rescue_id ) {
        li_notify_rescue(
            $rescue_id,
            "$subject_label on your Larry Impact order",
            "Hi,\n\nA $subject_label of \$$dollars has been initiated on order #$order_number for $rescue_name. This may affect your pending payout.\n\nThank you,\nLarry Impact"
        );
    }

    li_audit_log( $entry_type . '_received', array(
        'charge_id' => $charge_id,
        'order_id'  => $wc_order->get_id(),
        'amount_cents' => $total_cents,
    ), 'order', $order_number );
}
