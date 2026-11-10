<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// W-9s hold tax IDs, so they are kept above the web root (SiteGround's nginx serves
// static files under public_html without reading .htaccess). They are only readable
// through li_w9_download, by an admin or the rescue that owns them.

function li_w9_private_dir() {
    $root = defined( 'LI_PRIVATE_DIR' ) ? LI_PRIVATE_DIR : dirname( untrailingslashit( ABSPATH ) ) . '/li-private';
    return rtrim( $root, '/' ) . '/w9';
}

function li_w9_protect_dir( $dir ) {
    if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) return false;
    $root = dirname( $dir );
    $deny = "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n";
    foreach ( array( $root, $dir ) as $d ) {
        if ( ! file_exists( $d . '/.htaccess' ) ) file_put_contents( $d . '/.htaccess', $deny );
        if ( ! file_exists( $d . '/index.php' ) ) file_put_contents( $d . '/index.php', "<?php\n// Silence is golden.\n" );
    }
    return is_dir( $dir );
}

function li_w9_link( $rescue_id ) {
    return admin_url( 'admin-ajax.php?action=li_w9_download&rescue_id=' . rawurlencode( $rescue_id ) );
}

function li_w9_is_private_link( $url ) {
    return false !== strpos( (string) $url, 'action=li_w9_download' );
}

// Public uploads URL -> file path, or '' if the URL is not a file in the uploads folder.
function li_w9_legacy_path( $url, $baseurl, $basedir ) {
    $url     = preg_replace( '#^https?://#i', '', (string) $url );
    $baseurl = rtrim( preg_replace( '#^https?://#i', '', (string) $baseurl ), '/' ) . '/';
    if ( '' === $url || 0 !== strpos( $url, $baseurl ) ) return '';
    $rel = rawurldecode( strtok( substr( $url, strlen( $baseurl ) ), '?#' ) );
    if ( '' === $rel || false !== strpos( $rel, '..' ) || 0 === strpos( $rel, 'li-private/' ) ) return '';
    return rtrim( $basedir, '/' ) . '/' . $rel;
}

function li_w9_can_view( $rescue_id, $is_admin, $own_rescue_id ) {
    if ( '' === (string) $rescue_id ) return false;
    return $is_admin || ( '' !== (string) $own_rescue_id && (string) $own_rescue_id === (string) $rescue_id );
}

function li_w9_file_name( $rescue_id, $ext ) {
    $ext = strtolower( preg_replace( '/[^a-z0-9]/i', '', (string) $ext ) );
    return 'w9_' . sanitize_key( $rescue_id ) . '_' . wp_generate_password( 24, false ) . '.' . ( $ext ? $ext : 'pdf' );
}

// Saves the W-9 privately and returns the login-only link, or a WP_Error.
function li_w9_store( $rescue_id, $bits, $ext ) {
    $dir = li_w9_private_dir();
    if ( ! li_w9_protect_dir( $dir ) ) return new WP_Error( 'w9_dir', 'Could not prepare private W-9 storage.' );
    $name = li_w9_file_name( $rescue_id, $ext );
    if ( false === file_put_contents( $dir . '/' . $name, $bits ) ) return new WP_Error( 'w9_write', 'Could not save the W-9.' );
    $old = get_option( 'li_w9_file_' . $rescue_id, '' );
    update_option( 'li_w9_file_' . $rescue_id, $name, false );
    if ( $old && $old !== $name && file_exists( $dir . '/' . basename( $old ) ) ) @unlink( $dir . '/' . basename( $old ) );
    return li_w9_link( $rescue_id );
}

