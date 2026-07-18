<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'wp_ajax_li_get_orders', 'li_ajax_get_orders' );
function li_ajax_get_orders() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
    }
    check_ajax_referer( 'li_get_orders', 'nonce' );

    $page     = max( 1, intval( $_POST['page'] ?? 1 ) );
    $per_page = max( 10, min( 100, intval( $_POST['per_page'] ?? 50 ) ) );
    $status   = sanitize_text_field( $_POST['status'] ?? 'all' );
    $search   = sanitize_text_field( $_POST['search'] ?? '' );
    $offset   = ( $page - 1 ) * $per_page;

    $query = 'orders?select=*,products(name),rescues(name)&order=ordered_at.desc&limit=' . $per_page . '&offset=' . $offset;
    if ( $status !== 'all' ) {
        $query .= '&status=eq.' . urlencode( $status );
    }
    if ( $search ) {
        $term = urlencode( '*' . $search . '*' );
        $query .= '&or=(shopify_order_id.ilike.' . $term . ',products.name.ilike.' . $term . ',rescues.name.ilike.' . $term . ')';
    }

    $orders = li_db_get( $query );
    if ( ! is_array( $orders ) ) {
        wp_send_json_error( array( 'message' => 'Could not load orders.' ) );
    }
    wp_send_json_success( array(
        'orders'   => $orders,
        'page'     => $page,
        'per_page' => $per_page,
        'has_more' => count( $orders ) === $per_page,
    ) );
}

add_action( 'wp_ajax_li_process_payouts', 'li_handle_process_payouts' );
function li_handle_process_payouts() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
    }
    check_ajax_referer( 'li_process_payouts', 'nonce' );
    $results   = li_run_auto_payouts();
    $successes = array_filter( $results, function( $r ) { return ! empty( $r['success'] ); } );
    $messages  = array_column( $results, 'message' );
    wp_send_json_success( array( 'messages' => $messages, 'success_count' => count( $successes ), 'total' => count( $results ) ) );
}

