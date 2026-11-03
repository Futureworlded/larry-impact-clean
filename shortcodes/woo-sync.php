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
        'name'        => li_wc_product_display_name( $product ),
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
    $post_type = get_post_type( $product_id );
    if ( ! in_array( $post_type, array( 'product', 'product_variation' ), true ) ) return;
    $product = wc_get_product( $product_id );
    if ( ! $product ) return;
    $sku = $product->get_sku() ? $product->get_sku() : 'WC-' . $product_id;
    $existing = li_db_get( 'products?sku=eq.' . urlencode( $sku ) . '&select=id' );
    if ( empty( $existing ) ) return;
    li_db_patch( 'products?id=eq.' . urlencode( $existing[0]['id'] ), array( 'active' => false ) );
}

add_action( 'woocommerce_update_product', 'li_sync_product', 10, 1 );
add_action( 'woocommerce_new_product',    'li_sync_product', 10, 1 );
add_action( 'before_delete_post',         'li_delete_product', 10, 1 );

function li_run_woo_sync() {
    if ( ! function_exists( 'wc_get_products' ) ) {
        return array( 'synced' => 0, 'skipped' => 0, 'hidden' => 0 );
    }
    $products = wc_get_products( array( 'status' => 'publish', 'limit' => -1 ) );
    $synced = 0; $skipped = 0;
    foreach ( $products as $product ) {
        li_sync_product( $product->get_id() );
        $synced++;
    }
    $hidden = li_deactivate_orphan_products();
    return array( 'synced' => $synced, 'skipped' => $skipped, 'hidden' => $hidden );
}

function li_handle_woo_sync() {
    if ( ! isset( $_POST['li_woo_sync_nonce'] ) || ! wp_verify_nonce( $_POST['li_woo_sync_nonce'], 'li_woo_sync' ) || ! current_user_can( 'manage_options' ) ) return;
    $result = li_run_woo_sync();
    set_transient( 'li_sync_result', $result, 60 );
    wp_redirect( admin_url( 'admin.php?page=li-rescues&synced=1' ) );
    exit;
}
add_action( 'admin_post_li_woo_sync', 'li_handle_woo_sync' );

add_action( 'wp_ajax_li_woo_sync', 'li_ajax_woo_sync' );
function li_ajax_woo_sync() {
    check_ajax_referer( 'li_woo_sync', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
    }
    $result = li_run_woo_sync();
    wp_send_json_success( $result );
}

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
    $hmac   = (string) $request->get_header( 'x-shopify-hmac-sha256' );

    if ( ! $secret ) {
        li_log( 'Shopify webhook rejected: no Shopify webhook secret is saved in settings' );
        return new WP_Error( 'unauthorized', 'Webhook secret not configured', array( 'status' => 401 ) );
    }
    $calculated = base64_encode( hash_hmac( 'sha256', $raw, $secret, true ) );
    if ( '' === $hmac || ! hash_equals( $calculated, $hmac ) ) {
        li_log( 'Shopify webhook HMAC verification failed' );
        return new WP_Error( 'unauthorized', 'HMAC verification failed', array( 'status' => 401 ) );
    }

    $topic   = sanitize_text_field( (string) $request->get_header( 'x-shopify-topic' ) );
    $payload = json_decode( $raw, true );
    if ( ! is_array( $payload ) || empty( $payload['line_items'] ) || in_array( $topic, array( 'orders/cancelled', 'orders/delete' ), true ) ) {
        return new WP_REST_Response( array( 'status' => 'ignored' ), 200 );
    }

    $result = li_ingest_shopify_order( $payload, array( 'topic' => $topic, 'signature' => 'verified' ) );

    // A database write failure is temporary, so ask Shopify to resend the order.
    // Missing products and rescues are kept in Order Health for an admin to fix.
    return new WP_REST_Response( array( 'status' => $result ), 'failed' === $result ? 503 : 200 );
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

// Rescue payout field for split calculations
add_action( 'woocommerce_product_options_general_product_data', 'li_add_product_rescue_cents_field', 15 );
function li_add_product_rescue_cents_field() {
    global $post;
    $value = '';
    if ( $post ) {
        $cents = get_post_meta( $post->ID, '_li_rescue_cents', true );
        if ( '' !== $cents ) {
            $value = number_format( intval( $cents ) / 100, 2 );
        }
    }
    woocommerce_wp_text_input( array(
        'id'          => '_li_rescue_cents',
        'label'       => 'Rescue payout ($)',
        'description' => 'Fixed dollar amount that goes to the rescue for each unit sold. Leave blank to use the default split.',
        'type'        => 'number',
        'value'       => $value,
        'custom_attributes' => array( 'step' => '0.01', 'min' => '0' ),
        'desc_tip'    => true,
    ) );
}

add_action( 'woocommerce_admin_process_product_object', 'li_save_product_rescue_cents_field', 10, 1 );
function li_save_product_rescue_cents_field( $product ) {
    if ( isset( $_POST['_li_rescue_cents'] ) ) {
        $dollars = floatval( $_POST['_li_rescue_cents'] );
        $cents   = max( 0, intval( round( $dollars * 100 ) ) );
        $product->update_meta_data( '_li_rescue_cents', $cents );
    }
}