add_action( 'wp_ajax_li_w9_download', 'li_w9_download' );
add_action( 'wp_ajax_nopriv_li_w9_download', 'li_w9_download' );
function li_w9_download() {
    $rid = sanitize_text_field( wp_unslash( $_GET['rescue_id'] ?? '' ) );
    if ( ! is_user_logged_in() ) {
        wp_safe_redirect( wp_login_url( li_w9_link( $rid ) ) );
        exit;
    }
    $own = '';
    if ( ! current_user_can( 'manage_options' ) ) {
        $mine = li_get_rescue_by_email( wp_get_current_user()->user_email );
        $own  = $mine['id'] ?? '';
    }
    if ( ! li_w9_can_view( $rid, current_user_can( 'manage_options' ), $own ) ) {
        wp_die( 'You do not have access to this W-9.', 'Not allowed', array( 'response' => 403 ) );
    }
    $name = basename( (string) get_option( 'li_w9_file_' . $rid, '' ) );
    $path = $name ? li_w9_private_dir() . '/' . $name : '';
    if ( ! $path || ! is_file( $path ) ) {
        wp_die( 'No W-9 on file.', 'Not found', array( 'response' => 404 ) );
    }
    $type = wp_check_filetype( $name );
    nocache_headers();
    header( 'Content-Type: ' . ( $type['type'] ? $type['type'] : 'application/octet-stream' ) );
    header( 'Content-Disposition: inline; filename="W-9.' . ( $type['ext'] ? $type['ext'] : 'pdf' ) . '"' );
    header( 'Content-Length: ' . filesize( $path ) );
    header( 'X-Content-Type-Options: nosniff' );
    header( 'Cache-Control: private, no-store' );
    readfile( $path );
    exit;
}

// Moves W-9s uploaded before this change out of the public uploads folder.
function li_w9_migrate_legacy() {
    $out  = array( 'moved' => 0, 'already' => 0, 'missing' => 0, 'failed' => 0 );
    $rows = li_db_get( 'rescues?select=id,w9_url' );
    if ( ! is_array( $rows ) ) return new WP_Error( 'w9_list', 'Could not read rescues.' );
    $dir = li_w9_private_dir();
    if ( ! li_w9_protect_dir( $dir ) ) return new WP_Error( 'w9_dir', 'Could not prepare private W-9 storage.' );
    $u = wp_upload_dir();
    foreach ( $rows as $row ) {
        $rid = $row['id'] ?? '';
        $url = ! empty( $row['w9_url'] ) ? $row['w9_url'] : get_option( 'li_w9_url_' . $rid, '' );
        if ( ! $rid || ! $url ) continue;
        if ( li_w9_is_private_link( $url ) ) { $out['already']++; continue; }
        $src = li_w9_legacy_path( $url, $u['baseurl'], $u['basedir'] );
        if ( ! $src || ! is_file( $src ) ) { $out['missing']++; li_log( 'W-9 for rescue ' . $rid . ' is not in the uploads folder; left as is.' ); continue; }
        $name = li_w9_file_name( $rid, pathinfo( $src, PATHINFO_EXTENSION ) );
        if ( ! @rename( $src, $dir . '/' . $name ) ) { $out['failed']++; continue; }
        update_option( 'li_w9_file_' . $rid, $name, false );
        $r = li_db_patch( 'rescues?id=eq.' . urlencode( $rid ), array( 'w9_url' => li_w9_link( $rid ) ) );
        if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) >= 400 ) {
            update_option( 'li_w9_url_' . $rid, li_w9_link( $rid ) );
        } else {
            delete_option( 'li_w9_url_' . $rid );
        }
        $out['moved']++;
    }
    return $out;
}

add_action( 'admin_init', 'li_w9_maybe_migrate' );
function li_w9_maybe_migrate() {
    if ( get_option( 'li_w9_private_done' ) || ! current_user_can( 'manage_options' ) ) return;
    if ( get_transient( 'li_w9_migrate_running' ) ) return;
    set_transient( 'li_w9_migrate_running', 1, 5 * MINUTE_IN_SECONDS );
    $out = li_w9_migrate_legacy();
    delete_transient( 'li_w9_migrate_running' );
    if ( is_wp_error( $out ) ) { li_log( 'W-9 move failed: ' . $out->get_error_message() ); return; }
    li_log( 'W-9 move: ' . wp_json_encode( $out ) );
    if ( 0 === $out['failed'] ) update_option( 'li_w9_private_done', 1 );
}
