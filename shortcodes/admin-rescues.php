<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// handle preview profile save
function li_handle_preview_save() {
    if ( ! isset( $_POST['li_preview_nonce'] ) ) return '';
    if ( ! wp_verify_nonce( $_POST['li_preview_nonce'], 'li_preview_save' ) ) return '';
    if ( ! current_user_can( 'manage_options' ) ) return '';
    $rid = sanitize_text_field( $_POST['rescue_id'] ?? '' );
    if ( ! $rid ) return '';
    $data = array(
        'name'            => sanitize_text_field( $_POST['rescue_name'] ?? '' ),
        'email'           => sanitize_email( $_POST['rescue_email'] ?? '' ),
        'phone'           => sanitize_text_field( $_POST['rescue_phone'] ?? '' ),
        'website'         => esc_url_raw( $_POST['rescue_website'] ?? '' ),
        'city'            => sanitize_text_field( $_POST['rescue_city'] ?? '' ),
        'state'           => sanitize_text_field( $_POST['rescue_state'] ?? '' ),
        'mission'         => sanitize_textarea_field( $_POST['rescue_mission'] ?? '' ),
        'about'           => sanitize_textarea_field( $_POST['rescue_about'] ?? '' ),
        'founding_year'   => intval( $_POST['rescue_founding_year'] ?? 0 ),
        'animals_rescued' => intval( $_POST['rescue_animals_rescued'] ?? 0 ),
        'status'          => sanitize_text_field( $_POST['rescue_status'] ?? 'pending' ),
        'video_url'       => esc_url_raw( $_POST['rescue_video_url'] ?? '' ),
        'ein'             => sanitize_text_field( $_POST['rescue_ein'] ?? '' ),
    );
    $r = li_db_patch( 'rescues?id=eq.' . urlencode( $rid ), $data );
    if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) >= 400 ) {
        return '<div class="li-msg-err">Could not save changes. Please try again.</div>';
    }
    if ( function_exists( 'li_create_rescue_pages' ) ) {
        li_create_rescue_pages();
    }
    return '<div class="li-msg-ok">Changes saved successfully.</div>';
}

function li_page_rescues() {
    $view_id = isset( $_GET['rescue_id'] ) ? sanitize_text_field( $_GET['rescue_id'] ) : '';
    if ( $view_id ) {
        li_page_rescue_detail( $view_id );
    } else {
        li_page_rescue_list();
    }
}

function li_page_rescue_list() {
    ob_start();
    $url = esc_js( LI_DB_URL );
    $key = esc_js( LI_DB_KEY );
    $base = esc_js( admin_url( 'admin.php?page=li-rescues&rescue_id=' ) );

    echo '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px;">';
    echo '<div style="font-size:13px;color:#6b6560;">All rescue organizations. Click any row to manage their profile.</div>';
    echo '<form method="post" action="' . admin_url( 'admin-post.php' ) . '">';
    wp_nonce_field( 'li_woo_sync', 'li_woo_sync_nonce' );
    echo '<input type="hidden" name="action" value="li_woo_sync" />';
    echo '<button type="submit" class="li-btn li-btn-sm">Sync WooCommerce Products</button>';
    echo '</form>';
    echo '</div>';

    if ( isset( $_GET['synced'] ) ) {
        $r = get_transient( 'li_sync_result' );
        if ( $r ) {
            echo '<div class="li-msg-ok">' . intval( $r['synced'] ) . ' products synced. ' . intval( $r['skipped'] ) . ' coffee products skipped.</div>';
            delete_transient( 'li_sync_result' );
        }
    }

    echo '<div class="li-table-wrap"><table class="li-table"><thead><tr>';
    echo '<th>Rescue name</th><th>Location</th><th>Email</th><th>Status</th><th>Joined</th>';
    echo '</tr></thead><tbody id="li-rescues-tbody"><tr><td colspan="5" class="li-loading">Loading rescues...</td></tr></tbody></table></div>';
    echo '<script>';
    echo li_js_vars();
    echo 'var LI_BASE="' . $base . '";';
    echo '
    fetch(LI_URL+"/rest/v1/rescues?select=*&order=created_at.desc",{headers:{"apikey":LI_KEY,"Authorization":"Bearer "+LI_KEY}})
    .then(function(r){if(!r.ok)throw new Error(r.status);return r.json();})
    .then(function(data){
        var tbody=document.getElementById("li-rescues-tbody");
        if(!data||data.length===0){tbody.innerHTML="<tr><td colspan=5 class=li-empty>No rescues yet.</td></tr>";return;}
        tbody.innerHTML=data.map(function(r){
            var d=new Date(r.created_at).toLocaleDateString("en-US",{month:"short",day:"numeric",year:"numeric"});
            var loc=[r.city,r.state].filter(Boolean).join(", ")||"--";
            var st=r.status||"unknown";
            var bc=st==="approved"||st==="active"?"li-badge-approved":st==="pending"?"li-badge-pending":st==="declined"?"li-badge-declined":"li-badge-inactive";
            var url=LI_BASE+r.id;
            return "<tr style=cursor:pointer data-url="+JSON.stringify(url)+" class=li-rescue-row>"
                +"<td><strong>"+(r.name||"--")+"</strong></td>"
                +"<td>"+loc+"</td>"
                +"<td>"+(r.email||"--")+"</td>"
                +"<td><span class=\"li-badge "+bc+"\">"+st+"</span></td>"
                +"<td>"+d+"</td></tr>";
        }).join("");
        document.querySelectorAll(".li-rescue-row").forEach(function(row){
            row.addEventListener("click",function(){window.location.href=row.getAttribute("data-url");});
        });
    })
    .catch(function(){document.getElementById("li-rescues-tbody").innerHTML="<tr><td colspan=5 class=li-empty>Unable to load rescues.</td></tr>";});
    </script>';
    li_admin_wrap( 'Rescues', ob_get_clean() );
}

