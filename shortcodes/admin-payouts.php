<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'wp_ajax_li_get_orders', 'li_ajax_get_orders' );
function li_ajax_get_orders() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
    }
    check_ajax_referer( 'li_get_orders', 'nonce' );
    $orders = li_db_get( 'orders?select=*,products(name),rescues(name)&order=ordered_at.desc' );
    if ( ! is_array( $orders ) ) {
        wp_send_json_error( array( 'message' => 'Could not load orders.' ) );
    }
    wp_send_json_success( $orders );
}

add_action( 'wp_ajax_li_process_payouts', 'li_handle_process_payouts' );
function li_handle_process_payouts() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
    }
    check_ajax_referer( 'li_process_payouts', 'nonce' );
    $results = li_run_auto_payouts();
    $successes = array_filter( $results, function( $r ) { return ! empty( $r['success'] ); } );
    $messages = array_column( $results, 'message' );
    wp_send_json_success( array( 'messages' => $messages, 'success_count' => count( $successes ), 'total' => count( $results ) ) );
}

add_action( 'wp_ajax_li_mark_paid', 'li_handle_mark_paid' );
function li_handle_mark_paid() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
    }
    check_ajax_referer( 'li_mark_paid', 'nonce' );
    $id = sanitize_text_field( $_POST['id'] ?? '' );
    if ( ! $id ) {
        wp_send_json_error( array( 'message' => 'Missing order.' ) );
    }
    $order = li_db_get( 'orders?id=eq.' . urlencode( $id ) . '&select=*' );
    $order = ! empty( $order ) ? $order[0] : null;
    $r = li_db_patch( 'orders?id=eq.' . urlencode( $id ), array( 'status' => 'paid' ) );
    if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) >= 400 ) {
        wp_send_json_error( array( 'message' => 'Could not update order status.' ) );
    }
    if ( $order ) {
        $order_ref = sanitize_text_field( $order['shopify_order_id'] ?? '' );
        $product_id = sanitize_text_field( $order['product_id'] ?? '' );
        $rescue_id = sanitize_text_field( $order['rescue_id'] ?? '' );
        $amount = intval( $order['rescue_split_cents'] ?? 0 );
        $payout_id = li_create_payout( $rescue_id, array( $order_ref ), $amount, '', 'completed' );
        li_record_ledger( 'payout', array(
            'order_ref'    => $order_ref,
            'product_id'   => $product_id,
            'rescue_id'    => $rescue_id,
            'amount_cents' => -1 * $amount,
            'net_cents'    => -1 * $amount,
            'meta'         => array( 'payout_id' => $payout_id, 'manual' => true ),
            'source'       => 'manual',
        ) );
        li_audit_log( 'order_marked_paid', array( 'order_id' => $id, 'payout_id' => $payout_id ), 'order', $order_ref );
    }
    wp_send_json_success();
}

