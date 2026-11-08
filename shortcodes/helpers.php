<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function li_service_key() {
    if ( defined( 'LI_SERVICE_KEY' ) && LI_SERVICE_KEY ) {
        return LI_SERVICE_KEY;
    }
    return (string) get_option( 'li_service_key', '' );
}

/**
 * The plugin only talks to the database with the private server key. Without it,
 * reads and writes stop with an error instead of quietly using the public key.
 */
function li_db_key_or_error() {
    $key = li_service_key();
    if ( '' === $key ) {
        if ( ! get_transient( 'li_service_key_missing_logged' ) ) {
            set_transient( 'li_service_key_missing_logged', 1, HOUR_IN_SECONDS );
            error_log( 'Larry Impact: database server key is missing. Add it in Larry Impact > Settings.' );
        }
        return new WP_Error( 'li_no_service_key', 'The Larry database server key is missing. Add it in Larry Impact > Settings.' );
    }
    return $key;
}

add_action( 'admin_notices', 'li_service_key_missing_notice' );
function li_service_key_missing_notice() {
    if ( '' !== li_service_key() || ! current_user_can( 'manage_options' ) ) {
        return;
    }
    echo '<div class="notice notice-error"><p><strong>Larry Impact:</strong> the database server key is missing, so orders and rescues cannot be read or saved. Add it in Larry Impact &gt; Settings.</p></div>';
}

function li_currency() {
    return strtoupper( sanitize_text_field( get_option( 'li_currency', 'USD' ) ) );
}

function li_currency_symbol() {
    return get_option( 'li_currency_symbol', '$' );
}

function li_format_money( $cents ) {
    return li_currency_symbol() . number_format( intval( $cents ) / 100, 2 );
}

function li_db_get( $endpoint ) {
    $key = li_db_key_or_error();
    if ( is_wp_error( $key ) ) return array();
    $response = wp_remote_get( LI_DB_URL . '/rest/v1/' . $endpoint, array(
        'headers' => array(
            'apikey'        => $key,
            'Authorization' => 'Bearer ' . $key,
        ),
    ) );
    if ( is_wp_error( $response ) ) return array();
    $data = json_decode( wp_remote_retrieve_body( $response ), true ) ?: array();
    // Clean one layer of WordPress escaping from text stored by older forms.
    return wp_unslash( $data );
}

function li_db_patch( $endpoint, $data ) {
    $key = li_db_key_or_error();
    if ( is_wp_error( $key ) ) return $key;
    $data = wp_unslash( $data );
    return wp_remote_request( LI_DB_URL . '/rest/v1/' . $endpoint, array(
        'method'  => 'PATCH',
        'headers' => array(
            'apikey'        => $key,
            'Authorization' => 'Bearer ' . $key,
            'Content-Type'  => 'application/json',
            'Prefer'        => 'return=minimal',
        ),
        'body' => wp_json_encode( $data ),
    ) );
}

function li_db_post( $endpoint, $data ) {
    $key = li_db_key_or_error();
    if ( is_wp_error( $key ) ) return $key;
    $data = wp_unslash( $data );
    return wp_remote_post( LI_DB_URL . '/rest/v1/' . $endpoint, array(
        'headers' => array(
            'apikey'        => $key,
            'Authorization' => 'Bearer ' . $key,
            'Content-Type'  => 'application/json',
            'Prefer'        => 'return=minimal',
        ),
        'body' => wp_json_encode( $data ),
    ) );
}

function li_is_admin() {
    return is_user_logged_in() && current_user_can( 'manage_options' );
}

function li_require_login() {
    if ( ! is_user_logged_in() ) {
        wp_redirect( home_url( '/login/' ) );
        exit;
    }
}

function li_get_rescue_by_email( $email ) {
    $data = li_db_get( 'rescues?email=eq.' . urlencode( $email ) . '&select=*' );
    return li_rescue_merge_points( li_rescue_merge_w9( ! empty( $data ) ? $data[0] : null ) );
}

