<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function li_validate_upload_file( $file, $allowed_ext ) {
    if ( ! is_array( $file ) || ! empty( $file['error'] ) ) {
        return ! empty( $file['error'] ) ? $file['error'] : 'No file received.';
    }

    $max_size = wp_max_upload_size();
    if ( $max_size > 0 && $file['size'] > $max_size ) {
        return 'File is too large. Maximum size: ' . size_format( $max_size ) . '.';
    }

    $allowed_ext = array_map( 'strtolower', $allowed_ext );
    $filetype = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $allowed_ext );
    if ( empty( $filetype['ext'] ) || empty( $filetype['type'] ) ) {
        return 'Invalid or unsafe file type.';
    }
    if ( ! in_array( strtolower( $filetype['ext'] ), $allowed_ext, true ) ) {
        return 'File extension not allowed.';
    }

    $real_mime = wp_get_image_mime( $file['tmp_name'] );
    if ( strpos( $filetype['type'], 'image/' ) === 0 ) {
        if ( empty( $real_mime ) || strpos( $real_mime, 'image/' ) !== 0 ) {
            return 'Uploaded file is not a valid image.';
        }
        $info = getimagesize( $file['tmp_name'] );
        if ( false === $info ) {
            return 'Could not read image dimensions.';
        }
        $width  = $info[0];
        $height = $info[1];
        if ( $width < 10 || $height < 10 || $width > 10000 || $height > 10000 ) {
            return 'Image dimensions are outside the allowed range (10x10 to 10000x10000 pixels).';
        }
    }

    if ( $filetype['type'] === 'application/pdf' && strtolower( $filetype['ext'] ) !== 'pdf' ) {
        return 'Only PDF files are allowed for document uploads.';
    }

    return true;
}

function li_handle_media_upload() {
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'You must be signed in to upload.' ) );
    }
    if ( ! isset( $_POST['li_nonce'] ) || ! wp_verify_nonce( $_POST['li_nonce'], 'li_media_upload' ) ) {
        wp_send_json_error( array( 'message' => 'Security check failed.' ) );
    }
    $rid = sanitize_text_field( $_POST['rescue_id'] ?? '' );
    if ( ! $rid ) {
        wp_send_json_error( array( 'message' => 'Missing rescue.' ) );
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        $user   = wp_get_current_user();
        $rescue = li_get_rescue_by_email( $user->user_email );
        if ( ! $rescue || $rescue['id'] !== $rid ) {
            wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
        }
    }
    if ( empty( $_FILES['li_file'] ) ) {
        wp_send_json_error( array( 'message' => 'No file received.' ) );
    }
    $file = $_FILES['li_file'];
    $allowed = array( 'jpg', 'jpeg', 'png', 'gif', 'webp' );
    $validation = li_validate_upload_file( $file, $allowed );
    if ( true !== $validation ) {
        wp_send_json_error( array( 'message' => $validation ) );
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
    $rid = sanitize_text_field( $_POST['rescue_id'] ?? '' );
    if ( ! current_user_can( 'manage_options' ) ) {
        $user   = wp_get_current_user();
        $rescue = li_get_rescue_by_email( $user->user_email );
        if ( ! $rescue || $rescue['id'] !== $rid ) {
            wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
        }
    }
    if ( empty( $_FILES['li_file'] ) ) {
        wp_send_json_error( array( 'message' => 'No file received.' ) );
    }
    $file = $_FILES['li_file'];
    $allowed = array( 'pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp' );
    $validation = li_validate_upload_file( $file, $allowed );
    if ( true !== $validation ) {
        wp_send_json_error( array( 'message' => $validation ) );
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
