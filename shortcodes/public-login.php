<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function li_login_shortcode() {
    if ( is_user_logged_in() ) {
        wp_redirect( current_user_can( 'manage_options' ) ? admin_url( 'admin.php?page=li-rescues' ) : home_url( '/dashboard/' ) );
        exit;
    }
    $error = '';
    if ( isset( $_POST['li_login_nonce'] ) && wp_verify_nonce( $_POST['li_login_nonce'], 'li_login' ) ) {
        $creds = array(
            'user_login'    => sanitize_text_field( $_POST['li_user'] ?? '' ),
            'user_password' => $_POST['li_pass'] ?? '',
            'remember'      => isset( $_POST['li_remember'] ),
        );
        $user = wp_signon( $creds, false );
        if ( is_wp_error( $user ) ) {
            $error = 'Invalid email or password. Please try again.';
        } else {
            wp_redirect( user_can( $user, 'manage_options' ) ? admin_url( 'admin.php?page=li-rescues' ) : home_url( '/dashboard/' ) );
            exit;
        }
    }
    ob_start();
    $logo_id = get_theme_mod( 'custom_logo' );
    echo '<style>
    .li-login-page{font-family:Montserrat,sans-serif;min-height:80vh;background:#f9f7f4;display:flex;align-items:center;justify-content:center;padding:2rem}
    .li-login-box{background:#fff;border:1px solid #e8e3db;border-radius:16px;padding:2.5rem;width:100%;max-width:420px}
    .li-login-logo{text-align:center;margin-bottom:1.5rem}
    .li-login-eye{font-size:11px;color:#a89880;letter-spacing:.1em;text-transform:uppercase;text-align:center;margin-bottom:6px}
    .li-login-title{font-size:22px;font-weight:600;color:#2c2a26;text-align:center;margin-bottom:6px}
    .li-login-sub{font-size:13px;color:#a89880;text-align:center;margin-bottom:2rem}
    .li-login-err{background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:12px 16px;color:#b91c1c;font-size:13px;margin-bottom:1.5rem;text-align:center}
    .li-login-field{margin-bottom:1rem}
    .li-login-label{display:block;font-size:12px;font-weight:600;color:#6b6560;letter-spacing:.04em;text-transform:uppercase;margin-bottom:6px}
    .li-login-input{width:100%;border:1px solid #ddd8d0;border-radius:8px;padding:10px 14px;font-size:14px;font-family:Montserrat,sans-serif;color:#2c2a26;background:#fdfcfa;outline:none;box-sizing:border-box}
    .li-login-input:focus{border-color:#c9a84c;background:#fff}
    .li-login-remember{display:flex;align-items:center;gap:8px;font-size:13px;color:#6b6560;margin-bottom:1.5rem;cursor:pointer}
    .li-login-btn{width:100%;background:#2c2a26;color:#fff;border:none;border-radius:8px;padding:12px;font-size:14px;font-weight:600;font-family:Montserrat,sans-serif;cursor:pointer}
    .li-login-btn:hover{background:#3d3a34}
    .li-login-divider{border:none;border-top:1px solid #f0ece6;margin:1.5rem 0}
    .li-login-forgot{text-align:center;font-size:13px;color:#a89880}
    .li-login-forgot a{color:#9a6f2a;text-decoration:none}
    </style>';
    echo '<div class="li-login-page"><div class="li-login-box">';
    echo '<div class="li-login-logo">';
    if ( $logo_id ) {
        echo wp_get_attachment_image( $logo_id, array( 120, 60 ) );
    } else {
        echo '<div style="font-size:18px;font-weight:700;color:#2c2a26;">Larry Impact</div>';
    }
    echo '</div>';
    echo '<div class="li-login-eye">Member Portal</div>';
    echo '<div class="li-login-title">Welcome back</div>';
    echo '<div class="li-login-sub">Sign in to your account</div>';
    if ( $error ) echo '<div class="li-login-err">' . esc_html( $error ) . '</div>';
    echo '<form method="post" action="">';
    wp_nonce_field( 'li_login', 'li_login_nonce' );
    echo '<div class="li-login-field"><label class="li-login-label">Email</label>';
    echo '<input class="li-login-input" type="text" name="li_user" placeholder="your@email.com" value="' . esc_attr( $_POST['li_user'] ?? '' ) . '" required /></div>';
    echo '<div class="li-login-field"><label class="li-login-label">Password</label>';
    echo '<input class="li-login-input" type="password" name="li_pass" placeholder="Your password" required /></div>';
    echo '<label class="li-login-remember"><input type="checkbox" name="li_remember" value="1" /> Keep me signed in</label>';
    echo '<button class="li-login-btn" type="submit">Sign In</button>';
    echo '</form>';
    echo '<hr class="li-login-divider">';
    echo '<div class="li-login-forgot"><a href="' . wp_lostpassword_url( home_url( '/rescue-login/' ) ) . '">Forgot your password?</a></div>';
    echo '</div></div>';
    return ob_get_clean();
}
add_shortcode( 'larry_login', 'li_login_shortcode' );

function li_block_admin_for_non_admins() {
    if ( is_admin() && is_user_logged_in() && ! current_user_can( 'manage_options' ) && ! wp_doing_ajax() ) {
        wp_redirect( home_url( '/dashboard/' ) );
        exit;
    }
}
add_action( 'admin_init', 'li_block_admin_for_non_admins' );

