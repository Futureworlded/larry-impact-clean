<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Shopify refunds and cancellations.
 *
 * A refund never edits the original order line. It adds a reversing line
 * (<order>::<product>::refund-<refund id>) with negative amounts and status
 * "pending", so the rescue's next payout is reduced by the refunded share, even
 * when the original line was already paid. The unique shopify_order_id column
 * makes each reversal impossible to save twice.
 */

function li_refund_line_ref( $order_ref, $product_id, $refund_id ) {
    return $order_ref . '::' . $product_id . '::refund-' . $refund_id;
}

/**
 * Splits a refunded amount between rescue and Larry in the same proportion as the
 * original line, never reversing more than the line still has.
 *
 * @param array $original       Order row: sale_amount_cents, rescue_split_cents, larry_split_cents.
 * @param int   $refund_cents   Refunded amount for this line (before tax).
 * @param int   $already_cents  Sale amount already reversed by earlier refunds (positive).
 * @param int   $already_rescue Rescue amount already reversed by earlier refunds (positive).
 */
function li_refund_reversal( $original, $refund_cents, $already_cents = 0, $already_rescue = 0 ) {
    $sale   = max( 0, intval( $original['sale_amount_cents'] ?? 0 ) );
    $rescue = max( 0, intval( $original['rescue_split_cents'] ?? 0 ) );
    $left_sale   = max( 0, $sale - max( 0, intval( $already_cents ) ) );
    $left_rescue = max( 0, $rescue - max( 0, intval( $already_rescue ) ) );
    $refund = min( max( 0, intval( $refund_cents ) ), $left_sale );
    if ( $refund <= 0 || $sale <= 0 ) {
        return array( 'sale_cents' => 0, 'rescue_cents' => 0, 'larry_cents' => 0 );
    }
    $rescue_back = $refund >= $left_sale ? $left_rescue : min( $left_rescue, intval( round( $rescue * $refund / $sale ) ) );
    return array(
        'sale_cents'   => $refund,
        'rescue_cents' => $rescue_back,
        'larry_cents'  => $refund - $rescue_back,
    );
}

/**
 * Finds the Larry order number (e.g. LR-1006) for a numeric Shopify order id.
 */
function li_shopify_order_ref_by_id( $shopify_order_id ) {
    $event = li_order_event_get_by_external( 'shopify', (string) $shopify_order_id );
    if ( $event && $event->order_ref ) {
        return $event->order_ref;
    }
    $data = li_shopify_graphql( 'query LarryOrderName($id: ID!) { order(id: $id) { name } }', array( 'id' => 'gid://shopify/Order/' . intval( $shopify_order_id ) ) );
    if ( is_wp_error( $data ) || empty( $data['order']['name'] ) ) {
        return '';
    }
    return ltrim( sanitize_text_field( $data['order']['name'] ), '#' );
}

/**
 * Turns a GraphQL refund node into the same shape as the refunds/create webhook.
 */
function li_shopify_graphql_refund_to_payload( $order_id, $order_name, $node ) {
    $lines = array();
    foreach ( (array) ( $node['refundLineItems']['nodes'] ?? array() ) as $line ) {
        $lines[] = array(
            'quantity'  => intval( $line['quantity'] ?? 0 ),
            'subtotal'  => $line['subtotalSet']['shopMoney']['amount'] ?? 0,
            'line_item' => array( 'sku' => $line['lineItem']['sku'] ?? '', 'title' => $line['lineItem']['title'] ?? '' ),
        );
    }
    return array(
        'id'                => (string) ( $node['legacyResourceId'] ?? '' ),
        'order_id'          => (string) $order_id,
        'order_name'        => $order_name,
        'created_at'        => $node['createdAt'] ?? '',
        'refund_line_items' => $lines,
    );
}