function li_page_payouts() {
    ob_start();
    $ajax = esc_js( admin_url( 'admin-ajax.php' ) );
    $get_nonce = esc_js( wp_create_nonce( 'li_get_orders' ) );
    $mp_nonce = esc_js( wp_create_nonce( 'li_mark_paid' ) );
    $pp_nonce = esc_js( wp_create_nonce( 'li_process_payouts' ) );
    echo '<div class="li-stat-grid">';
    echo '<div class="li-stat"><div class="li-stat-val li-stat-val-green" id="li-py-earned">--</div><div class="li-stat-label">Total earned by rescues</div></div>';
    echo '<div class="li-stat"><div class="li-stat-val li-stat-val-amber" id="li-py-paid">--</div><div class="li-stat-label">Total paid out</div></div>';
    echo '<div class="li-stat"><div class="li-stat-val" id="li-py-pending">--</div><div class="li-stat-label">Pending payout</div></div>';
    echo '<div class="li-stat"><div class="li-stat-val" id="li-py-orders">--</div><div class="li-stat-label">Orders tracked</div></div>';
    echo '</div>';
    echo '<div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">';
    echo '<button type="button" class="li-btn" style="background:#3a7a4a;" onclick="liPyRunPayouts()">Run automatic payouts</button>';
    echo '<div id="li-py-payout-msg" style="font-size:13px;display:none;"></div>';
    echo '</div>';

    echo '<div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">';
    echo '<span style="font-size:13px;color:#a89880;">Filter</span>';
    echo '<select class="li-select" style="width:auto" id="li-py-filter" onchange="liPyFilter()"><option value="all">All</option><option value="pending">Pending</option><option value="paid">Paid</option></select>';
    echo '</div>';
    echo '<div id="li-py-msg" style="display:none;margin-bottom:16px;"></div>';
    echo '<div class="li-table-wrap"><table class="li-table"><thead><tr>';
    echo '<th>Date</th><th>Order</th><th>Product</th><th>Rescue</th><th>Earned</th><th>Status</th><th>Action</th>';
    echo '</tr></thead><tbody id="li-py-tbody"><tr><td colspan="7" class="li-loading">Loading orders...</td></tr></tbody></table></div>';
    echo '<script>';
    echo 'var LI_AJAX="' . $ajax . '";var LI_GET_ORDERS_NONCE="' . $get_nonce . '";var LI_MP_NONCE="' . $mp_nonce . '";var LI_PAYOUT_NONCE="' . $pp_nonce . '";';
    echo '
    var liPyAll=[];var liPyFiltered=[];
    function liPyLoad(){
        var fd=new FormData();fd.append("action","li_get_orders");fd.append("nonce",LI_GET_ORDERS_NONCE);
        fetch(LI_AJAX,{method:"POST",body:fd})
        .then(function(r){return r.json();})
        .then(function(res){
            if(!res.success||!Array.isArray(res.data))throw new Error(res.data&&res.data.message?res.data.message:"Invalid response");
            liPyAll=res.data;
            var t=liPyAll.reduce(function(s,o){return s+(o.rescue_split_cents||0);},0);
            var p=liPyAll.filter(function(o){return o.status==="paid";}).reduce(function(s,o){return s+(o.rescue_split_cents||0);},0);
            document.getElementById("li-py-earned").textContent="$"+(t/100).toFixed(2);
            document.getElementById("li-py-paid").textContent="$"+(p/100).toFixed(2);
            document.getElementById("li-py-pending").textContent="$"+((t-p)/100).toFixed(2);
            document.getElementById("li-py-orders").textContent=liPyAll.length;
            liPyFilter();
        })
        .catch(function(){document.getElementById("li-py-tbody").innerHTML="<tr><td colspan=7 class=li-empty>Unable to load orders.</td></tr>";});
    }
    function liPyFilter(){
        var s=document.getElementById("li-py-filter").value;
        liPyFiltered=s==="all"?liPyAll:liPyAll.filter(function(o){return o.status===s;});
        liPyRender();
    }
    function liPyRender(){
        var tbody=document.getElementById("li-py-tbody");
        if(!liPyFiltered||liPyFiltered.length===0){tbody.innerHTML="<tr><td colspan=7 class=li-empty>No orders found.</td></tr>";return;}
        tbody.innerHTML=liPyFiltered.map(function(o){
            var d=new Date(o.ordered_at).toLocaleDateString("en-US",{month:"short",day:"numeric",year:"numeric"});
            var p=o.products?o.products.name:"Unknown";
            var r=o.rescues?o.rescues.name:"Unassigned";
            var b=o.status==="paid"?"<span class=\"li-badge li-badge-approved\">Paid</span>":"<span class=\"li-badge li-badge-pending\">Pending</span>";
            var a=o.status==="pending"?"<button class=\"li-btn li-btn-sm\" style=background:#3a7a4a onclick=liPyMarkPaid("+JSON.stringify(o.id)+")>Mark paid</button>":"--";
            var oid=(o.shopify_order_id||"").split("::")[0];
            return "<tr><td>"+d+"</td><td style=font-size:11px;color:#a89880>#"+oid+"</td><td>"+p+"</td><td>"+r+"</td><td style=color:#3a7a4a;font-weight:600>$"+(o.rescue_split_cents/100).toFixed(2)+"</td><td>"+b+"</td><td>"+a+"</td></tr>";
        }).join("");
    }
    async function liPyMarkPaid(id){
        if(!confirm("Mark this order as paid to the rescue?"))return;
        try{
            var fd=new FormData();fd.append("action","li_mark_paid");fd.append("nonce",LI_MP_NONCE);fd.append("id",id);
            var res=await fetch(LI_AJAX,{method:"POST",body:fd});var data=await res.json();
            if(!data.success)throw new Error(data.data&&data.data.message?data.data.message:"Save failed");
            var el=document.getElementById("li-py-msg");
            el.className="li-msg-ok";el.textContent="Order marked as paid.";el.style.display="block";
            setTimeout(function(){el.style.display="none";},3000);
            liPyLoad();
        }catch(e){
            var el=document.getElementById("li-py-msg");
            el.className="li-msg-err";el.textContent="Could not update. Please try again.";el.style.display="block";
        }
    }
    async function liPyRunPayouts(){
        if(!confirm("This will transfer pending balances to every approved rescue that has completed Stripe onboarding. Continue?"))return;
        var el=document.getElementById("li-py-payout-msg");
        el.style.display="inline";el.textContent="Processing...";
        try{
            var fd=new FormData();fd.append("action","li_process_payouts");fd.append("nonce",LI_PAYOUT_NONCE);
            var res=await fetch(LI_AJAX,{method:"POST",body:fd});var data=await res.json();
            if(!data.success)throw new Error(data.data&&data.data.message?data.data.message:"Payout failed");
            el.style.color="#3a7a4a";el.textContent=data.data.success_count+"/"+data.data.total+" rescues processed. "+data.data.messages.join("; ");
            liPyLoad();
        }catch(e){el.style.color="#b91c1c";el.textContent=e.message||"Payout failed.";}
    }
    document.addEventListener("DOMContentLoaded",liPyLoad);
    </script>';
    li_admin_wrap( 'Payouts', ob_get_clean() );
}
