<?php
// Hides account names, page dates and the WordPress version from signed-out visitors.
// The Larry REST routes (larry-impact/v1) used by the Shopify cart stay public.

if ( ! defined( 'ABSPATH' ) ) exit;

function li_privacy_public_view() {
    return ! current_user_can( 'edit_posts' );
}

add_filter( 'rest_endpoints', function( $endpoints ) {
    if ( li_privacy_public_view() ) {
        foreach ( array_keys( $endpoints ) as $route ) {
            if ( 0 === strpos( $route, '/wp/v2/users' ) ) {
                unset( $endpoints[ $route ] );
            }
        }
    }
    return $endpoints;
} );

add_action( 'rest_api_init', function() {
    foreach ( get_post_types( array( 'show_in_rest' => true ) ) as $type ) {
        add_filter( 'rest_prepare_' . $type, 'li_privacy_strip_rest_dates', 10, 1 );
    }
} );
function li_privacy_strip_rest_dates( $response ) {
    if ( li_privacy_public_view() && $response instanceof WP_REST_Response ) {
        $data = $response->get_data();
        unset( $data['date'], $data['date_gmt'], $data['modified'], $data['modified_gmt'], $data['author'] );
        $response->set_data( $data );
        $response->remove_link( 'author' );
        $response->remove_link( 'version-history' );
        $response->remove_link( 'predecessor-version' );
    }
    return $response;
}

// Runs before redirect_canonical, which would reveal the author slug for ?author=N.
add_action( 'template_redirect', function() {
    if ( li_privacy_public_view() && ( is_author() || isset( $_GET['author'] ) ) ) {
        wp_safe_redirect( home_url( '/' ), 301 );
        exit;
    }
}, 1 );

add_filter( 'author_link', function( $link ) {
    return li_privacy_public_view() ? home_url( '/' ) : $link;
} );
add_filter( 'the_author', 'li_privacy_author_name' );
add_filter( 'get_the_author_display_name', 'li_privacy_author_name' );
function li_privacy_author_name( $name ) {
    return li_privacy_public_view() && ! is_admin() ? get_bloginfo( 'name' ) : $name;
}

add_filter( 'oembed_response_data', function( $data ) {
    unset( $data['author_name'], $data['author_url'] );
    return $data;
} );

add_filter( 'wp_sitemaps_add_provider', function( $provider, $name ) {
    return 'users' === $name ? false : $provider;
}, 10, 2 );

add_filter( 'the_generator', '__return_empty_string' );
