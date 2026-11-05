<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Order Health.
 *
 * Every Shopify order that reaches Larry is saved with what happened to each line.
 * Orders that could not be fully recorded are emailed to the admin and listed in
 * wp-admin, where they can be fixed and recorded again. When a Shopify Admin API
 * token is configured, paid Shopify orders are also checked hourly so an order is
 * never lost because a webhook was not delivered.
 */

if ( ! defined( 'LI_SHOPIFY_API_VERSION' ) ) {
    define( 'LI_SHOPIFY_API_VERSION', '2026-07' );
}

add_action( 'plugins_loaded', 'li_order_health_migrate', 3 );
function li_order_health_migrate() {
    $version = 1;
    if ( intval( get_option( 'li_order_health_db_version', 0 ) ) >= $version ) {
        return;
    }
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = li_order_events_table();
    $sql   = "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        created_at datetime DEFAULT NULL,
        updated_at datetime DEFAULT NULL,
        source varchar(30) NOT NULL DEFAULT '',
        topic varchar(60) NOT NULL DEFAULT '',
        external_id varchar(60) NOT NULL DEFAULT '',
        order_ref varchar(100) NOT NULL DEFAULT '',
        signature varchar(20) NOT NULL DEFAULT '',
        result varchar(20) NOT NULL DEFAULT 'received',
        attempts int(11) NOT NULL DEFAULT 0,
        details longtext,
        payload longtext,
        resolved tinyint(1) NOT NULL DEFAULT 0,
        resolved_at datetime DEFAULT NULL,
        resolved_by bigint(20) unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        KEY order_ref (order_ref),
        KEY external_id (external_id),
        KEY result (result),
        KEY resolved (resolved)
    ) " . $wpdb->get_charset_collate() . ';';
    dbDelta( $sql );
    update_option( 'li_order_health_db_version', $version );
}

function li_order_events_table() {
    global $wpdb;
    return $wpdb->prefix . 'li_order_events';
}

function li_order_event_get( $event_id ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . li_order_events_table() . ' WHERE id = %d', $event_id ) );
}

function li_order_event_get_by_external( $source, $external_id ) {
    global $wpdb;
    if ( '' === (string) $external_id ) {
        return null;
    }
    return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . li_order_events_table() . ' WHERE source = %s AND external_id = %s ORDER BY id DESC LIMIT 1', $source, $external_id ) );
}

function li_order_event_save( $data ) {
    global $wpdb;
    $source      = sanitize_key( $data['source'] ?? 'shopify' );
    $external_id = sanitize_text_field( (string) ( $data['external_id'] ?? '' ) );
    $now         = current_time( 'mysql' );
    $row         = array(
        'source'     => $source,
        'topic'      => sanitize_text_field( $data['topic'] ?? '' ),
        'external_id'=> $external_id,
        'order_ref'  => sanitize_text_field( $data['order_ref'] ?? '' ),
        'signature'  => sanitize_key( $data['signature'] ?? '' ),
        'payload'    => wp_json_encode( $data['payload'] ?? array() ),
        'updated_at' => $now,
    );
    $existing = li_order_event_get_by_external( $source, $external_id );
    if ( $existing ) {
        $wpdb->update( li_order_events_table(), $row, array( 'id' => $existing->id ) );
        return intval( $existing->id );
    }
    $row['created_at'] = $now;
    $wpdb->insert( li_order_events_table(), $row );
    return intval( $wpdb->insert_id );
}

function li_order_outcome_result( $outcome ) {
    if ( ! empty( $outcome['failed'] ) ) {
        return 'failed';
    }
    if ( ! empty( $outcome['held'] ) || ! empty( $outcome['skipped'] ) ) {
        return 'needs_attention';
    }
    return empty( $outcome['recorded'] ) ? 'ignored' : 'recorded';
}

function li_order_event_finish( $event_id, $outcome ) {
    global $wpdb;
    $event  = li_order_event_get( $event_id );
    $result = li_order_outcome_result( $outcome );
    if ( ! $event ) {
        return $result;
    }
    $previous   = json_decode( (string) $event->details, true );
    $previous   = is_array( $previous ) ? $previous : array();
    $is_problem = in_array( $result, array( 'needs_attention', 'failed' ), true );
    $alert_key  = $is_problem ? md5( wp_json_encode( li_order_problem_lines( $outcome ) ) ) : '';
    $dismissed  = $is_problem && intval( $event->resolved_by ) > 0 && $alert_key === ( $previous['alert_key'] ?? '' );

    $outcome['alert_key'] = $alert_key;
    $now = current_time( 'mysql' );
    $wpdb->update(
        li_order_events_table(),
        array(
            'result'      => $result,
            'details'     => wp_json_encode( $outcome ),
            'attempts'    => intval( $event->attempts ) + 1,
            'updated_at'  => $now,
            'resolved'    => ( ! $is_problem || $dismissed ) ? 1 : 0,
            'resolved_at' => ( ! $is_problem || $dismissed ) ? $now : null,
            'resolved_by' => $dismissed ? intval( $event->resolved_by ) : 0,
        ),
        array( 'id' => $event_id )
    );

    if ( $is_problem && ! $dismissed && $alert_key !== ( $previous['alert_key'] ?? '' ) ) {
        li_order_health_alert( $outcome );
    }
    return $result;
}

