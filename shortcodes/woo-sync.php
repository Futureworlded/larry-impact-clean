<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function li_get_product_categories( $product_id ) {
    $terms = get_the_terms( $product_id, 'product_cat' );
    if ( $terms && ! is_wp_error( $terms ) ) {
        return $terms;
    }
    $product = wc_get_product( $product_id );
    if ( $product && $product->is_type( 'variation' ) ) {
        $parent_id = $product->get_parent_id();
        $terms = get_the_terms( $parent_id, 'product_cat' );
        if ( $terms && ! is_wp_error( $terms ) ) {
            return $terms;
        }
    }
    return array();
}

function li_sync_product( $product_id ) {
    if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return;
    if ( ! function_exists( 'wc_get_product' ) ) return;
    $product = wc_get_product( $product_id );
    if ( ! $product ) return;

    $terms = li_get_product_categories( $product_id );
    if ( $terms ) {
        foreach ( $terms as $term ) {
            if ( $term->slug === 'coffee' ) return;
        }
    }

    if ( $product->get_type() === 'variable' ) {
        foreach ( $product->get_children() as $vid ) {
            li_sync_product( $vid );
        }
        return;
    }

    $sku   = $product->get_sku() ? $product->get_sku() : 'WC-' . $product_id;
    $price = intval( round( floatval( $product->get_price() ) * 100 ) );
    $cost  = 0;
    $cost_meta = get_post_meta( $product_id, '_li_cost_cents', true );
    if ( '' !== $cost_meta ) {
        $cost = intval( $cost_meta );
    }

    $cats   = array();
    $format = 'Merch';
    if ( $terms ) {
        foreach ( $terms as $t ) {
            $cats[] = $t->name;
            if ( $t->slug === 'impactlist' ) {
                $format = 'Impactlist';
            }
        }
    }

    $payload = array(
        'name'        => $product->get_name(),
        'sku'         => $sku,
        'description' => implode( ', ', $cats ),
        'price_cents' => $price,
        'cost_cents'  => $cost,
        'active'      => true,
        'format'      => $format,
    );

    $existing = li_db_get( 'products?sku=eq.' . urlencode( $sku ) );
    if ( ! empty( $existing ) ) {
        li_db_patch( 'products?sku=eq.' . urlencode( $sku ), $payload );
    } else {
        li_db_post( 'products', $payload );
    }

    $synced = li_get_product_by_sku( $sku );
    if ( $synced ) {
        li_ensure_split_config( $synced['id'], $synced['price_cents'], $synced['cost_cents'] );
    }
}

function li_delete_product( $product_id ) {
    if ( ! function_exists( 'wc_get_product' ) ) return;
    if ( get_post_type( $product_id ) !== 'product' ) return;
    $product = wc_get_product( $product_id );
    if ( ! $product ) return;
    $sku = $product->get_sku() ? $product->get_sku() : 'WC-' . $product_id;
    wp_remote_request( LI_DB_URL . '/rest/v1/products?sku=eq.' . urlencode( $sku ), array(
        'method'  => 'DELETE',
        'headers' => array( 'apikey' => li_service_key(), 'Authorization' => 'Bearer ' . li_service_key() ),
    ) );
}

add_action( 'woocommerce_update_product', 'li_sync_product', 10, 1 );
add_action( 'woocommerce_new_product',    'li_sync_product', 10, 1 );
add_action( 'before_delete_post',         'li_delete_product', 10, 1 );

function li_handle_woo_sync() {
    if ( ! isset( $_POST['li_woo_sync_nonce'] ) || ! wp_verify_nonce( $_POST['li_woo_sync_nonce'], 'li_woo_sync' ) || ! current_user_can( 'manage_options' ) ) return;
    if ( ! function_exists( 'wc_get_products' ) ) { wp_redirect( admin_url( 'admin.php?page=li-rescues' ) ); exit; }
    $products = wc_get_products( array( 'status' => 'publish', 'limit' => -1 ) );
    $synced = 0; $skipped = 0;
    foreach ( $products as $product ) {
        $terms = li_get_product_categories( $product->get_id() );
        $is_coffee = false;
        if ( $terms ) foreach ( $terms as $t ) if ( $t->slug === 'coffee' ) { $is_coffee = true; break; }
        if ( $is_coffee ) { $skipped++; continue; }
        li_sync_product( $product->get_id() );
        $synced++;
    }
    set_transient( 'li_sync_result', array( 'synced' => $synced, 'skipped' => $skipped ), 60 );
    wp_redirect( admin_url( 'admin.php?page=li-rescues&synced=1' ) );
    exit;
}
add_action( 'admin_post_li_woo_sync', 'li_handle_woo_sync' );

// WooCommerce order recording

