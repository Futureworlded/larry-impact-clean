<?php
/**
 * Shopify as the product source: products sold only in Shopify are added to
 * Larry automatically, and their rescue payout is set in Split Config.
 * Products that still exist in WooCommerce keep syncing from WooCommerce.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// A product's rescue payout lives only in products.rescue_cents.
function li_set_product_rescue_cents( $sku, $cents ) {
    if ( '' === (string) $sku ) {
        return false;
    }
    $r = li_store_update( 'products', 'sku=eq.' . urlencode( $sku ), array( 'rescue_cents' => max( 0, intval( $cents ) ) ) );
    return ! empty( $r );
}

function li_is_woo_sku( $sku ) {
    return '' !== (string) $sku && function_exists( 'wc_get_product_id_by_sku' ) && wc_get_product_id_by_sku( $sku );
}

function li_shopify_skus() {
    $skus = get_option( 'li_shopify_skus', array() );
    return is_array( $skus ) ? $skus : array();
}

function li_is_shopify_only_sku( $sku ) {
    return '' !== (string) $sku && ! li_is_woo_sku( $sku ) && in_array( (string) $sku, li_shopify_skus(), true );
}

// True when an administrator has set what the rescue earns for this product.
function li_product_has_payout( $product ) {
    return null !== ( $product['rescue_cents'] ?? null );
}

function li_upsert_shopify_product( $sku, $name, $price_cents ) {
    $sku = trim( (string) $sku );
    if ( '' === $sku ) {
        return null;
    }
    $existing = li_get_product_by_sku( $sku );
    if ( li_is_woo_sku( $sku ) ) {
        return $existing;
    }
    $price_cents = max( 0, intval( $price_cents ) );
    if ( $existing ) {
        $changes = array();
        if ( $name && ( $existing['name'] ?? '' ) !== $name ) {
            $changes['name'] = $name;
        }
        if ( $price_cents > 0 && intval( $existing['price_cents'] ?? 0 ) !== $price_cents ) {
            $changes['price_cents'] = $price_cents;
        }
        if ( isset( $existing['active'] ) && false === $existing['active'] ) {
            $changes['active'] = true;
        }
        if ( $changes ) {
            li_db_patch( 'products?id=eq.' . urlencode( $existing['id'] ), $changes );
            $existing = array_merge( $existing, $changes );
        }
        return $existing;
    }
    li_db_post( 'products', array(
        'name'        => $name ? $name : $sku,
        'sku'         => $sku,
        'description' => 'Shopify',
        'price_cents' => $price_cents,
        'cost_cents'  => 0,
        'active'      => true,
        'format'      => 'Merch',
    ) );
    $created = li_get_product_by_sku( $sku );
    if ( $created ) {
        li_log( 'Added Shopify product to Larry: ' . $sku . ' ' . $name );
    }
    return $created;
}

function li_shopify_sync_products() {
    $variants = li_shopify_active_variants();
    if ( is_wp_error( $variants ) ) {
        return $variants;
    }
    $summary = array( 'run_at' => time(), 'variants' => count( $variants ), 'added' => 0, 'woo' => 0, 'no_sku' => 0 );
    $skus    = array();
    foreach ( $variants as $variant ) {
        $sku = $variant['sku'];
        if ( '' === $sku ) {
            $summary['no_sku']++;
            continue;
        }
        $skus[] = $sku;
        if ( li_is_woo_sku( $sku ) ) {
            $summary['woo']++;
            continue;
        }
        $had = li_get_product_by_sku( $sku );
        $row = li_upsert_shopify_product( $sku, $variant['name'], intval( round( floatval( $variant['price'] ) * 100 ) ) );
        if ( $row && ! $had ) {
            $summary['added']++;
        }
    }
    update_option( 'li_shopify_skus', array_values( array_unique( $skus ) ), false );
    update_option( 'li_shopify_product_sync_last', $summary, false );
    return $summary;
}

add_action( 'init', 'li_schedule_shopify_product_sync' );
function li_schedule_shopify_product_sync() {
    if ( ! wp_next_scheduled( 'li_shopify_product_sync_cron' ) ) {
        wp_schedule_event( time() + 300, 'hourly', 'li_shopify_product_sync_cron' );
    }
}

add_action( 'li_shopify_product_sync_cron', 'li_shopify_product_sync_cron' );
function li_shopify_product_sync_cron() {
    $r = li_shopify_sync_products();
    if ( is_wp_error( $r ) ) {
        li_log( 'Shopify product sync failed: ' . $r->get_error_message() );
    }
}
