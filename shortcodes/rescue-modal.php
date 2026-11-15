<?php
// Larry Impact - Rescue attribution for Shopify links

if ( ! defined( 'ABSPATH' ) ) exit;

function li_default_rescue() {
    $rescue = li_get_rescue_by_name( 'Let A Rescue Rescue You' );
    if ( ! $rescue ) {
        $rescues = li_db_get( 'rescues?status=eq.approved&select=*&limit=1&order=name.asc' );
        if ( ! empty( $rescues ) ) $rescue = $rescues[0];
    }
    return $rescue;
}

// Auto-set rescue cookie from rescue partner pages

add_action( 'template_redirect', 'li_set_rescue_cookie_early', 20 );
add_action( 'wp_footer', 'li_auto_set_rescue_from_page', 5 );

function li_set_rescue_cookie_early() {
    if ( isset( $_GET['fl_builder'] ) ) return;
    if ( is_singular() ) {
        $post_id = get_the_ID();
        $slug    = get_post_meta( $post_id, '_li_rescue_slug', true );
        $name    = get_the_title( $post_id );
        if ( $slug ) {
            if ( function_exists( 'WC' ) && WC()->session ) {
                WC()->session->set( 'li_rescue_slug', $slug );
                WC()->session->set( 'li_rescue_name', $name );
            }
            $exp = time() + ( 30 * DAY_IN_SECONDS );
            setcookie( 'li_rescue_slug', $slug, $exp, '/' );
            setcookie( 'li_rescue_name', $name, $exp, '/' );
            if ( isset( $_COOKIE['li_rescue_skipped'] ) ) {
                setcookie( 'li_rescue_skipped', '', time() - 3600, '/' );
            }
        }
    }
    if ( ! empty( $_GET['rescue'] ) ) {
        $slug = sanitize_text_field( $_GET['rescue'] );
        $name = '';
        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->set( 'li_rescue_slug', $slug );
        }
        setcookie( 'li_rescue_slug', $slug, time() + ( 30 * DAY_IN_SECONDS ), '/' );
        $rescue = li_get_rescue_by_slug( $slug );
        if ( $rescue ) {
            $name = $rescue['name'] ?? '';
            setcookie( 'li_rescue_name', $name, time() + ( 30 * DAY_IN_SECONDS ), '/' );
            if ( function_exists( 'WC' ) && WC()->session ) {
                WC()->session->set( 'li_rescue_name', $name );
            }
        }
        if ( isset( $_COOKIE['li_rescue_skipped'] ) ) {
            setcookie( 'li_rescue_skipped', '', time() - 3600, '/' );
        }
    }
}

add_action( 'template_redirect', 'li_set_default_homepage_rescue', 25 );
function li_set_default_homepage_rescue() {
    if ( isset( $_GET['fl_builder'] ) ) return;
    if ( ! is_front_page() && ! is_home() ) return;
    if ( ! empty( $_COOKIE['li_rescue_slug'] ) ) return;
    $rescue = li_default_rescue();
    if ( ! $rescue ) return;
    $slug = $rescue['slug'] ?? '';
    $name = $rescue['name'] ?? '';
    if ( ! $slug ) return;
    if ( function_exists( 'WC' ) && WC()->session ) {
        WC()->session->set( 'li_rescue_slug', $slug );
        WC()->session->set( 'li_rescue_name', $name );
    }
    $exp = time() + ( 30 * DAY_IN_SECONDS );
    setcookie( 'li_rescue_slug', $slug, $exp, '/' );
    setcookie( 'li_rescue_name', $name, $exp, '/' );
    if ( isset( $_COOKIE['li_rescue_skipped'] ) ) {
        setcookie( 'li_rescue_skipped', '', time() - 3600, '/' );
    }
}

function li_auto_set_rescue_from_page() {
    if ( isset( $_GET['fl_builder'] ) ) return;
    if ( is_singular( 'post' ) ) {
        $post_id = get_the_ID();
        $slug    = get_post_meta( $post_id, '_li_rescue_slug', true );
        $name    = get_the_title( $post_id );
        if ( $slug ) {
            if ( function_exists( 'WC' ) && WC()->session ) {
                WC()->session->set( 'li_rescue_slug', $slug );
                WC()->session->set( 'li_rescue_name', $name );
            }
            echo '<script>
(function(){
    var slug = ' . json_encode( $slug ) . ';
    var name = ' . json_encode( $name ) . ';
    var exp  = new Date(); exp.setTime(exp.getTime()+(30*24*60*60*1000));
    var e    = "; expires="+exp.toUTCString();
    document.cookie="li_rescue_slug="+encodeURIComponent(slug)+e+"; path=/";
    document.cookie="li_rescue_name="+encodeURIComponent(name)+e+"; path=/";
    document.cookie="li_rescue_skipped=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/";
})();
</script>' . "\n";
        }
    }
    if ( ! empty( $_GET['rescue'] ) ) {
        $slug = sanitize_text_field( $_GET['rescue'] );
        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->set( 'li_rescue_slug', $slug );
        }
        echo '<script>
(function(){
    var slug=' . json_encode( $slug ) . ';
    var exp=new Date(); exp.setTime(exp.getTime()+(30*24*60*60*1000));
    var e="; expires="+exp.toUTCString();
    document.cookie="li_rescue_slug="+encodeURIComponent(slug)+e+"; path=/";
})();
</script>' . "\n";
    }
}

