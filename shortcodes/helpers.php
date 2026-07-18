<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function li_service_key() {
    if ( defined( 'LI_SERVICE_KEY' ) && LI_SERVICE_KEY ) {
        return LI_SERVICE_KEY;
    }
    $key = get_option( 'li_service_key', '' );
    return $key ? $key : LI_DB_KEY;
}

function li_db_get( $endpoint ) {
    $response = wp_remote_get( LI_DB_URL . '/rest/v1/' . $endpoint, array(
        'headers' => array(
            'apikey'        => LI_DB_KEY,
            'Authorization' => 'Bearer ' . LI_DB_KEY,
        ),
    ) );
    if ( is_wp_error( $response ) ) return array();
    return json_decode( wp_remote_retrieve_body( $response ), true ) ?: array();
}

function li_db_patch( $endpoint, $data ) {
    $key = li_service_key();
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
    $key = li_service_key();
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
    return 'var LI_URL="' . esc_js( LI_DB_URL ) . '";var LI_KEY="' . esc_js( LI_DB_KEY ) . '";';
}

function li_money( $cents ) {
    return '$' . number_format( $cents / 100, 2 );
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

function li_create_rescue_wp_user( $name, $email, $send_email = true ) {
    $email = sanitize_email( $email );
    $user  = get_user_by( 'email', $email );
    if ( $user ) {
        if ( ! in_array( 'administrator', $user->roles, true ) ) {
            wp_update_user( array( 'ID' => $user->ID, 'role' => 'subscriber' ) );
        }
        return array( 'user_id' => $user->ID, 'password' => '', 'message' => 'Existing user updated.' );
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
        return array( 'user_id' => 0, 'password' => '', 'message' => $user_id->get_error_message() );
    }
    $user = get_user_by( 'id', $user_id );
    if ( $user && ! in_array( 'administrator', $user->roles, true ) ) {
        wp_update_user( array( 'ID' => $user->ID, 'role' => 'subscriber' ) );
    }
    if ( $send_email && $password ) {
        $login_url = home_url( '/rescue-login/' );
        wp_mail( $email, 'Your Larry Impact rescue account is approved', "Hi {$name},\n\nYour rescue application has been approved. You can log in with the following credentials:\n\nUsername: {$username}\nPassword: {$password}\n\nLogin: {$login_url}\n\nPlease change your password after logging in.\n\nThe Larry Impact Team" );
    }
    return array( 'user_id' => $user_id, 'password' => $password, 'message' => 'User created.' );
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

function li_process_rescue_payout( $rescue ) {
    $rescue_id = $rescue['id'] ?? '';
    $stripe_account_id = $rescue['stripe_account_id'] ?? '';
    if ( ! $rescue_id || ! $stripe_account_id ) {
        return array( 'success' => false, 'message' => 'No Stripe account on file for this rescue.' );
    }
    $pending = li_db_get( 'orders?rescue_id=eq.' . urlencode( $rescue_id ) . '&status=eq.pending&select=*' );
    if ( is_wp_error( $pending ) ) {
        return array( 'success' => false, 'message' => 'Could not load pending orders.' );
    }
    $pending = array_filter( $pending, function( $o ) { return ( $o['status'] ?? '' ) === 'pending'; } );
    if ( empty( $pending ) ) {
        return array( 'success' => true, 'message' => 'No pending orders for ' . ( $rescue['name'] ?? 'rescue' ) . '.' );
    }
    $total_cents = array_sum( array_map( function( $o ) { return intval( $o['rescue_split_cents'] ?? 0 ); }, $pending ) );
    $min_dollars = floatval( get_option( 'li_min_payout', 100 ) );
    $min_cents   = max( 0, intval( $min_dollars * 100 ) );
    if ( $total_cents < $min_cents ) {
        return array( 'success' => false, 'message' => 'Balance $' . ( $total_cents / 100 ) . ' is below the $' . number_format( $min_dollars, 2 ) . ' minimum payout.' );
    }
    $sk = li_stripe_sk();
    if ( ! $sk ) {
        return array( 'success' => false, 'message' => 'Stripe secret key is not configured.' );
    }
    $r = wp_remote_post( 'https://api.stripe.com/v1/transfers', array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $sk,
        ),
        'body'    => array(
            'amount'      => $total_cents,
            'currency'    => 'usd',
            'destination' => $stripe_account_id,
        ),
    ) );
    $response_code = wp_remote_retrieve_response_code( $r );
    $body          = json_decode( wp_remote_retrieve_body( $r ), true );
    if ( is_wp_error( $r ) || $response_code >= 400 ) {
        return array( 'success' => false, 'message' => ( $body['error']['message'] ?? 'Stripe transfer failed.' ) );
    }
    $transfer_id = $body['id'] ?? '';
    foreach ( $pending as $o ) {
        li_db_patch( 'orders?id=eq.' . urlencode( $o['id'] ), array( 'status' => 'paid' ) );
    }
    return array( 'success' => true, 'message' => 'Transferred $' . number_format( $total_cents / 100, 2 ) . ' to ' . ( $rescue['name'] ?? 'rescue' ) . ( $transfer_id ? ' (' . $transfer_id . ')' : '' ) );
}

