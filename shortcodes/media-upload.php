<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function li_handle_media_upload() {
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'You must be signed in to upload.' ) );
    }
    if ( ! isset( $_POST['li_nonce'] ) || ! wp_verify_nonce( $_POST['li_nonce'], 'li_media_upload' ) ) {
        wp_send_json_error( array( 'message' => 'Security check failed.' ) );
    }
    if ( empty( $_FILES['li_file'] ) ) {
        wp_send_json_error( array( 'message' => 'No file received.' ) );
    }
    $file = $_FILES['li_file'];
    if ( ! empty( $file['error'] ) ) {
        wp_send_json_error( array( 'message' => $file['error'] ) );
    }
    $type = $file['type'] ?? '';
    if ( strpos( $type, 'image/' ) !== 0 ) {
        wp_send_json_error( array( 'message' => 'Only image files are allowed.' ) );
    }
    $ext = strtolower( pathinfo( sanitize_file_name( $file['name'] ), PATHINFO_EXTENSION ) );
    $allowed_ext = array( 'jpg', 'jpeg', 'png', 'gif', 'webp' );
    if ( ! in_array( $ext, $allowed_ext, true ) ) {
        wp_send_json_error( array( 'message' => 'Invalid image file type.' ) );
    }
    $bits = file_get_contents( $file['tmp_name'] );
    if ( false === $bits ) {
        wp_send_json_error( array( 'message' => 'Could not read the uploaded file.' ) );
    }
    $upload = wp_upload_bits( sanitize_file_name( $file['name'] ), null, $bits );
    if ( ! empty( $upload['error'] ) ) {
        wp_send_json_error( array( 'message' => $upload['error'] ) );
    }
    wp_send_json_success( array( 'url' => $upload['url'] ) );
}
add_action( 'wp_ajax_li_media_upload', 'li_handle_media_upload' );

function li_handle_rescue_field_update() {
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'You must be signed in.' ) );
    }
    if ( ! isset( $_POST['li_nonce'] ) || ! wp_verify_nonce( $_POST['li_nonce'], 'li_media_upload' ) ) {
        wp_send_json_error( array( 'message' => 'Security check failed.' ) );
    }
    $rid   = sanitize_text_field( $_POST['rescue_id'] ?? '' );
    $field = sanitize_text_field( $_POST['field'] ?? '' );
    $value = esc_url_raw( $_POST['value'] ?? '' );
    if ( ! $rid || ! $field ) {
        wp_send_json_error( array( 'message' => 'Missing rescue or field.' ) );
    }
    $allowed = array( 'logo_url', 'hero_photo_url', 'w9_url' );
    if ( ! in_array( $field, $allowed, true ) ) {
        wp_send_json_error( array( 'message' => 'Invalid field.' ) );
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        $user   = wp_get_current_user();
        $rescue = li_get_rescue_by_email( $user->user_email );
        if ( ! $rescue || $rescue['id'] !== $rid ) {
            wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
        }
    }
    $r = li_db_patch( 'rescues?id=eq.' . urlencode( $rid ), array( $field => $value ) );
    if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) >= 400 ) {
        wp_send_json_error( array( 'message' => 'Could not save to rescue profile.' ) );
    }
    wp_send_json_success();
}
add_action( 'wp_ajax_li_update_rescue_field', 'li_handle_rescue_field_update' );


add_action( 'wp_ajax_li_upload_w9', 'li_handle_w9_upload' );
function li_handle_w9_upload() {
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'You must be signed in to upload.' ) );
    }
    if ( ! isset( $_POST['li_nonce'] ) || ! wp_verify_nonce( $_POST['li_nonce'], 'li_media_upload' ) ) {
        wp_send_json_error( array( 'message' => 'Security check failed.' ) );
    }
    if ( empty( $_FILES['li_file'] ) ) {
        wp_send_json_error( array( 'message' => 'No file received.' ) );
    }
    $rid = sanitize_text_field( $_POST['rescue_id'] ?? '' );
    if ( ! current_user_can( 'manage_options' ) ) {
        $user   = wp_get_current_user();
        $rescue = li_get_rescue_by_email( $user->user_email );
        if ( ! $rescue || $rescue['id'] !== $rid ) {
            wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
        }
    }
    $file = $_FILES['li_file'];
    if ( ! empty( $file['error'] ) ) {
        wp_send_json_error( array( 'message' => $file['error'] ) );
    }
    $type = $file['type'] ?? '';
    $ext  = strtolower( pathinfo( sanitize_file_name( $file['name'] ), PATHINFO_EXTENSION ) );
    if ( strpos( $type, 'image/' ) !== 0 && $type !== 'application/pdf' && $ext !== 'pdf' ) {
        wp_send_json_error( array( 'message' => 'Only images or PDF files are allowed.' ) );
    }
    if ( $ext !== 'pdf' && ! in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ), true ) ) {
        wp_send_json_error( array( 'message' => 'Invalid file type.' ) );
    }
    $bits = file_get_contents( $file['tmp_name'] );
    if ( false === $bits ) {
        wp_send_json_error( array( 'message' => 'Could not read the uploaded file.' ) );
    }
    $filename = 'w9_' . sanitize_file_name( $file['name'] );
    $upload = wp_upload_bits( $filename, null, $bits );
    if ( ! empty( $upload['error'] ) ) {
        wp_send_json_error( array( 'message' => $upload['error'] ) );
    }
    $url = $upload['url'];
    $r = li_db_patch( 'rescues?id=eq.' . urlencode( $rid ), array( 'w9_url' => esc_url_raw( $url ) ) );
    if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) >= 400 ) {
        update_option( 'li_w9_url_' . $rid, esc_url_raw( $url ) );
    }
    wp_send_json_success( array( 'url' => $url ) );
}