add_action( 'wp_ajax_li_approve_all_ready', 'li_handle_approve_all_ready' );
function li_handle_approve_all_ready() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
    }
    check_ajax_referer( 'li_approve_all_ready', 'nonce' );
    $ready   = li_get_payouts( array( 'status' => 'ready', 'limit' => 100 ) );
    $results = array();
    foreach ( $ready as $p ) {
        $results[] = li_process_payout_batch( $p['id'] );
    }
    $successes = array_filter( $results, function( $r ) { return ! empty( $r['success'] ); } );
    $messages  = array_column( $results, 'message' );
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
        $order_ref  = sanitize_text_field( $order['shopify_order_id'] ?? '' );
        $product_id = sanitize_text_field( $order['product_id'] ?? '' );
        $rescue_id  = sanitize_text_field( $order['rescue_id'] ?? '' );
        $amount     = intval( $order['rescue_split_cents'] ?? 0 );
        $payout_id  = li_create_payout( $rescue_id, array( $order_ref ), $amount, '', 'completed', array( $amount ) );
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
    $notice = '';
    if ( isset( $_GET['li_payout_action'], $_GET['payout_id'] ) && current_user_can( 'manage_options' ) ) {
        check_admin_referer( 'li_payout_action', 'li_payout_nonce' );
        $id     = intval( $_GET['payout_id'] );
        $action = sanitize_text_field( $_GET['li_payout_action'] );
        switch ( $action ) {
            case 'approve':
                $r = li_process_payout_batch( $id );
                $notice = $r['success'] ? '<div class="li-msg-ok">' . esc_html( $r['message'] ) . '</div>' : '<div class="li-msg-err">' . esc_html( $r['message'] ) . '</div>';
                break;
            case 'complete':
                $ok = li_mark_payout_completed( $id );
                $notice = $ok ? '<div class="li-msg-ok">Batch marked completed.</div>' : '<div class="li-msg-err">Could not complete batch.</div>';
                break;
            case 'archive':
                $ok = li_archive_payout( $id );
                $notice = $ok ? '<div class="li-msg-ok">Batch archived.</div>' : '<div class="li-msg-err">Could not archive batch.</div>';
                break;
            case 'rollback':
                $ok = li_rollback_payout( $id );
                $notice = $ok ? '<div class="li-msg-ok">Batch rolled back.</div>' : '<div class="li-msg-err">Could not roll back batch.</div>';
                break;
        }
    }

    ob_start();
    echo $notice;

    $ajax  = esc_js( admin_url( 'admin-ajax.php' ) );
    $get_nonce   = esc_js( wp_create_nonce( 'li_get_orders' ) );
    $mp_nonce    = esc_js( wp_create_nonce( 'li_mark_paid' ) );
    $pp_nonce    = esc_js( wp_create_nonce( 'li_process_payouts' ) );
    $apr_nonce   = esc_js( wp_create_nonce( 'li_approve_all_ready' ) );
    $symbol      = esc_js( li_currency_symbol() );

    echo '<div class="li-stat-grid">';
    echo '<div class="li-stat"><div class="li-stat-val li-stat-val-green" id="li-py-earned">--</div><div class="li-stat-label">Total earned by rescues</div></div>';
    echo '<div class="li-stat"><div class="li-stat-val li-stat-val-amber" id="li-py-paid">--</div><div class="li-stat-label">Total paid out</div></div>';
    echo '<div class="li-stat"><div class="li-stat-val" id="li-py-pending">--</div><div class="li-stat-label">Pending payout</div></div>';
    echo '<div class="li-stat"><div class="li-stat-val" id="li-py-orders">--</div><div class="li-stat-label">Orders tracked</div></div>';
    echo '</div>';

    echo '<div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap;">';
    echo '<button type="button" class="li-btn" style="background:#3a7a4a;" onclick="liPyRunPayouts()">Prepare payout batches</button>';
    echo '<button type="button" class="li-btn" style="background:#c9a84c;" onclick="liPyApproveAll()">Approve all ready</button>';
    echo '<div id="li-py-payout-msg" style="font-size:13px;display:none;"></div>';
    echo '</div>';

    // Payout batches table
    echo '<h2 style="font-size:15px;color:#2c2a26;margin:24px 0 12px;">Payout batches</h2>';
    $payouts = li_get_payouts( array( 'limit' => 100 ) );
    if ( empty( $payouts ) ) {
        echo '<p style="color:#6b6560;font-size:13px;">No payout batches yet. Click "Prepare payout batches" to group pending orders into reviewable batches.</p>';
    } else {
        echo '<div class="li-table-wrap"><table class="li-table"><thead><tr>';
        echo '<th>Batch</th><th>Rescue</th><th>Amount</th><th>Status</th><th>Transfer ID</th><th>Created</th><th>Actions</th>';
        echo '</tr></thead><tbody>';
        foreach ( $payouts as $p ) {
            $rescue = li_get_rescue_by_id( $p['rescue_id'] );
            $name   = $rescue ? ( $rescue['name'] ?? 'Rescue' ) : 'Rescue';
            $badge  = '<span class="li-badge li-badge-' . esc_attr( $p['status'] ) . '">' . esc_html( ucwords( str_replace( '_', ' ', $p['status'] ) ) ) . '</span>';
            $actions = array();
            if ( $p['status'] === 'ready' ) {
                $actions[] = '<a class="li-btn li-btn-sm" style="background:#3a7a4a;" href="' . esc_url( wp_nonce_url( admin_url( 'admin.php?page=li-payouts&li_payout_action=approve&payout_id=' . $p['id'] ), 'li_payout_action', 'li_payout_nonce' ) ) . '">Approve & Pay</a>';
            }
            if ( in_array( $p['status'], array( 'approved', 'pending' ), true ) ) {
                $actions[] = '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin.php?page=li-payouts&li_payout_action=complete&payout_id=' . $p['id'] ), 'li_payout_action', 'li_payout_nonce' ) ) . '">Mark completed</a>';
            }
            if ( $p['status'] === 'completed' ) {
                $actions[] = '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin.php?page=li-payouts&li_payout_action=archive&payout_id=' . $p['id'] ), 'li_payout_action', 'li_payout_nonce' ) ) . '">Archive</a>';
            }
            if ( ! in_array( $p['status'], array( 'archived', 'rolled_back' ), true ) ) {
                $actions[] = '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin.php?page=li-payouts&li_payout_action=rollback&payout_id=' . $p['id'] ), 'li_payout_action', 'li_payout_nonce' ) ) . '" style="color:#b91c1c;">Rollback</a>';
            }
            echo '<tr>';
            echo '<td>#' . esc_html( $p['id'] ) . '</td>';
            echo '<td>' . esc_html( $name ) . '</td>';
            echo '<td style="color:#3a7a4a;font-weight:600;">' . esc_html( li_format_money( $p['amount_cents'] ) ) . '</td>';
            echo '<td>' . $badge . '</td>';
            echo '<td style="font-size:11px;color:#a89880;">' . esc_html( $p['transfer_id'] ?: '-' ) . '</td>';
            echo '<td style="font-size:12px;">' . esc_html( date( 'M j, Y g:i a', strtotime( $p['created_at'] ) ) ) . '</td>';
            echo '<td style="font-size:12px;">' . implode( '&nbsp;|&nbsp;', $actions ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    echo '<h2 style="font-size:15px;color:#2c2a26;margin:24px 0 12px;">Orders</h2>';
    echo '<div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap;">';
    echo '<span style="font-size:13px;color:#a89880;">Filter</span>';
    echo '<select class="li-select" style="width:auto" id="li-py-filter" onchange="liPyFilter()"><option value="all">All</option><option value="pending">Pending</option><option value="ready_for_payout">Ready for payout</option><option value="approved">Approved</option><option value="paid">Paid</option></select>';
    echo '<input type="search" id="li-py-search" placeholder="Search orders..." style="padding:6px 10px;border:1px solid #ddd8d0;border-radius:6px;font-size:13px;" onkeydown="if(event.key===\'Enter\'){liPyLoad();}" />';
    echo '<button type="button" class="li-btn li-btn-sm" onclick="liPyLoad()">Search</button>';
    echo '</div>';
    echo '<div id="li-py-msg" style="display:none;margin-bottom:16px;"></div>';
    echo '<div class="li-table-wrap"><table class="li-table"><thead><tr>';
    echo '<th>Date</th><th>Order</th><th>Product</th><th>Rescue</th><th>Earned</th><th>Status</th><th>Action</th>';
    echo '</tr></thead><tbody id="li-py-tbody"><tr><td colspan="7" class="li-loading">Loading orders...</td></tr></tbody></table></div>';
    echo '<div style="display:flex;align-items:center;justify-content:space-between;margin-top:12px;">';
    echo '<button type="button" class="li-btn li-btn-sm" id="li-py-prev" disabled onclick="liPyPage(-1)">Previous</button>';
    echo '<span id="li-py-page-info" style="font-size:13px;color:#6b6560;">Page 1</span>';
    echo '<button type="button" class="li-btn li-btn-sm" id="li-py-next" disabled onclick="liPyPage(1)">Next</button>';
    echo '</div>';

    echo '<script>';
    echo 'var LI_AJAX="' . $ajax . '";var LI_GET_ORDERS_NONCE="' . $get_nonce . '";var LI_MP_NONCE="' . $mp_nonce . '";var LI_PAYOUT_NONCE="' . $pp_nonce . '";var LI_APPROVE_ALL_NONCE="' . $apr_nonce . '";var LI_SYMBOL="' . $symbol . '";';
    echo '
    var liPyAll=[];var liPyFiltered=[];var liPyPageNum=1;var liPyPerPage=50;var liPyHasMore=false;
    function liPyLoad(reset){
        if(reset){liPyPageNum=1;}
        var status=document.getElementById("li-py-filter").value;
        var search=document.getElementById("li-py-search").value.trim();
        var fd=new FormData();fd.append("action","li_get_orders");fd.append("nonce",LI_GET_ORDERS_NONCE);fd.append("page",liPyPageNum);fd.append("per_page",liPyPerPage);fd.append("status",status);fd.append("search",search);
        fetch(LI_AJAX,{method:"POST",body:fd})
        .then(function(r){return r.json();})
        .then(function(res){
            if(!res.success||!res.data||!Array.isArray(res.data.orders))throw new Error(res.data&&res.data.message?res.data.message:"Invalid response");
            liPyAll=res.data.orders;liPyHasMore=res.data.has_more;liPyFiltered=liPyAll;
            var t=liPyAll.reduce(function(s,o){return s+(o.rescue_split_cents||0);},0);
            var p=liPyAll.filter(function(o){return o.status==="paid";}).reduce(function(s,o){return s+(o.rescue_split_cents||0);},0);
            document.getElementById("li-py-earned").textContent=LI_SYMBOL+(t/100).toFixed(2);
            document.getElementById("li-py-paid").textContent=LI_SYMBOL+(p/100).toFixed(2);
            document.getElementById("li-py-pending").textContent=LI_SYMBOL+((t-p)/100).toFixed(2);
            document.getElementById("li-py-orders").textContent=liPyAll.length;
            document.getElementById("li-py-page-info").textContent="Page "+liPyPageNum;
            document.getElementById("li-py-prev").disabled=liPyPageNum<=1;
            document.getElementById("li-py-next").disabled=!liPyHasMore;
            liPyRender();
        })
        .catch(function(){document.getElementById("li-py-tbody").innerHTML="<tr><td colspan=7 class=li-empty>Unable to load orders.</td></tr>";document.getElementById("li-py-next").disabled=true;document.getElementById("li-py-prev").disabled=liPyPageNum<=1;});
    }
    function liPyFilter(){liPyPageNum=1;liPyLoad();}
    function liPyPage(dir){liPyPageNum+=dir;if(liPyPageNum<1)liPyPageNum=1;liPyLoad();}
    function liPyRender(){
        var tbody=document.getElementById("li-py-tbody");
        if(!liPyFiltered||liPyFiltered.length===0){tbody.innerHTML="<tr><td colspan=7 class=li-empty>No orders found.</td></tr>";return;}
        tbody.innerHTML=liPyFiltered.map(function(o){
            var d=new Date(o.ordered_at).toLocaleDateString("en-US",{month:"short",day:"numeric",year:"numeric"});
            var p=o.products?o.products.name:"Unknown";
            var r=o.rescues?o.rescues.name:"Unassigned";
            var st=o.status||"pending";
            var badge=st==="paid"?"<span class=\\"li-badge li-badge-approved\\">Paid</span>":("<span class=\\"li-badge li-badge-"+st+"\\">"+st.replace(/_/g," ")+"</span>");
            var a=(st==="pending"||st==="ready_for_payout")?"<button class=\\"li-btn li-btn-sm\\" style=background:#3a7a4a onclick=liPyMarkPaid("+JSON.stringify(o.id)+")>Mark paid</button>":"--";
            var oid=(o.shopify_order_id||"").split("::")[0];
            return "<tr><td>"+d+"</td><td style=font-size:11px;color:#a89880>#"+oid+"</td><td>"+p+"</td><td>"+r+"</td><td style=color:#3a7a4a;font-weight:600>"+LI_SYMBOL+(o.rescue_split_cents/100).toFixed(2)+"</td><td>"+badge+"</td><td>"+a+"</td></tr>";
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
        if(!confirm("This will group pending orders into ready-for-review payout batches. No money is transferred yet. Continue?"))return;
        var el=document.getElementById("li-py-payout-msg");
        el.style.display="inline";el.textContent="Preparing...";
        try{
            var fd=new FormData();fd.append("action","li_process_payouts");fd.append("nonce",LI_PAYOUT_NONCE);
            var res=await fetch(LI_AJAX,{method:"POST",body:fd});var data=await res.json();
            if(!data.success)throw new Error(data.data&&data.data.message?data.data.message:"Prepare failed");
            el.style.color="#3a7a4a";el.textContent=data.data.success_count+"/"+data.data.total+" rescues prepared. "+data.data.messages.join("; ");
            liPyLoad(true);window.location.reload();
        }catch(e){el.style.color="#b91c1c";el.textContent=e.message||"Prepare failed.";}
    }
    async function liPyApproveAll(){
        if(!confirm("This will approve and transfer all ready batches. Continue?"))return;
        var el=document.getElementById("li-py-payout-msg");
        el.style.display="inline";el.textContent="Approving...";
        try{
            var fd=new FormData();fd.append("action","li_approve_all_ready");fd.append("nonce",LI_APPROVE_ALL_NONCE);
            var res=await fetch(LI_AJAX,{method:"POST",body:fd});var data=await res.json();
            if(!data.success)throw new Error(data.data&&data.data.message?data.data.message:"Approval failed");
            el.style.color="#3a7a4a";el.textContent=data.data.success_count+"/"+data.data.total+" batches approved. "+data.data.messages.join("; ");
            liPyLoad(true);window.location.reload();
        }catch(e){el.style.color="#b91c1c";el.textContent=e.message||"Approval failed.";}
    }
    document.addEventListener("DOMContentLoaded",function(){liPyLoad(true);});
    </script>';
    li_admin_wrap( 'Payouts', ob_get_clean() );
}
