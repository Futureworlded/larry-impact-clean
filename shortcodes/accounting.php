<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Larry Impact - Local immutable ledger, payout history, audit log and fraud flags.
 *
 * These tables live in the WordPress database so the financial record stays
 * under site control even if the Supabase record is later changed.
 */

add_action( 'plugins_loaded', 'li_migrate_tables', 2 );
function li_migrate_tables() {
    global $wpdb;
    $version = 2;
    $installed = intval( get_option( 'li_accounting_db_version', 0 ) );
    if ( $installed >= $version ) {
        return;
    }
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();
    $table_audit     = $wpdb->prefix . 'li_audit_log';
    $table_ledger    = $wpdb->prefix . 'li_ledger';
    $table_payouts   = $wpdb->prefix . 'li_payouts';
    $table_payout_lines = $wpdb->prefix . 'li_payout_lines';
    $table_fraud     = $wpdb->prefix . 'li_fraud_flags';
    $table_locks     = $wpdb->prefix . 'li_order_locks';
    $table_versions  = $wpdb->prefix . 'li_split_versions';

    $sql = "CREATE TABLE {$table_audit} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        actor varchar(100) NOT NULL DEFAULT 'system',
        action varchar(100) NOT NULL,
        object_type varchar(50) DEFAULT '',
        object_id varchar(255) DEFAULT '',
        details longtext DEFAULT NULL,
        ip_address varchar(100) DEFAULT '',
        PRIMARY KEY (id),
        KEY action (action),
        KEY object (object_type, object_id)
    ) {$charset_collate};";

    $sql .= "CREATE TABLE {$table_ledger} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        entry_type varchar(50) NOT NULL,
        order_ref varchar(255) DEFAULT '',
        product_id varchar(100) DEFAULT '',
        rescue_id varchar(100) DEFAULT '',
        amount_cents bigint(20) NOT NULL DEFAULT 0,
        net_cents bigint(20) NOT NULL DEFAULT 0,
        meta longtext DEFAULT NULL,
        source varchar(50) DEFAULT '',
        PRIMARY KEY (id),
        KEY entry_type (entry_type),
        KEY order_ref (order_ref),
        KEY rescue_id (rescue_id)
    ) {$charset_collate};";

    $sql .= "CREATE TABLE {$table_payouts} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        rescue_id varchar(100) NOT NULL,
        amount_cents bigint(20) NOT NULL DEFAULT 0,
        transfer_id varchar(255) DEFAULT '',
        status varchar(50) NOT NULL DEFAULT 'pending',
        notes longtext DEFAULT NULL,
        PRIMARY KEY (id),
        KEY rescue_id (rescue_id),
        KEY status (status)
    ) {$charset_collate};";

    $sql .= "CREATE TABLE {$table_payout_lines} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        payout_id bigint(20) unsigned NOT NULL,
        order_ref varchar(255) DEFAULT '',
        amount_cents bigint(20) NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        KEY payout_id (payout_id),
        KEY order_ref (order_ref)
    ) {$charset_collate};";

    $sql .= "CREATE TABLE {$table_fraud} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        flag_type varchar(50) NOT NULL,
        order_ref varchar(255) DEFAULT '',
        product_id varchar(100) DEFAULT '',
        rescue_id varchar(100) DEFAULT '',
        details longtext DEFAULT NULL,
        resolved tinyint(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        KEY flag_type (flag_type),
        KEY order_ref (order_ref)
    ) {$charset_collate};";

    $sql .= "CREATE TABLE {$table_locks} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        order_ref varchar(255) NOT NULL,
        locked_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY order_ref (order_ref)
    ) {$charset_collate};";

    $sql .= "CREATE TABLE {$table_versions} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        product_id varchar(100) NOT NULL,
        version int(11) NOT NULL DEFAULT 1,
        rescue_percent decimal(6,3) NOT NULL DEFAULT 0,
        larry_percent decimal(6,3) NOT NULL DEFAULT 0,
        cost_percent decimal(6,3) NOT NULL DEFAULT 0,
        effective_from datetime DEFAULT CURRENT_TIMESTAMP,
        created_by bigint(20) unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        KEY product_id (product_id),
        KEY effective_from (effective_from)
    ) {$charset_collate};";

    dbDelta( $sql );
    update_option( 'li_accounting_db_version', $version );
    li_audit_log( 'tables_installed', array( 'version' => $version ) );
}