function li_page_rescue_detail( $view_id ) {
    $save_msg = li_handle_preview_save();
    $data = li_db_get( 'rescues?id=eq.' . urlencode( $view_id ) . '&select=*' );
    $rescue = ! empty( $data ) ? $data[0] : null;
    if ( ! $rescue ) {
        li_admin_wrap( 'Rescues', '<a href="' . admin_url( 'admin.php?page=li-rescues' ) . '" class="li-back">Back to rescues</a><p>Rescue not found.</p>' );
        return;
    }
    $url_js  = esc_js( LI_DB_URL );
    $key_js  = esc_js( LI_DB_KEY );
    $rid_js  = esc_js( $view_id );
    $nonce   = wp_create_nonce( 'li_media_upload' );
    $ajax_js = esc_js( admin_url( 'admin-ajax.php' ) );

    ob_start();
    echo $save_msg;
    if ( isset( $_GET['login_msg'] ) ) { echo '<div class="li-msg-ok">' . esc_html( urldecode( $_GET['login_msg'] ) ) . '</div>'; }
    echo '<a href="' . admin_url( 'admin.php?page=li-rescues' ) . '" class="li-back">Back to all rescues</a>';
    echo '<div class="li-rescue-header">';
    echo '<div><div class="li-rescue-name">' . esc_html( $rescue['name'] ) . '</div>';
    echo '<div class="li-rescue-meta">' . esc_html( $rescue['city'] ?? '' ) . ( $rescue['state'] ? ', ' . esc_html( $rescue['state'] ) : '' ) . ' &middot; ' . esc_html( $rescue['email'] ?? '' ) . '</div></div>';
    echo '</div>';

    // tabs
    $tabs = array( 'profile' => 'Profile', 'media' => 'Media', 'stats' => 'Stats', 'orders' => 'Orders', 'account' => 'Account', 'preview' => '&#128269; Rescue view' );
    echo '<div class="li-tabs">';
    $first = true;
    foreach ( $tabs as $key => $label ) {
        $active = $first ? ' li-tab-active' : '';
        $style  = $key === 'preview' ? ' style="margin-left:auto;color:#9a6f2a;"' : '';
        echo '<button class="li-tab' . $active . '" onclick="liTab(\'' . $key . '\',this)"' . $style . '>' . $label . '</button>';
        $first = false;
    }
    echo '</div>';

    // Profile tab
    echo '<div class="li-panel li-panel-active" id="li-tab-profile">';
    echo '<form method="post" action="">';
    wp_nonce_field( 'li_preview_save', 'li_preview_nonce' );
    echo '<input type="hidden" name="rescue_id" value="' . esc_attr( $view_id ) . '" />';
    echo '<div class="li-card"><div class="li-card-title">Organization info</div>';
    echo '<div class="li-row"><div class="li-field"><label class="li-label">Organization name</label>';
    echo '<input class="li-input" type="text" name="rescue_name" value="' . esc_attr( $rescue['name'] ?? '' ) . '" /></div>';
    echo '<div class="li-field"><label class="li-label">Status</label>';
    echo '<select class="li-select" name="rescue_status">';
    foreach ( array( 'pending', 'approved', 'active', 'inactive', 'declined' ) as $s ) {
        echo '<option value="' . $s . '"' . selected( $rescue['status'] ?? '', $s, false ) . '>' . ucfirst( $s ) . '</option>';
    }
    echo '</select></div></div>';
    echo '<div class="li-row"><div class="li-field"><label class="li-label">City</label>';
    echo '<input class="li-input" type="text" name="rescue_city" value="' . esc_attr( $rescue['city'] ?? '' ) . '" /></div>';
    echo '<div class="li-field"><label class="li-label">State</label>';
    echo '<input class="li-input" type="text" name="rescue_state" value="' . esc_attr( $rescue['state'] ?? '' ) . '" /></div></div>';
    echo '<div class="li-row"><div class="li-field"><label class="li-label">Website</label>';
    echo '<input class="li-input" type="url" name="rescue_website" value="' . esc_attr( $rescue['website'] ?? '' ) . '" /></div>';
    echo '<div class="li-field"><label class="li-label">Founding year</label>';
    echo '<input class="li-input" type="number" name="rescue_founding_year" value="' . esc_attr( $rescue['founding_year'] ?? '' ) . '" /></div></div>';
    echo '<div class="li-row li-row-full"><div class="li-field"><label class="li-label">Mission statement</label>';
    echo '<textarea class="li-textarea" name="rescue_mission">' . esc_textarea( $rescue['mission'] ?? '' ) . '</textarea></div></div>';
    echo '<div class="li-row li-row-full"><div class="li-field"><label class="li-label">Full story</label>';
    echo '<textarea class="li-textarea" style="min-height:120px" name="rescue_about">' . esc_textarea( $rescue['about'] ?? '' ) . '</textarea></div></div></div>';
    echo '<div class="li-card"><div class="li-card-title">Contact</div>';
    echo '<div class="li-row"><div class="li-field"><label class="li-label">Email</label>';
    echo '<input class="li-input" type="email" name="rescue_email" value="' . esc_attr( $rescue['email'] ?? '' ) . '" /></div>';
    echo '<div class="li-field"><label class="li-label">Phone</label>';
    echo '<input class="li-input" type="tel" name="rescue_phone" value="' . esc_attr( $rescue['phone'] ?? '' ) . '" /></div></div>';
    echo '<div class="li-row"><div class="li-field"><label class="li-label">Animals rescued</label>';
    echo '<input class="li-input" type="number" name="rescue_animals_rescued" value="' . esc_attr( $rescue['animals_rescued'] ?? 0 ) . '" /></div>';
    echo '<div class="li-field"><label class="li-label">EIN / Tax ID</label>';
    echo '<input class="li-input" type="text" name="rescue_ein" value="' . esc_attr( $rescue['ein'] ?? '' ) . '" /></div></div></div>';
    echo '<div class="li-card"><div class="li-card-title">Video</div>';
    echo '<div class="li-row li-row-full"><div class="li-field"><label class="li-label">YouTube or Vimeo URL</label>';
    echo '<input class="li-input" type="url" name="rescue_video_url" placeholder="https://youtube.com/watch?v=..." value="' . esc_attr( $rescue['video_url'] ?? '' ) . '" /></div></div></div>';
    echo '<button class="li-btn" type="submit">Save changes</button>';
    echo '</form></div>';

    // Media tab
    echo '<div class="li-panel" id="li-tab-media">';
    echo '<div class="li-card"><div class="li-card-title">Logo</div>';
    if ( ! empty( $rescue['logo_url'] ) ) {
        echo '<img src="' . esc_url( $rescue['logo_url'] ) . '" class="li-media-thumb" id="li-logo-img" />';
    } else {
        echo '<div class="li-media-empty" id="li-logo-img">No logo uploaded yet</div>';
    }
    echo '<br><input type="file" id="li-logo-file" accept="image/*" style="display:none" onchange="liUpload(this,\'logo_url\',\'' . $rid_js . '\')" />';
    echo '<button type="button" class="li-upload-btn" onclick="document.getElementById(\'li-logo-file\').click()">Upload logo</button>';
    echo '<div id="li-logo-status" style="font-size:12px;color:#9a6f2a;margin-top:6px;"></div></div>';
    echo '<div class="li-card"><div class="li-card-title">Hero photo</div>';
    if ( ! empty( $rescue['hero_photo_url'] ) ) {
        echo '<img src="' . esc_url( $rescue['hero_photo_url'] ) . '" class="li-media-thumb li-media-wide" id="li-hero-img" />';
    } else {
        echo '<div class="li-media-empty" id="li-hero-img">No hero photo uploaded yet</div>';
    }
    echo '<br><input type="file" id="li-hero-file" accept="image/*" style="display:none" onchange="liUpload(this,\'hero_photo_url\',\'' . $rid_js . '\')" />';
    echo '<button type="button" class="li-upload-btn" onclick="document.getElementById(\'li-hero-file\').click()">Upload hero photo</button>';
    echo '<div id="li-hero-status" style="font-size:12px;color:#9a6f2a;margin-top:6px;"></div></div>';
    echo '</div>';

    // Stats tab
    echo '<div class="li-panel" id="li-tab-stats">';
    echo '<div class="li-stat-grid" id="li-rescue-stats"><div style="text-align:center;padding:20px;color:#a89880;">Loading stats...</div></div>';
    echo '</div>';

    // Orders tab
    echo '<div class="li-panel" id="li-tab-orders">';
    echo '<div class="li-table-wrap"><table class="li-table"><thead><tr>';
    echo '<th>Date</th><th>Order</th><th>Product</th><th>Sale</th><th>Their cut</th><th>Status</th>';
    echo '</tr></thead><tbody id="li-rescue-orders"><tr><td colspan="6" class="li-loading">Loading...</td></tr></tbody></table></div>';
    echo '</div>';

    // Account tab
    $stripe_id       = $rescue['stripe_account_id'] ?? '';
    $stripe_verified = false;
    if ( $stripe_id ) {
        $sc = wp_remote_get( 'https://api.stripe.com/v1/accounts/' . $stripe_id, array( 'headers' => array( 'Authorization' => 'Bearer ' . li_stripe_sk() ) ) );
        if ( ! is_wp_error( $sc ) ) {
            $sd = json_decode( wp_remote_retrieve_body( $sc ), true );
            $stripe_verified = ! empty( $sd['payouts_enabled'] ) && $sd['payouts_enabled'] === true;
        }
    }
    echo '<div class="li-panel" id="li-tab-account">';
    echo '<div class="li-card"><div class="li-card-title">Stripe payout status</div>';
    if ( $stripe_verified ) {
        echo '<div class="li-badge li-badge-approved">&#10003; Bank account verified and ready for payouts</div>';
    } elseif ( $stripe_id ) {
        echo '<div class="li-badge li-badge-pending">&#9888; Stripe account created but onboarding not complete</div>';
        echo '<p style="font-size:12px;color:#a89880;margin-top:8px;">The rescue needs to complete their bank account setup from their own dashboard.</p>';
    } else {
        echo '<p style="font-size:13px;color:#a89880;">No Stripe account connected yet.</p>';
    }
    echo '</div>';
    echo '<div class="li-card"><div class="li-card-title">W-9 Tax form</div>';
    $w9_url = $rescue['w9_url'] ?? '';
    if ( $w9_url ) {
        echo '<div class="li-badge li-badge-approved">&#10003; W-9 on file</div>';
        echo '<p style="font-size:12px;color:#a89880;margin-top:8px;"><a href="' . esc_url( $w9_url ) . '" target="_blank" rel="noopener noreferrer">View W-9</a></p>';
    } else {
        echo '<div class="li-badge li-badge-pending">&#9888; No W-9 uploaded</div>';
    }
    echo '</div>';
    $user = get_user_by( 'email', $rescue['email'] ?? '' );
    if ( ! $user ) {
        echo '<div class="li-card"><div class="li-card-title">WordPress login</div>';
        echo '<p style="font-size:13px;color:#6b6560;margin-bottom:12px;">No WordPress user exists for this rescue. Create one to grant dashboard access.</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'li_create_rescue_login' );
        echo '<input type="hidden" name="action" value="li_create_rescue_login" />';
        echo '<input type="hidden" name="rescue_id" value="' . esc_attr( $view_id ) . '" />';
        echo '<button type="submit" class="li-btn">Create WordPress login</button>';
        echo '</form></div>';
    } else {
        echo '<div class="li-card"><div class="li-card-title">WordPress login</div>';
        echo '<p style="font-size:13px;color:#6b6560;">User exists: <strong>' . esc_html( $user->user_login ) . '</strong></p>';
        echo '</div>';
    }
    echo '</div>';

    // Rescue view (preview) tab
    echo '<div class="li-panel" id="li-tab-preview">';
    echo '<div class="li-preview-notice">&#128269; Admin preview &mdash; you are viewing this rescue as they would see it. All edits save directly to their profile.</div>';
    echo '<div class="li-stat-grid" id="li-prev-stats"><div style="text-align:center;padding:20px;color:#a89880;">Click the Rescue view tab to load stats...</div></div>';
    echo '<div class="li-card"><div class="li-card-title">Recent earnings</div>';
    echo '<div class="li-table-wrap"><table class="li-table"><thead><tr><th>Date</th><th>Product</th><th>Their cut</th><th>Status</th></tr></thead>';
    echo '<tbody id="li-prev-orders"><tr><td colspan="4" class="li-empty">No data loaded yet.</td></tr></tbody></table></div></div>';
    echo '</div>';

    // JS
    echo '<script>';
    echo li_js_vars();
    echo 'var LI_RID="' . $rid_js . '";var LI_NONCE="' . $nonce . '";var LI_AJAX="' . $ajax_js . '";';
    echo '
    function liTab(name,btn){
        document.querySelectorAll(".li-panel").forEach(function(el){el.classList.remove("li-panel-active");});
        document.querySelectorAll(".li-tab").forEach(function(el){el.classList.remove("li-tab-active");});
        document.getElementById("li-tab-"+name).classList.add("li-panel-active");
        btn.classList.add("li-tab-active");
        if(name==="stats")liLoadStats();
        if(name==="orders")liLoadOrders();
        if(name==="preview")liLoadPreview();
    }
    function liLoadStats(){
        fetch(LI_URL+"/rest/v1/orders?rescue_id=eq."+LI_RID+"&select=*",{headers:{"apikey":LI_KEY,"Authorization":"Bearer "+LI_KEY}})
        .then(function(r){return r.json();})
        .then(function(orders){
            var t=orders.reduce(function(s,o){return s+o.rescue_split_cents;},0);
            var p=orders.filter(function(o){return o.status==="paid";}).reduce(function(s,o){return s+o.rescue_split_cents;},0);
            document.getElementById("li-rescue-stats").innerHTML=
                "<div class=li-stat><div class=li-stat-val>"+orders.length+"</div><div class=li-stat-label>Orders</div></div>"+
                "<div class=li-stat><div class=\"li-stat-val li-stat-val-green\">$"+(t/100).toFixed(2)+"</div><div class=li-stat-label>Total earned</div></div>"+
                "<div class=li-stat><div class=\"li-stat-val li-stat-val-amber\">$"+(p/100).toFixed(2)+"</div><div class=li-stat-label>Paid out</div></div>"+
                "<div class=li-stat><div class=li-stat-val>$"+((t-p)/100).toFixed(2)+"</div><div class=li-stat-label>Pending</div></div>";
        });
    }
    function liLoadOrders(){
        fetch(LI_URL+"/rest/v1/orders?rescue_id=eq."+LI_RID+"&select=*,products(name)&order=ordered_at.desc",{headers:{"apikey":LI_KEY,"Authorization":"Bearer "+LI_KEY}})
        .then(function(r){return r.json();})
        .then(function(orders){
            var tbody=document.getElementById("li-rescue-orders");
            if(!orders||orders.length===0){tbody.innerHTML="<tr><td colspan=6 class=li-empty>No orders yet.</td></tr>";return;}
            tbody.innerHTML=orders.map(function(o){
                var d=new Date(o.ordered_at).toLocaleDateString("en-US",{month:"short",day:"numeric",year:"numeric"});
                var b=o.status==="paid"?"<span class=\"li-badge li-badge-approved\">Paid</span>":"<span class=\"li-badge li-badge-pending\">Pending</span>";
                var oid=(o.shopify_order_id||"").split("::")[0];
                return "<tr><td>"+d+"</td><td style=font-size:11px;color:#a89880>#"+oid+"</td><td>"+(o.products?o.products.name:"--")+"</td><td>$"+(o.sale_amount_cents/100).toFixed(2)+"</td><td style=color:#3a7a4a;font-weight:600>$"+(o.rescue_split_cents/100).toFixed(2)+"</td><td>"+b+"</td></tr>";
            }).join("");
        });
    }
    function liLoadPreview(){
        fetch(LI_URL+"/rest/v1/orders?rescue_id=eq."+LI_RID+"&select=*,products(name)&order=ordered_at.desc",{headers:{"apikey":LI_KEY,"Authorization":"Bearer "+LI_KEY}})
        .then(function(r){return r.json();})
        .then(function(orders){
            var t=orders.reduce(function(s,o){return s+o.rescue_split_cents;},0);
            var p=orders.filter(function(o){return o.status==="paid";}).reduce(function(s,o){return s+o.rescue_split_cents;},0);
            document.getElementById("li-prev-stats").innerHTML=
                "<div class=li-stat><div class=li-stat-val>"+orders.length+"</div><div class=li-stat-label>Orders</div></div>"+
                "<div class=li-stat><div class=\"li-stat-val li-stat-val-green\">$"+(t/100).toFixed(2)+"</div><div class=li-stat-label>Total earned</div></div>"+
                "<div class=li-stat><div class=\"li-stat-val li-stat-val-amber\">$"+(p/100).toFixed(2)+"</div><div class=li-stat-label>Paid out</div></div>"+
                "<div class=li-stat><div class=li-stat-val>$"+((t-p)/100).toFixed(2)+"</div><div class=li-stat-label>Pending</div></div>";
            var tbody=document.getElementById("li-prev-orders");
            if(!orders||orders.length===0){tbody.innerHTML="<tr><td colspan=4 class=li-empty>No orders yet.</td></tr>";return;}
            tbody.innerHTML=orders.slice(0,5).map(function(o){
                var d=new Date(o.ordered_at).toLocaleDateString("en-US",{month:"short",day:"numeric",year:"numeric"});
                var b=o.status==="paid"?"<span class=\"li-badge li-badge-approved\">Paid</span>":"<span class=\"li-badge li-badge-pending\">Pending</span>";
                return "<tr><td>"+d+"</td><td>"+(o.products?o.products.name:"--")+"</td><td style=color:#3a7a4a;font-weight:600>$"+(o.rescue_split_cents/100).toFixed(2)+"</td><td>"+b+"</td></tr>";
            }).join("");
        });
    }
    async function liUpload(input,field,rescueId){
        if(!input.files||!input.files[0])return;
        var sid=field==="logo_url"?"li-logo-status":"li-hero-status";
        var iid=field==="logo_url"?"li-logo-img":"li-hero-img";
        document.getElementById(sid).textContent="Uploading...";
        var fd=new FormData();
        fd.append("action","li_media_upload");
        fd.append("li_file",input.files[0]);
        fd.append("li_nonce",LI_NONCE);
        try{
            var res=await fetch(LI_AJAX,{method:"POST",body:fd});
            var data=await res.json();
            if(data.success&&data.data.url){
                var el=document.getElementById(iid);
                if(el.tagName==="IMG"){el.src=data.data.url;}else{var img=document.createElement("img");img.src=data.data.url;img.className="li-media-thumb";img.id=iid;el.parentNode.replaceChild(img,el);}
                var pfd=new FormData();pfd.append("action","li_update_rescue_field");pfd.append("li_nonce",LI_NONCE);pfd.append("rescue_id",rescueId);pfd.append("field",field);pfd.append("value",data.data.url);
                var pres=await fetch(LI_AJAX,{method:"POST",body:pfd});var pdata=await pres.json();
                if(!pdata.success)throw new Error(pdata.data&&pdata.data.message?pdata.data.message:"Save failed");
                document.getElementById(sid).textContent="Uploaded.";
                setTimeout(function(){document.getElementById(sid).textContent="";},2000);
            }
        }catch(e){document.getElementById(sid).textContent="Upload failed.";}
    }
    </script>';
    li_admin_wrap( 'Rescues', ob_get_clean() );
}



add_action( 'admin_post_li_create_rescue_login', 'li_handle_create_rescue_login' );
function li_handle_create_rescue_login() {
    check_admin_referer( 'li_create_rescue_login' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Unauthorized' ); }
    $rid = sanitize_text_field( $_POST['rescue_id'] ?? '' );
    $data = li_db_get( 'rescues?id=eq.' . urlencode( $rid ) . '&select=*' );
    $rescue = ! empty( $data ) ? $data[0] : null;
    if ( ! $rescue ) { wp_redirect( admin_url( 'admin.php?page=li-rescues' ) ); exit; }
    $result = li_create_rescue_wp_user( $rescue['name'] ?? '', $rescue['email'] ?? '', true );
    wp_redirect( admin_url( 'admin.php?page=li-rescues&rescue_id=' . $rid . '&login_msg=' . urlencode( $result['message'] ?? 'Done' ) ) );
    exit;
}