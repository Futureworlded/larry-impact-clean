<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function li_page_reports() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Unauthorized.' );
    }
    $report = li_get_report_data();
    ob_start();
    echo '<div class="li-stat-grid">';
    echo '<div class="li-stat"><div class="li-stat-val">$' . esc_html( number_format( $report['total_revenue'] / 100, 2 ) ) . '</div><div class="li-stat-label">Total Revenue</div></div>';
    echo '<div class="li-stat"><div class="li-stat-val li-stat-val-amber">$' . esc_html( number_format( $report['total_payouts'] / 100, 2 ) ) . '</div><div class="li-stat-label">Rescue Payouts</div></div>';
    echo '<div class="li-stat"><div class="li-stat-val li-stat-val-green">$' . esc_html( number_format( $report['larry_revenue'] / 100, 2 ) ) . '</div><div class="li-stat-label">Net / Larry Revenue</div></div>';
    echo '<div class="li-stat"><div class="li-stat-val">$' . esc_html( number_format( $report['outstanding'] / 100, 2 ) ) . '</div><div class="li-stat-label">Outstanding Liability</div></div>';
    echo '<div class="li-stat"><div class="li-stat-val">' . esc_html( $report['order_count'] ) . '</div><div class="li-stat-label">Orders</div></div>';
    echo '<div class="li-stat"><div class="li-stat-val">$' . esc_html( number_format( $report['avg_order'] / 100, 2 ) ) . '</div><div class="li-stat-label">Avg Order</div></div>';
    echo '<div class="li-stat"><div class="li-stat-val">$' . esc_html( number_format( $report['refunds'] / 100, 2 ) ) . '</div><div class="li-stat-label">Refunds</div></div>';
    echo '<div class="li-stat"><div class="li-stat-val">$' . esc_html( number_format( $report['chargebacks'] / 100, 2 ) ) . '</div><div class="li-stat-label">Chargebacks</div></div>';
    echo '</div>';

    echo '<div class="li-row" style="margin-bottom:1rem;">';
    echo '<div class="li-card"><div class="li-card-title">Top Rescues</div>';
    if ( empty( $report['top_rescues'] ) ) {
        echo '<p style="color:#6b6560;font-size:13px;">No rescue revenue recorded yet.</p>';
    } else {
        echo '<table class="li-table"><thead><tr><th>Rescue</th><th style="text-align:right;">Earned</th></tr></thead><tbody>';
        foreach ( $report['top_rescues'] as $r ) {
            echo '<tr><td>' . esc_html( $r['name'] ?: 'Unknown' ) . '</td><td style="text-align:right;">$' . esc_html( number_format( $r['amount'] / 100, 2 ) ) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';

    echo '<div class="li-card"><div class="li-card-title">Top Products</div>';
    if ( empty( $report['top_products'] ) ) {
        echo '<p style="color:#6b6560;font-size:13px;">No product revenue recorded yet.</p>';
    } else {
        echo '<table class="li-table"><thead><tr><th>Product</th><th style="text-align:right;">Sales</th></tr></thead><tbody>';
        foreach ( $report['top_products'] as $p ) {
            echo '<tr><td>' . esc_html( $p['name'] ?: 'Unknown' ) . '</td><td style="text-align:right;">$' . esc_html( number_format( $p['amount'] / 100, 2 ) ) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';
    echo '</div>';

    echo '<div class="li-card" style="margin-bottom:1rem;"><div class="li-card-title">Sales by Month</div>';
    if ( empty( $report['sales_by_month'] ) ) {
        echo '<p style="color:#6b6560;font-size:13px;">No monthly data yet.</p>';
    } else {
        echo '<table class="li-table"><thead><tr><th>Month</th><th style="text-align:right;">Revenue</th></tr></thead><tbody>';
        foreach ( $report['sales_by_month'] as $m => $a ) {
            echo '<tr><td>' . esc_html( $m ) . '</td><td style="text-align:right;">$' . esc_html( number_format( $a / 100, 2 ) ) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';

    echo '<div class="li-row" style="margin-bottom:1rem;">';
    echo '<div class="li-card"><div class="li-card-title">Recent Payout Batches</div>';
    if ( empty( $report['recent_payouts'] ) ) {
        echo '<p style="color:#6b6560;font-size:13px;">No payouts recorded yet.</p>';
    } else {
        echo '<table class="li-table"><thead><tr><th>Date</th><th>Rescue</th><th>Amount</th><th>Status</th><th>Transfer</th></tr></thead><tbody>';
        foreach ( $report['recent_payouts'] as $p ) {
            $date = date( 'M j, Y', strtotime( $p->created_at ) );
            $rescue_name = $p->rescue_id ? ( li_get_rescue_by_id( $p->rescue_id )['name'] ?? $p->rescue_id ) : 'Unknown';
            echo '<tr><td>' . esc_html( $date ) . '</td><td>' . esc_html( $rescue_name ) . '</td><td>$' . esc_html( number_format( $p->amount_cents / 100, 2 ) ) . '</td><td>' . esc_html( $p->status ) . '</td><td>' . esc_html( $p->transfer_id ) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';

    echo '<div class="li-card"><div class="li-card-title">Fraud Flags</div>';
    if ( empty( $report['fraud_flags'] ) ) {
        echo '<p style="color:#6b6560;font-size:13px;">No fraud flags yet.</p>';
    } else {
        echo '<table class="li-table"><thead><tr><th>Date</th><th>Type</th><th>Order</th><th>Rescue</th></tr></thead><tbody>';
        foreach ( $report['fraud_flags'] as $f ) {
            $date = date( 'M j, Y', strtotime( $f->created_at ) );
            echo '<tr><td>' . esc_html( $date ) . '</td><td>' . esc_html( $f->flag_type ) . '</td><td>' . esc_html( $f->order_ref ) . '</td><td>' . esc_html( $f->rescue_id ) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';
    echo '</div>';

    li_admin_wrap( 'Reports', ob_get_clean() );
}

function li_get_report_data() {
    global $wpdb;
    $report = array(
        'total_revenue'  => 0,
        'total_payouts'  => 0,
        'larry_revenue'  => 0,
        'outstanding'    => 0,
        'order_count'    => 0,
        'avg_order'      => 0,
        'refunds'        => 0,
        'chargebacks'    => 0,
        'top_rescues'    => array(),
        'top_products'   => array(),
        'sales_by_month' => array(),
        'recent_payouts' => array(),
        'fraud_flags'    => array(),
    );

    $rows = $wpdb->get_results( "SELECT entry_type, rescue_id, product_id, amount_cents, net_cents, meta, created_at FROM {$wpdb->prefix}li_ledger" );
    if ( ! is_array( $rows ) ) {
        return $report;
    }

    $rescue_totals  = array();
    $product_totals = array();
    $month_totals   = array();

    foreach ( $rows as $r ) {
        $amount = intval( $r->amount_cents );
        $meta   = json_decode( $r->meta, true ) ?: array();
        $month  = date( 'Y-m', strtotime( $r->created_at ) );

        switch ( $r->entry_type ) {
            case 'order':
                $report['total_revenue'] += $amount;
                $report['larry_revenue'] += intval( $meta['larry_cents'] ?? 0 );
                $report['order_count']++;
                $rescue_totals[ $r->rescue_id ]  = ( $rescue_totals[ $r->rescue_id ] ?? 0 ) + intval( $meta['rescue_cents'] ?? 0 );
                $product_totals[ $r->product_id ] = ( $product_totals[ $r->product_id ] ?? 0 ) + $amount;
                $month_totals[ $month ]           = ( $month_totals[ $month ] ?? 0 ) + $amount;
                break;
            case 'payout':
                $report['total_payouts'] += abs( $amount );
                $report['larry_revenue'] -= abs( $amount );
                break;
            case 'refund':
                $report['refunds'] += abs( $amount );
                $report['larry_revenue'] -= abs( $amount );
                break;
            case 'chargeback':
                $report['chargebacks'] += abs( $amount );
                $report['larry_revenue'] -= abs( $amount );
                break;
            case 'payout_rollback':
                $report['total_payouts'] -= abs( $amount );
                $report['larry_revenue'] += abs( $amount );
                break;
        }
    }

    arsort( $rescue_totals );
    arsort( $product_totals );
    arsort( $month_totals );

    foreach ( array_slice( $rescue_totals, 0, 5, true ) as $rescue_id => $amount ) {
        $rescue = $rescue_id ? li_get_rescue_by_id( $rescue_id ) : null;
        $report['top_rescues'][] = array(
            'id'     => $rescue_id,
            'name'   => $rescue ? ( $rescue['name'] ?? $rescue_id ) : 'Unknown',
            'amount' => $amount,
        );
    }

    foreach ( array_slice( $product_totals, 0, 5, true ) as $product_id => $amount ) {
        $product = $product_id ? li_get_product_by_id( $product_id ) : null;
        $report['top_products'][] = array(
            'id'     => $product_id,
            'name'   => $product ? ( $product['name'] ?? $product_id ) : 'Unknown',
            'amount' => $amount,
        );
    }

    $report['sales_by_month'] = $month_totals;
    $report['avg_order']      = $report['order_count'] > 0 ? round( $report['total_revenue'] / $report['order_count'] ) : 0;
    $report['outstanding']    = max( 0, $report['total_revenue'] - $report['total_payouts'] - $report['refunds'] - $report['chargebacks'] );
    $report['larry_revenue']  = max( 0, $report['larry_revenue'] );

    $report['recent_payouts'] = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}li_payouts ORDER BY created_at DESC LIMIT 10" );
    $report['fraud_flags']  = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}li_fraud_flags ORDER BY created_at DESC LIMIT 10" );

    return $report;
}
