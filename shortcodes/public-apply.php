<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function li_apply_shortcode() {
    $submitted = false;
    $error     = '';
    if ( isset( $_POST['li_apply_nonce'] ) && wp_verify_nonce( $_POST['li_apply_nonce'], 'li_apply' ) ) {
        $name    = sanitize_text_field( $_POST['li_org_name'] ?? '' );
        $email   = sanitize_email( $_POST['li_email'] ?? '' );
        $mission = sanitize_textarea_field( $_POST['li_mission'] ?? '' );
        if ( ! $name || ! $email || ! $mission ) {
            $error = 'Please fill in all required fields.';
        } else {
            $slug           = sanitize_title( $name ) . '-' . substr( md5( $email . time() ), 0, 6 );
            $contact_name   = sanitize_text_field( $_POST['li_contact_name'] ?? '' );
            $heard          = sanitize_text_field( $_POST['li_heard'] ?? '' );
            $about_extras   = array();
            if ( $contact_name ) $about_extras[] = 'Primary contact: ' . $contact_name;
            if ( $heard )       $about_extras[] = 'How did you hear: ' . $heard;
            $about          = $mission . ( $about_extras ? "\n\n" . implode( "\n", $about_extras ) : '' );
            $payload = array(
                'name'    => $name,
                'slug'    => $slug,
                'email'   => $email,
                'phone'   => sanitize_text_field( $_POST['li_phone'] ?? '' ),
                'address' => sanitize_text_field( $_POST['li_address'] ?? '' ),
                'city'    => sanitize_text_field( $_POST['li_city'] ?? '' ),
                'state'   => sanitize_text_field( $_POST['li_state'] ?? '' ),
                'mission' => $mission,
                'about'   => $about,
                'website' => esc_url_raw( $_POST['li_website'] ?? '' ),
                'status'  => 'pending',
            );
            $r = li_db_post( 'rescues', $payload );
            if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) >= 400 ) {
                $error = 'Something went wrong. Please try again or contact us directly.';
            } else {
                $submitted = true;
                wp_mail( get_option( 'li_admin_email', get_option( 'admin_email' ) ), 'New Rescue Application: ' . $name, "Organization: $name\nEmail: $email\nMission: $mission\n\nReview: " . admin_url( 'admin.php?page=li-apps' ) );
                if ( $email && get_option( 'li_auto_confirm', 1 ) ) wp_mail( $email, 'We received your application', "Hi,\n\nThank you for applying to join Larry Impact. We have received your application for $name and will be in touch soon.\n\nThe Larry Impact Team" );
            }
        }
    }
    ob_start();
    echo '<style>
    .li-apply-wrap{font-family:Montserrat,sans-serif;max-width:680px;margin:0 auto;padding:2rem 1rem}
    .li-apply-card{background:#fff;border:1px solid #e8e3db;border-radius:16px;padding:2rem;margin-bottom:1.5rem}
    .li-apply-section{font-size:12px;font-weight:700;color:#c9a84c;letter-spacing:.1em;text-transform:uppercase;margin-bottom:1.25rem;padding-bottom:8px;border-bottom:1px solid #f0ece6}
    .li-apply-row{display:grid;grid-template-columns:1fr 1fr;gap:16px}
    .li-apply-row-full{grid-template-columns:1fr}
    .li-apply-field{margin-bottom:1rem}
    .li-apply-label{display:block;font-size:12px;font-weight:600;color:#6b6560;letter-spacing:.04em;text-transform:uppercase;margin-bottom:6px}
    .li-apply-req{color:#c9a84c;margin-left:2px}
    .li-apply-input,.li-apply-textarea{width:100%;border:1px solid #ddd8d0;border-radius:8px;padding:10px 14px;font-size:14px;font-family:Montserrat,sans-serif;color:#2c2a26;background:#fdfcfa;outline:none;box-sizing:border-box}
    .li-apply-input:focus,.li-apply-textarea:focus{border-color:#c9a84c;background:#fff}
    .li-apply-textarea{resize:vertical;min-height:100px;line-height:1.6}
    .li-apply-err{background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:14px 16px;color:#b91c1c;font-size:13px;margin-bottom:1.5rem}
    .li-apply-btn{width:100%;background:#2c2a26;color:#fff;border:none;border-radius:10px;padding:14px;font-size:15px;font-weight:600;font-family:Montserrat,sans-serif;cursor:pointer}
    .li-apply-btn:hover{background:#3d3a34}
    .li-apply-note{font-size:12px;color:#a89880;text-align:center;margin-top:12px;line-height:1.6}
    .li-apply-success{background:#fff;border:1px solid #e8e3db;border-radius:16px;padding:3rem 2rem;text-align:center}
    @media(max-width:600px){.li-apply-row{grid-template-columns:1fr}}
    </style>';
    echo '<div class="li-apply-wrap">';
    if ( $submitted ) {
        echo '<div class="li-apply-success">';
        echo '<div style="font-size:48px;margin-bottom:1rem;">&#10003;</div>';
        echo '<div style="font-size:22px;font-weight:600;color:#2c2a26;margin-bottom:12px;">Application received</div>';
        echo '<div style="font-size:14px;color:#6b6560;line-height:1.7;max-width:480px;margin:0 auto;">Thank you for applying to join the Larry Impact platform. We have received your application and our team will review it carefully. We will be in touch soon.</div>';
        echo '</div>';
    } else {
        if ( $error ) echo '<div class="li-apply-err">' . esc_html( $error ) . '</div>';
        echo '<form method="post" action="">';
        wp_nonce_field( 'li_apply', 'li_apply_nonce' );
        echo '<div class="li-apply-card"><div class="li-apply-section">Organization info</div>';
        echo '<div class="li-apply-row li-apply-row-full"><div class="li-apply-field"><label class="li-apply-label">Organization name <span class="li-apply-req">*</span></label>';
        echo '<input class="li-apply-input" type="text" name="li_org_name" value="' . esc_attr( $_POST['li_org_name'] ?? '' ) . '" required /></div></div>';
        echo '<div class="li-apply-row"><div class="li-apply-field"><label class="li-apply-label">City <span class="li-apply-req">*</span></label>';
        echo '<input class="li-apply-input" type="text" name="li_city" value="' . esc_attr( $_POST['li_city'] ?? '' ) . '" required /></div>';
        echo '<div class="li-apply-field"><label class="li-apply-label">State</label>';
        echo '<input class="li-apply-input" type="text" name="li_state" value="' . esc_attr( $_POST['li_state'] ?? '' ) . '" /></div></div>';
        echo '<div class="li-apply-row li-apply-row-full"><div class="li-apply-field"><label class="li-apply-label">Street address</label>';
        echo '<input class="li-apply-input" type="text" name="li_address" value="' . esc_attr( $_POST['li_address'] ?? '' ) . '" /></div></div>';
        echo '<div class="li-apply-row li-apply-row-full"><div class="li-apply-field"><label class="li-apply-label">Website</label>';
        echo '<input class="li-apply-input" type="url" name="li_website" placeholder="https://" value="' . esc_attr( $_POST['li_website'] ?? '' ) . '" /></div></div></div>';
        echo '<div class="li-apply-card"><div class="li-apply-section">Primary contact</div>';
        echo '<div class="li-apply-row li-apply-row-full"><div class="li-apply-field"><label class="li-apply-label">Your name <span class="li-apply-req">*</span></label>';
        echo '<input class="li-apply-input" type="text" name="li_contact_name" value="' . esc_attr( $_POST['li_contact_name'] ?? '' ) . '" required /></div></div>';
        echo '<div class="li-apply-row"><div class="li-apply-field"><label class="li-apply-label">Email address <span class="li-apply-req">*</span></label>';
        echo '<input class="li-apply-input" type="email" name="li_email" value="' . esc_attr( $_POST['li_email'] ?? '' ) . '" required /></div>';
        echo '<div class="li-apply-field"><label class="li-apply-label">Phone number</label>';
        echo '<input class="li-apply-input" type="tel" name="li_phone" value="' . esc_attr( $_POST['li_phone'] ?? '' ) . '" /></div></div></div>';
        echo '<div class="li-apply-card"><div class="li-apply-section">Your mission</div>';
        echo '<div class="li-apply-row li-apply-row-full"><div class="li-apply-field"><label class="li-apply-label">Tell us about your organization <span class="li-apply-req">*</span></label>';
        echo '<textarea class="li-apply-textarea" name="li_mission" placeholder="Who you are, what animals you rescue, and what drives your work..." required>' . esc_textarea( $_POST['li_mission'] ?? '' ) . '</textarea></div></div>';
        echo '<div class="li-apply-row li-apply-row-full"><div class="li-apply-field"><label class="li-apply-label">How did you hear about Larry Impact?</label>';
        echo '<input class="li-apply-input" type="text" name="li_heard" value="' . esc_attr( $_POST['li_heard'] ?? '' ) . '" /></div></div></div>';
        echo '<button class="li-apply-btn" type="submit">Submit application</button>';
        echo '<div class="li-apply-note">All applications are reviewed by our team. We will contact you within a few business days.</div>';
        echo '</form>';
    }
    echo '</div>';
    return ob_get_clean();
}
add_shortcode( 'larry_apply', 'li_apply_shortcode' );

// Fallback: if the Apply page is published without the shortcode, render the form at the end of the content.
add_filter( 'the_content', 'li_apply_append_to_page', 20 );
function li_apply_append_to_page( $content ) {
	if ( is_admin() || ! is_page( 'apply' ) || ! in_the_loop() ) {
		return $content;
	}
	if ( strpos( $content, 'li-apply-wrap' ) !== false ) {
		return $content;
	}
	return $content . li_apply_shortcode();
}

