<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Every money record lives in Supabase: orders, the ledger, payout batches and
 * their lines, order events, fraud flags, order locks, split versions and the
 * audit log. WordPress keeps none of it. The ledger and audit log are
 * append-only in the database (see db/migrations/002_money_tables.sql).
 *
 * Records that were kept in WordPress before 3.5.0 are copied over once, keeping
 * their IDs, and the copy is checked row by row and cent by cent.
 */

function li_store_request( $method, $endpoint, $body = null, $prefer = 'return=representation' ) {
    $key = li_db_key_or_error();
    if ( is_wp_error( $key ) ) {
        return $key;
    }
    $headers = array(
        'apikey'        => $key,
        'Authorization' => 'Bearer ' . $key,
        'Content-Type'  => 'application/json',
    );
    if ( $prefer ) {
        $headers['Prefer'] = $prefer;
    }
    $args = array( 'method' => $method, 'timeout' => 20, 'headers' => $headers );
    if ( null !== $body ) {
        $args['body'] = wp_json_encode( $body );
    }
    $r = wp_remote_request( LI_DB_URL . '/rest/v1/' . $endpoint, $args );
    if ( is_wp_error( $r ) ) {
        return $r;
    }
    $code = intval( wp_remote_retrieve_response_code( $r ) );
    if ( $code >= 400 ) {
        return new WP_Error( 'li_store_' . $code, trim( wp_remote_retrieve_body( $r ) ), array( 'status' => $code ) );
    }
    $data = json_decode( wp_remote_retrieve_body( $r ), true );
    return is_array( $data ) ? $data : array();
}

function li_store_error( $what, $err ) {
    // error_log only: li_log writes to the audit log, which could loop.
    error_log( 'Larry Impact: ' . $what . ' failed: ' . ( is_wp_error( $err ) ? $err->get_error_message() : 'unknown' ) );
}

function li_store_insert( $table, $row ) {
    $r = li_store_request( 'POST', $table, $row );
    if ( is_wp_error( $r ) ) {
        li_store_error( 'Saving to ' . $table, $r );
        return 0;
    }
    return intval( $r[0]['id'] ?? 0 );
}

/** Updates matching rows and returns the rows that changed (empty when none matched), or false on error. */
function li_store_update( $table, $query, $data ) {
    $r = li_store_request( 'PATCH', $table . '?' . $query, $data );
    if ( is_wp_error( $r ) ) {
        li_store_error( 'Updating ' . $table, $r );
        return false;
    }
    return $r;
}

function li_store_select( $table, $query = '' ) {
    $r = li_store_request( 'GET', $table . ( $query ? '?' . $query : '' ), null, '' );
    return is_wp_error( $r ) ? array() : $r;
}

function li_store_select_all( $table, $query = '' ) {
    $rows   = array();
    $limit  = 1000;
    $offset = 0;
    do {
        $page = li_store_select( $table, ltrim( $query . '&order=id.asc&limit=' . $limit . '&offset=' . $offset, '&' ) );
        $rows = array_merge( $rows, $page );
        $offset += $limit;
    } while ( count( $page ) === $limit );
    return $rows;
}

function li_store_first( $table, $query ) {
    $rows = li_store_select( $table, $query . '&limit=1' );
    return $rows ? $rows[0] : null;
}

function li_store_objects( $rows ) {
    return array_map( function( $r ) { return (object) $r; }, (array) $rows );
}

function li_store_now() {
    return gmdate( 'c' );
}

function li_store_local_time( $value, $format = 'M j, Y g:i a' ) {
    $ts = $value ? strtotime( $value ) : false;
    return $ts ? wp_date( $format, $ts ) : '';
}

/* -------------------------------------------------------------------------
 * One-time copy of the WordPress money tables into Supabase.
 * ---------------------------------------------------------------------- */

function li_money_tables_map() {
    return array(
        'li_ledger'         => array( 'ledger', array( 'id', 'created_at', 'entry_type', 'order_ref', 'product_id', 'rescue_id', 'amount_cents', 'net_cents', 'meta', 'source' ) ),
        'li_payouts'        => array( 'payout_batches', array( 'id', 'created_at', 'rescue_id', 'amount_cents', 'transfer_id', 'status', 'notes' ) ),
        'li_payout_lines'   => array( 'payout_lines', array( 'id', 'payout_id', 'order_ref', 'amount_cents' ) ),
        'li_audit_log'      => array( 'audit_log', array( 'id', 'created_at', 'actor', 'action', 'object_type', 'object_id', 'details', 'ip_address' ) ),
        'li_fraud_flags'    => array( 'fraud_flags', array( 'id', 'created_at', 'flag_type', 'order_ref', 'product_id', 'rescue_id', 'details', 'resolved' ) ),
        'li_order_locks'    => array( 'order_locks', array( 'id', 'created_at', 'order_ref', 'locked_at' ) ),
        'li_split_versions' => array( 'split_versions', array( 'id', 'created_at', 'product_id', 'version', 'rescue_percent', 'larry_percent', 'cost_percent', 'effective_from', 'created_by' ) ),
        'li_order_events'   => array( 'order_events', array( 'id', 'created_at', 'updated_at', 'source', 'topic', 'external_id', 'order_ref', 'signature', 'result', 'attempts', 'details', 'payload', 'resolved', 'resolved_at', 'resolved_by' ) ),
    );
}

