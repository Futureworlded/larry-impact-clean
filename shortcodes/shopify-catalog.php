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

/** Printful products in Shopify have SKUs like 8178766_10087: product number, then Printful variant (garment, color, size). */
function li_is_printful_sku( $sku ) {
    return (bool) preg_match( '/^\d+_\d+$/', (string) $sku );
}

/**
 * Payouts for Printful SKUs that have none yet, copied from the same Printful
 * variant on other products, or else from the product's other variants, but
 * only when those all agree. Anything else is left for Split Config.
 */
function li_inherit_payouts( $skus, $known ) {
    $by_variant = array();
    $by_product = array();
    foreach ( $known as $sku => $p ) {
        if ( li_is_printful_sku( $sku ) && li_product_has_payout( $p ) ) {
            list( $product, $variant ) = explode( '_', (string) $sku );
            $by_variant[ $variant ][ intval( $p['rescue_cents'] ) ] = true;
            $by_product[ $product ][ intval( $p['rescue_cents'] ) ] = true;
        }
    }
    $skus = array_values( array_filter( array_map( 'strval', $skus ), 'li_is_printful_sku' ) );
    $out  = array();
    foreach ( $skus as $sku ) {
        list( $product, $variant ) = explode( '_', $sku );
        if ( 1 === count( $by_variant[ $variant ] ?? array() ) ) {
            $out[ $sku ] = key( $by_variant[ $variant ] );
        }
    }
    foreach ( $out as $sku => $cents ) {
        $by_product[ strtok( $sku, '_' ) ][ $cents ] = true;
    }
    foreach ( $skus as $sku ) {
        $product = strtok( $sku, '_' );
        if ( ! isset( $out[ $sku ] ) && 1 === count( $by_product[ $product ] ?? array() ) ) {
            $out[ $sku ] = key( $by_product[ $product ] );
        }
    }
    return $out;
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
    $summary = array( 'run_at' => time(), 'variants' => count( $variants ), 'added' => 0, 'woo' => 0, 'no_sku' => 0, 'payouts_set' => 0 );
    $skus    = array();
    $new     = array();
    $unpaid  = array();
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
            if ( ! li_product_has_payout( $existing ) ) {
                $unpaid[] = $sku;
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
            'rescue_cents' => null,
        );
    }
    $inherited = li_inherit_payouts( array_merge( $unpaid, array_keys( $new ) ), $known );
    foreach ( $inherited as $sku => $cents ) {
        if ( isset( $new[ $sku ] ) ) {
            $new[ $sku ]['rescue_cents'] = $cents;
        } elseif ( li_set_product_rescue_cents( $sku, $cents ) ) {
            $summary['payouts_set']++;
        }
    }
    foreach ( array_chunk( array_values( $new ), 200 ) as $rows ) {
        $r = li_store_request( 'POST', 'products', $rows, 'return=minimal' );
        if ( is_wp_error( $r ) ) {
            li_store_error( 'Adding Shopify products', $r );
            continue;
        }
        $summary['added'] += count( $rows );
        $summary['payouts_set'] += count( array_filter( array_column( $rows, 'rescue_cents' ), 'is_int' ) );
        li_log( 'Added ' . count( $rows ) . ' Shopify products to Larry' );
    }
    update_option( 'li_shopify_skus', array_values( array_unique( $skus ) ), false );
    update_option( 'li_shopify_product_sync_last', $summary, false );
    return $summary;
}

/** IDs of active Printful products that are not in the Merch collection yet. */
function li_shopify_missing_merch( $products, $merch_ids ) {
    $missing = array();
    foreach ( $products as $p ) {
        $skus = array_column( (array) ( $p['variants']['nodes'] ?? array() ), 'sku' );
        if ( $skus && count( array_filter( $skus, 'li_is_printful_sku' ) ) === count( $skus ) && ! isset( $merch_ids[ $p['id'] ] ) ) {
            $missing[] = $p['id'];
        }
    }
    return $missing;
}

/** New Printful products go into the Shopify Merch collection by themselves (added at the end). */
function li_shopify_add_new_merch() {
    if ( ! li_shopify_admin_token() || ! li_is_live_site() ) {
        return 0;
    }
    $handle = apply_filters( 'li_shopify_merch_collection', 'merch' );
    $query  = 'query LarryMerch($handle: String!, $cursor: String) {
        collectionByIdentifier(identifier: { handle: $handle }) { id }
        products(first: 100, after: $cursor, query: "status:active") {
            pageInfo { hasNextPage endCursor }
            nodes { id variants(first: 100) { nodes { sku } } inCollection: collections(first: 50) { nodes { handle } } }
        }
    }';
    $products = array();
    $merch    = array();
    $id       = '';
    $cursor   = null;
    for ( $page = 0; $page < 20; $page++ ) {
        $data = li_shopify_graphql( $query, array( 'handle' => $handle, 'cursor' => $cursor ) );
        if ( is_wp_error( $data ) ) {
            return $data;
        }
        $id = (string) ( $data['collectionByIdentifier']['id'] ?? '' );
        foreach ( (array) ( $data['products']['nodes'] ?? array() ) as $p ) {
            $products[] = $p;
            if ( in_array( $handle, array_column( (array) ( $p['inCollection']['nodes'] ?? array() ), 'handle' ), true ) ) {
                $merch[ $p['id'] ] = true;
            }
        }
        if ( empty( $data['products']['pageInfo']['hasNextPage'] ) ) {
            break;
        }
        $cursor = $data['products']['pageInfo']['endCursor'];
    }
    $missing = li_shopify_missing_merch( $products, $merch );
    if ( ! $id || ! $missing ) {
        return 0;
    }
    $data = li_shopify_graphql(
        'mutation LarryAddMerch($id: ID!, $products: [ID!]!) { collectionAddProducts(id: $id, productIds: $products) { userErrors { message } } }',
        array( 'id' => $id, 'products' => array_slice( $missing, 0, 250 ) )
    );
    $errors = is_wp_error( $data ) ? array( array( 'message' => $data->get_error_message() ) ) : (array) ( $data['collectionAddProducts']['userErrors'] ?? array() );
    if ( $errors ) {
        return new WP_Error( 'li_shopify_merch', implode( '; ', array_column( $errors, 'message' ) ) );
    }
    li_log( 'Added ' . count( $missing ) . ' new Printful products to the Shopify ' . $handle . ' collection' );
    return count( $missing );
}

/** Only the live site changes the Shopify store; staging copies share its settings. */
function li_is_live_site() {
    $host = wp_parse_url( home_url(), PHP_URL_HOST );
    return in_array( $host, (array) apply_filters( 'li_live_hosts', array( 'larryimpact.com', 'www.larryimpact.com' ) ), true );
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
    $m = li_shopify_add_new_merch();
    if ( is_wp_error( $m ) ) {
        li_log( 'Adding new products to Shopify Merch failed: ' . $m->get_error_message() );
    }
}
