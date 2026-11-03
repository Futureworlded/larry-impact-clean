<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'wp_ajax_li_approve_rescue', 'li_ajax_approve_rescue' );
add_action( 'wp_ajax_li_decline_rescue', 'li_ajax_decline_rescue' );

function li_ajax_approve_rescue() {
    check_ajax_referer( 'li_approve_rescue', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
    }

    $id    = sanitize_text_field( $_POST['id'] ?? '' );
    $name  = sanitize_text_field( $_POST['name'] ?? '' );
    $email = sanitize_email( $_POST['email'] ?? '' );

    if ( ! $id || ! $email ) {
        wp_send_json_error( array( 'message' => 'Missing rescue information.' ) );
    }

    $r = li_db_patch( 'rescues?id=eq.' . urlencode( $id ), array( 'status' => 'approved' ) );
    if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) >= 400 ) {
        wp_send_json_error( array( 'message' => 'Could not update rescue status.' ) );
    }

    // Create or update WordPress login account for the rescue.
    $result   = li_create_rescue_wp_user( $name, $email, true );
    $password = $result['password'] ?? '';
    $username = $result['username'] ?? '';
    $user_id  = intval( $result['user_id'] ?? 0 );

    // Publish / update the rescue partner page.
    if ( function_exists( 'li_create_rescue_pages' ) ) {
        li_create_rescue_pages();
    }

    $login_url = home_url( '/rescue-login/' );
    wp_send_json_success( array(
        'message'    => $name . ' has been approved and a login account was created.',
        'password'   => $password,
        'username'   => $username,
        'email'      => $email,
        'email_sent' => ! empty( $result['email_sent'] ),
        'login_url'  => $login_url,
        'user_id'    => $user_id,
    ) );
}

function li_ajax_decline_rescue() {
    check_ajax_referer( 'li_approve_rescue', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
    }

    $id = sanitize_text_field( $_POST['id'] ?? '' );
    if ( ! $id ) {
        wp_send_json_error( array( 'message' => 'Missing rescue information.' ) );
    }

    $r = li_db_patch( 'rescues?id=eq.' . urlencode( $id ), array( 'status' => 'declined' ) );
    if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) >= 400 ) {
        wp_send_json_error( array( 'message' => 'Could not decline application.' ) );
    }
    wp_send_json_success( array( 'message' => 'Application declined.' ) );
}