/** Converts a WordPress row to the Supabase shape. */
function li_money_convert_row( $row ) {
    $out = array();
    foreach ( $row as $col => $val ) {
        if ( in_array( $col, array( 'created_at', 'updated_at', 'resolved_at', 'locked_at', 'effective_from' ), true ) ) {
            if ( ! $val || 0 === strpos( (string) $val, '0000-00-00' ) ) {
                continue;
            }
            $val = get_gmt_from_date( $val, 'c' );
        } elseif ( 'resolved' === $col ) {
            $val = (bool) intval( $val );
        } elseif ( in_array( $col, array( 'id', 'payout_id', 'amount_cents', 'net_cents', 'version', 'created_by', 'attempts', 'resolved_by' ), true ) ) {
            $val = intval( $val );
        } elseif ( in_array( $col, array( 'rescue_percent', 'larry_percent', 'cost_percent' ), true ) ) {
            $val = floatval( $val );
        } else {
            $val = null === $val ? null : (string) $val;
        }
        $out[ $col ] = $val;
    }
    return $out;
}

function li_money_wp_table_exists( $table ) {
    global $wpdb;
    return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
}

function li_money_migrate_from_wordpress() {
    global $wpdb;
    $report = array( 'status' => 'done', 'at' => li_store_now(), 'tables' => array(), 'error' => '' );
    $payout_ids = array();

    foreach ( li_money_tables_map() as $wp_name => $map ) {
        list( $sb_table, $cols ) = $map;
        $wp_table = $wpdb->prefix . $wp_name;
        $row      = array( 'wp_rows' => 0, 'copied' => 0, 'supabase_rows' => 0, 'wp_cents' => 0, 'supabase_cents' => 0 );
        if ( ! li_money_wp_table_exists( $wp_table ) ) {
            $report['tables'][ $sb_table ] = $row;
            continue;
        }
        $max_id = intval( $wpdb->get_var( "SELECT MAX(id) FROM {$wp_table}" ) );
        if ( $max_id >= 1000000 ) {
            $report['status'] = 'failed';
            $report['error']  = $wp_table . ' has IDs above 1,000,000 and cannot be copied safely.';
            break;
        }
        $has_cents = in_array( 'amount_cents', $cols, true );
        $last_id   = 0;
        do {
            $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT ' . implode( ',', $cols ) . " FROM {$wp_table} WHERE id > %d ORDER BY id ASC LIMIT 200", $last_id ), ARRAY_A );
            $batch = array();
            foreach ( (array) $rows as $r ) {
                $last_id = intval( $r['id'] );
                $row['wp_rows']++;
                if ( $has_cents ) {
                    $row['wp_cents'] += intval( $r['amount_cents'] );
                }
                if ( 'payout_lines' === $sb_table && ! isset( $payout_ids[ intval( $r['payout_id'] ) ] ) ) {
                    $row['orphans'] = ( $row['orphans'] ?? 0 ) + 1;
                    continue;
                }
                if ( 'payout_batches' === $sb_table ) {
                    $payout_ids[ intval( $r['id'] ) ] = true;
                }
                $batch[] = li_money_convert_row( $r );
            }
            if ( $batch ) {
                $res = li_store_request( 'POST', $sb_table . '?on_conflict=id', $batch, 'resolution=ignore-duplicates,return=minimal' );
                if ( is_wp_error( $res ) ) {
                    $report['status'] = 'failed';
                    $report['error']  = 'Copying ' . $wp_name . ': ' . $res->get_error_message();
                    break 2;
                }
                $row['copied'] += count( $batch );
            }
        } while ( count( (array) $rows ) === 200 );

        $copied = li_store_select_all( $sb_table, 'select=id' . ( $has_cents ? ',amount_cents' : '' ) . '&id=lt.1000000' );
        $row['supabase_rows'] = count( $copied );
        if ( $has_cents ) {
            $row['supabase_cents'] = array_sum( array_map( 'intval', array_column( $copied, 'amount_cents' ) ) );
        }
        $expected_rows  = $row['wp_rows'] - intval( $row['orphans'] ?? 0 );
        $expected_cents = $row['wp_cents'];
        if ( 'payout_lines' === $sb_table && ! empty( $row['orphans'] ) ) {
            $expected_cents = null;
        }
        if ( $row['supabase_rows'] !== $expected_rows || ( null !== $expected_cents && $has_cents && $row['supabase_cents'] !== $expected_cents ) ) {
            $report['status'] = 'failed';
            $report['error']  = $sb_table . ' does not match: WordPress ' . $expected_rows . ' rows, Supabase ' . $row['supabase_rows'] . ' rows.';
        }
        $report['tables'][ $sb_table ] = $row;
        if ( 'failed' === $report['status'] ) {
            break;
        }
    }

    if ( 'done' === $report['status'] ) {
        $report['payouts'] = li_money_migrate_rescue_cents();
        if ( ! empty( $report['payouts']['failed'] ) ) {
            $report['status'] = 'failed';
            $report['error']  = 'Could not save the rescue payout for ' . implode( ', ', $report['payouts']['failed'] ) . '.';
        }
    }
    return $report;
}