function li_order_problem_lines( $outcome ) {
    $lines  = array();
    $rescue = $outcome['rescue'] ?? array();
    if ( ! empty( $outcome['held'] ) ) {
        $status = $rescue['status'] ?? '';
        if ( 'missing' === $status ) {
            $lines[] = 'No rescue was attached to the order. Pick a rescue (or No rescue) and record it.';
        } elseif ( 'unknown' === $status ) {
            $lines[] = 'The order says rescue "' . ( $rescue['value'] ?? '' ) . '", which does not match any rescue in Larry.';
        } elseif ( 'not_approved' === $status ) {
            $lines[] = 'The rescue on the order (' . ( $rescue['name'] ?? '' ) . ') is not approved.';
        }
    }
    foreach ( (array) ( $outcome['skipped'] ?? array() ) as $line ) {
        if ( 'no_sku' === ( $line['reason'] ?? '' ) ) {
            $lines[] = 'Product has no SKU in Shopify: ' . ( $line['title'] ?? '' );
        } elseif ( 'no_payout' === ( $line['reason'] ?? '' ) ) {
            $lines[] = 'No rescue payout set yet for ' . ( $line['title'] ?? '' ) . ' (SKU ' . ( $line['sku'] ?? '' ) . '). Set it in Split Config, then click Retry.';
        } else {
            $lines[] = 'Product is not in the Larry product list: ' . ( $line['title'] ?? '' ) . ' (SKU ' . ( $line['sku'] ?? '' ) . ')';
        }
    }
    foreach ( (array) ( $outcome['failed'] ?? array() ) as $line ) {
        $lines[] = 'Could not save to the Larry database: ' . ( $line['title'] ?? '' ) . ' (SKU ' . ( $line['sku'] ?? '' ) . ')';
    }
    return $lines;
}

function li_order_health_alert( $outcome ) {
    $to = apply_filters( 'li_order_health_alert_to', get_option( 'li_admin_email', get_option( 'admin_email' ) ) );
    if ( ! $to ) {
        return;
    }
    $ref     = $outcome['order_ref'] ?? '';
    $subject = 'Larry Impact: order ' . $ref . ' was not fully recorded';
    $body    = "Order {$ref} came in from Shopify but was not fully added to the Larry dashboard.\n\n";
    foreach ( li_order_problem_lines( $outcome ) as $line ) {
        $body .= '- ' . $line . "\n";
    }
    $body .= "\nFix it here: " . admin_url( 'admin.php?page=li-order-health' ) . "\n";
    wp_mail( $to, $subject, $body );
}

function li_order_health_unresolved_count() {
    global $wpdb;
    $table = li_order_events_table();
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
        return 0;
    }
    return intval( $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE resolved = 0 AND result IN ('needs_attention','failed')" ) );
}

add_action( 'admin_notices', 'li_order_health_admin_notice' );
function li_order_health_admin_notice() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    if ( isset( $_GET['page'] ) && 'li-order-health' === $_GET['page'] ) {
        return;
    }
    $count = li_order_health_unresolved_count();
    if ( $count < 1 ) {
        return;
    }
    echo '<div class="notice notice-warning"><p><strong>Larry Impact:</strong> ' . esc_html( $count ) . ' order' . ( 1 === $count ? '' : 's' ) . ' could not be fully recorded. <a href="' . esc_url( admin_url( 'admin.php?page=li-order-health' ) ) . '">Review in Order Health</a></p></div>';
}

/* ---------- Rescue matching ---------- */

function li_shopify_rescue_value( $payload ) {
    $attributes = array_merge(
        (array) ( $payload['note_attributes'] ?? array() ),
        (array) ( $payload['attributes'] ?? array() )
    );
    foreach ( (array) ( $payload['line_items'] ?? array() ) as $line ) {
        foreach ( (array) ( $line['properties'] ?? array() ) as $prop ) {
            $attributes[] = $prop;
        }
    }
    foreach ( $attributes as $attr ) {
        $name = strtolower( trim( (string) ( $attr['name'] ?? $attr['key'] ?? '' ), " _\t" ) );
        if ( 'rescue' === $name ) {
            $value = sanitize_text_field( (string) ( $attr['value'] ?? '' ) );
            if ( '' !== $value ) {
                return $value;
            }
        }
    }
    return '';
}

function li_resolve_order_rescue( $value, $override = '' ) {
    if ( 'none' === $override ) {
        return array( 'status' => 'none', 'value' => $value, 'rescue' => null );
    }
    if ( $override ) {
        $data = li_db_get( 'rescues?id=eq.' . urlencode( $override ) . '&select=*' );
        if ( ! empty( $data ) ) {
            return array( 'status' => 'override', 'value' => $value, 'rescue' => $data[0] );
        }
    }
    if ( '' === $value ) {
        return array( 'status' => 'missing', 'value' => '', 'rescue' => null );
    }
    $rescue = li_get_rescue_by_slug( sanitize_title( $value ) );
    if ( ! $rescue ) {
        $rescue = li_get_rescue_by_name( $value );
    }
    if ( ! $rescue ) {
        return array( 'status' => 'unknown', 'value' => $value, 'rescue' => null );
    }
    if ( isset( $rescue['status'] ) && 'approved' !== $rescue['status'] ) {
        return array( 'status' => 'not_approved', 'value' => $value, 'rescue' => $rescue );
    }
    return array( 'status' => 'matched', 'value' => $value, 'rescue' => $rescue );
}

/* ---------- Shopify order processing ---------- */

/**
 * Shopify Bundles sends component lines instead of the parent bundle. Each
 * definition maps the parent Larry SKU to its component SKUs and quantities.
 */
function li_shopify_bundle_definitions() {
    return apply_filters( 'li_shopify_bundles', array(
        'LI-BIG-TREAT-BOX' => array( '46602' => 4, '46334' => 2, '45729' => 2 ),
    ) );
}

