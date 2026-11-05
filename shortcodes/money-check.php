<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Daily money balance check. For every rescue:
 *   earned (sales minus refunds) - in payouts = still owed
 * which holds when the order lines marked "in a payout" or "paid" add up to the
 * payout batches. It also checks each order line's split, each refund against its
 * original sale, and each batch against its lines. Any difference is emailed.
 */

function li_money_is_refund_ref( $ref ) {
    return false !== strpos( (string) $ref, '::refund-' );
}

/**
 * @param array $orders  Rows with shopify_order_id, rescue_id, sale_amount_cents, rescue_split_cents, larry_split_cents, status.
 * @param array $batches Rows with id, rescue_id, amount_cents, status, transfer_id, created_at.
 * @param array $lines   Rows with payout_id, order_ref, amount_cents.
 * @return array { problems: string[], rescues: [rescue_id => earned, in_payouts, owed, settled] }
 */
function li_money_check_problems( $orders, $batches, $lines, $now = null ) {
    $now      = $now ? $now : time();
    $problems = array();
    $rescues  = array();
    $by_ref   = array();
    $reversed = array();

    foreach ( $orders as $o ) {
        $ref    = (string) ( $o['shopify_order_id'] ?? '' );
        $sale   = intval( $o['sale_amount_cents'] ?? 0 );
        $rescue = intval( $o['rescue_split_cents'] ?? 0 );
        $larry  = intval( $o['larry_split_cents'] ?? 0 );
        $status = (string) ( $o['status'] ?? '' );
        $by_ref[ $ref ] = $o;

        if ( li_money_is_refund_ref( $ref ) ) {
            if ( $sale > 0 || $rescue > 0 || $larry > 0 || abs( $rescue ) + abs( $larry ) > abs( $sale ) ) {
                $problems[] = 'Refund line ' . $ref . ' has amounts that do not add up.';
            }
            $orig = substr( $ref, 0, strpos( $ref, '::refund-' ) );
            $reversed[ $orig ]['sale']   = ( $reversed[ $orig ]['sale'] ?? 0 ) + abs( $sale );
            $reversed[ $orig ]['rescue'] = ( $reversed[ $orig ]['rescue'] ?? 0 ) + abs( $rescue );
        } elseif ( $sale < 0 || $rescue < 0 || $larry < 0 || $rescue + $larry > $sale ) {
            $problems[] = 'Order line ' . $ref . ': the rescue and Larry shares are more than the sale.';
        }

        if ( in_array( $status, array( 'refunded', 'disputed' ), true ) ) {
            continue;
        }
        $rid = (string) ( $o['rescue_id'] ?? '' );
        if ( '' === $rid ) {
            continue;
        }
        if ( ! isset( $rescues[ $rid ] ) ) {
            $rescues[ $rid ] = array( 'earned' => 0, 'in_payouts' => 0, 'owed' => 0, 'settled' => 0 );
        }
        $rescues[ $rid ]['earned'] += $rescue;
        if ( 'pending' === $status ) {
            $rescues[ $rid ]['owed'] += $rescue;
        } else {
            $rescues[ $rid ]['settled'] += $rescue;
        }
    }

    foreach ( $reversed as $orig => $r ) {
        if ( ! isset( $by_ref[ $orig ] ) ) {
            $problems[] = 'Refund lines point to order line ' . $orig . ', which does not exist.';
            continue;
        }
        if ( $r['sale'] > intval( $by_ref[ $orig ]['sale_amount_cents'] ?? 0 ) || $r['rescue'] > intval( $by_ref[ $orig ]['rescue_split_cents'] ?? 0 ) ) {
            $problems[] = 'Order line ' . $orig . ' has been refunded more than it was sold for.';
        }
    }

    $lines_by_batch = array();
    $batched_refs   = array();
    foreach ( $lines as $l ) {
        $lines_by_batch[ intval( $l['payout_id'] ) ][] = $l;
    }
    foreach ( $batches as $b ) {
        $status = (string) ( $b['status'] ?? '' );
        if ( 'rolled_back' === $status ) {
            continue;
        }
        $id    = intval( $b['id'] );
        $total = 0;
        foreach ( $lines_by_batch[ $id ] ?? array() as $l ) {
            $total += intval( $l['amount_cents'] );
            $ref = (string) $l['order_ref'];
            if ( 0 !== strpos( $ref, 'bulk_' ) ) {
                $batched_refs[ $ref ] = ( $batched_refs[ $ref ] ?? 0 ) + 1;
            }
        }
        if ( $total !== intval( $b['amount_cents'] ) ) {
            $problems[] = 'Payout batch #' . $id . ' is ' . li_format_money( $b['amount_cents'] ) . ' but its orders add up to ' . li_format_money( $total ) . '.';
        }
        if ( 'sending' === $status && strtotime( $b['created_at'] ?? '' ) < $now - HOUR_IN_SECONDS ) {
            $problems[] = 'Payout batch #' . $id . ' has been sending to Stripe for over an hour. Check Stripe before retrying.';
        }
        $rid = (string) ( $b['rescue_id'] ?? '' );
        if ( ! isset( $rescues[ $rid ] ) ) {
            $rescues[ $rid ] = array( 'earned' => 0, 'in_payouts' => 0, 'owed' => 0, 'settled' => 0 );
        }
        $rescues[ $rid ]['in_payouts'] += intval( $b['amount_cents'] );
    }

    foreach ( $batched_refs as $ref => $count ) {
        if ( $count > 1 ) {
            $problems[] = 'Order line ' . $ref . ' is in ' . $count . ' payout batches.';
        }
        $status = (string) ( $by_ref[ $ref ]['status'] ?? '' );
        if ( ! isset( $by_ref[ $ref ] ) ) {
            $problems[] = 'A payout batch includes order line ' . $ref . ', which does not exist.';
        } elseif ( 'pending' === $status ) {
            $problems[] = 'Order line ' . $ref . ' is in a payout batch but still marked pending, so it could be paid again.';
        }
    }
    foreach ( $by_ref as $ref => $o ) {
        if ( in_array( (string) ( $o['status'] ?? '' ), array( 'ready_for_payout', 'paid' ), true ) && ! isset( $batched_refs[ $ref ] ) && intval( $o['rescue_split_cents'] ?? 0 ) !== 0 ) {
            $problems[] = 'Order line ' . $ref . ' is marked ' . str_replace( '_', ' ', $o['status'] ) . ' but is not in any payout batch.';
        }
    }

    foreach ( $rescues as $rid => $t ) {
        if ( $t['settled'] !== $t['in_payouts'] ) {
            $name = function_exists( 'li_get_rescue_by_id' ) && $rid ? ( li_get_rescue_by_id( $rid )['name'] ?? $rid ) : $rid;
            $problems[] = $name . ': orders in payouts add up to ' . li_format_money( $t['settled'] ) . ', but its payout batches add up to ' . li_format_money( $t['in_payouts'] ) . '.';
        }
    }

    return array( 'problems' => array_values( array_unique( $problems ) ), 'rescues' => $rescues );
}

