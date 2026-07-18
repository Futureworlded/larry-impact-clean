<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function li_rescue_auth() {
    if ( ! is_user_logged_in() ) {
        wp_redirect( home_url( '/rescue-login/' ) );
        exit;
    }
}

function li_rescue_nav( $active ) {
    $logout = wp_logout_url( home_url( '/rescue-login/' ) );
    $links  = array(
        'overview' => array( 'Overview',      home_url( '/dashboard/' ) ),
        'profile'  => array( 'My profile',    home_url( '/dashboard/my-profile/' ) ),
        'earnings' => array( 'My earnings',   home_url( '/dashboard/my-earnings/' ) ),
        'account'  => array( 'Setup', home_url( '/dashboard/my-account/' ) ),
        'settings' => array( 'Settings',      home_url( '/dashboard/my-settings/' ) ),
    );
    echo '<nav style="display:flex;gap:4px;margin-bottom:2rem;border-bottom:1px solid #e8e3db;flex-wrap:wrap;">';
    foreach ( $links as $key => $link ) {
        $style = $key === $active ? 'color:#2c2a26;border-bottom:2px solid #c9a84c;' : 'color:#a89880;border-bottom:2px solid transparent;';
        echo '<a href="' . esc_url( $link[1] ) . '" style="padding:10px 18px;font-size:13px;font-weight:600;text-decoration:none;margin-bottom:-1px;' . $style . '">' . esc_html( $link[0] ) . '</a>';
    }
    echo '<a href="' . esc_url( $logout ) . '" style="margin-left:auto;padding:10px 18px;font-size:13px;font-weight:600;color:#b91c1c;text-decoration:none;border-bottom:2px solid transparent;">Log out</a>';
    echo '</nav>';
}