function li_shopify_order_lines( $payload ) {
    $lines = array();
    foreach ( (array) ( $payload['line_items'] ?? array() ) as $line ) {
        $qty = intval( $line['quantity'] ?? 1 );
        if ( $qty < 1 ) {
            continue;
        }
        $price    = floatval( $line['price'] ?? 0 );
        $discount = floatval( $line['total_discount'] ?? 0 );
        if ( $discount <= 0 && ! empty( $line['discount_allocations'] ) ) {
            foreach ( (array) $line['discount_allocations'] as $allocation ) {
                $discount += floatval( $allocation['amount'] ?? 0 );
            }
        }
        $title = trim( (string) ( $line['title'] ?? '' ) );
        if ( ! empty( $line['variant_title'] ) ) {
            $title .= ' - ' . $line['variant_title'];
        }
        $lines[] = array(
            'sku'        => sanitize_text_field( (string) ( $line['sku'] ?? '' ) ),
            'title'      => sanitize_text_field( $title ),
            'unit_cents' => intval( round( $price * 100 ) ),
            'qty'        => $qty,
            'sale_cents' => intval( round( max( 0, ( $price * $qty ) - $discount ) * 100 ) ),
        );
    }
    return $lines;
}

function li_collapse_shopify_bundles( $lines ) {
    foreach ( li_shopify_bundle_definitions() as $parent_sku => $parts ) {
        $have = array();
        foreach ( $lines as $line ) {
            if ( isset( $parts[ $line['sku'] ] ) ) {
                $have[ $line['sku'] ] = ( $have[ $line['sku'] ] ?? 0 ) + $line['qty'];
            }
        }
        $first_sku = array_key_first( $parts );
        $count     = intdiv( $have[ $first_sku ] ?? 0, $parts[ $first_sku ] );
        if ( $count < 1 ) {
            continue;
        }
        foreach ( $parts as $sku => $per_bundle ) {
            if ( ( $have[ $sku ] ?? 0 ) !== $count * $per_bundle ) {
                continue 2;
            }
        }
        $sale = 0;
        $kept = array();
        foreach ( $lines as $line ) {
            if ( isset( $parts[ $line['sku'] ] ) ) {
                $sale += $line['sale_cents'];
            } else {
                $kept[] = $line;
            }
        }
        $kept[] = array(
            'sku'        => $parent_sku,
            'title'      => 'Bundle ' . $parent_sku,
            'qty'        => $count,
            'sale_cents' => $sale,
            'bundle'     => true,
        );
        $lines = $kept;
    }
    return $lines;
}

function li_shopify_order_ref( $payload ) {
    return ltrim( sanitize_text_field( (string) ( $payload['name'] ?? ( $payload['id'] ?? '' ) ) ), '#' );
}

/**
 * Records a Shopify order. Lines are only written when the rescue is known (or an
 * admin picked one), so an order is never stored without the right rescue credit.
 */
function li_process_shopify_order( $payload, $context = array() ) {
    $order_ref  = li_shopify_order_ref( $payload );
    $ordered_at = sanitize_text_field( (string) ( $payload['processed_at'] ?? $payload['created_at'] ?? gmdate( 'c' ) ) );
    $resolved   = li_resolve_order_rescue( li_shopify_rescue_value( $payload ), $context['rescue_override'] ?? '' );
    $rescue     = $resolved['rescue'];
    $outcome    = array(
        'order_ref'        => $order_ref,
        'financial_status' => sanitize_text_field( (string) ( $payload['financial_status'] ?? '' ) ),
        'rescue'           => array(
            'status' => $resolved['status'],
            'value'  => $resolved['value'],
            'id'     => $rescue ? $rescue['id'] : '',
            'name'   => $rescue ? ( $rescue['name'] ?? '' ) : '',
        ),
        'recorded'         => array(),
        'skipped'          => array(),
        'failed'           => array(),
        'pending'          => array(),
        'held'             => false,
    );

    $to_record = array();
    foreach ( li_collapse_shopify_bundles( li_shopify_order_lines( $payload ) ) as $line ) {
        $is_bundle = ! empty( $line['bundle'] );
        if ( $line['sale_cents'] <= 0 && ! $is_bundle ) {
            continue;
        }
        $product = '' !== $line['sku'] ? li_get_product_by_sku( $line['sku'] ) : null;
        if ( ! $product && '' !== $line['sku'] && ! $is_bundle && ! li_is_woo_sku( $line['sku'] ) ) {
            $product = li_upsert_shopify_product( $line['sku'], $line['title'], $line['unit_cents'] ?? 0 );
            if ( $product ) {
                $known = get_option( 'li_shopify_skus', array() );
                $known = is_array( $known ) ? $known : array();
                if ( ! in_array( $line['sku'], $known, true ) ) {
                    $known[] = $line['sku'];
                    update_option( 'li_shopify_skus', $known, false );
                }
            }
        }
        if ( $product && li_is_shopify_only_sku( $line['sku'] ) && ! li_product_has_payout( $product ) ) {
            $line['reason']       = 'no_payout';
            $outcome['skipped'][] = $line;
            li_log( 'Shopify order ' . $order_ref . ' line held: no rescue payout set for SKU ' . $line['sku'] );
            continue;
        }
        if ( ! $product ) {
            $line['reason']        = '' === $line['sku'] ? 'no_sku' : 'unknown_sku';
            $outcome['skipped'][] = $line;
            li_log( 'Shopify order ' . $order_ref . ' line not recorded: no Larry product for SKU ' . $line['sku'] );
            continue;
        }
        if ( $is_bundle && $line['sale_cents'] <= 0 ) {
            $line['sale_cents'] = intval( $product['price_cents'] ?? 0 ) * $line['qty'];
        }
        $line['product_id'] = $product['id'];
        if ( li_is_order_locked( $order_ref . '::' . $product['id'] ) ) {
            $line['already']        = true;
            $outcome['recorded'][] = $line;
            continue;
        }
        $to_record[] = $line;
    }

    if ( $to_record && in_array( $resolved['status'], array( 'missing', 'unknown', 'not_approved' ), true ) ) {
        $outcome['held']    = true;
        $outcome['pending'] = $to_record;
        return $outcome;
    }

    foreach ( $to_record as $line ) {
        $ok = li_record_order( array(
            'order_id'   => $order_ref,
            'rescue_id'  => $rescue ? $rescue['id'] : null,
            'product_id' => $line['product_id'],
            'sale_cents' => $line['sale_cents'],
            'quantity'   => $line['qty'],
            'channel'    => 'shopify',
            'ordered_at' => $ordered_at,
        ) );
        if ( $ok ) {
            $outcome['recorded'][] = $line;
        } else {
            $line['reason']       = 'save_failed';
            $outcome['failed'][] = $line;
        }
    }
    return $outcome;
}

