<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Homepage product sections from Shopify.
 *
 * The homepage keeps its WooCommerce shortcodes and look. When "Homepage products
 * come from Shopify" is on, these two render from Shopify's public product feed
 * with the same WooCommerce markup, so products and design categories added in
 * Shopify appear on WordPress automatically:
 *   [products tag="homepage-featured" ...]   Shopify products tagged homepage-featured
 *   [product_categories parent="<merch>" ...] Shopify merch design collections
 * If Shopify can't be reached, the last good copy is shown; if there is none,
 * WooCommerce renders as before.
 */

function li_homepage_from_shopify() {
    return (bool) get_option( 'li_homepage_from_shopify', 0 );
}

function li_storefront_store() {
    return preg_replace( '#^https?://|/.*$#', '', (string) get_option( 'li_shopify_store', 'larrybev.myshopify.com' ) );
}

function li_storefront_fetch( $path ) {
    $r = wp_remote_get( 'https://' . li_storefront_store() . $path, array( 'timeout' => 15 ) );
    if ( is_wp_error( $r ) || 200 !== intval( wp_remote_retrieve_response_code( $r ) ) ) {
        return null;
    }
    $data = json_decode( wp_remote_retrieve_body( $r ), true );
    return is_array( $data ) ? $data : null;
}

/** Published Shopify products and collections, cached for an hour. */
function li_storefront_data() {
    $cached = get_transient( 'li_shopify_storefront' );
    if ( is_array( $cached ) ) {
        return $cached;
    }
    $products = array();
    for ( $page = 1; $page <= 4; $page++ ) {
        $d = li_storefront_fetch( '/products.json?limit=250&page=' . $page );
        if ( null === $d ) {
            $products = null;
            break;
        }
        $products = array_merge( $products, (array) ( $d['products'] ?? array() ) );
        if ( count( (array) ( $d['products'] ?? array() ) ) < 250 ) {
            break;
        }
    }
    $collections = li_storefront_fetch( '/collections.json?limit=250' );
    if ( null === $products || null === $collections ) {
        $last = get_option( 'li_shopify_storefront_last', array() );
        set_transient( 'li_shopify_storefront', $last, 5 * MINUTE_IN_SECONDS );
        return $last;
    }
    $data = array( 'products' => $products, 'collections' => (array) ( $collections['collections'] ?? array() ), 'at' => time() );
    update_option( 'li_shopify_storefront_last', $data, false );
    set_transient( 'li_shopify_storefront', $data, HOUR_IN_SECONDS );
    return $data;
}

function li_storefront_image( $src, $width = 600 ) {
    return $src ? add_query_arg( 'width', $width, $src ) : wc_placeholder_img_src();
}

function li_storefront_products_html( $products, $columns ) {
    $html = '<div class="woocommerce columns-' . $columns . ' "><ul class="products columns-' . $columns . '">';
    foreach ( array_values( $products ) as $i => $p ) {
        $url    = 'https://' . li_storefront_store() . '/products/' . rawurlencode( $p['handle'] );
        $prices = array_map( 'floatval', array_column( (array) $p['variants'], 'price' ) );
        $price  = $prices ? min( $prices ) : 0;
        $img    = $p['images'][0]['src'] ?? '';
        $pos    = 0 === $i % $columns ? ' first' : ( $columns - 1 === $i % $columns ? ' last' : '' );
        $html  .= '<li class="product type-product' . $pos . ' instock has-post-thumbnail product-type-external">';
        $html  .= '<a href="' . esc_url( $url ) . '" class="woocommerce-LoopProduct-link woocommerce-loop-product__link" rel="nofollow">';
        $html  .= '<img decoding="async" loading="lazy" width="300" height="300" style="aspect-ratio:1/1;object-fit:contain;background:#fff" src="' . esc_url( li_storefront_image( $img ) ) . '" class="attachment-woocommerce_thumbnail size-woocommerce_thumbnail" alt="' . esc_attr( $p['title'] ) . '">';
        $html  .= '<h2 class="woocommerce-loop-product__title">' . esc_html( $p['title'] ) . '</h2> ';
        $html  .= '<span class="price"><span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">' . esc_html( li_currency_symbol() ) . '</span>' . esc_html( number_format( $price, 2 ) ) . '</bdi></span></span> </a>';
        $html  .= '<a href="' . esc_url( $url ) . '" class="button product_type_external" rel="nofollow" aria-label="DETAILS">DETAILS</a></li>';
    }
    return $html . '</ul></div>';
}

