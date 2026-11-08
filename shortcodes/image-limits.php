<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Every image uploaded to the site is kept to at most 1200px wide and saved as WebP.
 * Media Library uploads use WordPress's big-image scaling; rescue uploads through
 * the plugin go through li_shrink_uploaded_image().
 */

define( 'LI_IMAGE_MAX_WIDTH', 1200 );

add_filter( 'big_image_size_threshold', function () {
    return LI_IMAGE_MAX_WIDTH;
} );

add_filter( 'image_editor_output_format', function ( $formats ) {
    $formats['image/jpeg'] = 'image/webp';
    $formats['image/png']  = 'image/webp';
    return $formats;
} );

add_filter( 'wp_editor_set_quality', function ( $quality, $mime = '' ) {
    return 'image/webp' === $mime ? 80 : $quality;
}, 10, 2 );

/** Resizes an uploaded image file to the max width and saves it as WebP. Returns the new path, or the original on failure. */
function li_shrink_uploaded_image( $path ) {
    $editor = wp_get_image_editor( $path );
    if ( is_wp_error( $editor ) ) {
        return $path;
    }
    $size = $editor->get_size();
    if ( ! empty( $size['width'] ) && $size['width'] > LI_IMAGE_MAX_WIDTH ) {
        $editor->resize( LI_IMAGE_MAX_WIDTH, null, false );
    }
    $editor->set_quality( 80 );
    $target = preg_replace( '/\.(png|jpe?g|webp)$/i', '', $path ) . '.webp';
    $saved  = $editor->save( $target, 'image/webp' );
    if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
        return $path;
    }
    if ( $saved['path'] !== $path ) {
        wp_delete_file( $path );
    }
    return $saved['path'];
}
