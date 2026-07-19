<?php
/**
 * Plugin Name: Larry Impact
 * Plugin URI:  https://larryimpact.com
 * Description: Powers all Larry Impact functionality.
 * Version:     3.1.7
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
    'media-upload.php',
    'rescue-modal.php',
    'stripe-webhook.php',
);

foreach ( $files as $file ) {
    require_once plugin_dir_path( __FILE__ ) . 'shortcodes/' . $file;
}
require_once plugin_dir_path( __FILE__ ) . 'rescue-pages.php';

add_action( 'plugins_loaded', 'li_migrate_options', 1 );
function li_migrate_options() {
    // Default rescue share of *net* profit. Gross percent is derived per-product from cost.
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