function li_storefront_featured( $attr ) {
    $data = li_storefront_data();
    if ( empty( $data['products'] ) ) {
        return false;
    }
    $tag      = strtolower( (string) ( $attr['tag'] ?? 'homepage-featured' ) );
    $limit    = max( 1, intval( $attr['per_page'] ?? ( $attr['limit'] ?? 4 ) ) );
    $columns  = max( 1, intval( $attr['columns'] ?? 4 ) );
    $products = array_filter( $data['products'], function( $p ) use ( $tag ) {
        $tags = is_array( $p['tags'] ) ? $p['tags'] : explode( ',', (string) $p['tags'] );
        return in_array( $tag, array_map( 'strtolower', array_map( 'trim', $tags ) ), true );
    } );
    if ( ! $products ) {
        return false;
    }
    return li_storefront_products_html( array_slice( $products, 0, $limit ), $columns );
}

/** Merch design collections, in this order first; new design collections added in Shopify follow. */
function li_storefront_design_order() {
    return apply_filters( 'li_storefront_design_order', array( 'people-suck', 'some-hearts-have-fur', 'peace-love-and-rescue', 'black-dog-lives-matter', 'dog-free-is-not-freedom', 'people-are-why-dogs-need-rescuing', 'black-cat-lives-matter', 'equal-rights-equal-lives', 'every-cat-deserves-nine-lives' ) );
}

function li_storefront_designs( $attr ) {
    $data = li_storefront_data();
    if ( empty( $data['collections'] ) ) {
        return false;
    }
    $skip    = apply_filters( 'li_storefront_not_designs', array( 'coffee', 'impactlist', 'merch', 'frontpage', 'all' ) );
    $order   = array_flip( li_storefront_design_order() );
    $designs = array_filter( $data['collections'], function( $c ) use ( $skip ) {
        return ! empty( $c['image']['src'] ) && ! in_array( $c['handle'], $skip, true );
    } );
    if ( ! $designs ) {
        return false;
    }
    usort( $designs, function( $a, $b ) use ( $order ) {
        $pa = $order[ $a['handle'] ] ?? 1000;
        $pb = $order[ $b['handle'] ] ?? 1000;
        return $pa === $pb ? strnatcasecmp( $a['title'], $b['title'] ) : $pa - $pb;
    } );
    $columns = max( 1, intval( $attr['columns'] ?? 3 ) );
    $html    = '<div class="woocommerce columns-' . $columns . '"><ul class="products columns-' . $columns . '">';
    foreach ( array_values( $designs ) as $i => $c ) {
        $url   = 'https://' . li_storefront_store() . '/collections/' . rawurlencode( $c['handle'] );
        $pos   = 0 === $i % $columns ? ' first' : ( $columns - 1 === $i % $columns ? ' last' : '' );
        $count = isset( $c['products_count'] ) ? ' <mark class="count">(' . intval( $c['products_count'] ) . ')</mark>' : '';
        $html .= '<li class="product-category product' . $pos . '"><a aria-label="Visit product category ' . esc_attr( $c['title'] ) . '" href="' . esc_url( $url ) . '">';
        $html .= '<img loading="lazy" decoding="async" src="' . esc_url( li_storefront_image( $c['image']['src'] ) ) . '" alt="' . esc_attr( $c['title'] ) . '" width="300" height="300"> ';
        $html .= '<h2 class="woocommerce-loop-category__title"> ' . esc_html( $c['title'] ) . $count . ' </h2></a></li>';
    }
    return $html . '</ul></div>';
}

function li_storefront_is_merch_parent( $parent ) {
    if ( 'merch' === $parent ) {
        return true;
    }
    $term = function_exists( 'get_term_by' ) ? get_term_by( 'slug', 'merch', 'product_cat' ) : null;
    return $term && intval( $parent ) === intval( $term->term_id );
}

add_filter( 'pre_do_shortcode_tag', 'li_storefront_shortcode', 10, 3 );
function li_storefront_shortcode( $return, $tag, $attr ) {
    if ( false !== $return || ! li_homepage_from_shopify() || is_admin() ) {
        return $return;
    }
    $attr = (array) $attr;
    if ( 'products' === $tag && ! empty( $attr['tag'] ) ) {
        $html = li_storefront_featured( $attr );
        return false === $html ? $return : $html;
    }
    if ( 'product_categories' === $tag && isset( $attr['parent'] ) && li_storefront_is_merch_parent( $attr['parent'] ) ) {
        $html = li_storefront_designs( $attr );
        return false === $html ? $return : $html;
    }
    return $return;
}

// Keep the homepage working if WooCommerce is ever turned off.
add_action( 'init', 'li_storefront_register_fallback_shortcodes', 20 );
function li_storefront_register_fallback_shortcodes() {
    if ( ! li_homepage_from_shopify() ) {
        return;
    }
    if ( ! shortcode_exists( 'products' ) ) {
        add_shortcode( 'products', function( $attr ) { return (string) li_storefront_featured( (array) $attr ); } );
    }
    if ( ! shortcode_exists( 'product_categories' ) ) {
        add_shortcode( 'product_categories', function( $attr ) { return (string) li_storefront_designs( (array) $attr ); } );
    }
}