function li_audit_log( $action, $details = array(), $object_type = '', $object_id = '' ) {
    global $wpdb;
    $user = wp_get_current_user();
    $actor = 'system';
    if ( $user && $user->ID ) {
        $actor = $user->user_login ? $user->user_login : $user->user_email;
    }
    $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( $_SERVER['REMOTE_ADDR'] ) : '';
    $wpdb->insert(
        $wpdb->prefix . 'li_audit_log',
        array(
            'actor'       => $actor,
            'action'      => sanitize_text_field( $action ),
            'object_type' => sanitize_text_field( $object_type ),
            'object_id'   => sanitize_text_field( $object_id ),
            'details'     => wp_json_encode( $details ),
            'ip_address'  => $ip,
        ),
        array( '%s', '%s', '%s', '%s', '%s', '%s' )
    );
}

function li_record_ledger( $entry_type, $args ) {
    global $wpdb;
    $defaults = array(
        'order_ref'  => '',
        'product_id' => '',
        'rescue_id'  => '',
        'amount_cents' => 0,
        'net_cents'  => 0,
        'meta'       => array(),
        'source'     => '',
    );
    $args = wp_parse_args( $args, $defaults );
    $wpdb->insert(
        $wpdb->prefix . 'li_ledger',
        array(
            'entry_type'   => sanitize_text_field( $entry_type ),
            'order_ref'    => sanitize_text_field( $args['order_ref'] ),
            'product_id'   => sanitize_text_field( $args['product_id'] ),
            'rescue_id'    => sanitize_text_field( $args['rescue_id'] ),
            'amount_cents' => intval( $args['amount_cents'] ),
            'net_cents'    => intval( $args['net_cents'] ),
            'meta'         => wp_json_encode( $args['meta'] ),
            'source'       => sanitize_text_field( $args['source'] ),
        ),
        array( '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
    );
    return $wpdb->insert_id;
}

function li_create_payout( $rescue_id, $order_refs, $amount_cents, $transfer_id, $status = 'completed', $line_amounts = array() ) {
    global $wpdb;
    $wpdb->insert(
        $wpdb->prefix . 'li_payouts',
        array(
            'rescue_id'  => sanitize_text_field( $rescue_id ),
            'amount_cents' => intval( $amount_cents ),
            'transfer_id'  => sanitize_text_field( $transfer_id ),
            'status'     => sanitize_text_field( $status ),
        ),
        array( '%s', '%d', '%s', '%s' )
    );
    $payout_id = $wpdb->insert_id;
    if ( $payout_id && is_array( $order_refs ) ) {
        foreach ( $order_refs as $i => $ref ) {
            $amt = isset( $line_amounts[ $i ] ) ? intval( $line_amounts[ $i ] ) : 0;
            $wpdb->insert(
                $wpdb->prefix . 'li_payout_lines',
                array(
                    'payout_id'  => $payout_id,
                    'order_ref'  => sanitize_text_field( $ref ),
                    'amount_cents' => $amt,
                ),
                array( '%d', '%s', '%d' )
            );
        }
    }
    li_audit_log( 'payout_created', array( 'payout_id' => $payout_id, 'rescue_id' => $rescue_id, 'amount_cents' => $amount_cents, 'transfer_id' => $transfer_id, 'status' => $status ), 'payout', $payout_id );
    return $payout_id;
}

function li_rollback_payout( $payout_id ) {
    global $wpdb;
    $payout = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}li_payouts WHERE id = %d", $payout_id ), ARRAY_A );
    if ( ! $payout ) {
        return false;
    }
    $wpdb->update(
        $wpdb->prefix . 'li_payouts',
        array( 'status' => 'rolled_back' ),
        array( 'id' => $payout_id ),
        array( '%s' ),
        array( '%d' )
    );
    li_record_ledger( 'payout_rollback', array(
        'order_ref'    => 'payout_' . $payout_id,
        'rescue_id'    => $payout['rescue_id'],
        'amount_cents' => -1 * intval( $payout['amount_cents'] ),
        'net_cents'    => -1 * intval( $payout['amount_cents'] ),
        'meta'         => array( 'original_payout_id' => $payout_id, 'transfer_id' => $payout['transfer_id'] ),
        'source'       => 'manual',
    ) );
    li_audit_log( 'payout_rolled_back', array( 'payout_id' => $payout_id ), 'payout', $payout_id );
    return true;
}