function li_run_money_check( $send_email = true ) {
    $orders  = li_store_select_all( 'orders', 'select=id,shopify_order_id,rescue_id,sale_amount_cents,rescue_split_cents,larry_split_cents,status' );
    $batches = li_store_select_all( 'payout_batches', 'select=id,rescue_id,amount_cents,status,transfer_id,created_at' );
    $lines   = li_store_select_all( 'payout_lines', 'select=id,payout_id,order_ref,amount_cents' );
    $result  = li_money_check_problems( $orders, $batches, $lines );
    $result['at']     = time();
    $result['orders'] = count( $orders );
    $previous = get_option( 'li_money_check_last', array() );
    update_option( 'li_money_check_last', $result, false );

    $key = md5( wp_json_encode( $result['problems'] ) );
    if ( $send_email && $result['problems'] && $key !== ( $previous['key'] ?? '' ) ) {
        li_notify_admin(
            'Larry Impact: money balance check found ' . count( $result['problems'] ) . ' problem(s)',
            "The daily balance check found:\n\n- " . implode( "\n- ", $result['problems'] ) . "\n\nReview: " . admin_url( 'admin.php?page=li-order-health' )
        );
    }
    $result['key'] = $key;
    update_option( 'li_money_check_last', $result, false );
    return $result;
}

add_action( 'init', 'li_schedule_money_check' );
function li_schedule_money_check() {
    if ( ! wp_next_scheduled( 'li_money_check_cron' ) ) {
        wp_schedule_event( time() + 900, 'daily', 'li_money_check_cron' );
    }
}

add_action( 'li_money_check_cron', 'li_money_check_cron' );
function li_money_check_cron() {
    li_run_money_check( true );
}

add_action( 'admin_post_li_money_check', 'li_money_check_now' );
function li_money_check_now() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Unauthorized.' );
    }
    check_admin_referer( 'li_money_check' );
    li_run_money_check( true );
    wp_safe_redirect( admin_url( 'admin.php?page=li-order-health' ) );
    exit;
}

function li_money_check_card() {
    $last = get_option( 'li_money_check_last', array() );
    $html = '<div class="li-card"><div class="li-card-title">Money balance check</div>';
    if ( empty( $last['at'] ) ) {
        $html .= '<p style="margin:0 0 12px">Not run yet. It runs every day.</p>';
    } else {
        $when = wp_date( 'M j, Y g:i a', intval( $last['at'] ) );
        if ( empty( $last['problems'] ) ) {
            $html .= '<p style="margin:0 0 12px;color:#3a7a4a"><strong>Everything balances</strong> (' . intval( $last['orders'] ?? 0 ) . ' order lines, checked ' . esc_html( $when ) . ').</p>';
        } else {
            $html .= '<p style="margin:0 0 8px;color:#b91c1c"><strong>' . count( $last['problems'] ) . ' problem(s) found</strong> (checked ' . esc_html( $when ) . '):</p><ul style="margin:0 0 12px 18px;list-style:disc">';
            foreach ( $last['problems'] as $p ) {
                $html .= '<li>' . esc_html( $p ) . '</li>';
            }
            $html .= '</ul>';
        }
        if ( ! empty( $last['rescues'] ) ) {
            $html .= '<div class="li-table-wrap"><table class="li-table"><thead><tr><th>Rescue</th><th>Earned (after refunds)</th><th>In payouts</th><th>Still owed</th><th>Balanced</th></tr></thead><tbody>';
            foreach ( $last['rescues'] as $rid => $t ) {
                $rescue = $rid ? li_get_rescue_by_id( $rid ) : null;
                $ok     = $t['earned'] - $t['in_payouts'] === $t['owed'];
                $html  .= '<tr><td>' . esc_html( $rescue['name'] ?? $rid ) . '</td><td>' . esc_html( li_format_money( $t['earned'] ) ) . '</td><td>' . esc_html( li_format_money( $t['in_payouts'] ) ) . '</td><td>' . esc_html( li_format_money( $t['owed'] ) ) . '</td><td>' . ( $ok ? '<span style="color:#3a7a4a">Yes</span>' : '<span style="color:#b91c1c">No</span>' ) . '</td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
    }
    $html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px">' . wp_nonce_field( 'li_money_check', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="li_money_check" /><button class="li-btn li-btn-sm" type="submit">Run check now</button></form>';
    return $html . '</div>';
}