/**
 * Keeps only the order fields Larry needs, so customer contact details are not stored.
 */
function li_shopify_payload_minimal( $payload ) {
    $lines = array();
    foreach ( (array) ( $payload['line_items'] ?? array() ) as $line ) {
        $lines[] = array_intersect_key( (array) $line, array_flip( array( 'id', 'sku', 'title', 'variant_title', 'quantity', 'price', 'total_discount', 'discount_allocations', 'properties', 'product_id', 'variant_id' ) ) );
    }
    $min = array_intersect_key( (array) $payload, array_flip( array( 'id', 'name', 'created_at', 'processed_at', 'financial_status', 'note_attributes', 'attributes' ) ) );
    $min['line_items'] = $lines;
    return $min;
}

function li_ingest_shopify_order( $payload, $context = array() ) {
    $payload  = li_shopify_payload_minimal( $payload );
    $event_id = li_order_event_save( array(
        'source'      => 'shopify',
        'external_id' => (string) ( $payload['id'] ?? li_shopify_order_ref( $payload ) ),
        'order_ref'   => li_shopify_order_ref( $payload ),
        'topic'       => $context['topic'] ?? '',
        'signature'   => $context['signature'] ?? '',
        'payload'     => $payload,
    ) );
    return li_order_event_finish( $event_id, li_process_shopify_order( $payload, $context ) );
}

/* ---------- Shopify Admin API check ---------- */

function li_shopify_admin_token() {
    if ( defined( 'LI_SHOPIFY_ADMIN_TOKEN' ) && LI_SHOPIFY_ADMIN_TOKEN ) {
        return LI_SHOPIFY_ADMIN_TOKEN;
    }
    return (string) get_option( 'li_shopify_admin_token', '' );
}

function li_shopify_graphql( $query, $variables = array() ) {
    $token = li_shopify_admin_token();
    if ( ! $token ) {
        return new WP_Error( 'li_no_shopify_token', 'No Shopify Admin API token is saved in Larry Impact settings.' );
    }
    $store    = preg_replace( '#^https?://|/.*$#', '', (string) get_option( 'li_shopify_store', 'larrybev.myshopify.com' ) );
    $response = wp_remote_post( 'https://' . $store . '/admin/api/' . LI_SHOPIFY_API_VERSION . '/graphql.json', array(
        'timeout' => 30,
        'headers' => array(
            'Content-Type'           => 'application/json',
            'X-Shopify-Access-Token' => $token,
        ),
        'body'    => wp_json_encode( array( 'query' => $query, 'variables' => (object) $variables ) ),
    ) );
    if ( is_wp_error( $response ) ) {
        return $response;
    }
    $code = wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( $code >= 400 || ! is_array( $body ) || ! empty( $body['errors'] ) ) {
        $detail = is_array( $body ) ? wp_json_encode( $body['errors'] ?? $body ) : '';
        return new WP_Error( 'li_shopify_api', 'Shopify API error ' . $code . ' ' . substr( $detail, 0, 300 ) );
    }
    return $body['data'] ?? array();
}

function li_shopify_graphql_order_to_payload( $node ) {
    $attributes = array();
    foreach ( (array) ( $node['customAttributes'] ?? array() ) as $attr ) {
        $attributes[] = array( 'name' => $attr['key'] ?? '', 'value' => $attr['value'] ?? '' );
    }
    $lines = array();
    foreach ( (array) ( $node['lineItems']['nodes'] ?? array() ) as $line ) {
        $discount = 0;
        foreach ( (array) ( $line['discountAllocations'] ?? array() ) as $allocation ) {
            $discount += floatval( $allocation['allocatedAmountSet']['shopMoney']['amount'] ?? 0 );
        }
        $properties = array();
        foreach ( (array) ( $line['customAttributes'] ?? array() ) as $attr ) {
            $properties[] = array( 'name' => $attr['key'] ?? '', 'value' => $attr['value'] ?? '' );
        }
        $lines[] = array(
            'sku'            => $line['sku'] ?? '',
            'title'          => $line['title'] ?? '',
            'variant_title'  => $line['variantTitle'] ?? '',
            'quantity'       => intval( $line['quantity'] ?? 1 ),
            'price'          => $line['originalUnitPriceSet']['shopMoney']['amount'] ?? 0,
            'total_discount' => $discount,
            'properties'     => $properties,
        );
    }
    return array(
        'id'               => (string) ( $node['legacyResourceId'] ?? '' ),
        'name'             => $node['name'] ?? '',
        'created_at'       => $node['createdAt'] ?? '',
        'processed_at'     => $node['processedAt'] ?? '',
        'financial_status' => strtolower( (string) ( $node['displayFinancialStatus'] ?? '' ) ),
        'note_attributes'  => $attributes,
        'line_items'       => $lines,
    );
}

/**
 * Compares paid Shopify orders with Larry and records any that are missing.
 */