function li_admin_wrap( $title, $content ) {
    echo '<div class="wrap">';
    echo '<style>
        .li-page { font-family: -apple-system, BlinkMacSystemFont, sans-serif; }
        .li-header { background: #2c2a26; color: #fff; padding: 18px 24px; border-radius: 8px; margin-bottom: 24px; }
        .li-header .li-eye { font-size: 10px; color: #c9a84c; letter-spacing: .12em; text-transform: uppercase; margin-bottom: 4px; }
        .li-header h1 { font-size: 18px; font-weight: 600; color: #fff; margin: 0; padding: 0; line-height: 1; border: none; }
        .li-body { background: #f9f7f4; border: 1px solid #e8e3db; border-radius: 8px; padding: 24px; }
        .li-card { background: #fff; border: 1px solid #e8e3db; border-radius: 12px; padding: 1.5rem; margin-bottom: 16px; }
        .li-card-title { font-size: 12px; font-weight: 700; color: #c9a84c; letter-spacing: .1em; text-transform: uppercase; margin-bottom: 1rem; padding-bottom: 8px; border-bottom: 1px solid #f0ece6; }
        .li-badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .li-badge-approved { background: #eaf3de; color: #3a7a4a; border: 1px solid #b8d898; }
        .li-badge-pending  { background: #fef3e2; color: #9a6f2a; border: 1px solid #f0d9a8; }
        .li-badge-declined { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        .li-badge-inactive { background: #f1efe8; color: #6b6560; border: 1px solid #d3d1c7; }
        .li-btn { background: #2c2a26; color: #fff; border: none; border-radius: 8px; padding: 9px 18px; font-size: 13px; font-weight: 600; cursor: pointer; font-family: inherit; text-decoration: none; display: inline-block; }
        .li-btn:hover { background: #3d3a34; color: #fff; }
        .li-btn-sm { padding: 5px 12px; font-size: 12px; }
        .li-btn-gold { background: #c9a84c; color: #2c2a26; }
        .li-btn-gold:hover { background: #b8962e; color: #2c2a26; }
        .li-table-wrap { background: #fff; border: 1px solid #e8e3db; border-radius: 12px; overflow-x: auto; }
        .li-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .li-table thead tr { background: #f9f7f4; }
        .li-table th { padding: 12px 16px; text-align: left; font-size: 11px; color: #a89880; letter-spacing: .06em; text-transform: uppercase; font-weight: 600; white-space: nowrap; }
        .li-table td { padding: 13px 16px; border-top: 1px solid #f0ece6; color: #3a3530; }
        .li-table tr:hover td { background: #fdfcfa; }
        .li-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .li-row-full { grid-template-columns: 1fr; }
        .li-field { margin-bottom: 1rem; }
        .li-label { display: block; font-size: 12px; font-weight: 600; color: #6b6560; letter-spacing: .04em; text-transform: uppercase; margin-bottom: 6px; }
        .li-input, .li-select, .li-textarea { width: 100%; border: 1px solid #ddd8d0; border-radius: 8px; padding: 9px 12px; font-size: 13px; font-family: inherit; color: #2c2a26; background: #fdfcfa; outline: none; box-sizing: border-box; }
        .li-input:focus, .li-select:focus, .li-textarea:focus { border-color: #c9a84c; background: #fff; }
        .li-textarea { resize: vertical; min-height: 80px; line-height: 1.5; }
        .li-msg-ok  { background: #eaf3de; border: 1px solid #b8d898; border-radius: 8px; padding: 12px 16px; color: #3a7a4a; font-size: 13px; margin-bottom: 16px; }
        .li-msg-err { background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 12px 16px; color: #b91c1c; font-size: 13px; margin-bottom: 16px; }
        .li-empty { text-align: center; padding: 48px 20px; color: #a89880; font-size: 14px; }
        .li-loading { text-align: center; padding: 48px 20px; color: #a89880; font-size: 14px; }
        .li-stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-bottom: 1.5rem; }
        .li-stat { background: #fff; border: 1px solid #e8e3db; border-radius: 10px; padding: 1rem; text-align: center; }
        .li-stat-val { font-size: 24px; font-weight: 600; color: #2c2a26; }
        .li-stat-val-green { color: #3a7a4a; }
        .li-stat-val-amber { color: #9a6f2a; }
        .li-stat-label { font-size: 11px; color: #a89880; margin-top: 4px; text-transform: uppercase; letter-spacing: .05em; }
        .li-tabs { display: flex; gap: 4px; margin-bottom: 20px; border-bottom: 1px solid #e8e3db; }
        .li-tab { padding: 10px 18px; font-size: 13px; font-weight: 600; color: #a89880; cursor: pointer; border: none; background: none; border-bottom: 2px solid transparent; margin-bottom: -1px; font-family: inherit; }
        .li-tab:hover { color: #2c2a26; }
        .li-tab-active { color: #2c2a26; border-bottom-color: #c9a84c; }
        .li-panel { display: none; }
        .li-panel-active { display: block; }
        .li-back { font-size: 13px; color: #6b6560; text-decoration: none; display: inline-block; margin-bottom: 16px; }
        .li-back:hover { color: #2c2a26; }
        .li-rescue-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 20px; flex-wrap: wrap; gap: 12px; }
        .li-rescue-name { font-size: 20px; font-weight: 600; color: #2c2a26; margin-bottom: 4px; }
        .li-rescue-meta { font-size: 13px; color: #a89880; }
        .li-media-thumb { max-width: 120px; max-height: 80px; border-radius: 8px; border: 1px solid #e8e3db; object-fit: contain; background: #f9f7f4; }
        .li-media-wide { max-width: 200px; max-height: 100px; }
        .li-media-empty { display: inline-block; background: #f9f7f4; border: 1px solid #e8e3db; border-radius: 8px; padding: 14px 20px; font-size: 12px; color: #a89880; }
        .li-upload-btn { background: #f9f7f4; border: 1px solid #ddd8d0; border-radius: 6px; padding: 7px 14px; font-size: 12px; font-weight: 600; color: #4a4540; cursor: pointer; font-family: inherit; margin-top: 10px; display: inline-block; }
        .li-upload-btn:hover { background: #f0ece6; }
        .li-hint { font-size: 11px; color: #a89880; margin-top: 4px; line-height: 1.5; }
        .li-toggle-row { display: flex; align-items: center; justify-content: space-between; padding: 4px 0; }
        .li-toggle { position: relative; display: inline-block; width: 42px; height: 24px; flex-shrink: 0; }
        .li-toggle input { opacity: 0; width: 0; height: 0; }
        .li-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background: #ddd8d0; border-radius: 24px; transition: .2s; }
        .li-slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background: #fff; border-radius: 50%; transition: .2s; }
        .li-toggle input:checked + .li-slider { background: #c9a84c; }
        .li-toggle input:checked + .li-slider:before { transform: translateX(18px); }
        .li-preview-notice { background: #fef3e2; border: 1px solid #f0d9a8; border-radius: 8px; padding: 12px 16px; font-size: 13px; color: #9a6f2a; margin-bottom: 20px; }
    </style>';
    echo '<div class="li-page">';
    echo '<div class="li-header"><div class="li-eye">Larry Impact</div><h1>' . esc_html( $title ) . '</h1></div>';
    echo '<div class="li-body">' . $content . '</div>';
    echo '</div></div>';
}

// shared JS helpers
function li_js_vars() {
    return 'var LI_ADMIN_AJAX="' . esc_js( admin_url( 'admin-ajax.php?action=li_admin_supabase_proxy' ) ) . '";var LI_ADMIN_NONCE="' . esc_js( wp_create_nonce( 'li_admin_supabase_proxy' ) ) . '";';
}

add_action( 'wp_ajax_li_admin_supabase_proxy', 'li_ajax_admin_supabase_proxy' );
function li_ajax_admin_supabase_proxy() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
    }
    check_ajax_referer( 'li_admin_supabase_proxy', 'nonce' );
    $path = isset( $_REQUEST['path'] ) ? sanitize_text_field( $_REQUEST['path'] ) : '';
    if ( ! $path || preg_match( '/\b(rpc|schema)\b/i', $path ) ) {
        wp_send_json_error( array( 'message' => 'Invalid proxy path.' ) );
    }
    $data = li_db_get( $path );
    if ( ! is_array( $data ) ) {
        wp_send_json_error( array( 'message' => 'Could not load data.' ) );
    }
    wp_send_json( $data );
}

add_action( 'wp_ajax_li_rescue_get_orders',        'li_ajax_rescue_get_orders' );
add_action( 'wp_ajax_nopriv_li_rescue_get_orders', 'li_ajax_rescue_get_orders' );
function li_ajax_rescue_get_orders() {
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
    }
    check_ajax_referer( 'li_rescue_get_orders', 'nonce' );
    $rescue_id = sanitize_text_field( $_POST['rescue_id'] ?? '' );
    $user = wp_get_current_user();
    $rescue = li_get_rescue_by_email( $user->user_email );
    if ( ! $rescue || $rescue['id'] !== $rescue_id ) {
        wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
    }
    $orders = li_db_get( 'orders?rescue_id=eq.' . urlencode( $rescue_id ) . '&select=*,products(name)&order=ordered_at.desc' );
    if ( ! is_array( $orders ) ) {
        wp_send_json_error( array( 'message' => 'Could not load orders.' ) );
    }
    wp_send_json( $orders );
}

function li_money( $cents ) {
    return li_format_money( $cents );
}

function li_badge( $status ) {
    $classes = array(
        'approved' => 'li-badge-approved',
        'active'   => 'li-badge-approved',
        'pending'  => 'li-badge-pending',
        'declined' => 'li-badge-declined',
        'inactive' => 'li-badge-inactive',
    );
    $cls = isset( $classes[ $status ] ) ? $classes[ $status ] : 'li-badge-inactive';
    return '<span class="li-badge ' . $cls . '">' . esc_html( ucfirst( $status ) ) . '</span>';
}

function li_stripe_sk() {
    $key = get_option( 'li_stripe_sk', '' );
    if ( $key ) return $key;
    $wc = get_option( 'woocommerce_stripe_settings', array() );
    if ( ! empty( $wc['secret_key'] ) ) return $wc['secret_key'];
    if ( defined( 'LI_STRIPE_SK' ) && LI_STRIPE_SK ) return LI_STRIPE_SK;
    return '';
}

function li_stripe_pk() {
    $key = get_option( 'li_stripe_pk', '' );
    if ( $key ) return $key;
    $wc = get_option( 'woocommerce_stripe_settings', array() );
    if ( ! empty( $wc['publishable_key'] ) ) return $wc['publishable_key'];
    if ( defined( 'LI_STRIPE_PK' ) && LI_STRIPE_PK ) return LI_STRIPE_PK;
    return '';
}

function li_stripe_webhook_secret() {
    $key = get_option( 'li_stripe_webhook_secret', '' );
    if ( $key ) return $key;
    if ( defined( 'LI_STRIPE_WEBHOOK_SECRET' ) && LI_STRIPE_WEBHOOK_SECRET ) return LI_STRIPE_WEBHOOK_SECRET;
    return '';
}

function li_get_rescue_by_id( $rescue_id ) {
    $data = li_db_get( 'rescues?id=eq.' . urlencode( $rescue_id ) . '&select=*' );
    return li_rescue_merge_points( li_rescue_merge_w9( ! empty( $data ) && is_array( $data ) ? $data[0] : null ) );
}

function li_send_email( $to, $subject, $message ) {
    $from_name  = get_option( 'li_from_name', 'Larry Impact' );
    $from_email = get_option( 'li_from_email', get_option( 'admin_email' ) );
    $reply_to   = get_option( 'li_reply_to', $from_email );
    $headers    = array(
        'From: ' . sanitize_text_field( $from_name ) . ' <' . sanitize_email( $from_email ) . '>',
        'Reply-To: ' . sanitize_email( $reply_to ),
        'Content-Type: text/plain; charset=UTF-8',
    );
    wp_mail( $to, $subject, $message, $headers );
}

function li_notify_admin( $subject, $message ) {
    $admin_email = get_option( 'li_admin_email', get_option( 'admin_email' ) );
    if ( ! $admin_email ) return;
    li_send_email( $admin_email, $subject, $message );
}

function li_notify_rescue( $rescue_id, $subject, $message ) {
    $rescue = li_get_rescue_by_id( $rescue_id );
    if ( ! $rescue || empty( $rescue['email'] ) ) return;
    li_send_email( $rescue['email'], $subject, $message );
}

function li_check_payout_threshold( $rescue_id ) {
    if ( ! $rescue_id ) return;
    $pending = li_db_get( 'orders?rescue_id=eq.' . urlencode( $rescue_id ) . '&status=eq.pending&select=rescue_split_cents' );
    if ( ! is_array( $pending ) ) return;
    $total_cents = array_sum( array_map( function( $o ) { return intval( $o['rescue_split_cents'] ?? 0 ); }, $pending ) );
    if ( $total_cents < 50000 ) return;
    if ( get_transient( 'li_threshold_notified_' . $rescue_id ) ) return;
    set_transient( 'li_threshold_notified_' . $rescue_id, 1, DAY_IN_SECONDS );
    $rescue = li_get_rescue_by_id( $rescue_id );
    $name   = $rescue ? ( $rescue['name'] ?? 'A rescue' ) : 'A rescue';
    li_notify_admin(
        'Pending payout exceeds ' . li_format_money( 50000 ) . ': ' . $name,
        "Rescue: {$name}\nPending payout balance: " . li_format_money( $total_cents ) . "\n\nReview payouts: " . admin_url( 'admin.php?page=li-payouts' )
    );
}

function li_create_rescue_wp_user( $name, $email, $send_email = true ) {
    $email = sanitize_email( $email );
    $user  = get_user_by( 'email', $email );
    if ( $user ) {
        if ( ! in_array( 'administrator', $user->roles, true ) ) {
            wp_update_user( array( 'ID' => $user->ID, 'role' => 'rescue_partner' ) );
        }
        return array( 'user_id' => $user->ID, 'password' => '', 'username' => $user->user_login, 'email_sent' => false, 'message' => 'Existing user updated.' );
    }
    $password = wp_generate_password( 12, true );
    $username = sanitize_user( $name );
    if ( ! $username || username_exists( $username ) ) {
        $username = sanitize_user( current( explode( '@', $email ) ) );
    }
    if ( username_exists( $username ) ) {
        $username .= '_' . wp_rand( 100, 999 );
    }
    $user_id = wp_create_user( $username, $password, $email );
    if ( is_wp_error( $user_id ) ) {
        li_log( 'Failed to create WP user for rescue email ' . $email . ': ' . $user_id->get_error_message() );
        return array( 'user_id' => 0, 'password' => '', 'username' => '', 'email_sent' => false, 'message' => $user_id->get_error_message() );
    }
    $user = get_user_by( 'id', $user_id );
    if ( $user && ! in_array( 'administrator', $user->roles, true ) ) {
        wp_update_user( array( 'ID' => $user->ID, 'role' => 'rescue_partner' ) );
    }
    $email_sent = false;
    if ( $send_email && $password ) {
        $login_url = home_url( '/rescue-login/' );
        $setup_url = home_url( '/dashboard/my-account/' );
        $subject   = 'Your Larry Impact rescue account is approved - action required';
        $message   = "Hi {$name},

"
            . "Your rescue application has been approved. Welcome to Larry Impact!

"
            . "Login details:
"
            . "Username: {$username}
"
            . "Temporary password: {$password}
"
            . "Login: {$login_url}

"
            . "Please complete your payout setup:
"
            . "1. Log in and open Setup / My Account.
"
            . "2. Upload your rescue's W-9 tax form.
"
            . "3. Click Connect bank account and complete the secure Stripe setup.

"
            . "Setup page: {$setup_url}

"
            . "Payouts cannot be issued until your W-9 and bank setup are complete.
"
            . "Please also change your temporary password after logging in.

"
            . "The Larry Impact Team";
        $email_sent = wp_mail( $email, $subject, $message );
    }
    return array( 'user_id' => $user_id, 'password' => $password, 'username' => $username, 'email_sent' => $email_sent, 'message' => 'User created.' );
}

function li_rescue_merge_w9( $rescue ) {
    if ( ! $rescue || ! empty( $rescue['w9_url'] ) ) return $rescue;
    $fallback = get_option( 'li_w9_url_' . ( $rescue['id'] ?? '' ), '' );
    if ( $fallback ) {
        $rescue['w9_url'] = $fallback;
    }
    return $rescue;
}



function li_rescue_merge_points( $rescue ) {
    if ( ! $rescue || isset( $rescue['points'] ) ) return $rescue;
    $fallback = get_option( 'li_rescue_points_' . ( $rescue['id'] ?? '' ), 0 );
    $rescue['points'] = intval( $fallback );
    return $rescue;
}

function li_award_rescue_points( $rescue_id, $points ) {
    if ( ! $rescue_id || $points <= 0 ) return;
    $rescue_data = li_db_get( 'rescues?id=eq.' . urlencode( $rescue_id ) . '&select=*' );
    $rescue = ! empty( $rescue_data ) ? $rescue_data[0] : null;
    if ( ! $rescue ) return;
    $current = isset( $rescue['points'] ) ? intval( $rescue['points'] ) : intval( get_option( 'li_rescue_points_' . $rescue_id, 0 ) );
    $new_total = $current + $points;
    $r = li_db_patch( 'rescues?id=eq.' . urlencode( $rescue_id ), array( 'points' => $new_total ) );
    if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) >= 400 ) {
        update_option( 'li_rescue_points_' . $rescue_id, $new_total );
    }
}

function li_prepare_rescue_payout( $rescue ) {
    $rescue_id = $rescue['id'] ?? '';
    if ( ! $rescue_id ) {
        return array( 'success' => false, 'message' => 'Invalid rescue.' );
    }
    $pending = li_db_get( 'orders?rescue_id=eq.' . urlencode( $rescue_id ) . '&status=eq.pending&select=*' );
    if ( is_wp_error( $pending ) ) {
        return array( 'success' => false, 'message' => 'Could not load pending orders.' );
    }
    $pending = array_filter( $pending, function( $o ) { return in_array( $o['status'] ?? '', array( 'pending' ), true ); } );
    if ( empty( $pending ) ) {
        return array( 'success' => true, 'message' => 'No pending orders for ' . ( $rescue['name'] ?? 'rescue' ) . '.' );
    }
    $total_cents = array_sum( array_map( function( $o ) { return intval( $o['rescue_split_cents'] ?? 0 ); }, $pending ) );
    $min_dollars = floatval( get_option( 'li_min_payout', 25 ) );
    $min_cents   = max( 0, intval( $min_dollars * 100 ) );
    if ( $total_cents < $min_cents ) {
        return array( 'success' => false, 'message' => 'Balance ' . li_format_money( $total_cents ) . ' is below the ' . li_format_money( $min_cents ) . ' minimum payout.' );
    }

    if ( $total_cents <= 0 ) {
        return array( 'success' => false, 'message' => 'Balance ' . li_format_money( $total_cents ) . ' for ' . ( $rescue['name'] ?? 'rescue' ) . ' is not above zero (refunds are carried to the next sales).' );
    }

    // Claim the lines in one step, so a second run at the same moment cannot put them in another batch.
    $ids     = array_values( array_filter( array_map( function( $o ) { return preg_replace( '/[^a-f0-9-]/i', '', (string) ( $o['id'] ?? '' ) ); }, $pending ) ) );
    $claimed = $ids ? li_store_update( 'orders', 'rescue_id=eq.' . urlencode( $rescue_id ) . '&status=eq.pending&id=in.(' . implode( ',', $ids ) . ')', array( 'status' => 'ready_for_payout' ) ) : array();
    if ( empty( $claimed ) ) {
        return array( 'success' => false, 'message' => 'These orders are already in a payout batch.' );
    }
    $release = function() use ( $claimed ) {
        $ids = implode( ',', array_column( $claimed, 'id' ) );
        li_store_update( 'orders', 'id=in.(' . $ids . ')&status=eq.ready_for_payout', array( 'status' => 'pending' ) );
    };
    $total_cents = array_sum( array_map( function( $o ) { return intval( $o['rescue_split_cents'] ?? 0 ); }, $claimed ) );
    if ( $total_cents <= 0 || $total_cents < $min_cents ) {
        $release();
        return array( 'success' => false, 'message' => 'Balance ' . li_format_money( $total_cents ) . ' is below the ' . li_format_money( $min_cents ) . ' minimum payout.' );
    }

    $order_refs   = array_map( function( $o ) { return sanitize_text_field( $o['shopify_order_id'] ?? '' ); }, $claimed );
    $line_amounts = array_map( function( $o ) { return intval( $o['rescue_split_cents'] ?? 0 ); }, $claimed );
    $payout_id    = li_create_payout( $rescue_id, $order_refs, $total_cents, '', 'ready', $line_amounts );
    if ( ! $payout_id ) {
        $release();
        return array( 'success' => false, 'message' => 'Could not create payout batch.' );
    }
    foreach ( $order_refs as $order_ref ) {
        if ( $order_ref ) {
            li_lock_order( $order_ref );
        }
    }

    li_audit_log( 'payout_prepared', array( 'payout_id' => $payout_id, 'rescue_id' => $rescue_id, 'amount_cents' => $total_cents ), 'payout', $payout_id );
    li_notify_admin(
        'Payout batch ready for review',
        'Rescue: ' . ( $rescue['name'] ?? 'Rescue' ) . "\nAmount: " . li_format_money( $total_cents ) . "\nReview: " . admin_url( 'admin.php?page=li-payouts' )
    );

    return array( 'success' => true, 'message' => 'Batch #' . $payout_id . ' prepared for ' . li_format_money( $total_cents ) . '.', 'payout_id' => $payout_id );
}

function li_process_payout_batch( $payout_id ) {
    $payout = li_get_payout( $payout_id );
    if ( ! $payout ) {
        return array( 'success' => false, 'message' => 'Payout batch not found.' );
    }
    if ( ! in_array( $payout['status'], array( 'ready', 'approved' ), true ) ) {
        return array( 'success' => false, 'message' => 'Payout batch is not ready for approval.' );
    }
    $rescue = li_get_rescue_by_id( $payout['rescue_id'] );
    if ( ! $rescue ) {
        return array( 'success' => false, 'message' => 'Rescue not found.' );
    }
    $rescue_id = $rescue['id'] ?? '';
    $stripe_account_id = $rescue['stripe_account_id'] ?? '';
    if ( ! $rescue_id || ! $stripe_account_id ) {
        return array( 'success' => false, 'message' => 'No Stripe account on file for this rescue.' );
    }
    if ( ! empty( $payout['transfer_id'] ) ) {
        return array( 'success' => false, 'message' => 'This batch was already sent to Stripe (' . $payout['transfer_id'] . ').' );
    }
    $total_cents = intval( $payout['amount_cents'] );
    if ( $total_cents <= 0 ) {
        return array( 'success' => false, 'message' => 'Payout batch amount must be above zero.' );
    }
    $sk = li_stripe_sk();
    if ( ! $sk ) {
        return array( 'success' => false, 'message' => 'Stripe secret key is not configured.' );
    }

    // Claim the batch so two clicks or two cron runs cannot both send it.
    $claimed = li_store_update( 'payout_batches', 'id=eq.' . intval( $payout_id ) . '&status=in.(ready,approved)&transfer_id=eq.', array( 'status' => 'sending' ) );
    if ( empty( $claimed ) ) {
        return array( 'success' => false, 'message' => 'This batch is already being sent.' );
    }

    $r = wp_remote_post( 'https://api.stripe.com/v1/transfers', li_stripe_transfer_request( $payout_id, $total_cents, $stripe_account_id, $sk ) );
    $response_code = wp_remote_retrieve_response_code( $r );
    $body          = json_decode( wp_remote_retrieve_body( $r ), true );
    if ( is_wp_error( $r ) || $response_code >= 400 ) {
        // Put the batch back so it can be retried. If Stripe did make the transfer before
        // the error, the retry's idempotency key returns that transfer instead of paying twice.
        li_store_update( 'payout_batches', 'id=eq.' . intval( $payout_id ) . '&status=eq.sending', array( 'status' => $payout['status'] ) );
        li_audit_log( 'payout_stripe_failed', array( 'payout_id' => $payout_id, 'rescue_id' => $rescue_id, 'response' => $body ), 'payout', $payout_id );
        $error = is_wp_error( $r ) ? $r->get_error_message() : ( $body['error']['message'] ?? 'Stripe transfer failed.' );
        li_notify_admin(
            'Payout failed: ' . ( $rescue['name'] ?? 'Rescue' ),
            "Rescue: " . ( $rescue['name'] ?? 'Rescue' ) . "\nAmount: " . li_format_money( $total_cents ) . "\nError: $error\n\nRetrying this batch is safe: Stripe will not pay it twice.\n\nReview payouts: " . admin_url( 'admin.php?page=li-payouts' )
        );
        return array( 'success' => false, 'message' => $error );
    }
    $transfer_id = $body['id'] ?? '';

    $dup = $transfer_id ? li_store_first( 'payout_batches', 'select=id&transfer_id=eq.' . urlencode( $transfer_id ) . '&id=neq.' . intval( $payout_id ) ) : null;
    if ( $dup ) {
        li_flag_fraud( 'duplicate_payout', array( 'rescue_id' => $rescue_id, 'transfer_id' => $transfer_id ) );
        return array( 'success' => false, 'message' => 'This Stripe transfer has already been recorded.' );
    }

    li_store_update( 'payout_batches', 'id=eq.' . intval( $payout_id ), array(
        'status'      => 'approved',
        'transfer_id' => sanitize_text_field( $transfer_id ),
        'notes'       => 'Transfer initiated: ' . sanitize_text_field( $transfer_id ),
    ) );
    li_audit_log( 'payout_status_changed', array( 'payout_id' => $payout_id, 'new_status' => 'approved', 'transfer_id' => $transfer_id ), 'payout', $payout_id );

    $lines = li_store_select( 'payout_lines', 'payout_id=eq.' . intval( $payout_id ) );
    foreach ( $lines as $line ) {
        $order_ref = sanitize_text_field( $line['order_ref'] ?? '' );
        $amount = intval( $line['amount_cents'] ?? 0 );
        if ( ! $order_ref || $amount <= 0 ) {
            continue;
        }
        li_record_ledger( 'payout', array(
            'order_ref'    => $order_ref,
            'product_id'   => '',
            'rescue_id'    => $rescue_id,
            'amount_cents' => -1 * $amount,
            'net_cents'    => -1 * $amount,
            'meta'         => array( 'payout_id' => $payout_id, 'transfer_id' => $transfer_id ),
            'source'       => 'stripe',
        ) );
    }

    li_audit_log( 'payout_transfer_initiated', array( 'payout_id' => $payout_id, 'rescue_id' => $rescue_id, 'transfer_id' => $transfer_id, 'amount_cents' => $total_cents ), 'payout', $payout_id );
    return array( 'success' => true, 'message' => 'Transfer initiated for ' . li_format_money( $total_cents ) . ' to ' . ( $rescue['name'] ?? 'rescue' ) . ( $transfer_id ? ' (' . $transfer_id . ')' : '' ) . '. The batch is approved and will complete when Stripe confirms the transfer.', 'payout_id' => $payout_id );
}



/**
 * Stripe request for one payout batch. The idempotency key is tied to the batch,
 * so a retried request returns the original transfer instead of making a new one.
 */
function li_stripe_transfer_request( $payout_id, $amount_cents, $destination, $secret_key ) {
    return array(
        'timeout' => 30,
        'headers' => array(
            'Authorization'   => 'Bearer ' . $secret_key,
            'Idempotency-Key' => 'larry-payout-' . intval( $payout_id ) . '-' . intval( $amount_cents ),
        ),
        'body'    => array(
            'amount'         => intval( $amount_cents ),
            'currency'       => strtolower( li_currency() ),
            'destination'    => $destination,
            'transfer_group' => 'larry-payout-' . intval( $payout_id ),
            'metadata'       => array( 'larry_payout_id' => intval( $payout_id ) ),
        ),
    );
}

function li_get_split_config( $product_id ) {
    $data = li_db_get( 'split_config?product_id=eq.' . urlencode( $product_id ) . '&select=*' );
    return ! empty( $data ) ? $data[0] : null;
}

function li_get_product_rescue_cents( $product ) {
    return max( 0, intval( $product['rescue_cents'] ?? 0 ) );
}

function li_get_all_wc_products_by_sku() {
    $map = array();
    if ( ! function_exists( 'wc_get_product' ) ) {
        return $map;
    }
    $ids = get_posts(
        array(
            'post_type'      => array( 'product', 'product_variation' ),
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        )
    );
    if ( ! is_array( $ids ) ) {
        return $map;
    }
    foreach ( $ids as $id ) {
        $product = wc_get_product( $id );
        if ( ! $product ) {
            continue;
        }
        $sku = $product->get_sku();
        if ( $sku ) {
            $map[ $sku ] = $product;
        }
    }
    return $map;
}

function li_wc_product_display_name( $product ) {
    if ( ! is_object( $product ) || ! method_exists( $product, 'get_name' ) ) {
        return '';
    }
    if ( $product->is_type( 'variation' ) ) {
        $parent_id = $product->get_parent_id();
        $base      = $product->get_name();
        if ( $parent_id && function_exists( 'wc_get_product' ) ) {
            $parent = wc_get_product( $parent_id );
            if ( $parent ) {
                $base = $parent->get_name();
            }
        }
        $attrs = function_exists( 'wc_get_formatted_variation' ) ? wc_get_formatted_variation( $product, true ) : '';
        return $attrs ? $base . ' - ' . $attrs : $base;
    }
    return $product->get_name();
}

function li_deactivate_orphan_products() {
    if ( ! function_exists( 'wc_get_product' ) ) {
        return 0;
    }
    global $wpdb;
    $wc_skus = $wpdb->get_col(
        "SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
         WHERE pm.meta_key='_sku' AND pm.meta_value <> ''
         AND p.post_status='publish'
         AND p.post_type IN ('product','product_variation')"
    );
    $wc_skus = is_array( $wc_skus ) ? $wc_skus : array();
    $deactivated = 0;
    $limit  = 1000;
    $offset = 0;
    do {
        $products = li_db_get( 'products?select=id,sku,active&order=id.asc&limit=' . $limit . '&offset=' . $offset );
        if ( ! is_array( $products ) || empty( $products ) ) {
            break;
        }
        foreach ( $products as $p ) {
            $sku = $p['sku'] ?? '';
            if ( ! $sku ) {
                continue;
            }
            if ( isset( $p['active'] ) && $p['active'] === false ) {
                continue;
            }
            if ( in_array( $sku, $wc_skus, true ) || ( function_exists( 'li_shopify_skus' ) && in_array( $sku, li_shopify_skus(), true ) ) ) {
                continue;
            }
            $r = li_db_patch( 'products?id=eq.' . urlencode( $p['id'] ), array( 'active' => false ) );
            if ( ! is_wp_error( $r ) && wp_remote_retrieve_response_code( $r ) < 400 ) {
                $deactivated++;
            }
        }
        $offset += $limit;
    } while ( count( $products ) === $limit );
    return $deactivated;
}

function li_get_rescue_cents_default( $product, $default_split = 0 ) {
    $price = max( 1, intval( $product['price_cents'] ?? 0 ) );
    $cost  = max( 0, intval( $product['cost_cents'] ?? 0 ) );
    if ( $default_split <= 0 ) {
        $default_split = floatval( get_option( 'li_default_split', 55 ) );
    }
    if ( $default_split <= 0 ) {
        $default_split = 55;
    }
    $cost_percent  = ( $cost / $price ) * 100;
    $net_percent   = max( 0, 100 - $cost_percent );
    $rescue_gross  = ( $net_percent > 0 ) ? ( $default_split * $net_percent / 100 ) : 0;
    return max( 0, intval( round( $price * $rescue_gross / 100 ) ) );
}

function li_migrate_rescue_cents_from_split_config() {
    if ( get_option( 'li_rescue_cents_migrated' ) ) {
        return;
    }
    $products = li_db_get( 'products?select=*' );
    if ( ! is_array( $products ) ) {
        $products = array();
    }
    foreach ( $products as $p ) {
        $product_id   = $p['id'] ?? '';
        $price_cents  = max( 1, intval( $p['price_cents'] ?? 0 ) );
        $rescue_cents = 0;
        $sp = li_get_split_config( $product_id );
        if ( $sp && floatval( $sp['rescue_percent'] ?? 0 ) > 0 ) {
            $rescue_cents = intval( round( $price_cents * floatval( $sp['rescue_percent'] ) / 100 ) );
        }
        if ( $rescue_cents > 0 && null === ( $p['rescue_cents'] ?? null ) && li_is_woo_sku( $p['sku'] ?? '' ) ) {
            li_set_product_rescue_cents( $p['sku'], $rescue_cents );
        }
    }
    update_option( 'li_rescue_cents_migrated', 1 );
}

function li_ensure_split_config( $product_id, $price_cents = 0, $cost_cents = 0, $force_recalculate = false ) {
    // Routine product synchronization must not overwrite an amount entered by
    // an administrator. Only the explicit Reset all to defaults action forces
    // recalculation of an existing split.
    $existing = li_get_split_config( $product_id );
    if ( $existing && ! $force_recalculate ) {
        return $existing;
    }
    $product = li_get_product_by_id( $product_id );
    $price   = max( 1, intval( $price_cents ? $price_cents : ( $product['price_cents'] ?? 0 ) ) );

    if ( $product && ! empty( $product['sku'] ) && function_exists( 'wc_get_product_id_by_sku' ) ) {
        $wp_id = wc_get_product_id_by_sku( $product['sku'] );
        if ( $wp_id ) {
            $wc_product = wc_get_product( $wp_id );
            if ( $wc_product ) {
                $wc_price = max( 1, intval( round( floatval( $wc_product->get_price() ) * 100 ) ) );
                if ( $wc_price > 0 && abs( $wc_price - $price ) > 0 ) {
                    $price = $wc_price;
                    li_db_patch( 'products?id=eq.' . urlencode( $product_id ), array( 'price_cents' => $price ) );
                }
            }
        }
    }

    if ( $product ) {
        $product['price_cents'] = $price;
    }

    $cost    = max( 0, intval( $cost_cents ? $cost_cents : ( $product['cost_cents'] ?? 0 ) ) );
    $cost_percent = ( $cost / $price ) * 100;
    $net_percent  = max( 0, 100 - $cost_percent );

    $rescue_cents = 0;
    if ( $product && ! $force_recalculate ) {
        $rescue_cents = li_get_product_rescue_cents( $product );
        if ( $rescue_cents <= 0 ) {
            $rescue_cents = li_get_rescue_cents_default( $product );
            if ( $rescue_cents > 0 && li_is_woo_sku( $product['sku'] ?? '' ) ) {
                li_set_product_rescue_cents( $product['sku'], $rescue_cents );
            }
        }
    }

    if ( $rescue_cents > 0 && ! $force_recalculate ) {
        $rescue_percent = ( $rescue_cents / $price ) * 100;
        $larry_percent  = max( 0, $net_percent - $rescue_percent );
    } else {
        $default_split = floatval( get_option( 'li_default_split', 55 ) );
        if ( $default_split <= 0 ) {
            $default_split = 55;
        }
        $rescue_percent = ( $net_percent > 0 ) ? ( $default_split * $net_percent / 100 ) : 0;
        $larry_percent  = max( 0, $net_percent - $rescue_percent );
    }

    $payload = array(
        'rescue_percent' => round( $rescue_percent, 4 ),
        'larry_percent'  => round( $larry_percent, 4 ),
    );

    if ( $existing ) {
        $r = li_db_patch( 'split_config?id=eq.' . urlencode( $existing['id'] ), $payload );
    } else {
        $payload['product_id'] = $product_id;
        $r = li_db_post( 'split_config', $payload );
    }
    if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) >= 400 ) {
        li_log( 'Failed to update split_config for product ' . $product_id . ': ' . wp_remote_retrieve_response_code( $r ) . ' ' . wp_remote_retrieve_body( $r ) );
        return li_get_split_config( $product_id );
    }
    if ( $force_recalculate && $product && li_is_woo_sku( $product['sku'] ?? '' ) ) {
        li_set_product_rescue_cents( $product['sku'], intval( round( $price * $rescue_percent / 100 ) ) );
    }
    $latest = li_get_split_version( $product_id );
    if ( ! $latest || abs( floatval( $latest['rescue_percent'] ) - round( $rescue_percent, 4 ) ) > 0.0001 || abs( floatval( $latest['larry_percent'] ) - round( $larry_percent, 4 ) ) > 0.0001 || abs( floatval( $latest['cost_percent'] ) - round( $cost_percent, 4 ) ) > 0.0001 ) {
        li_create_split_version( $product_id, round( $rescue_percent, 4 ), round( $larry_percent, 4 ), round( $cost_percent, 4 ) );
    }
    return li_get_split_config( $product_id );
}
function li_get_product_by_sku( $sku ) {
    $data = li_db_get( 'products?sku=eq.' . urlencode( $sku ) . '&select=*' );
    return ! empty( $data ) ? $data[0] : null;
}

function li_get_rescue_by_slug( $slug ) {
    $data = li_db_get( 'rescues?slug=eq.' . urlencode( $slug ) . '&select=*' );
    return li_rescue_merge_w9( ! empty( $data ) ? $data[0] : null );
}

function li_get_rescue_by_name( $name ) {
    $name = trim( str_replace( array( '*', '%', ',', '(', ')' ), '', (string) $name ) );
    if ( '' === $name ) {
        return null;
    }
    $data = li_db_get( 'rescues?name=ilike.' . urlencode( $name ) . '&select=*' );
    return ! empty( $data ) ? $data[0] : null;
}

function li_calculate_splits( $product, $sale_cents, $rescue = null, $quantity = 1 ) {
    $price = max( 1, intval( $product['price_cents'] ?? 1 ) );
    $cost  = max( 0, intval( $product['cost_cents'] ?? 0 ) );
    $cost_percent = ( $cost / $price ) * 100;
    $net_percent  = max( 0, 100 - $cost_percent );

    $rescue_cents_per_unit = li_get_product_rescue_cents( $product );
    if ( $rescue_cents_per_unit > 0 ) {
        $quantity       = max( 1, intval( $quantity ) );
        $total_rescue   = min( $sale_cents, $rescue_cents_per_unit * $quantity );
        $total_larry    = max( 0, $sale_cents - $total_rescue );
        $rescue_percent = $sale_cents > 0 ? ( $total_rescue / $sale_cents ) * 100 : 0;
        $larry_percent  = $sale_cents > 0 ? ( $total_larry / $sale_cents ) * 100 : 0;

        return array(
            'rescue_percent' => round( $rescue_percent, 4 ),
            'larry_percent'  => round( $larry_percent, 4 ),
            'rescue_cents'   => $total_rescue,
            'larry_cents'    => $total_larry,
        );
    }

    $sp = li_get_split_config( $product['id'] );
    if ( $sp ) {
        $rp = floatval( $sp['rescue_percent'] ?? 0 );
        $lp = floatval( $sp['larry_percent'] ?? 0 );
    } else {
        $rescue_net = floatval( $rescue['rescue_split_percent'] ?? 0 );
        if ( $rescue_net <= 0 ) {
            $rescue_net = floatval( get_option( 'li_default_split', 55 ) );
        }
        if ( $rescue_net <= 0 ) {
            $rescue_net = 55;
        }
        $rp = ( $net_percent > 0 ) ? ( $rescue_net * $net_percent / 100 ) : 0;
        $lp = max( 0, $net_percent - $rp );
    }

    $rescue_cents = intval( round( $sale_cents * $rp / 100 ) );
    $larry_cents  = intval( round( $sale_cents * $lp / 100 ) );

    return array(
        'rescue_percent' => $rp,
        'larry_percent'  => $lp,
        'rescue_cents'   => max( 0, $rescue_cents ),
        'larry_cents'    => max( 0, $larry_cents ),
    );
}

function li_record_order( $args ) {
    $order_id    = sanitize_text_field( $args['order_id'] ?? '' );
    $rescue_id   = $args['rescue_id'] ?? null;
    $product_id  = $args['product_id'] ?? null;
    $sale_cents  = max( 0, intval( $args['sale_cents'] ?? 0 ) );
    $ordered_at  = sanitize_text_field( $args['ordered_at'] ?? gmdate( 'c' ) );
    $quantity    = max( 1, intval( $args['quantity'] ?? 1 ) );

    if ( ! $order_id || ! $product_id || $sale_cents <= 0 ) {
        li_log( 'Order record skipped: missing order_id, product_id or sale_cents' );
        return false;
    }

    $product = li_get_product_by_id( $product_id );
    if ( ! $product ) {
        li_log( 'Order record skipped: product not found ' . $product_id );
        return false;
    }

    // The orders table enforces a unique constraint on shopify_order_id, so we
    // store a per-line composite to allow one row per product per order.
    $line_order_id = $order_id . '::' . $product_id;

    if ( li_check_duplicate_order( $line_order_id, $product_id ) ) {
        li_flag_fraud( 'duplicate_order', array( 'order_ref' => $line_order_id, 'product_id' => $product_id ) );
        li_log( 'Duplicate order rejected: ' . $line_order_id );
        return true;
    }

    if ( li_is_order_locked( $line_order_id ) ) {
        li_log( 'Order is locked, skipping re-record: ' . $line_order_id );
        return true;
    }

    $existing = li_db_get( 'orders?shopify_order_id=eq.' . urlencode( $line_order_id ) . '&product_id=eq.' . urlencode( $product_id ) . '&select=id' );
    if ( ! empty( $existing ) ) {
        li_lock_order( $line_order_id );
        return true;
    }

    $rescue = null;
    if ( $rescue_id ) {
        $rescue_data = li_db_get( 'rescues?id=eq.' . urlencode( $rescue_id ) . '&select=*' );
        if ( ! empty( $rescue_data ) ) {
            $rescue = $rescue_data[0];
        }
    }

    $split = li_calculate_splits( $product, $sale_cents, $rescue, $quantity );
    $split_version = li_get_split_version( $product_id );

    $payload = array(
        'shopify_order_id'    => $line_order_id,
        'product_id'          => $product_id,
        'quantity'            => $quantity,
        'sale_amount_cents'   => $sale_cents,
        'rescue_split_cents'  => $split['rescue_cents'],
        'larry_split_cents'   => $split['larry_cents'],
        'status'              => 'pending',
        'ordered_at'          => $ordered_at,
    );
    if ( $rescue_id ) {
        $payload['rescue_id'] = $rescue_id;
    }

    $r = li_db_post( 'orders', $payload );
    if ( ! is_wp_error( $r ) && 409 === intval( wp_remote_retrieve_response_code( $r ) ) ) {
        // The database already has this order line (unique shopify_order_id), e.g. two deliveries at once.
        li_lock_order( $line_order_id );
        li_log( 'Order line already in the database, not saved again: ' . $line_order_id );
        return true;
    }
    if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) >= 400 ) {
        li_log( 'Failed to record order ' . $order_id . ' product ' . $product_id . ': ' . wp_remote_retrieve_response_code( $r ) . ' ' . wp_remote_retrieve_body( $r ) );
        return false;
    }
    if ( $rescue_id ) {
        li_award_rescue_points( $rescue_id, intval( floor( $split['rescue_cents'] / 100 ) ) );
    }

    li_record_ledger( 'order', array(
        'order_ref'    => $line_order_id,
        'product_id'   => $product_id,
        'rescue_id'    => $rescue_id,
        'amount_cents' => $sale_cents,
        'net_cents'    => $sale_cents,
        'meta'         => array(
            'quantity'        => $quantity,
            'rescue_cents'    => $split['rescue_cents'],
            'larry_cents'     => $split['larry_cents'],
            'rescue_percent'  => $split['rescue_percent'],
            'larry_percent'   => $split['larry_percent'],
            'split_version'   => $split_version ? $split_version['version'] : 1,
            'split_version_id'=> $split_version ? $split_version['id'] : 0,
        ),
        'source'       => sanitize_key( $args['channel'] ?? 'woocommerce' ),
    ) );
    li_lock_order( $line_order_id );
    li_audit_log( 'order_recorded', array( 'order_ref' => $line_order_id, 'amount_cents' => $sale_cents ), 'order', $line_order_id );
    li_check_payout_threshold( $rescue_id );
    return true;
}

function li_get_product_by_id( $product_id ) {
    $data = li_db_get( 'products?id=eq.' . urlencode( $product_id ) . '&select=*' );
    return ! empty( $data ) ? $data[0] : null;
}



function li_run_auto_payouts() {
    $results = array();
    if ( ! function_exists( 'li_get_rescue_by_email' ) ) {
        return $results;
    }
    $rescues = li_db_get( 'rescues?status=eq.approved&select=*' );
    if ( is_wp_error( $rescues ) || ! is_array( $rescues ) ) {
        return $results;
    }
    foreach ( $rescues as $rescue ) {
        $rescue = li_rescue_merge_w9( li_rescue_merge_points( $rescue ) );
        $result = li_prepare_rescue_payout( $rescue );
        $results[] = $result;
    }
    return $results;
}

add_action( 'li_auto_payouts_cron', 'li_cron_run_auto_payouts' );
function li_cron_run_auto_payouts() {
    li_run_auto_payouts();
}

if ( ! wp_next_scheduled( 'li_auto_payouts_cron' ) ) {
    wp_schedule_event( time(), 'daily', 'li_auto_payouts_cron' );
}