function li_log( $msg ) {
    error_log( 'Larry Impact: ' . $msg );
}

function li_get_split_config( $product_id ) {
    $data = li_db_get( 'split_config?product_id=eq.' . urlencode( $product_id ) . '&select=*' );
    return ! empty( $data ) ? $data[0] : null;
}

function li_ensure_split_config( $product_id, $price_cents = 0, $cost_cents = 0 ) {
    $price = max( 1, intval( $price_cents ) );
    $cost  = max( 0, intval( $cost_cents ) );
    $cost_percent = ( $cost / $price ) * 100;
    $net_percent  = max( 0, 100 - $cost_percent );

    $rp = 0;
    $lp = 0;

    $product = li_get_product_by_id( $product_id );
    if ( $product && ! empty( $product['sku'] ) ) {
        $wp_id = wc_get_product_id_by_sku( $product['sku'] );
        if ( $wp_id ) {
            $rescue_cents_meta = get_post_meta( $wp_id, '_li_rescue_cents', true );
            $larry_cents_meta  = get_post_meta( $wp_id, '_li_larry_cents', true );
            if ( '' !== $rescue_cents_meta && '' !== $larry_cents_meta ) {
                $rp = ( $price > 0 ) ? round( floatval( $rescue_cents_meta ) / $price * 100, 4 ) : 0;
                $lp = ( $price > 0 ) ? round( floatval( $larry_cents_meta ) / $price * 100, 4 ) : 0;
            }
        }
    }

    if ( $rp <= 0 && $lp <= 0 ) {
        $rescue_net = floatval( get_option( 'li_default_split', 55 ) );
        if ( $rescue_net <= 0 ) {
            $rescue_net = 55;
        }
        $rp = ( $net_percent > 0 ) ? ( $rescue_net * $net_percent / 100 ) : 0;
        $lp = max( 0, $net_percent - $rp );
    }

    $payload = array(
        'rescue_percent' => round( $rp, 4 ),
        'larry_percent'  => round( $lp, 4 ),
    );

    $existing = li_get_split_config( $product_id );
    if ( $existing ) {
        $r = li_db_patch( 'split_config?id=eq.' . urlencode( $existing['id'] ), $payload );
    } else {
        $payload['product_id'] = $product_id;
        $r = li_db_post( 'split_config', $payload );
    }
    if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) >= 400 ) {
        li_log( 'Failed to update split_config for product ' . $product_id . ': ' . wp_remote_retrieve_response_code( $r ) . ' ' . wp_remote_retrieve_body( $r ) );
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
    $data = li_db_get( 'rescues?name=ilike.' . urlencode( '%' . $name . '%' ) . '&select=*' );
    if ( ! empty( $data ) ) {
        return $data[0];
    }
    $data = li_db_get( 'rescues?name=eq.' . urlencode( $name ) . '&select=*' );
    return ! empty( $data ) ? $data[0] : null;
}

function li_calculate_splits( $product, $sale_cents, $rescue = null ) {
    $price = max( 1, intval( $product['price_cents'] ?? 1 ) );
    $cost  = max( 0, intval( $product['cost_cents'] ?? 0 ) );
    $cost_percent = ( $cost / $price ) * 100;
    $net_percent  = max( 0, 100 - $cost_percent );

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

    $existing = li_db_get( 'orders?shopify_order_id=eq.' . urlencode( $line_order_id ) . '&product_id=eq.' . urlencode( $product_id ) . '&select=id' );
    if ( ! empty( $existing ) ) {
        return true;
    }

    $rescue = null;
    if ( $rescue_id ) {
        $rescue_data = li_db_get( 'rescues?id=eq.' . urlencode( $rescue_id ) . '&select=*' );
        if ( ! empty( $rescue_data ) ) {
            $rescue = $rescue_data[0];
        }
    }

    $split = li_calculate_splits( $product, $sale_cents, $rescue );

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
    if ( $rescue_id ) {
        li_award_rescue_points( $rescue_id, intval( floor( $split['rescue_cents'] / 100 ) ) );
    }
    if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) >= 400 ) {
        li_log( 'Failed to record order ' . $order_id . ' product ' . $product_id . ': ' . wp_remote_retrieve_response_code( $r ) . ' ' . wp_remote_retrieve_body( $r ) );
        return false;
    }
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
        $result = li_process_rescue_payout( $rescue );
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