function li_shopify_reconcile( $days = 0 ) {
    $query = 'query LarryOrders($cursor: String, $q: String) {
        orders(first: 50, after: $cursor, query: $q, sortKey: CREATED_AT) {
            pageInfo { hasNextPage endCursor }
            nodes {
                legacyResourceId name createdAt processedAt displayFinancialStatus
                customAttributes { key value }
                lineItems(first: 100) { nodes {
                    sku title variantTitle quantity
                    originalUnitPriceSet { shopMoney { amount } }
                    discountAllocations { allocatedAmountSet { shopMoney { amount } } }
                    customAttributes { key value }
                } }
            }
        }
    }';
    $last  = get_option( 'li_shopify_reconcile_last', array() );
    $since = $days > 0 ? time() - $days * DAY_IN_SECONDS : ( ! empty( $last['ok_at'] ) ? intval( $last['ok_at'] ) - 2 * DAY_IN_SECONDS : time() - 60 * DAY_IN_SECONDS );
    $q     = '(financial_status:paid OR financial_status:partially_refunded) created_at:>=' . gmdate( 'Y-m-d\TH:i:s\Z', $since );

    $summary = array( 'run_at' => time(), 'checked' => 0, 'recorded' => 0, 'problems' => 0, 'error' => '' );
    $cursor  = null;
    for ( $page = 0; $page < 20; $page++ ) {
        $data = li_shopify_graphql( $query, array( 'cursor' => $cursor, 'q' => $q ) );
        if ( is_wp_error( $data ) ) {
            $summary['error'] = $data->get_error_message();
            break;
        }
        foreach ( (array) ( $data['orders']['nodes'] ?? array() ) as $node ) {
            $summary['checked']++;
            $payload  = li_shopify_graphql_order_to_payload( $node );
            $existing = li_order_event_get_by_external( 'shopify', $payload['id'] );
            if ( $existing && intval( $existing->resolved ) === 1 ) {
                continue;
            }
            $result = li_ingest_shopify_order( $payload, array(
                'topic'     => $existing ? $existing->topic : 'scheduled check',
                'signature' => $existing ? $existing->signature : 'api',
            ) );
            if ( 'recorded' === $result ) {
                $summary['recorded']++;
            } elseif ( in_array( $result, array( 'needs_attention', 'failed' ), true ) ) {
                $summary['problems']++;
            }
        }
        if ( empty( $data['orders']['pageInfo']['hasNextPage'] ) ) {
            break;
        }
        $cursor = $data['orders']['pageInfo']['endCursor'];
    }
    $summary['ok_at'] = '' === $summary['error'] ? $summary['run_at'] : ( $last['ok_at'] ?? 0 );
    update_option( 'li_shopify_reconcile_last', $summary, false );
    if ( $summary['error'] ) {
        li_log( 'Shopify order check failed: ' . $summary['error'] );
    }
    return $summary;
}

add_action( 'init', 'li_schedule_shopify_reconcile' );
function li_schedule_shopify_reconcile() {
    $scheduled = wp_next_scheduled( 'li_shopify_reconcile_cron' );
    if ( li_shopify_admin_token() && ! $scheduled ) {
        wp_schedule_event( time() + 300, 'hourly', 'li_shopify_reconcile_cron' );
    } elseif ( ! li_shopify_admin_token() && $scheduled ) {
        wp_clear_scheduled_hook( 'li_shopify_reconcile_cron' );
    }
}

add_action( 'li_shopify_reconcile_cron', 'li_shopify_reconcile_cron' );
function li_shopify_reconcile_cron() {
    li_shopify_reconcile();
}

/**
 * Lists Shopify products for sale that Larry cannot record correctly: missing from Larry or without a rescue payout.
 */
function li_shopify_catalog_gaps() {
    $variants = li_shopify_active_variants();
    if ( is_wp_error( $variants ) ) {
        return $variants;
    }
    $components = array();
    foreach ( li_shopify_bundle_definitions() as $parts ) {
        foreach ( $parts as $sku => $qty ) {
            $components[ (string) $sku ] = true;
        }
    }
    $gaps = array();
    foreach ( $variants as $variant ) {
        $sku = $variant['sku'];
        if ( isset( $components[ $sku ] ) ) {
            continue;
        }
        $product = '' !== $sku ? li_get_product_by_sku( $sku ) : null;
        if ( ! $product ) {
            $issue = '' === $sku ? 'No SKU in Shopify' : 'Not in Larry';
        } elseif ( li_get_product_rescue_cents( $product ) <= 0 ) {
            $issue = 'No rescue payout set';
        } else {
            continue;
        }
        $gaps[] = array( 'sku' => $sku, 'name' => $variant['name'], 'price' => $variant['price'], 'issue' => $issue );
    }
    set_transient( 'li_shopify_catalog_gaps', array( 'checked_at' => time(), 'gaps' => $gaps ), 2 * DAY_IN_SECONDS );
    return $gaps;
}

// Uses the Admin API when a token is saved, otherwise the store's public product feed.
function li_shopify_active_variants() {
    $out = array();
    if ( li_shopify_admin_token() ) {
        $query  = 'query LarryVariants($cursor: String) {
            productVariants(first: 250, after: $cursor) {
                pageInfo { hasNextPage endCursor }
                nodes { sku displayName price product { status } }
            }
        }';
        $cursor = null;
        for ( $page = 0; $page < 40; $page++ ) {
            $data = li_shopify_graphql( $query, array( 'cursor' => $cursor ) );
            if ( is_wp_error( $data ) ) {
                return $data;
            }
            foreach ( (array) ( $data['productVariants']['nodes'] ?? array() ) as $v ) {
                if ( 'ACTIVE' === ( $v['product']['status'] ?? '' ) ) {
                    $out[] = array( 'sku' => trim( (string) ( $v['sku'] ?? '' ) ), 'name' => (string) ( $v['displayName'] ?? '' ), 'price' => (string) ( $v['price'] ?? '' ) );
                }
            }
            if ( empty( $data['productVariants']['pageInfo']['hasNextPage'] ) ) {
                break;
            }
            $cursor = $data['productVariants']['pageInfo']['endCursor'];
        }
        return $out;
    }
    $store = preg_replace( '#^https?://|/.*$#', '', (string) get_option( 'li_shopify_store', 'larrybev.myshopify.com' ) );
    for ( $page = 1; $page <= 20; $page++ ) {
        $r = wp_remote_get( 'https://' . $store . '/products.json?limit=250&page=' . $page, array( 'timeout' => 20 ) );
        if ( is_wp_error( $r ) || 200 !== wp_remote_retrieve_response_code( $r ) ) {
            return new WP_Error( 'shopify_feed', 'Could not read the Shopify product list.' );
        }
        $products = json_decode( wp_remote_retrieve_body( $r ), true )['products'] ?? array();
        foreach ( (array) $products as $product ) {
            foreach ( (array) ( $product['variants'] ?? array() ) as $v ) {
                $name  = (string) ( $product['title'] ?? '' );
                $title = (string) ( $v['title'] ?? '' );
                $out[] = array(
                    'sku'   => trim( (string) ( $v['sku'] ?? '' ) ),
                    'name'  => 'Default Title' === $title ? $name : $name . ' - ' . $title,
                    'price' => (string) ( $v['price'] ?? '' ),
                );
            }
        }
        if ( count( (array) $products ) < 250 ) {
            break;
        }
    }
    return $out;
}