function li_rescue_styles() {
    static $done = false;
    if ( $done ) return;
    $done = true;
    echo '<style>
    .li-rd-wrap{font-family:Montserrat,sans-serif;max-width:800px;margin:0 auto;padding:2rem 1rem}
    .li-rd-eye{font-size:11px;color:#a89880;letter-spacing:.1em;text-transform:uppercase;margin-bottom:4px}
    .li-rd-title{font-size:22px;font-weight:600;color:#2c2a26;margin-bottom:2rem}
    .li-rd-card{background:#fff;border:1px solid #e8e3db;border-radius:12px;padding:1.5rem;margin-bottom:16px}
    .li-rd-card-title{font-size:12px;font-weight:700;color:#c9a84c;letter-spacing:.1em;text-transform:uppercase;margin-bottom:1rem;padding-bottom:8px;border-bottom:1px solid #f0ece6}
    .li-rd-stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:1.5rem}
    .li-rd-stat{background:#f9f7f4;border:1px solid #e8e3db;border-radius:10px;padding:1rem;text-align:center}
    .li-rd-stat-val{font-size:22px;font-weight:600;color:#2c2a26}
    .li-rd-row{display:grid;grid-template-columns:1fr 1fr;gap:16px}
    .li-rd-row-full{grid-template-columns:1fr}
    .li-rd-field{margin-bottom:1rem}
    .li-rd-label{display:block;font-size:12px;font-weight:600;color:#6b6560;letter-spacing:.04em;text-transform:uppercase;margin-bottom:6px}
    .li-rd-input,.li-rd-textarea{width:100%;border:1px solid #ddd8d0;border-radius:8px;padding:10px 12px;font-size:13px;font-family:Montserrat,sans-serif;color:#2c2a26;background:#fdfcfa;outline:none;box-sizing:border-box}
    .li-rd-input:focus,.li-rd-textarea:focus{border-color:#c9a84c;background:#fff}
    .li-rd-textarea{resize:vertical;min-height:90px;line-height:1.5}
    .li-rd-btn{background:#2c2a26;color:#fff;border:none;border-radius:8px;padding:11px 28px;font-size:14px;font-weight:600;font-family:Montserrat,sans-serif;cursor:pointer}
    .li-rd-btn:hover{background:#3d3a34}
    .li-rd-table-wrap{background:#fff;border:1px solid #e8e3db;border-radius:12px;overflow-x:auto}
    .li-rd-table{width:100%;border-collapse:collapse;font-size:13px}
    .li-rd-table thead tr{background:#f9f7f4}
    .li-rd-table th{padding:11px 14px;text-align:left;font-size:11px;color:#a89880;letter-spacing:.06em;text-transform:uppercase;font-weight:600;white-space:nowrap}
    .li-rd-table td{padding:12px 14px;border-top:1px solid #f0ece6;color:#3a3530}
    .li-rd-ok{background:#eaf3de;border:1px solid #b8d898;border-radius:8px;padding:12px 16px;color:#3a7a4a;font-size:13px;margin-bottom:16px}
    .li-rd-err{background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:12px 16px;color:#b91c1c;font-size:13px;margin-bottom:16px}
    .li-rd-empty{text-align:center;padding:40px 20px;color:#a89880;font-size:14px}
    .li-rd-media-thumb{max-width:120px;max-height:80px;border-radius:8px;border:1px solid #e8e3db;object-fit:contain;background:#f9f7f4}
    .li-rd-media-empty{display:inline-block;background:#f9f7f4;border:1px solid #e8e3db;border-radius:8px;padding:14px 20px;font-size:12px;color:#a89880}
    .li-rd-upload-btn{background:#f9f7f4;border:1px solid #ddd8d0;border-radius:6px;padding:7px 14px;font-size:12px;font-weight:600;color:#4a4540;cursor:pointer;font-family:Montserrat,sans-serif;margin-top:10px;display:inline-block}
    .li-rd-upload-btn:hover{background:#f0ece6}
    .li-rd-stripe-btn{display:inline-flex;align-items:center;gap:8px;background:#635bff;color:#fff;border:none;border-radius:8px;padding:12px 24px;font-size:14px;font-weight:600;font-family:Montserrat,sans-serif;cursor:pointer;text-decoration:none}
    .li-rd-stripe-btn:hover{background:#4f49d1;color:#fff}
    .li-rd-connected{display:inline-flex;align-items:center;gap:8px;background:#eaf3de;border:1px solid #b8d898;border-radius:8px;padding:10px 16px;font-size:13px;font-weight:600;color:#3a7a4a}
    @media(max-width:600px){.li-rd-row{grid-template-columns:1fr}}

    .li-rd-progress-wrap{background:#ede8e0;border-radius:10px;height:12px;overflow:hidden;margin:12px 0}
    .li-rd-progress-bar{background:#c9a84c;height:100%;border-radius:10px;transition:width .3s}
    .li-rd-missing-list{margin:0;padding-left:18px;font-size:13px;color:#6b6560}
    .li-rd-missing-list li{margin-bottom:6px}
    .li-rd-completion-val{font-size:28px;font-weight:700;color:#2c2a26}
    .li-rd-completion-card{display:flex;align-items:center;gap:16px;margin-bottom:12px}
    </style>';
}

// [larry_rescue_overview]

function li_rescue_profile_completion( $rescue ) {
    if ( ! $rescue ) return '';
    $fields = array(
        'name'          => array( 'label' => 'Organization name', 'url' => home_url( '/dashboard/my-profile/' ) ),
        'email'         => array( 'label' => 'Email address', 'url' => home_url( '/dashboard/my-settings/' ) ),
        'phone'         => array( 'label' => 'Phone number', 'url' => home_url( '/dashboard/my-profile/' ) ),
        'website'       => array( 'label' => 'Website', 'url' => home_url( '/dashboard/my-profile/' ) ),
        'address'       => array( 'label' => 'Street address', 'url' => home_url( '/dashboard/my-profile/' ) ),
        'city'          => array( 'label' => 'City', 'url' => home_url( '/dashboard/my-profile/' ) ),
        'state'         => array( 'label' => 'State', 'url' => home_url( '/dashboard/my-profile/' ) ),
        'mission'       => array( 'label' => 'Mission statement', 'url' => home_url( '/dashboard/my-profile/' ) ),
        'about'         => array( 'label' => 'About section', 'url' => home_url( '/dashboard/my-profile/' ) ),
        'logo_url'      => array( 'label' => 'Logo', 'url' => home_url( '/dashboard/my-profile/' ) ),
        'hero_photo_url'=> array( 'label' => 'Hero photo', 'url' => home_url( '/dashboard/my-profile/' ) ),
        'video_url'     => array( 'label' => 'Video', 'url' => home_url( '/dashboard/my-profile/' ) ),
        'ein'           => array( 'label' => 'EIN / Tax ID', 'url' => home_url( '/dashboard/my-account/' ) ),
        'w9_url'        => array( 'label' => 'W-9 Tax form', 'url' => home_url( '/dashboard/my-account/' ) ),
    );
    $filled = 0;
    $missing = array();
    $total = count( $fields );
    foreach ( $fields as $key => $info ) {
        $val = $rescue[ $key ] ?? '';
        if ( is_string( $val ) ? trim( $val ) !== '' : ! empty( $val ) ) {
            $filled++;
        } else {
            $missing[] = $info;
        }
    }
    $percent = $total > 0 ? round( ( $filled / $total ) * 100 ) : 0;
    $out = '<div class="li-rd-card"><div class="li-rd-card-title">Profile completion</div>';
    $out .= '<div class="li-rd-completion-card">';
    $out .= '<div class="li-rd-completion-val">' . intval( $percent ) . '%</div>';
    $out .= '<div style="flex:1;"><div class="li-rd-progress-wrap"><div class="li-rd-progress-bar" style="width:' . intval( $percent ) . '%;"></div></div></div>';
    $out .= '</div>';
    if ( $missing ) {
        $out .= '<p style="font-size:13px;color:#6b6560;margin:0 0 10px;">Still missing:</p>';
        $out .= '<ul class="li-rd-missing-list">';
        foreach ( $missing as $m ) {
            $out .= '<li><a href="' . esc_url( $m['url'] ) . '" style="color:#9a6f2a;text-decoration:none;">' . esc_html( $m['label'] ) . '</a></li>';
        }
        $out .= '</ul>';
    } else {
        $out .= '<p style="font-size:13px;color:#3a7a4a;margin:0;">Your profile is complete.</p>';
    }
    $out .= '</div>';
    return $out;
}

function li_rescue_overview_shortcode() {
    li_rescue_auth();
    $user   = wp_get_current_user();
    $rescue = li_get_rescue_by_email( $user->user_email );
    $ajax_js = esc_js( admin_url( 'admin-ajax.php' ) );
    $nonce   = esc_js( wp_create_nonce( 'li_rescue_get_orders' ) );
    ob_start();
    li_rescue_styles();
    echo '<div class="li-rd-wrap">';
    echo '<div class="li-rd-eye">Larry Impact</div><div class="li-rd-title">Welcome back</div>';
    li_rescue_nav( 'overview' );
        echo li_rescue_profile_completion( $rescue );
    if ( $rescue ) {
        $rid = esc_js( $rescue['id'] );
        echo '<div class="li-rd-stat-grid" id="li-rd-stats"><div style="text-align:center;padding:20px;color:#a89880;">Loading your stats...</div></div>';
        echo '<div class="li-rd-card"><div class="li-rd-card-title">Ownership points</div>';
        echo '<div style="font-size:28px;font-weight:700;color:#c9a84c;">' . number_format( intval( $rescue['points'] ?? 0 ) ) . '</div>';
        echo '<p style="font-size:12px;color:#6b6560;margin-top:4px;">Points earned from rescue-powered purchases.</p></div>';
        echo '<div class="li-rd-card"><div class="li-rd-card-title">Recent earnings</div>';
        echo '<div class="li-rd-table-wrap"><table class="li-rd-table"><thead><tr><th>Date</th><th>Product</th><th>Your cut</th><th>Status</th></tr></thead>';
        echo '<tbody id="li-rd-recent"><tr><td colspan="4" class="li-rd-empty">Loading...</td></tr></tbody></table></div></div>';
        echo '<script>';
        echo 'var LI_RESCUE_ORDERS_AJAX="' . $ajax_js . '";var LI_RESCUE_ORDERS_NONCE="' . $nonce . '";var LI_RESCUE_ID="' . $rid . '";';
        echo 'var fd=new FormData();fd.append("action","li_rescue_get_orders");fd.append("nonce",LI_RESCUE_ORDERS_NONCE);fd.append("rescue_id",LI_RESCUE_ID);fetch(LI_RESCUE_ORDERS_AJAX,{method:"POST",body:fd}).then(function(r){return r.json();}).then(function(res){var orders=Array.isArray(res)?res:[];
            var t=orders.reduce(function(s,o){return s+o.rescue_split_cents;},0);
            var p=orders.filter(function(o){return o.status==="paid";}).reduce(function(s,o){return s+o.rescue_split_cents;},0);
            document.getElementById("li-rd-stats").innerHTML=
                "<div class=li-rd-stat><div class=li-rd-stat-val style=color:#3a7a4a>$"+(t/100).toFixed(2)+"</div><div style=font-size:11px;color:#a89880;margin-top:4px;text-transform:uppercase;letter-spacing:.05em>Total earned</div></div>"+
                "<div class=li-rd-stat><div class=li-rd-stat-val style=color:#9a6f2a>$"+(p/100).toFixed(2)+"</div><div style=font-size:11px;color:#a89880;margin-top:4px;text-transform:uppercase;letter-spacing:.05em>Paid out</div></div>"+
                "<div class=li-rd-stat><div class=li-rd-stat-val>$"+((t-p)/100).toFixed(2)+"</div><div style=font-size:11px;color:#a89880;margin-top:4px;text-transform:uppercase;letter-spacing:.05em>Pending</div></div>"+
                "<div class=li-rd-stat><div class=li-rd-stat-val>"+orders.length+"</div><div style=font-size:11px;color:#a89880;margin-top:4px;text-transform:uppercase;letter-spacing:.05em>Orders</div></div>";
            var tbody=document.getElementById("li-rd-recent");
            if(!orders||orders.length===0){tbody.innerHTML="<tr><td colspan=4 class=li-rd-empty>No orders yet.</td></tr>";return;}
            tbody.innerHTML=orders.slice(0,5).map(function(o){
                var d=new Date(o.ordered_at).toLocaleDateString("en-US",{month:"short",day:"numeric",year:"numeric"});
                var b=o.status==="paid"?"<span style=display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;background:#eaf3de;color:#3a7a4a;border:1px solid #b8d898>Paid</span>":"<span style=display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;background:#fef3e2;color:#9a6f2a;border:1px solid #f0d9a8>Pending</span>";
                return "<tr><td>"+d+"</td><td>"+(o.products?o.products.name:"--")+"</td><td style=color:#3a7a4a;font-weight:600>$"+(o.rescue_split_cents/100).toFixed(2)+"</td><td>"+b+"</td></tr>";
            }).join("");
        });
        </script>';
    } else {
        echo '<div class="li-rd-card"><p style="font-size:14px;color:#6b6560;">Your profile is being set up. Check back shortly.</p></div>';
    }


    echo '</div>';
    return ob_get_clean();
}
add_shortcode( 'larry_rescue_overview', 'li_rescue_overview_shortcode' );

// [larry_rescue_profile]
function li_rescue_profile_shortcode() {
    li_rescue_auth();
    $user   = wp_get_current_user();
    $rescue = li_get_rescue_by_email( $user->user_email );
    $msg    = '';
    if ( $rescue && isset( $_POST['li_rd_profile_nonce'] ) && wp_verify_nonce( $_POST['li_rd_profile_nonce'], 'li_rd_profile' ) ) {
        $data = array(
            'mission'           => sanitize_textarea_field( $_POST['li_mission'] ?? '' ),
            'about'             => sanitize_textarea_field( $_POST['li_about'] ?? '' ),
            'website'           => esc_url_raw( $_POST['li_website'] ?? '' ),
            'donate_url'        => esc_url_raw( $_POST['li_donate_url'] ?? '' ),
            'shopify_coffee_url'=> esc_url_raw( $_POST['li_shopify_coffee_url'] ?? '' ),
            'phone'             => sanitize_text_field( $_POST['li_phone'] ?? '' ),
            'address'           => sanitize_text_field( $_POST['li_address'] ?? '' ),
            'city'              => sanitize_text_field( $_POST['li_city'] ?? '' ),
            'state'             => sanitize_text_field( $_POST['li_state'] ?? '' ),
            'animals_rescued'   => intval( $_POST['li_animals'] ?? 0 ),
            'video_url'         => esc_url_raw( $_POST['li_video'] ?? '' ),
        );
        $r = li_db_patch( 'rescues?id=eq.' . urlencode( $rescue['id'] ), $data );
        $msg    = is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) >= 400 ? '<div class="li-rd-err">Could not save. Please try again.</div>' : '<div class="li-rd-ok">Profile updated.</div>';
        $rescue = li_get_rescue_by_email( $user->user_email );
    }
    $nonce   = wp_create_nonce( 'li_media_upload' );
    $ajax_js = esc_js( admin_url( 'admin-ajax.php' ) );
    $rid_js  = $rescue ? esc_js( $rescue['id'] ) : '';
    ob_start();
    li_rescue_styles();
    echo '<div class="li-rd-wrap">';
    echo '<div class="li-rd-eye">Larry Impact</div><div class="li-rd-title">My profile</div>';
    li_rescue_nav( 'profile' );
    echo $msg;
    if ( ! $rescue ) { echo '<div class="li-rd-card"><p style="font-size:14px;color:#6b6560;">Profile not found.</p></div></div>'; return ob_get_clean(); }
    echo '<form method="post" action="">';
    wp_nonce_field( 'li_rd_profile', 'li_rd_profile_nonce' );
    echo '<div class="li-rd-card"><div class="li-rd-card-title">Your story</div>';
    echo '<div class="li-rd-row li-rd-row-full"><div class="li-rd-field"><label class="li-rd-label">Mission statement</label>';
    echo '<textarea class="li-rd-textarea" name="li_mission">' . esc_textarea( $rescue['mission'] ?? '' ) . '</textarea></div></div>';
    echo '<div class="li-rd-row li-rd-row-full"><div class="li-rd-field"><label class="li-rd-label">Full story</label>';
    echo '<textarea class="li-rd-textarea" style="min-height:140px" name="li_about">' . esc_textarea( $rescue['about'] ?? '' ) . '</textarea></div></div></div>';
    echo '<div class="li-rd-card"><div class="li-rd-card-title">Details</div>';
    echo '<div class="li-rd-row"><div class="li-rd-field"><label class="li-rd-label">Animals rescued</label>';
    echo '<input class="li-rd-input" type="number" name="li_animals" value="' . esc_attr( $rescue['animals_rescued'] ?? 0 ) . '" /></div>';
    echo '<div class="li-rd-field"><label class="li-rd-label">Website</label>';
    echo '<input class="li-rd-input" type="url" name="li_website" value="' . esc_attr( $rescue['website'] ?? '' ) . '" /></div></div>';
    echo '<div class="li-rd-row"><div class="li-rd-field"><label class="li-rd-label">Donation URL</label>';
    echo '<input class="li-rd-input" type="url" name="li_donate_url" placeholder="https://..." value="' . esc_attr( $rescue['donate_url'] ?? '' ) . '" /></div>';
    echo '<div class="li-rd-field"><label class="li-rd-label">Shopify coffee page URL</label>';
    echo '<input class="li-rd-input" type="url" name="li_shopify_coffee_url" placeholder="https://..." value="' . esc_attr( $rescue['shopify_coffee_url'] ?? '' ) . '" /></div></div>';
    echo '<div class="li-rd-row li-rd-row-full"><div class="li-rd-field"><label class="li-rd-label">Video URL</label>';
    echo '<input class="li-rd-input" type="url" name="li_video" placeholder="https://youtube.com/watch?v=..." value="' . esc_attr( $rescue['video_url'] ?? '' ) . '" /></div></div></div>';
    echo '<div class="li-rd-card"><div class="li-rd-card-title">Contact</div>';
    echo '<div class="li-rd-row"><div class="li-rd-field"><label class="li-rd-label">Phone</label>';
    echo '<input class="li-rd-input" type="text" name="li_phone" value="' . esc_attr( $rescue['phone'] ?? '' ) . '" /></div>';
    echo '<div class="li-rd-field"><label class="li-rd-label">Address</label>';
    echo '<input class="li-rd-input" type="text" name="li_address" placeholder="Street address" value="' . esc_attr( $rescue['address'] ?? '' ) . '" /></div></div>';
    echo '<div class="li-rd-row"><div class="li-rd-field"><label class="li-rd-label">City</label>';
    echo '<input class="li-rd-input" type="text" name="li_city" value="' . esc_attr( $rescue['city'] ?? '' ) . '" /></div>';
    echo '<div class="li-rd-field"><label class="li-rd-label">State</label>';
    echo '<input class="li-rd-input" type="text" name="li_state" value="' . esc_attr( $rescue['state'] ?? '' ) . '" /></div></div></div>';
    echo '<div class="li-rd-card"><div class="li-rd-card-title">Logo</div>';
    if ( ! empty( $rescue['logo_url'] ) ) { echo '<img src="' . esc_url( $rescue['logo_url'] ) . '" class="li-rd-media-thumb" id="li-rd-logo-img" />'; }
    else { echo '<div class="li-rd-media-empty" id="li-rd-logo-img">No logo uploaded yet</div>'; }
    echo '<br><input type="file" id="li-rd-logo-file" accept="image/*" style="display:none" onchange="liRdUpload(this,\'logo_url\',\'' . $rid_js . '\')" />';
    echo '<button type="button" class="li-rd-upload-btn" onclick="document.getElementById(\'li-rd-logo-file\').click()">Upload logo</button>';
    echo '<div id="li-rd-logo-status" style="font-size:12px;color:#9a6f2a;margin-top:6px;"></div></div>';
    echo '<div class="li-rd-card"><div class="li-rd-card-title">Hero photo</div>';
    if ( ! empty( $rescue['hero_photo_url'] ) ) { echo '<img src="' . esc_url( $rescue['hero_photo_url'] ) . '" class="li-rd-media-thumb" style="max-width:200px;max-height:100px;" id="li-rd-hero-img" />'; }
    else { echo '<div class="li-rd-media-empty" id="li-rd-hero-img">No hero photo uploaded yet</div>'; }
    echo '<br><input type="file" id="li-rd-hero-file" accept="image/*" style="display:none" onchange="liRdUpload(this,\'hero_photo_url\',\'' . $rid_js . '\')" />';
    echo '<button type="button" class="li-rd-upload-btn" onclick="document.getElementById(\'li-rd-hero-file\').click()">Upload hero photo</button>';
    echo '<div id="li-rd-hero-status" style="font-size:12px;color:#9a6f2a;margin-top:6px;"></div></div>';
    echo '<button class="li-rd-btn" type="submit">Save profile</button>';
    echo '</form>';
    echo '<script>';
    echo 'var LI_NONCE="' . $nonce . '";var LI_AJAX="' . $ajax_js . '";';
    echo 'async function liRdUpload(input,field,rid){
        if(!input.files||!input.files[0])return;
        var sid=field==="logo_url"?"li-rd-logo-status":"li-rd-hero-status";
        var iid=field==="logo_url"?"li-rd-logo-img":"li-rd-hero-img";
        document.getElementById(sid).textContent="Uploading...";
        var fd=new FormData();fd.append("action","li_media_upload");fd.append("li_file",input.files[0]);fd.append("li_nonce",LI_NONCE);fd.append("rescue_id",rid);
        try{
            var res=await fetch(LI_AJAX,{method:"POST",body:fd});var data=await res.json();
            if(data.success&&data.data.url){
                var el=document.getElementById(iid);
                if(el.tagName==="IMG"){el.src=data.data.url;}else{var img=document.createElement("img");img.src=data.data.url;img.className="li-rd-media-thumb";img.id=iid;el.parentNode.replaceChild(img,el);}
                var pfd=new FormData();pfd.append("action","li_update_rescue_field");pfd.append("li_nonce",LI_NONCE);pfd.append("rescue_id",rid);pfd.append("field",field);pfd.append("value",data.data.url);
                var pres=await fetch(LI_AJAX,{method:"POST",body:pfd});var pdata=await pres.json();
                if(!pdata.success)throw new Error(pdata.data&&pdata.data.message?pdata.data.message:"Save failed");
                document.getElementById(sid).textContent="Uploaded.";setTimeout(function(){document.getElementById(sid).textContent="";},2000);
            }
        }catch(e){document.getElementById(sid).textContent="Upload failed.";}
    }';
    echo '</script>';
    echo '</div>';
    return ob_get_clean();
}
add_shortcode( 'larry_rescue_profile', 'li_rescue_profile_shortcode' );

// [larry_rescue_splits]
function li_rescue_splits_shortcode() {
    li_rescue_auth();
    $user   = wp_get_current_user();
    $rescue = li_get_rescue_by_email( $user->user_email );
    $ajax_js = esc_js( admin_url( 'admin-ajax.php' ) );
    $nonce   = esc_js( wp_create_nonce( 'li_rescue_get_orders' ) );
    ob_start();
    li_rescue_styles();
    echo '<div class="li-rd-wrap">';
    echo '<div class="li-rd-eye">Larry Impact</div><div class="li-rd-title">My earnings</div>';
    li_rescue_nav( 'earnings' );
    if ( $rescue ) {
        $rid = esc_js( $rescue['id'] );
        echo '<div class="li-rd-stat-grid" id="li-rd-earn-stats"><div style="text-align:center;padding:20px;color:#a89880;">Loading...</div></div>';
        echo '<div class="li-rd-table-wrap"><table class="li-rd-table"><thead><tr><th>Date</th><th>Order</th><th>Product</th><th>Sale</th><th>Your cut</th><th>Status</th></tr></thead>';
        echo '<tbody id="li-rd-earn-tbody"><tr><td colspan="6" class="li-rd-empty">Loading orders...</td></tr></tbody></table></div>';
        echo '<script>';
        echo 'var LI_RESCUE_ORDERS_AJAX="' . $ajax_js . '";var LI_RESCUE_ORDERS_NONCE="' . $nonce . '";var LI_RESCUE_ID="' . $rid . '";';
        echo 'var fd=new FormData();fd.append("action","li_rescue_get_orders");fd.append("nonce",LI_RESCUE_ORDERS_NONCE);fd.append("rescue_id",LI_RESCUE_ID);fetch(LI_RESCUE_ORDERS_AJAX,{method:"POST",body:fd}).then(function(r){return r.json();}).then(function(res){var orders=Array.isArray(res)?res:[];
            var t=orders.reduce(function(s,o){return s+o.rescue_split_cents;},0);
            var p=orders.filter(function(o){return o.status==="paid";}).reduce(function(s,o){return s+o.rescue_split_cents;},0);
            document.getElementById("li-rd-earn-stats").innerHTML=
                "<div class=li-rd-stat><div class=li-rd-stat-val style=color:#3a7a4a>$"+(t/100).toFixed(2)+"</div><div style=font-size:11px;color:#a89880;margin-top:4px;text-transform:uppercase;letter-spacing:.05em>Total earned</div></div>"+
                "<div class=li-rd-stat><div class=li-rd-stat-val style=color:#9a6f2a>$"+(p/100).toFixed(2)+"</div><div style=font-size:11px;color:#a89880;margin-top:4px;text-transform:uppercase;letter-spacing:.05em>Paid out</div></div>"+
                "<div class=li-rd-stat><div class=li-rd-stat-val>$"+((t-p)/100).toFixed(2)+"</div><div style=font-size:11px;color:#a89880;margin-top:4px;text-transform:uppercase;letter-spacing:.05em>Pending</div></div>"+
                "<div class=li-rd-stat><div class=li-rd-stat-val>"+orders.length+"</div><div style=font-size:11px;color:#a89880;margin-top:4px;text-transform:uppercase;letter-spacing:.05em>Total orders</div></div>";
            var tbody=document.getElementById("li-rd-earn-tbody");
            if(!orders||orders.length===0){tbody.innerHTML="<tr><td colspan=6 class=li-rd-empty>No orders yet.</td></tr>";return;}
            tbody.innerHTML=orders.map(function(o){
                var d=new Date(o.ordered_at).toLocaleDateString("en-US",{month:"short",day:"numeric",year:"numeric"});
                var b=o.status==="paid"?"<span style=display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;background:#eaf3de;color:#3a7a4a;border:1px solid #b8d898>Paid</span>":"<span style=display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;background:#fef3e2;color:#9a6f2a;border:1px solid #f0d9a8>Pending</span>";
                var oid=(o.shopify_order_id||"").split("::")[0];
                return "<tr><td>"+d+"</td><td style=font-size:11px;color:#a89880>#"+oid+"</td><td>"+(o.products?o.products.name:"--")+"</td><td>$"+(o.sale_amount_cents/100).toFixed(2)+"</td><td style=color:#3a7a4a;font-weight:600>$"+(o.rescue_split_cents/100).toFixed(2)+"</td><td>"+b+"</td></tr>";
            }).join("");
        });
        </script>';
    } else {
        echo '<div class="li-rd-card"><p style="font-size:14px;color:#6b6560;">No earnings data found.</p></div>';
    }
    echo '</div>';
    return ob_get_clean();
}
add_shortcode( 'larry_rescue_splits', 'li_rescue_splits_shortcode' );

// [larry_rescue_account]
function li_rescue_account_shortcode() {
    li_rescue_auth();
    $user   = wp_get_current_user();
    $rescue = li_get_rescue_by_email( $user->user_email );
    $msg    = '';
    if ( isset( $_GET['stripe_connect'] ) && $_GET['stripe_connect'] === 'success' ) {
        $msg = '<div class="li-rd-ok">Your bank account has been connected successfully.</div>';
    }
    if ( $rescue && isset( $_POST['li_rd_account_nonce'] ) && wp_verify_nonce( $_POST['li_rd_account_nonce'], 'li_rd_account' ) ) {
        li_db_patch( 'rescues?id=eq.' . urlencode( $rescue['id'] ), array( 'ein' => sanitize_text_field( $_POST['li_ein'] ?? '' ) ) );
        $msg    = '<div class="li-rd-ok">Tax information saved.</div>';
        $rescue = li_get_rescue_by_email( $user->user_email );
    }
    $stripe_id       = $rescue['stripe_account_id'] ?? '';
    $stripe_verified = false;
    $connect_url     = '';
    $stripe_sk = li_stripe_sk();

    // Helper: invalidate a stored Stripe account ID so we create a fresh one.
    $li_clear_stripe_account = function( $rid ) use ( &$stripe_id, &$stripe_verified ) {
        li_db_patch( 'rescues?id=eq.' . urlencode( $rid ), array( 'stripe_account_id' => null ) );
        $stripe_id       = '';
        $stripe_verified = false;
    };

    // Verify or clear an existing account ID.
    if ( $stripe_id ) {
        $sc = wp_remote_get( 'https://api.stripe.com/v1/accounts/' . $stripe_id, array( 'headers' => array( 'Authorization' => 'Bearer ' . $stripe_sk ) ) );
        if ( is_wp_error( $sc ) || wp_remote_retrieve_response_code( $sc ) >= 400 ) {
            $li_clear_stripe_account( $rescue['id'] );
        } else {
            $sd = json_decode( wp_remote_retrieve_body( $sc ), true );
            if ( ! empty( $sd['error'] ) ) {
                li_log( 'Stripe account lookup failed for ' . $stripe_id . ': ' . wp_remote_retrieve_body( $sc ) );
                $li_clear_stripe_account( $rescue['id'] );
            } else {
                $stripe_verified = ! empty( $sd['payouts_enabled'] ) && $sd['payouts_enabled'] === true;
            }
        }
    }

    if ( ! $stripe_verified && $rescue ) {
        $return_url  = home_url( '/dashboard/my-account/?stripe_connect=success' );
        $refresh_url = home_url( '/dashboard/my-account/?stripe_connect=refresh' );

        // Try to create or recreate an Express account up to once.
        $attempts = 0;
        while ( ! $stripe_id && $attempts < 2 ) {
            $attempts++;
            $nr = wp_remote_post( 'https://api.stripe.com/v1/accounts', array(
                'headers' => array( 'Authorization' => 'Bearer ' . $stripe_sk, 'Content-Type' => 'application/x-www-form-urlencoded' ),
                'body'    => array(
                    'type'                               => 'express',
                    'country'                            => 'US',
                    'email'                              => $rescue['email'] ?? '',
                    'business_type'                      => 'non_profit',
                    'business_profile[name]'             => $rescue['name'] ?? '',
                    'capabilities[transfers][requested]' => 'true',
                ),
            ) );
            if ( ! is_wp_error( $nr ) ) {
                $na = json_decode( wp_remote_retrieve_body( $nr ), true );
                if ( ! empty( $na['id'] ) ) {
                    $stripe_id = $na['id'];
                    li_db_patch( 'rescues?id=eq.' . urlencode( $rescue['id'] ), array( 'stripe_account_id' => $stripe_id ) );
                } elseif ( ! empty( $na['error'] ) ) {
                    li_log( 'Stripe account creation failed (attempt ' . $attempts . '): ' . wp_remote_retrieve_body( $nr ) );
                }
            }
        }

        if ( $stripe_id ) {
            $lr = wp_remote_post( 'https://api.stripe.com/v1/account_links', array(
                'headers' => array( 'Authorization' => 'Bearer ' . $stripe_sk, 'Content-Type' => 'application/x-www-form-urlencoded' ),
                'body'    => array( 'account' => $stripe_id, 'refresh_url' => $refresh_url, 'return_url' => $return_url, 'type' => 'account_onboarding' ),
            ) );
            if ( ! is_wp_error( $lr ) ) {
                $ll = json_decode( wp_remote_retrieve_body( $lr ), true );
                if ( ! empty( $ll['url'] ) ) {
                    $connect_url = $ll['url'];
                } elseif ( ! empty( $ll['error'] ) ) {
                    li_log( 'Stripe account link failed: ' . wp_remote_retrieve_body( $lr ) );
                }
            }
        }
    }
    $w9_nonce = wp_create_nonce( 'li_media_upload' );
    $w9_ajax  = esc_url( admin_url( 'admin-ajax.php' ) );
    $has_w9     = ! empty( $rescue['w9_url'] );
    $has_ein    = ! empty( trim( $rescue['ein'] ?? '' ) );
    $has_stripe = $stripe_verified;
    $steps_total = 4;
    $steps_done  = ( $has_w9 ? 1 : 0 ) + ( $has_ein ? 1 : 0 ) + ( $has_stripe ? 1 : 0 ) + 1; // split review always readable
    $setup_pct   = round( ( $steps_done / $steps_total ) * 100 );
    $all_done    = $has_w9 && $has_ein && $has_stripe;

    ob_start();
    li_rescue_styles();
    echo '<style>
    .li-setup-wrap{max-width:740px;margin:0 auto;padding:2rem 1rem;font-family:Montserrat,sans-serif}
    .li-setup-title{font-size:22px;font-weight:600;color:#2c2a26;margin-bottom:6px}
    .li-setup-sub{font-size:13px;color:#6b6560;margin-bottom:1.5rem}
    .li-setup-progress{height:12px;background:#ede8e0;border-radius:10px;overflow:hidden;margin-bottom:2rem}
    .li-setup-progress>div{height:100%;background:#c9a84c;border-radius:10px;transition:width .3s}
    .li-setup-step{background:#fff;border:1px solid #e8e3db;border-radius:12px;padding:1.5rem;margin-bottom:16px}
    .li-setup-step.done{border-left:4px solid #3a7a4a}
    .li-setup-step-head{display:flex;align-items:center;gap:12px;margin-bottom:12px}
    .li-setup-step-num{width:28px;height:28px;border-radius:50%;background:#2c2a26;color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700}
    .li-setup-step.done .li-setup-step-num{background:#3a7a4a}
    .li-setup-step-title{font-size:14px;font-weight:700;color:#2c2a26;text-transform:uppercase;letter-spacing:.04em}
    .li-setup-step-status{margin-left:auto;font-size:12px;font-weight:600;padding:4px 10px;border-radius:20px;background:#f0ece6;color:#6b6560}
    .li-setup-step.done .li-setup-step-status{background:#eaf3de;color:#3a7a4a}
    .li-setup-help{background:#f9f7f4;border:1px solid #e8e3db;border-radius:8px;padding:14px 16px;margin:12px 0;font-size:13px;color:#6b6560;line-height:1.6}
    .li-setup-help strong{color:#2c2a26}
    .li-setup-help ul{margin:8px 0 0 18px;padding:0}
    .li-setup-help li{margin-bottom:4px}
    .li-setup-download{display:inline-flex;align-items:center;gap:6px;background:#f9f7f4;border:1px solid #ddd8d0;border-radius:6px;padding:8px 14px;font-size:13px;font-weight:600;color:#4a4540;text-decoration:none;margin-bottom:12px}
    .li-setup-download:hover{background:#f0ece6}
    .li-setup-done{background:#eaf3de;border:1px solid #b8d898;border-radius:12px;padding:1.5rem;text-align:center;margin-bottom:16px}
    .li-setup-done-title{font-size:16px;font-weight:700;color:#3a7a4a;margin-bottom:6px}
    </style>';
    echo '<div class="li-setup-wrap">';
    echo '<div class="li-rd-eye">Larry Impact</div><div class="li-setup-title">Rescue setup</div>';
    echo '<div class="li-setup-sub">Complete these steps so we can send you payouts and keep your profile ready for supporters.</div>';
    if ( $all_done ) {
        echo '<div class="li-setup-done"><div class="li-setup-done-title">Setup complete</div><p style="font-size:13px;color:#3a7a4a;margin:0;">Your W-9, tax ID, and Stripe account are on file. You are ready to receive payouts.</p></div>';
    } else {
        echo '<div class="li-setup-progress"><div style="width:' . intval( $setup_pct ) . '%;"></div></div>';
    }
    li_rescue_nav( 'account' );
    echo $msg;
    if ( ! $rescue ) { echo '<div class="li-rd-card"><p style="font-size:14px;color:#6b6560;">Account not found.</p></div></div>'; return ob_get_clean(); }

    // Step 1: W-9
    echo '<div class="li-setup-step ' . ( $has_w9 ? 'done' : '' ) . '">';
    echo '<div class="li-setup-step-head"><div class="li-setup-step-num">1</div><div class="li-setup-step-title">W-9 Tax Form</div><div class="li-setup-step-status">' . ( $has_w9 ? 'Complete' : 'Pending' ) . '</div></div>';
    echo '<p style="font-size:13px;color:#6b6560;margin:0 0 12px;line-height:1.6;">The IRS requires a signed W-9 before we can send payouts. Download the blank form, sign it, then upload the file.</p>';
    echo '<a href="https://www.irs.gov/pub/irs-pdf/fw9.pdf" target="_blank" rel="noopener noreferrer" class="li-setup-download">Download blank W-9 (PDF)</a>';
    if ( $has_w9 ) {
        echo '<p style="font-size:13px;color:#3a7a4a;margin:0 0 8px;"><a href="' . esc_url( $rescue['w9_url'] ) . '" target="_blank">View uploaded W-9</a></p>';
    }
    echo '<p style="font-size:12px;color:#a89880;margin:0 0 8px;">Upload a signed PDF or image.</p>';
    echo '<input type="file" id="li-w9-file" accept="application/pdf,image/*" style="margin-bottom:8px;" onchange="liUploadW9(this)" />';
    echo '<div id="li-w9-status" style="font-size:13px;color:#6b6560;"></div>';
    echo '</div>';

    // Step 2: Tax ID
    echo '<div class="li-setup-step ' . ( $has_ein ? 'done' : '' ) . '">';
    echo '<div class="li-setup-step-head"><div class="li-setup-step-num">2</div><div class="li-setup-step-title">Tax ID</div><div class="li-setup-step-status">' . ( $has_ein ? 'Complete' : 'Pending' ) . '</div></div>';
    echo '<form method="post" action="">';
    wp_nonce_field( 'li_rd_account', 'li_rd_account_nonce' );
    echo '<div class="li-rd-row"><div class="li-rd-field"><label class="li-rd-label">EIN / Tax ID</label>';
    echo '<input class="li-rd-input" type="text" name="li_ein" placeholder="XX-XXXXXXX" value="' . esc_attr( $rescue['ein'] ?? '' ) . '" /></div></div>';
    echo '<p style="font-size:12px;color:#a89880;margin-bottom:16px;line-height:1.6;">Stored securely and used only for payout tax reporting.</p>';
    echo '<button class="li-rd-btn" type="submit">Save tax info</button>';
    echo '</form></div>';

    // Step 3: Stripe
    echo '<div class="li-setup-step ' . ( $has_stripe ? 'done' : '' ) . '">';
    echo '<div class="li-setup-step-head"><div class="li-setup-step-num">3</div><div class="li-setup-step-title">Connect Stripe</div><div class="li-setup-step-status">' . ( $has_stripe ? 'Complete' : 'Pending' ) . '</div></div>';
    if ( $has_stripe ) {
        echo '<div class="li-rd-connected">&#10003; Bank account connected</div>';
        echo '<p style="font-size:13px;color:#6b6560;margin-top:12px;line-height:1.6;">Your bank account is connected. When payouts are processed you will receive funds directly.</p>';
    } elseif ( $connect_url ) {
        echo '<p style="font-size:13px;color:#6b6560;margin-bottom:12px;line-height:1.6;">Click the button below to create or connect a Stripe account. This is how Larry Impact sends you direct deposit payouts.</p>';
        echo '<div class="li-setup-help"><strong>What to expect</strong><ul><li>Stripe will ask for your rescueâs bank details and a contact person.</li><li>Use the same email you used here if possible.</li><li>When finished, close the Stripe tab and return to this page.</li><li>It may take a few minutes for the connection to show complete.</li></ul></div>';
        echo '<a href="' . esc_url( $connect_url ) . '" class="li-rd-stripe-btn" target="_blank" rel="noopener noreferrer">Connect bank account</a>';
    } else {
        echo '<div class="li-rd-err">Unable to generate the connection link. Please try again.</div>';
    }
    echo '</div>';

    // Step 4: Split review
    $split = floatval( $rescue['rescue_split_percent'] ?? get_option( 'li_default_split', 55 ) );
    echo '<div class="li-setup-step done">';
    echo '<div class="li-setup-step-head"><div class="li-setup-step-num">4</div><div class="li-setup-step-title">Your Split</div><div class="li-setup-step-status">Review</div></div>';
    echo '<p style="font-size:13px;color:#6b6560;margin:0;line-height:1.6;">Your rescue currently receives <strong>' . number_format( $split, 0 ) . '%</strong> of net profits after product costs. This can be adjusted by Larry Impact support if needed.</p>';
    echo '</div>';

    echo '<script>
    function liUploadW9(input){
        var file=input.files[0];
        if(!file)return;
        var status=document.getElementById("li-w9-status");
        status.textContent="Uploading...";
        var fd=new FormData();
        fd.append("action","li_upload_w9");
        fd.append("li_nonce","' . esc_js( $w9_nonce ) . '");
        fd.append("rescue_id","' . esc_js( $rescue['id'] ?? '' ) . '");
        fd.append("li_file",file);
        fetch("' . esc_js( $w9_ajax ) . '",{method:"POST",body:fd,credentials:"same-origin"})
        .then(function(r){return r.json();})
        .then(function(d){if(d.success){status.textContent="Uploaded successfully.";status.style.color="#3a7a4a";setTimeout(function(){window.location.reload();},800);}else{status.textContent=d.data&&d.data.message?d.data.message:"Upload failed.";status.style.color="#b91c1c";}})
        .catch(function(){status.textContent="Upload failed.";status.style.color="#b91c1c";});
    }
    </script>';
    echo '</div>';
    return ob_get_clean();
}
add_shortcode( 'larry_rescue_account', 'li_rescue_account_shortcode' );

// [larry_rescue_settings]
function li_rescue_settings_shortcode() {
    li_rescue_auth();
    $user = wp_get_current_user();
    $msg  = '';
    if ( isset( $_POST['li_rd_settings_nonce'] ) && wp_verify_nonce( $_POST['li_rd_settings_nonce'], 'li_rd_settings' ) ) {
        $new_email = sanitize_email( $_POST['li_email'] ?? '' );
        $new_pass  = $_POST['li_pass'] ?? '';
        $new_pass2 = $_POST['li_pass2'] ?? '';
        if ( $new_email && $new_email !== $user->user_email ) wp_update_user( array( 'ID' => $user->ID, 'user_email' => $new_email ) );
        if ( $new_pass ) {
            if ( $new_pass === $new_pass2 ) {
                wp_set_password( $new_pass, $user->ID );
                $msg = '<div class="li-rd-ok">Password updated. Please log in again.</div>';
            } else {
                $msg = '<div class="li-rd-err">Passwords do not match. Nothing was saved.</div>';
            }
        } else {
            $msg = '<div class="li-rd-ok">Settings saved.</div>';
        }
    }
    ob_start();
    li_rescue_styles();
    echo '<div class="li-rd-wrap">';
    echo '<div class="li-rd-eye">Larry Impact</div><div class="li-rd-title">Settings</div>';
    li_rescue_nav( 'settings' );
    echo $msg;
    echo '<form method="post" action="">';
    wp_nonce_field( 'li_rd_settings', 'li_rd_settings_nonce' );
    echo '<div class="li-rd-card"><div class="li-rd-card-title">Login email</div>';
    echo '<div class="li-rd-row li-rd-row-full"><div class="li-rd-field"><label class="li-rd-label">Email address</label>';
    echo '<input class="li-rd-input" type="email" name="li_email" value="' . esc_attr( $user->user_email ) . '" /></div></div></div>';
    echo '<div class="li-rd-card"><div class="li-rd-card-title">Change password</div>';
    echo '<div class="li-rd-row"><div class="li-rd-field"><label class="li-rd-label">New password</label>';
    echo '<input class="li-rd-input" type="password" name="li_pass" placeholder="Leave blank to keep current" /></div>';
    echo '<div class="li-rd-field"><label class="li-rd-label">Confirm new password</label>';
    echo '<input class="li-rd-input" type="password" name="li_pass2" placeholder="Repeat new password" /></div></div></div>';
    echo '<button class="li-rd-btn" type="submit">Save settings</button>';
    echo '</form></div>';
    return ob_get_clean();
}
add_shortcode( 'larry_rescue_settings', 'li_rescue_settings_shortcode' );