// Read cookie into WooCommerce session on every page load

add_action( 'wp', function() {
    if ( isset( $_GET['fl_builder'] ) ) return;
    if ( ! function_exists( 'WC' ) || ! WC()->session ) return;
    $session_slug = WC()->session->get( 'li_rescue_slug', '' );
    if ( empty( $session_slug ) && ! empty( $_COOKIE['li_rescue_slug'] ) ) {
        WC()->session->set( 'li_rescue_slug', sanitize_text_field( $_COOKIE['li_rescue_slug'] ) );
        WC()->session->set( 'li_rescue_name', sanitize_text_field( $_COOKIE['li_rescue_name'] ?? '' ) );
    }
} );

// Attach rescue to WooCommerce order

add_action( 'woocommerce_checkout_create_order', function( $order ) {
    $slug = '';
    $name = '';
    if ( function_exists( 'WC' ) && WC()->session ) {
        $slug = WC()->session->get( 'li_rescue_slug', '' );
        $name = WC()->session->get( 'li_rescue_name', '' );
    }
    if ( empty( $slug ) ) {
        $slug = sanitize_text_field( $_COOKIE['li_rescue_slug'] ?? '' );
        $name = sanitize_text_field( $_COOKIE['li_rescue_name'] ?? '' );
    }
    if ( empty( $slug ) && empty( $_COOKIE['li_rescue_skipped'] ) ) {
        $rescue = li_default_rescue();
        if ( $rescue ) {
            $slug = $rescue['slug'] ?? '';
            $name = $rescue['name'] ?? '';
        }
    }
    if ( $slug ) {
        $order->update_meta_data( '_li_rescue_slug', $slug );
        $order->update_meta_data( '_li_rescue_name', $name );
    }
}, 20 );

// Append default rescue to Shopify Buy Now links when no rescue is selected

add_action( 'wp_footer', 'li_rewrite_shopify_rescue_links', 100 );
function li_rewrite_shopify_rescue_links() {
    if ( is_admin() ) return;
    if ( isset( $_GET['fl_builder'] ) ) return;
    $rescue = li_default_rescue();
    $default_slug = $rescue['slug'] ?? '';
    if ( ! $default_slug ) return;
    echo "<script>
    (function(){
        function getCookie(n){var v=document.cookie.match('(^|;) ?'+n+'=([^;]*)(;|$)');return v?decodeURIComponent(v[2]):null;}
        var slug = getCookie('li_rescue_slug') || " . json_encode( $default_slug ) . ";
        if (!slug) return;
        var links = document.querySelectorAll('a[href*=\"larrybev.myshopify.com\"]');
        for (var i=0;i<links.length;i++){
            var a=links[i];
            if (a.href.indexOf('rescue=') !== -1) continue;
            var sep = a.href.indexOf('?') === -1 ? '?' : '&';
            a.href = a.href + sep + 'rescue=' + encodeURIComponent(slug);
        }
    })();
    </script>";
}

// Show rescue in WP admin order view

add_action( 'woocommerce_admin_order_data_after_billing_address', function( $order ) {
    $slug = $order->get_meta( '_li_rescue_slug' );
    $name = $order->get_meta( '_li_rescue_name' );
    if ( $slug ) {
        echo '<p><strong>🐾 Supporting rescue:</strong> ' . esc_html( $name ?: $slug ) . '</p>';
    }
} );

// Ajax handler

add_action( 'wp_ajax_li_get_public_rescues',        'li_ajax_get_public_rescues' );
add_action( 'wp_ajax_nopriv_li_get_public_rescues', 'li_ajax_get_public_rescues' );
function li_ajax_get_public_rescues() {
    check_ajax_referer( 'li_public_rescues', 'nonce' );
    $rescues = li_db_get( 'rescues?select=id,name,slug,city,state,mission,logo_url,hero_photo_url&status=eq.approved&order=name.asc' );
    if ( ! is_array( $rescues ) ) {
        wp_send_json_error( array( 'message' => 'Could not load rescues.' ) );
    }
    wp_send_json_success( $rescues );
}

if ( ! function_exists( 'li_save_cart_rescue' ) ) {
    add_action( 'wp_ajax_li_save_cart_rescue',        'li_save_cart_rescue' );
    add_action( 'wp_ajax_nopriv_li_save_cart_rescue', 'li_save_cart_rescue' );
    function li_save_cart_rescue() {
        check_ajax_referer( 'li_cart_rescue', 'nonce' );
        $slug = sanitize_text_field( $_POST['rescue_slug'] ?? '' );
        $name = sanitize_text_field( $_POST['rescue_name'] ?? '' );
        if ( $slug && function_exists( 'WC' ) && WC()->session ) {
            WC()->session->set( 'li_rescue_slug', $slug );
            WC()->session->set( 'li_rescue_name', $name );
        }
        wp_send_json_success( [ 'slug' => $slug ] );
    }
}

// Shortcode (fallback for manual placement)

if ( ! function_exists( 'li_rescue_selector_output' ) ) {
    function li_rescue_selector_output() {}
}
// Shoppers pick their rescue in the Shopify cart now.
add_shortcode( 'li_rescue_selector', '__return_empty_string' );