add_action( 'init', 'li_schedule_shopify_catalog_check' );
function li_schedule_shopify_catalog_check() {
    if ( ! wp_next_scheduled( 'li_shopify_catalog_cron' ) ) {
        wp_schedule_event( time() + 600, 'daily', 'li_shopify_catalog_cron' );
    }
}

add_action( 'li_shopify_catalog_cron', 'li_shopify_catalog_cron' );
function li_shopify_catalog_cron() {
    li_shopify_sync_products();
    $gaps = li_shopify_catalog_gaps();
    if ( is_wp_error( $gaps ) ) {
        li_log( 'Shopify product check failed: ' . $gaps->get_error_message() );
        return;
    }
    $key = md5( wp_json_encode( $gaps ) );
    if ( ! $gaps ) {
        delete_option( 'li_shopify_catalog_alerted' );
        return;
    }
    if ( get_option( 'li_shopify_catalog_alerted' ) === $key ) {
        return;
    }
    $lines = array();
    foreach ( $gaps as $gap ) {
        $lines[] = '- ' . $gap['name'] . ' (' . ( '' !== $gap['sku'] ? $gap['sku'] : 'no SKU' ) . ', $' . $gap['price'] . '): ' . $gap['issue'];
    }
    li_notify_admin(
        'Shopify products not set up in Larry: ' . count( $gaps ),
        "These products are for sale in Shopify but Larry cannot record them correctly yet. Orders for them will be held in Order Health until they are added.\n\n" . implode( "\n", $lines ) . "\n\nReview: " . admin_url( 'admin.php?page=li-order-health' )
    );
    update_option( 'li_shopify_catalog_alerted', $key, false );
}

/* ---------- Admin page ---------- */

function li_order_health_handle_post() {
    if ( empty( $_POST['li_order_health_nonce'] ) || ! wp_verify_nonce( $_POST['li_order_health_nonce'], 'li_order_health' ) || ! current_user_can( 'manage_options' ) ) {
        return '';
    }
    global $wpdb;
    $action   = sanitize_key( $_POST['li_action'] ?? '' );
    $event_id = intval( $_POST['event_id'] ?? 0 );

    if ( 'retry' === $action && $event_id ) {
        $event   = li_order_event_get( $event_id );
        $payload = $event ? json_decode( (string) $event->payload, true ) : null;
        if ( ! is_array( $payload ) ) {
            return '<div class="li-msg-err">That order could not be loaded.</div>';
        }
        $result = li_ingest_shopify_order( $payload, array(
            'topic'           => $event->topic,
            'signature'       => $event->signature,
            'rescue_override' => sanitize_text_field( $_POST['rescue_override'] ?? '' ),
        ) );
        li_audit_log( 'order_health_retry', array( 'event_id' => $event_id, 'result' => $result ), 'order', $event->order_ref );
        return 'recorded' === $result
            ? '<div class="li-msg-ok">Order ' . esc_html( $event->order_ref ) . ' is now recorded.</div>'
            : '<div class="li-msg-err">Order ' . esc_html( $event->order_ref ) . ' still needs attention. See the problem listed below.</div>';
    }

    if ( 'dismiss' === $action && $event_id ) {
        $wpdb->update( li_order_events_table(), array( 'resolved' => 1, 'resolved_at' => current_time( 'mysql' ), 'resolved_by' => get_current_user_id() ), array( 'id' => $event_id ) );
        li_audit_log( 'order_health_dismissed', array( 'event_id' => $event_id ), 'order', (string) $event_id );
        return '<div class="li-msg-ok">Dismissed.</div>';
    }

    if ( 'reconcile' === $action ) {
        $summary = li_shopify_reconcile( max( 0, intval( $_POST['days'] ?? 0 ) ) );
        if ( $summary['error'] ) {
            return '<div class="li-msg-err">' . esc_html( $summary['error'] ) . '</div>';
        }
        return '<div class="li-msg-ok">Checked ' . esc_html( $summary['checked'] ) . ' paid Shopify orders. Newly recorded: ' . esc_html( $summary['recorded'] ) . '. Need attention: ' . esc_html( $summary['problems'] ) . '.</div>';
    }

    if ( 'sync_products' === $action ) {
        $sync = li_shopify_sync_products();
        if ( is_wp_error( $sync ) ) {
            return '<div class="li-msg-err">' . esc_html( $sync->get_error_message() ) . '</div>';
        }
        li_shopify_catalog_gaps();
        return '<div class="li-msg-ok">Read ' . esc_html( $sync['variants'] ) . ' Shopify products. Newly added to Larry: ' . esc_html( $sync['added'] ) . '.</div>';
    }

    if ( 'catalog' === $action ) {
        $gaps = li_shopify_catalog_gaps();
        if ( is_wp_error( $gaps ) ) {
            return '<div class="li-msg-err">' . esc_html( $gaps->get_error_message() ) . '</div>';
        }
        return '<div class="li-msg-ok">Product check finished.</div>';
    }

    if ( 'manual' === $action ) {
        $order_ref = ltrim( sanitize_text_field( $_POST['order_ref'] ?? '' ), '#' );
        $sku       = sanitize_text_field( $_POST['sku'] ?? '' );
        $qty       = max( 1, intval( $_POST['qty'] ?? 1 ) );
        $amount    = max( 0, floatval( $_POST['amount'] ?? 0 ) );
        $date      = sanitize_text_field( $_POST['ordered_on'] ?? '' );
        if ( ! $order_ref || ! $sku || $amount <= 0 ) {
            return '<div class="li-msg-err">Order number, SKU and amount paid are required.</div>';
        }
        $payload = array(
            'id'               => 'manual-' . $order_ref . '-' . $sku,
            'name'             => $order_ref,
            'created_at'       => $date ? $date . 'T12:00:00Z' : gmdate( 'c' ),
            'financial_status' => 'paid',
            'note_attributes'  => array(),
            'line_items'       => array( array( 'sku' => $sku, 'title' => $sku, 'quantity' => $qty, 'price' => round( $amount / $qty, 2 ), 'total_discount' => 0 ) ),
        );
        $result = li_ingest_shopify_order( $payload, array(
            'topic'           => 'entered by admin',
            'signature'       => 'admin',
            'rescue_override' => sanitize_text_field( $_POST['rescue_override'] ?? '' ),
        ) );
        li_audit_log( 'order_entered_by_admin', array( 'order_ref' => $order_ref, 'sku' => $sku, 'result' => $result ), 'order', $order_ref );
        return 'recorded' === $result
            ? '<div class="li-msg-ok">Order ' . esc_html( $order_ref ) . ' recorded.</div>'
            : '<div class="li-msg-err">Order ' . esc_html( $order_ref ) . ' was saved but still needs attention. See below.</div>';
    }
    return '';
}