/** Moves each product's rescue payout from the WooCommerce product or the Shopify setting to products.rescue_cents. */
function li_money_migrate_rescue_cents() {
    $result   = array( 'set' => 0, 'kept' => 0, 'failed' => array() );
    $shopify  = get_option( 'li_shopify_rescue_cents', array() );
    $shopify  = is_array( $shopify ) ? $shopify : array();
    $products = li_store_select_all( 'products', 'select=id,sku,rescue_cents' );
    foreach ( $products as $p ) {
        $sku = (string) ( $p['sku'] ?? '' );
        if ( '' === $sku ) {
            continue;
        }
        if ( null !== ( $p['rescue_cents'] ?? null ) ) {
            $result['kept']++;
            continue;
        }
        $cents = null;
        $wc_id = function_exists( 'wc_get_product_id_by_sku' ) ? wc_get_product_id_by_sku( $sku ) : 0;
        if ( $wc_id ) {
            $meta = get_post_meta( $wc_id, '_li_rescue_cents', true );
            if ( '' !== $meta ) {
                $cents = max( 0, intval( $meta ) );
            }
        } elseif ( isset( $shopify[ $sku ] ) ) {
            $cents = max( 0, intval( $shopify[ $sku ] ) );
        }
        if ( null === $cents ) {
            continue;
        }
        $r = li_store_update( 'products', 'id=eq.' . urlencode( $p['id'] ), array( 'rescue_cents' => $cents ) );
        if ( false === $r || empty( $r ) || intval( $r[0]['rescue_cents'] ?? -1 ) !== $cents ) {
            $result['failed'][] = $sku;
        } else {
            $result['set']++;
        }
    }
    return $result;
}

add_action( 'init', 'li_money_maybe_migrate', 20 );
function li_money_maybe_migrate() {
    if ( ! ( is_admin() || wp_doing_cron() ) ) {
        return;
    }
    $state = get_option( 'li_money_migration', array() );
    if ( 'done' === ( $state['status'] ?? '' ) ) {
        return;
    }
    if ( get_transient( 'li_money_migrating' ) || '' === li_service_key() ) {
        return;
    }
    set_transient( 'li_money_migrating', 1, 'failed' === ( $state['status'] ?? '' ) ? HOUR_IN_SECONDS : 10 * MINUTE_IN_SECONDS );
    $report = li_money_migrate_from_wordpress();
    update_option( 'li_money_migration', $report, false );
    if ( 'done' === $report['status'] ) {
        delete_transient( 'li_money_migrating' );
        li_audit_log( 'money_moved_to_supabase', $report );
    } else {
        li_notify_admin( 'Larry Impact: moving money records to Supabase failed', $report['error'] . "\n\nNothing was deleted from WordPress. It will try again in an hour.\n\n" . admin_url( 'admin.php?page=li-order-health' ) );
    }
}

add_action( 'admin_notices', 'li_money_migration_notice' );
function li_money_migration_notice() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $state = get_option( 'li_money_migration', array() );
    if ( 'failed' === ( $state['status'] ?? '' ) ) {
        echo '<div class="notice notice-error"><p><strong>Larry Impact:</strong> moving the money records to Supabase did not finish: ' . esc_html( $state['error'] ?? '' ) . ' Nothing was deleted. It will try again in an hour.</p></div>';
        return;
    }
    if ( 'done' !== ( $state['status'] ?? '' ) || strtotime( $state['at'] ?? '' ) < time() - 7 * DAY_IN_SECONDS ) {
        return;
    }
    $parts = array();
    foreach ( (array) ( $state['tables'] ?? array() ) as $table => $t ) {
        if ( $t['wp_rows'] ) {
            $parts[] = $table . ' ' . intval( $t['supabase_rows'] ) . '/' . intval( $t['wp_rows'] );
        }
    }
    $page = sanitize_key( $_GET['page'] ?? '' );
    if ( 'li-order-health' !== $page ) {
        return;
    }
    echo '<div class="notice notice-success"><p><strong>Larry Impact:</strong> all money records now live in Supabase. Copied and checked: ' . esc_html( $parts ? implode( ', ', $parts ) : 'no earlier records' ) . '. Rescue payouts set on ' . intval( $state['payouts']['set'] ?? 0 ) . ' products.</p></div>';
}