function li_process_shopify_refund( $refund ) {
    $refund_id = sanitize_text_field( (string) ( $refund['id'] ?? '' ) );
    $order_ref = ! empty( $refund['order_name'] ) ? ltrim( sanitize_text_field( $refund['order_name'] ), '#' ) : li_shopify_order_ref_by_id( $refund['order_id'] ?? '' );
    $outcome   = array(
        'order_ref' => $order_ref,
        'refund_id' => $refund_id,
        'recorded'  => array(),
        'skipped'   => array(),
        'failed'    => array(),
        'pending'   => array(),
        'held'      => false,
    );
    if ( ! $refund_id || ! $order_ref ) {
        $outcome['skipped'][] = array( 'title' => 'Refund ' . $refund_id, 'sku' => '', 'reason' => 'refund_no_order' );
        return $outcome;
    }

    foreach ( (array) ( $refund['refund_line_items'] ?? array() ) as $rl ) {
        $item  = (array) ( $rl['line_item'] ?? array() );
        $sku   = sanitize_text_field( (string) ( $item['sku'] ?? '' ) );
        $line  = array( 'sku' => $sku, 'title' => sanitize_text_field( (string) ( $item['title'] ?? $sku ) ), 'qty' => intval( $rl['quantity'] ?? 0 ) );
        $cents = intval( round( floatval( $rl['subtotal'] ?? 0 ) * 100 ) );
        if ( $cents <= 0 ) {
            continue;
        }
        $product  = '' !== $sku ? li_get_product_by_sku( $sku ) : null;
        $original = $product ? li_db_get( 'orders?shopify_order_id=eq.' . urlencode( $order_ref . '::' . $product['id'] ) . '&select=*' ) : array();
        if ( empty( $original ) ) {
            // Nothing was credited for this line (or it is part of a bundle), so check it by hand.
            $line['reason']        = 'refund_no_line';
            $outcome['skipped'][] = $line;
            continue;
        }
        $original = $original[0];
        $ref      = li_refund_line_ref( $order_ref, $product['id'], $refund_id );
        if ( li_db_get( 'orders?shopify_order_id=eq.' . urlencode( $ref ) . '&select=id' ) ) {
            $line['already']        = true;
            $outcome['recorded'][] = $line;
            continue;
        }
        $earlier = li_db_get( 'orders?shopify_order_id=like.' . urlencode( $order_ref . '::' . $product['id'] . '::refund-*' ) . '&select=sale_amount_cents,rescue_split_cents' );
        $done_sale = 0;
        $done_rescue = 0;
        foreach ( (array) $earlier as $e ) {
            $done_sale   += abs( intval( $e['sale_amount_cents'] ?? 0 ) );
            $done_rescue += abs( intval( $e['rescue_split_cents'] ?? 0 ) );
        }
        $rev = li_refund_reversal( $original, $cents, $done_sale, $done_rescue );
        if ( $rev['sale_cents'] <= 0 ) {
            $line['already']        = true;
            $outcome['recorded'][] = $line;
            continue;
        }
        $row = array(
            'shopify_order_id'   => $ref,
            'product_id'         => $product['id'],
            'quantity'           => -1 * max( 0, $line['qty'] ),
            'sale_amount_cents'  => -1 * $rev['sale_cents'],
            'rescue_split_cents' => -1 * $rev['rescue_cents'],
            'larry_split_cents'  => -1 * $rev['larry_cents'],
            'status'             => 'pending',
            'ordered_at'         => sanitize_text_field( (string) ( $refund['created_at'] ?? gmdate( 'c' ) ) ),
        );
        if ( ! empty( $original['rescue_id'] ) ) {
            $row['rescue_id'] = $original['rescue_id'];
        }
        $r    = li_db_post( 'orders', $row );
        $code = is_wp_error( $r ) ? 0 : intval( wp_remote_retrieve_response_code( $r ) );
        if ( 409 === $code ) {
            $line['already']        = true;
            $outcome['recorded'][] = $line;
            continue;
        }
        if ( ! $code || $code >= 400 ) {
            $line['reason']       = 'save_failed';
            $outcome['failed'][] = $line;
            continue;
        }
        li_record_ledger( 'refund', array(
            'order_ref'    => $ref,
            'product_id'   => $product['id'],
            'rescue_id'    => $original['rescue_id'] ?? '',
            'amount_cents' => -1 * $rev['sale_cents'],
            'net_cents'    => -1 * $rev['sale_cents'],
            'meta'         => array( 'shopify_refund_id' => $refund_id, 'rescue_cents' => -1 * $rev['rescue_cents'], 'larry_cents' => -1 * $rev['larry_cents'], 'original_status' => $original['status'] ?? '' ),
            'source'       => 'shopify',
        ) );
        li_audit_log( 'order_refunded', array( 'order_ref' => $ref, 'refund_id' => $refund_id, 'amount_cents' => $rev['sale_cents'], 'rescue_cents' => $rev['rescue_cents'] ), 'order', $order_ref );
        $line['rescue_cents']  = $rev['rescue_cents'];
        $line['sale_cents']    = $rev['sale_cents'];
        $line['rescue_id']     = $original['rescue_id'] ?? '';
        $outcome['recorded'][] = $line;
    }

    $new = array_filter( $outcome['recorded'], function( $l ) { return empty( $l['already'] ); } );
    if ( $new ) {
        $rescue_back = array_sum( array_column( $new, 'rescue_cents' ) );
        $sale_back   = array_sum( array_column( $new, 'sale_cents' ) );
        li_notify_admin(
            'Refund recorded: Order ' . $order_ref,
            'Shopify refund ' . $refund_id . ' on order ' . $order_ref . ': ' . li_format_money( $sale_back ) . ' refunded, ' . li_format_money( $rescue_back ) . " taken back from the rescue's next payout.\n\nReview: " . admin_url( 'admin.php?page=li-splits' )
        );
    }
    return $outcome;
}

function li_ingest_shopify_refund( $refund, $context = array() ) {
    $refund   = array_intersect_key( (array) $refund, array_flip( array( 'id', 'order_id', 'order_name', 'created_at', 'refund_line_items' ) ) );
    $lines    = array();
    foreach ( (array) ( $refund['refund_line_items'] ?? array() ) as $rl ) {
        $item    = (array) ( $rl['line_item'] ?? array() );
        $lines[] = array(
            'quantity'  => $rl['quantity'] ?? 0,
            'subtotal'  => $rl['subtotal'] ?? 0,
            'line_item' => array( 'sku' => $item['sku'] ?? '', 'title' => $item['title'] ?? '' ),
        );
    }
    $refund['refund_line_items'] = $lines;
    $outcome  = li_process_shopify_refund( $refund );
    $event_id = li_order_event_save( array(
        'source'      => 'shopify_refund',
        'external_id' => (string) ( $refund['id'] ?? '' ),
        'order_ref'   => $outcome['order_ref'],
        'topic'       => $context['topic'] ?? 'refunds/create',
        'signature'   => $context['signature'] ?? '',
        'payload'     => $refund,
    ) );
    return li_order_event_finish( $event_id, $outcome );
}