function li_order_health_rescue_select( $name = 'rescue_override', $allow_order_value = true ) {
    static $rescues = null;
    if ( null === $rescues ) {
        $rescues = li_db_get( 'rescues?status=eq.approved&select=id,name&order=name.asc' );
        $rescues = is_array( $rescues ) ? $rescues : array();
    }
    $html = '<select class="li-select" name="' . esc_attr( $name ) . '" style="width:auto;max-width:220px">';
    if ( $allow_order_value ) {
        $html .= '<option value="">Rescue on the order</option>';
    }
    foreach ( $rescues as $rescue ) {
        $html .= '<option value="' . esc_attr( $rescue['id'] ) . '">' . esc_html( $rescue['name'] ) . '</option>';
    }
    $html .= '<option value="none">No rescue</option></select>';
    return $html;
}

function li_order_health_form( $action, $fields_html, $button, $event_id = 0, $class = 'li-btn li-btn-sm' ) {
    $html  = '<form method="post" style="display:inline-flex;gap:6px;align-items:center;margin:0">';
    $html .= wp_nonce_field( 'li_order_health', 'li_order_health_nonce', true, false );
    $html .= '<input type="hidden" name="li_action" value="' . esc_attr( $action ) . '" />';
    if ( $event_id ) {
        $html .= '<input type="hidden" name="event_id" value="' . esc_attr( $event_id ) . '" />';
    }
    $html .= $fields_html . '<button class="' . esc_attr( $class ) . '" type="submit">' . esc_html( $button ) . '</button></form>';
    return $html;
}

