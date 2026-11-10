<?php
/**
 * Plugin Name: Larry Impact
 * Plugin URI:  https://larryimpact.com
 * Description: Powers all Larry Impact functionality.
 * Version:     3.6.5
 * Author:      WebDesignMike.com
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// API keys are configured in wp-config.php or the Larry Impact settings page.
// Do not store live secrets in plugin source files.

add_action( 'init', 'li_init_roles', 5 );
function li_init_roles() {
    $rescue_caps = array(
        'read'                     => true,
        'upload_files'             => true,
        'li_view_rescue_dashboard' => true,
    );
    $role = get_role( 'rescue_partner' );
    if ( ! $role ) {
        add_role( 'rescue_partner', 'Rescue Partner', $rescue_caps );
    } else {
        foreach ( $rescue_caps as $cap => $grant ) {
            $role->add_cap( $cap, $grant );
        }
    }
    $admin = get_role( 'administrator' );
    if ( $admin ) {
        $admin->add_cap( 'li_view_rescue_dashboard' );
    }
}

// load files
$files = array(
    'accounting.php',
    'helpers.php',
    'admin-menu.php',
    'admin-manual.php',
    'admin-splits.php',
    'admin-split-config.php',
    'admin-rescues.php',
    'admin-applications.php',
    'admin-payouts.php',
    'admin-reports.php',
    'admin-settings.php',
    'rescue-dashboard.php',
    'public-login.php',
    'public-apply.php',
    'woo-sync.php',
    'order-health.php',
    'shopify-catalog.php',
    'shopify-refunds.php',
    'money-store.php',
    'money-check.php',
    'shopify-storefront.php',
    'image-limits.php',
    'media-upload.php',
    'w9-private.php',
    'rescue-modal.php',
    'stripe-webhook.php',
);

foreach ( $files as $file ) {
    require_once plugin_dir_path( __FILE__ ) . 'shortcodes/' . $file;
}
require_once plugin_dir_path( __FILE__ ) . 'rescue-pages.php';

add_action( 'plugins_loaded', 'li_migrate_options', 1 );
function li_migrate_options() {
    // Default rescue share of *net* profit. Used to derive the initial fixed rescue cents when none is set.
    if ( false === get_option( 'li_default_split' ) ) {
        update_option( 'li_default_split', 55 );
    }
    if ( false === get_option( 'li_min_payout' ) ) {
        update_option( 'li_min_payout', 25 );
    }
    if ( false === get_option( 'li_currency' ) ) {
        update_option( 'li_currency', 'USD' );
    }
    if ( false === get_option( 'li_currency_symbol' ) ) {
        update_option( 'li_currency_symbol', '$' );
    }
    if ( function_exists( 'li_migrate_rescue_cents_from_split_config' ) ) {
        li_migrate_rescue_cents_from_split_config();
    }
}

add_action( 'admin_init', 'li_migrate_rescue_cents_on_admin' );
function li_migrate_rescue_cents_on_admin() {
    if ( function_exists( 'li_migrate_rescue_cents_from_split_config' ) ) {
        li_migrate_rescue_cents_from_split_config();
    }
}

// Show all Merch products in WooCommerce [products category="merch"] shortcodes (homepage + rescue pages)
add_filter( 'woocommerce_shortcode_products_query', 'li_merch_products_show_all', 10, 3 );
function li_merch_products_show_all( $query_args, $atts, $type ) {
    if ( ! empty( $atts['category'] ) && $atts['category'] === 'merch' ) {
        $query_args['posts_per_page'] = 50;
        $query_args['orderby']        = 'menu_order';
        $query_args['order']          = 'ASC';
    }
    return $query_args;
}

// Show empty design subcategories under Merch (so all 6 design icons appear)
add_filter( 'woocommerce_product_subcategories_hide_empty', '__return_false' );

// Force [product_categories parent="merch"] to show empty subcategories too
add_filter( 'shortcode_atts_product_categories', 'li_product_categories_show_empty_for_merch', 10, 4 );
function li_product_categories_show_empty_for_merch( $out, $pairs, $atts, $shortcode ) {
    if ( isset( $out['parent'] ) && ( $out['parent'] === 'merch' || $out['parent'] === '24' || (int) $out['parent'] === 24 ) ) {
        $out['hide_empty'] = 0;
    }
    return $out;
}

// Custom Merch design category order: People Suck, Some Hearts, Peace Love, Black Dog, Dog Free, People Are Why
function li_design_cat_order() {
    return array( 52, 55, 57, 53, 54, 56, 60, 61, 62 );
}

add_filter( 'woocommerce_product_subcategories_args', 'li_merch_subcategory_args_order', 20 );
function li_merch_subcategory_args_order( $args ) {
    if ( isset( $args['parent'] ) && (int) $args['parent'] === 24 ) {
        $args['include']  = li_design_cat_order();
        $args['orderby']  = 'include';
        $args['order']    = 'ASC';
        unset( $args['parent'] );
    }
    return $args;
}

add_filter( 'woocommerce_get_product_subcategories_cache_key', 'li_merch_subcategory_cache_key', 20, 2 );
function li_merch_subcategory_cache_key( $key, $parent_id ) {
    if ( (int) $parent_id === 24 ) {
        return 'li-merch-design-categories';
    }
    return $key;
}

add_filter( 'woocommerce_product_categories', 'li_merch_category_order_terms', 20 );
function li_merch_category_order_terms( $terms ) {
    if ( ! $terms || is_wp_error( $terms ) || empty( $terms ) ) {
        return $terms;
    }
    $terms = (array) $terms;
    $first = reset( $terms );
    if ( ! is_object( $first ) || ! isset( $first->taxonomy ) || $first->taxonomy !== 'product_cat' || (int) $first->parent !== 24 ) {
        return $terms;
    }
    $order   = array_flip( li_design_cat_order() );
    $ordered = array();
    foreach ( $terms as $term ) {
        $pos = isset( $order[ $term->term_id ] ) ? $order[ $term->term_id ] : 999;
        $ordered[ $pos ] = $term;
    }
    ksort( $ordered );
    return array_values( $ordered );
}