function li_record_woocommerce_order( $order_id ) {
    if ( ! function_exists( 'wc_get_order' ) ) return;
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;

    $rescue_slug = $order->get_meta( '_li_rescue_slug' );
    if ( ! $rescue_slug ) {
        $rescue_slug = sanitize_text_field( $_COOKIE['li_rescue_slug'] ?? '' );
    }

    $rescue = null;
    if ( $rescue_slug ) {
        $rescue = li_get_rescue_by_slug( $rescue_slug );
    }

    if ( $order->get_date_created() ) {
        $ordered_at = $order->get_date_created()->date( 'c' );
    } else {
        $ordered_at = gmdate( 'c' );
    }

    $channel = 'woocommerce';
    $order_number = strval( $order->get_order_number() );

    foreach ( $order->get_items() as $item ) {
        $product = $item->get_product();
        if ( ! $product ) continue;
        $sku = $product->get_sku() ? $product->get_sku() : 'WC-' . $product->get_id();
        $sp  = li_get_product_by_sku( $sku );
        if ( ! $sp ) {
            li_sync_product( $product->get_id() );
            $sp = li_get_product_by_sku( $sku );
        }
        if ( ! $sp ) {
            li_log( 'WooCommerce order item skipped: product not synced for SKU ' . $sku );
            continue;
        }

        $sale_cents = intval( round( floatval( $item->get_total() ) * 100 ) );
        if ( $sale_cents <= 0 ) continue;

        li_record_order( array(
            'order_id'   => $order_number,
            'rescue_id'  => $rescue ? $rescue['id'] : null,
            'product_id' => $sp['id'],
            'sale_cents' => $sale_cents,
            'quantity'   => max( 1, intval( $item->get_quantity() ) ),
            'channel'    => $channel,
            'ordered_at' => $ordered_at,
        ) );
    }
}
add_action( 'woocommerce_payment_complete', 'li_record_woocommerce_order', 10, 1 );
add_action( 'woocommerce_order_status_completed', 'li_record_woocommerce_order', 10, 1 );
add_action( 'woocommerce_order_status_processing', 'li_record_woocommerce_order', 10, 1 );
add_action( 'woocommerce_order_status_on-hold', 'li_record_woocommerce_order', 10, 1 );

// Shopify webhook

add_action( 'rest_api_init', function() {
    register_rest_route( 'larry-impact/v1', '/shopify-webhook', array(
        'methods'             => 'POST',
        'callback'            => 'li_handle_shopify_webhook',
        'permission_callback' => '__return_true',
    ) );
} );

function li_handle_shopify_webhook( WP_REST_Request $request ) {
    $secret = get_option( 'li_shopify_webhook_secret', '' );
    $raw    = $request->get_body();
    $hmac   = $request->get_header( 'x-shopify-hmac-sha256' );

    if ( $secret && $hmac ) {
        $calculated = base64_encode( hash_hmac( 'sha256', $raw, $secret, true ) );
        if ( ! hash_equals( $calculated, $hmac ) ) {
            li_log( 'Shopify webhook HMAC verification failed' );
            return new WP_Error( 'unauthorized', 'HMAC verification failed', array( 'status' => 401 ) );
        }
    }

    $payload = json_decode( $raw, true );
    if ( ! $payload || empty( $payload['line_items'] ) ) {
        return new WP_REST_Response( array( 'status' => 'ignored' ), 200 );
    }

    $rescue = null;
    $rescue_name = '';
    $attributes = array_merge(
        $payload['note_attributes'] ?? array(),
        $payload['attributes'] ?? array()
    );
    foreach ( $attributes as $attr ) {
        if ( isset( $attr['name'] ) && strtolower( $attr['name'] ) === 'rescue' ) {
            $rescue_name = sanitize_text_field( $attr['value'] ?? '' );
            break;
        }
    }
    if ( $rescue_name ) {
        $rescue = li_get_rescue_by_name( $rescue_name );
        if ( ! $rescue ) {
            $rescue = li_get_rescue_by_slug( sanitize_title( $rescue_name ) );
        }
    }

    $ordered_at = sanitize_text_field( $payload['processed_at'] ?? $payload['created_at'] ?? gmdate( 'c' ) );
    $order_number = ltrim( sanitize_text_field( $payload['name'] ?? strval( $payload['id'] ?? '0' ) ), '#' );
    $channel      = 'shopify';

    foreach ( $payload['line_items'] as $line ) {
        $sku   = sanitize_text_field( $line['sku'] ?? '' );
        $price = floatval( $line['price'] ?? 0 );
        $qty   = intval( $line['quantity'] ?? 1 );
        $discount = floatval( $line['total_discount'] ?? 0 );
        $line_total = max( 0, ( $price * $qty ) - $discount );
        $sale_cents = intval( round( $line_total * 100 ) );
        if ( $sale_cents <= 0 ) continue;

        $sp = null;
        if ( $sku ) {
            $sp = li_get_product_by_sku( $sku );
        }
        if ( ! $sp && ! empty( $line['product_id'] ) ) {
            // Shopify product ID is not stored, so we cannot match without SKU.
        }
        if ( ! $sp ) {
            li_log( 'Shopify line item skipped: no matching product for SKU ' . $sku );
            continue;
        }

        li_record_order( array(
            'order_id'   => $order_number,
            'rescue_id'  => $rescue ? $rescue['id'] : null,
            'product_id' => $sp['id'],
            'sale_cents' => $sale_cents,
            'quantity'   => max( 1, $qty ),
            'channel'    => $channel,
            'ordered_at' => $ordered_at,
        ) );
    }

    return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
}


// Product cost field for split calculations
add_action( 'woocommerce_product_options_general_product_data', 'li_add_product_cost_field' );
function li_add_product_cost_field() {
    woocommerce_wp_text_input( array(
        'id'          => '_li_cost_cents',
        'label'       => 'Larry Impact cost (cents)',
        'description' => 'Wholesale / landed cost in cents. Used to calculate rescue split.',
        'type'        => 'number',
        'custom_attributes' => array( 'step' => '1', 'min' => '0' ),
        'desc_tip'    => true,
    ) );
}

add_action( 'woocommerce_admin_process_product_object', 'li_save_product_cost_field', 10, 1 );
function li_save_product_cost_field( $product ) {
    if ( isset( $_POST['_li_cost_cents'] ) ) {
        $cost = max( 0, intval( $_POST['_li_cost_cents'] ) );
        $product->update_meta_data( '_li_cost_cents', $cost );
    }
}