function li_page_order_health() {
    global $wpdb;
    $msg   = li_order_health_handle_post();
    $table = li_order_events_table();
    ob_start();
    echo $msg;

    $open = $wpdb->get_results( "SELECT * FROM {$table} WHERE resolved = 0 AND result IN ('needs_attention','failed') ORDER BY created_at DESC LIMIT 100" );
    echo '<div class="li-card"><div class="li-card-title">Orders that need attention (' . esc_html( count( $open ) ) . ')</div>';
    if ( ! $open ) {
        echo '<p style="margin:0;color:#3a7a4a">Every order received has been recorded.</p>';
    } else {
        echo '<p class="li-hint" style="margin-top:0">If a rescue payout is missing, set it in Split Config, then click Record order. New Shopify products are added to Larry automatically.</p>';
        echo '<div class="li-table-wrap"><table class="li-table"><thead><tr><th>Received</th><th>Order</th><th>Problem</th><th>Action</th></tr></thead><tbody>';
        foreach ( $open as $event ) {
            $details = json_decode( (string) $event->details, true );
            $details = is_array( $details ) ? $details : array();
            echo '<tr><td>' . esc_html( mysql2date( 'M j, Y g:i a', $event->created_at ) ) . '</td>';
            echo '<td><strong>' . esc_html( $event->order_ref ) . '</strong></td>';
            echo '<td>' . implode( '<br>', array_map( 'esc_html', li_order_problem_lines( $details ) ) ) . '</td>';
            echo '<td style="white-space:nowrap">' . li_order_health_form( 'retry', li_order_health_rescue_select(), 'Record order', $event->id );
            echo ' ' . li_order_health_form( 'dismiss', '', 'Dismiss', $event->id, 'li-btn li-btn-sm' ) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';

    echo '<div class="li-card"><div class="li-card-title">Shopify order check</div>';
    if ( ! li_shopify_admin_token() ) {
        echo '<p style="margin:0">Not connected. Add a Shopify Admin API token in Larry Impact &rarr; Settings to check paid Shopify orders every hour.</p>';
    } else {
        $last = get_option( 'li_shopify_reconcile_last', array() );
        if ( ! empty( $last['run_at'] ) ) {
            echo '<p style="margin-top:0">Last check: ' . esc_html( wp_date( 'M j, Y g:i a', intval( $last['run_at'] ) ) ) . ' &middot; ' . esc_html( $last['checked'] ) . ' orders checked';
            echo $last['error'] ? ' &middot; <span style="color:#a33">' . esc_html( $last['error'] ) . '</span>' : '';
            echo '</p>';
        }
        echo li_order_health_form( 'reconcile', '<input type="hidden" name="days" value="0" />', 'Check now' );
        echo ' ' . li_order_health_form( 'reconcile', '<input type="hidden" name="days" value="90" />', 'Check last 90 days', 0, 'li-btn li-btn-sm' );
    }
    echo '</div>';

    echo '<div class="li-card"><div class="li-card-title">Shopify products</div>';
    echo '<p class="li-hint" style="margin-top:0">New Shopify products are added to Larry every hour. Set each one&rsquo;s rescue payout in Split Config. Checked every day: an email goes out when a product for sale in Shopify has no rescue payout.</p>';
    $sync_last = get_option( 'li_shopify_product_sync_last', array() );
    if ( ! empty( $sync_last['run_at'] ) ) {
        echo '<p style="margin-top:0">Last product sync: ' . esc_html( wp_date( 'M j, Y g:i a', intval( $sync_last['run_at'] ) ) ) . ' &middot; ' . esc_html( $sync_last['variants'] ) . ' Shopify products read</p>';
    }
    echo li_order_health_form( 'sync_products', '', 'Sync products now', 0, 'li-btn li-btn-sm' ) . ' ';
    echo li_order_health_form( 'catalog', '', 'Check now', 0, 'li-btn li-btn-sm' );
    $catalog = get_transient( 'li_shopify_catalog_gaps' );
    if ( is_array( $catalog ) ) {
        $gaps = $catalog['gaps'];
        echo '<p style="margin:16px 0 8px">' . ( $gaps ? '<strong>' . esc_html( count( $gaps ) ) . '</strong> Shopify products need setting up in Larry' : 'Every Shopify product is set up in Larry' ) . ' (checked ' . esc_html( wp_date( 'M j g:i a', intval( $catalog['checked_at'] ) ) ) . ').</p>';
        if ( $gaps ) {
            echo '<div class="li-table-wrap"><table class="li-table"><thead><tr><th>SKU</th><th>Product</th><th>Price</th><th>Problem</th></tr></thead><tbody>';
            foreach ( $gaps as $gap ) {
                echo '<tr><td>' . esc_html( '' !== $gap['sku'] ? $gap['sku'] : '(no SKU)' ) . '</td><td>' . esc_html( $gap['name'] ) . '</td><td>' . esc_html( $gap['price'] ) . '</td><td>' . esc_html( $gap['issue'] ?? 'Not in Larry' ) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
    }
    echo '</div>';

    echo '<div class="li-card"><div class="li-card-title">Record a missed order</div>';
    echo '<p class="li-hint" style="margin-top:0">For a paid Shopify order that never reached Larry. Use the Shopify order number and the product SKU exactly as Shopify shows them. Duplicates are blocked.</p>';
    $fields  = '<input class="li-input" style="width:110px" name="order_ref" placeholder="LR-1006" />';
    $fields .= '<input class="li-input" style="width:200px" name="sku" placeholder="SKU" />';
    $fields .= '<input class="li-input" style="width:70px" type="number" min="1" name="qty" value="1" />';
    $fields .= '<input class="li-input" style="width:110px" type="number" min="0" step="0.01" name="amount" placeholder="Amount paid" />';
    $fields .= '<input class="li-input" style="width:150px" type="date" name="ordered_on" />';
    $fields .= li_order_health_rescue_select( 'rescue_override', false );
    echo li_order_health_form( 'manual', $fields, 'Record order', 0, 'li-btn' );
    echo '</div>';

    $recent = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY updated_at DESC LIMIT 50" );
    echo '<div class="li-card"><div class="li-card-title">Recent orders received</div>';
    if ( ! $recent ) {
        echo '<p style="margin:0">No orders received since Order Health was turned on.</p>';
    } else {
        $labels = array( 'recorded' => 'Recorded', 'ignored' => 'Nothing to record', 'needs_attention' => 'Needs attention', 'failed' => 'Save failed' );
        echo '<div class="li-table-wrap"><table class="li-table"><thead><tr><th>Updated</th><th>Order</th><th>Came from</th><th>Rescue</th><th>Lines recorded</th><th>Status</th></tr></thead><tbody>';
        foreach ( $recent as $event ) {
            $details = json_decode( (string) $event->details, true );
            $details = is_array( $details ) ? $details : array();
            $ok      = in_array( $event->result, array( 'recorded', 'ignored' ), true ) || intval( $event->resolved ) === 1;
            echo '<tr><td>' . esc_html( mysql2date( 'M j g:i a', $event->updated_at ) ) . '</td>';
            echo '<td>' . esc_html( $event->order_ref ) . '</td>';
            echo '<td>' . esc_html( trim( $event->topic . ' ' . ( $event->signature ? '(' . $event->signature . ')' : '' ) ) ) . '</td>';
            echo '<td>' . esc_html( $details['rescue']['name'] ?? '' ) . '</td>';
            echo '<td>' . esc_html( count( (array) ( $details['recorded'] ?? array() ) ) ) . '</td>';
            echo '<td><span class="li-badge ' . ( $ok ? 'li-badge-approved' : 'li-badge-pending' ) . '">' . esc_html( $labels[ $event->result ] ?? $event->result ) . '</span></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';
    li_admin_wrap( 'Order Health', ob_get_clean() );
}
