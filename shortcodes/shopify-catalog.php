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
    delete_transient( 'li_shopify_catalog_gaps' );
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

/** Every Larry product keyed by SKU, read in a few requests instead of one per SKU. */
function li_products_by_sku() {
    $map    = array();
    $limit  = 1000;
    $offset = 0;
    do {
        $page = li_store_request( 'GET', 'products?select=*&order=id.asc&limit=' . $limit . '&offset=' . $offset, null, '' );
        if ( is_wp_error( $page ) ) {
            return $page;
        }
        foreach ( $page as $p ) {
            if ( '' !== (string) ( $p['sku'] ?? '' ) ) {
                $map[ (string) $p['sku'] ] = $p;
            }
        }
        $offset += $limit;
    } while ( count( $page ) === $limit );
    return $map;
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
    $known = li_products_by_sku();
    if ( is_wp_error( $known ) ) {
        return $known;
    }
    $summary = array( 'run_at' => time(), 'variants' => count( $variants ), 'added' => 0, 'woo' => 0, 'no_sku' => 0 );
    $skus    = array();
    $new     = array();
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
        $name        = $variant['name'];
        $price_cents = max( 0, intval( round( floatval( $variant['price'] ) * 100 ) ) );
        if ( isset( $known[ $sku ] ) ) {
            $existing = $known[ $sku ];
            $changes  = array();
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
            }
            continue;
        }
        $new[ $sku ] = array(
            'name'        => $name ? $name : $sku,
            'sku'         => $sku,
            'description' => 'Shopify',
            'price_cents' => $price_cents,
            'cost_cents'  => 0,
            'active'      => true,
            'format'      => 'Merch',
        );
    }
    foreach ( array_chunk( array_values( $new ), 200 ) as $rows ) {
        $r = li_store_request( 'POST', 'products', $rows, 'return=minimal' );
        if ( is_wp_error( $r ) ) {
            li_store_error( 'Adding Shopify products', $r );
            continue;
        }
        $summary['added'] += count( $rows );
        li_log( 'Added ' . count( $rows ) . ' Shopify products to Larry' );
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