function li_get_payout( $payout_id ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}li_payouts WHERE id = %d", $payout_id ), ARRAY_A );
}

function li_get_payouts( $args = array() ) {
    global $wpdb;
    $where = '1=1';
    $params = array();
    if ( ! empty( $args['rescue_id'] ) ) {
        $where .= ' AND rescue_id = %s';
        $params[] = $args['rescue_id'];
    }
    if ( ! empty( $args['status'] ) ) {
        $where .= ' AND status = %s';
        $params[] = $args['status'];
    }
    $limit  = ! empty( $args['limit'] ) ? max( 1, intval( $args['limit'] ) ) : 100;
    $offset = ! empty( $args['offset'] ) ? max( 0, intval( $args['offset'] ) ) : 0;
    array_push( $params, $limit, $offset );
    $sql = "SELECT * FROM {$wpdb->prefix}li_payouts WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";
    $prepared = count( $params ) > 2 ? $wpdb->prepare( $sql, ...$params ) : $wpdb->prepare( $sql, $limit, $offset );
    return $wpdb->get_results( $prepared, ARRAY_A );
}

function li_update_payout_status( $payout_id, $status, $notes = '' ) {
    global $wpdb;
    $data = array( 'status' => sanitize_text_field( $status ) );
    if ( '' !== $notes ) {
        $data['notes'] = sanitize_text_field( $notes );
    }
    $wpdb->update(
        $wpdb->prefix . 'li_payouts',
        $data,
        array( 'id' => $payout_id ),
        array( '%s' ),
        array( '%d' )
    );
    li_audit_log( 'payout_status_changed', array( 'payout_id' => $payout_id, 'new_status' => $status, 'notes' => $notes ), 'payout', $payout_id );
}

function li_archive_payout( $payout_id ) {
    $payout = li_get_payout( $payout_id );
    if ( ! $payout || ! in_array( $payout['status'], array( 'completed', 'paid' ), true ) ) {
        return false;
    }
    li_update_payout_status( $payout_id, 'archived', 'Archived by admin' );
    return true;
}

function li_mark_payout_completed( $payout_id ) {
    global $wpdb;
    $payout = li_get_payout( $payout_id );
    if ( ! $payout || $payout['status'] !== 'approved' ) {
        return false;
    }
    li_update_payout_status( $payout_id, 'completed', 'Marked completed manually' );
    $order_refs = $wpdb->get_col( $wpdb->prepare( "SELECT order_ref FROM {$wpdb->prefix}li_payout_lines WHERE payout_id = %d", $payout_id ) );
    if ( is_array( $order_refs ) ) {
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
    }
    return true;
}

function li_create_split_version( $product_id, $rescue_percent, $larry_percent, $cost_percent = 0, $effective_from = null ) {
    global $wpdb;
    $next = $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(version),0)+1 FROM {$wpdb->prefix}li_split_versions WHERE product_id = %s", $product_id ) );
    $effective = $effective_from ? sanitize_text_field( $effective_from ) : current_time( 'mysql' );
    $wpdb->insert(
        $wpdb->prefix . 'li_split_versions',
        array(
            'product_id'     => sanitize_text_field( $product_id ),
            'version'        => intval( $next ),
            'rescue_percent' => floatval( $rescue_percent ),
            'larry_percent'  => floatval( $larry_percent ),
            'cost_percent'   => floatval( $cost_percent ),
            'effective_from' => $effective,
            'created_by'     => get_current_user_id(),
        ),
        array( '%s', '%d', '%f', '%f', '%f', '%s', '%d' )
    );
    li_audit_log( 'split_version_created', array( 'product_id' => $product_id, 'version' => $next, 'rescue_percent' => $rescue_percent, 'larry_percent' => $larry_percent ), 'split', $product_id );
    return $wpdb->insert_id;
}