function li_page_applications() {
    $nonce = wp_create_nonce( 'li_approve_rescue' );
    $ajax  = esc_js( admin_url( 'admin-ajax.php' ) );
    ob_start();

    echo '<div class="li-tabs">';
    echo '<button class="li-tab li-tab-active" onclick="liAppTab(\'pending\',this)">Pending <span id="li-pending-count" style="background:#fef3e2;color:#9a6f2a;border-radius:20px;padding:1px 8px;font-size:11px;margin-left:4px;">0</span></button>';
    echo '<button class="li-tab" onclick="liAppTab(\'approved\',this)">Approved</button>';
    echo '<button class="li-tab" onclick="liAppTab(\'declined\',this)">Declined</button>';
    echo '</div>';
    echo '<div id="li-app-msg" style="display:none;margin-bottom:16px;"></div>';
    echo '<div class="li-table-wrap"><table class="li-table"><thead><tr>';
    echo '<th>Organization</th><th>Contact</th><th>Location</th><th>Submitted</th><th>Actions</th>';
    echo '</tr></thead><tbody id="li-app-tbody"><tr><td colspan="5" class="li-loading">Loading applications...</td></tr></tbody></table></div>';
    echo '<script>';
    echo li_js_vars();
    echo 'var LI_APPROVE_NONCE="' . $nonce . '";';
    echo 'var LI_AJAX="' . $ajax . '";';
    echo <<<'JS'
    var liAppAll=[];var liAppCurrent="pending";
    function liAppLoad(){
        fetch(LI_ADMIN_AJAX+"&path="+encodeURIComponent("rescues?select=*&order=created_at.desc")+"&nonce="+encodeURIComponent(LI_ADMIN_NONCE))
        .then(function(r){return r.json();})
        .then(function(data){
            if(!Array.isArray(data)){throw new Error("Invalid response");}
            liAppAll=data;
            var pending=data.filter(function(r){return r.status==="pending";});
            document.getElementById("li-pending-count").textContent=pending.length;
            liAppRender(liAppCurrent);
        })
        .catch(function(){document.getElementById("li-app-tbody").innerHTML="<tr><td colspan=5 class=li-empty>Unable to load applications.</td></tr>";});
    }
    function liAppTab(tab,btn){
        liAppCurrent=tab;
        document.querySelectorAll(".li-tab").forEach(function(t){t.classList.remove("li-tab-active");});
        btn.classList.add("li-tab-active");
        liAppRender(tab);
    }
    function escHtml(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
    function liAppAttach(){
        document.querySelectorAll(".li-app-approve").forEach(function(btn){btn.addEventListener("click",function(){liAppApprove(btn.dataset.id,btn.dataset.name,btn.dataset.email);});});
        document.querySelectorAll(".li-app-decline").forEach(function(btn){btn.addEventListener("click",function(){liAppDecline(btn.dataset.id);});});
    }
    function liAppRender(status){
        var tbody=document.getElementById("li-app-tbody");
        var filtered=liAppAll.filter(function(r){return r.status===status;});
        if(filtered.length===0){
            var msgs={pending:"No pending applications.",approved:"No approved rescues yet.",declined:"No declined applications."};
            tbody.innerHTML="<tr><td colspan=5 class=li-empty>"+(msgs[status]||"No records.")+"</td></tr>";
            return;
        }
        tbody.innerHTML=filtered.map(function(r){
            var d=new Date(r.created_at).toLocaleDateString("en-US",{month:"short",day:"numeric",year:"numeric"});
            var loc=[r.city,r.state].filter(Boolean).join(", ")||"--";
            var actions="";
            if(status==="pending"){
                actions='<div style="display:flex;gap:8px">'
                    +'<button class="li-btn li-btn-sm li-app-approve" data-id="'+escHtml(r.id)+'" data-name="'+escHtml(r.name||'')+'" data-email="'+escHtml(r.email||'')+'" style="background:#3a7a4a">Approve</button>'
                    +'<button class="li-btn li-btn-sm li-app-decline" data-id="'+escHtml(r.id)+'" style="background:#b91c1c">Decline</button>'
                    +'</div>';
            }else{
                var bc=status==="approved"?"li-badge-approved":"li-badge-declined";
                actions='<span class="li-badge '+bc+'">'+status+'</span>';
            }
            return "<tr><td><strong>"+(r.name||"--")+"</strong><br><span style=font-size:11px;color:#a89880>"+(r.email||"")+"</span></td>"
                +"<td><span style=font-size:12px;color:#6b6560>"+(r.phone||"--")+"</span></td>"
                +"<td>"+loc+"</td><td>"+d+"</td><td>"+actions+"</td></tr>";
        }).join("");
        liAppAttach();
    }
    async function liAppApprove(id,name,email){
        if(!confirm("Approve "+name+" and create a login account?"))return;
        try{
            var fd=new FormData();fd.append("action","li_approve_rescue");fd.append("nonce",LI_APPROVE_NONCE);fd.append("id",id);fd.append("name",name);fd.append("email",email);
            var res=await fetch(LI_AJAX,{method:"POST",body:fd});var data=await res.json();
            if(!data.success)throw new Error(data.data&&data.data.message?data.data.message:"Approve failed");
            liAppShowApproval(name,data.data);
            liAppLoad();
        }catch(e){liAppShowMsg("err",e.message||"Could not approve. Please try again.");}
    }
    async function liAppDecline(id){
        if(!confirm("Decline this application?"))return;
        try{
            var fd=new FormData();fd.append("action","li_decline_rescue");fd.append("nonce",LI_APPROVE_NONCE);fd.append("id",id);
            var res=await fetch(LI_AJAX,{method:"POST",body:fd});var data=await res.json();
            if(!data.success)throw new Error(data.data&&data.data.message?data.data.message:"Decline failed");
            liAppShowMsg("ok","Application declined.");
            liAppLoad();
        }catch(e){liAppShowMsg("err",e.message||"Could not decline. Please try again.");}
    }
    function liAppShowMsg(type,text){
        var el=document.getElementById("li-app-msg");
        el.className=type==="ok"?"li-msg-ok":"li-msg-err";
        el.innerHTML='<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;"><span>'+escHtml(text)+'</span><button type="button" style="background:transparent;border:none;cursor:pointer;font-size:16px;font-weight:700;color:inherit;" onclick="this.parentElement.parentElement.style.display=\'none\'">×</button></div>';
        el.style.display="block";
    }
    function liAppShowApproval(name,data){
        var el=document.getElementById("li-app-msg");
        el.className="li-msg-ok";
        var html='<p><strong>'+escHtml(name)+'</strong> has been approved.</p>';
        if(data.email_sent){
            html+='<p style="font-size:13px;margin-top:6px;">A welcome email was sent to <strong>'+escHtml(data.email||"")+'</strong>.</p>';
        } else {
            html+='<p style="font-size:13px;margin-top:6px;">The rescue can log in at <a href="'+escHtml(data.login_url||"")+'">'+escHtml(data.login_url||"")+'</a>.</p>';
        }
        if(data.username){
            html+='<p style="font-size:13px;margin-top:8px;">Username: <code>'+escHtml(data.username)+'</code></p>';
        }
        if(data.password){
            html+='<p style="font-size:13px;margin-top:8px;">Temporary password: <input type="text" readonly value="'+escHtml(data.password)+'" style="font-family:monospace;width:160px;border:1px solid #ddd8d0;border-radius:4px;padding:4px;" />'+
                ' <button type="button" class="li-btn li-btn-sm" onclick="navigator.clipboard.writeText(this.previousElementSibling.value).then(()=>{this.textContent=\'Copied\';setTimeout(()=>this.textContent=\'Copy\',1500);}).catch(()=>{this.textContent=\'Select & copy\';})">Copy</button></p>';
        }
        html+='<p style="font-size:12px;margin-top:8px;"><button type="button" style="background:transparent;border:none;cursor:pointer;text-decoration:underline;color:inherit;padding:0;" onclick="document.getElementById(\'li-app-msg\').style.display=\'none\'">Dismiss</button></p>';
        el.innerHTML=html;
        el.style.display="block";
    }
    document.addEventListener("DOMContentLoaded",liAppLoad);
    </script>
JS;
    li_admin_wrap( 'Applications', ob_get_clean() );
}