function li_get_split_version( $product_id, $date = null ) {
    global $wpdb;
    $date = $date ? sanitize_text_field( $date ) : current_time( 'mysql' );
    return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}li_split_versions WHERE product_id = %s AND effective_from <= %s ORDER BY version DESC LIMIT 1", $product_id, $date ), ARRAY_A );
}

function li_flag_fraud( $type, $details = array() ) {
    global $wpdb;
    $defaults = array(
        'order_ref'  => '',
        'product_id' => '',
        'rescue_id'  => '',
        'details'    => array(),
    );
    $args = wp_parse_args( $details, $defaults );
    $wpdb->insert(
        $wpdb->prefix . 'li_fraud_flags',
        array(
            'flag_type'  => sanitize_text_field( $type ),
            'order_ref'  => sanitize_text_field( $args['order_ref'] ),
            'product_id' => sanitize_text_field( $args['product_id'] ),
            'rescue_id'  => sanitize_text_field( $args['rescue_id'] ),
            'details'    => wp_json_encode( $args['details'] ),
        ),
        array( '%s', '%s', '%s', '%s', '%s' )
    );
    li_audit_log( 'fraud_flag', array( 'type' => $type, 'order_ref' => $args['order_ref'] ), 'fraud', $wpdb->insert_id );
    return $wpdb->insert_id;
}

function li_check_duplicate_order( $order_ref, $product_id = '' ) {
    global $wpdb;
    if ( ! $order_ref ) return false;
    $where = $wpdb->prepare( 'order_ref = %s', $order_ref );
    if ( $product_id ) {
        $where .= $wpdb->prepare( ' AND product_id = %s', $product_id );
    }
    $count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}li_ledger WHERE entry_type = 'order' AND {$where}" );
    return intval( $count ) > 0;
}

function li_lock_order( $order_ref ) {
    global $wpdb;
    if ( ! $order_ref ) return;
    $wpdb->replace(
        $wpdb->prefix . 'li_order_locks',
        array( 'order_ref' => sanitize_text_field( $order_ref ), 'locked_at' => current_time( 'mysql' ) ),
        array( '%s', '%s' )
    );
}

function li_is_order_locked( $order_ref ) {
    global $wpdb;
    if ( ! $order_ref ) return false;
    $count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}li_order_locks WHERE order_ref = %s", $order_ref ) );
    return intval( $count ) > 0;
}

function li_log( $msg ) {
    error_log( 'Larry Impact: ' . $msg );
    li_audit_log( 'error_or_info', array( 'message' => $msg ) );
}

add_action( 'woocommerce_order_refunded', 'li_handle_woocommerce_refund', 10, 2 );
function li_handle_woocommerce_refund( $order_id, $refund_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;
    $rescue_slug = $order->get_meta( '_li_rescue_slug' );
    if ( ! $rescue_slug ) return;
    $rescue = li_get_rescue_by_slug( $rescue_slug );
    if ( ! $rescue ) return;
    $rescue_id = $rescue['id'] ?? '';
    $order_number = strval( $order->get_order_number() );
    $refund = wc_get_order( $refund_id );
    if ( ! $refund ) return;
    foreach ( $refund->get_items() as $item ) {
        $product = $item->get_product();
        if ( ! $product ) continue;
        $product_id = '';
        $sku = $product->get_sku();
        if ( $sku ) {
            $product_data = li_get_product_by_sku( $sku );
            if ( $product_data ) {
                $product_id = $product_data['id'] ?? '';
            }
        }
        $refund_total = absint( round( floatval( $item->get_total() ) * 100 ) );
        if ( $refund_total <= 0 ) continue;
        $order_ref = $order_number . '::' . $product_id;
        li_record_ledger( 'refund', array(
            'order_ref'    => $order_ref,
            'product_id'   => $product_id,
            'rescue_id'    => $rescue_id,
            'amount_cents' => -1 * $refund_total,
            'net_cents'    => -1 * $refund_total,
            'meta'         => array( 'woocommerce_refund_id' => $refund_id, 'woocommerce_order_id' => $order_id ),
            'source'       => 'woocommerce',
        ) );
        li_audit_log( 'order_refunded', array( 'order_id' => $order_id, 'refund_id' => $refund_id, 'amount_cents' => $refund_total ), 'order', $order_ref );
    }
}
