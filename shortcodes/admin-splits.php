<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function li_page_splits() {
    ob_start();
    $url = esc_js( LI_DB_URL );
    $key = esc_js( LI_DB_KEY );
    echo '<div class="li-stat-grid">';
    echo '<div class="li-stat"><div class="li-stat-val" id="li-kpi-orders">--</div><div class="li-stat-label">Total orders</div></div>';
    echo '<div class="li-stat"><div class="li-stat-val li-stat-val-amber" id="li-kpi-revenue">--</div><div class="li-stat-label">Total revenue</div></div>';
    echo '<div class="li-stat"><div class="li-stat-val li-stat-val-green" id="li-kpi-rescue">--</div><div class="li-stat-label">To rescues</div></div>';
    echo '<div class="li-stat"><div class="li-stat-val li-stat-val-amber" id="li-kpi-larry">--</div><div class="li-stat-label">To Larry</div></div>';
    echo '</div>';
    echo '<div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">';
    echo '<span style="font-size:13px;color:#a89880;">Filter by status</span>';
    echo '<select class="li-select" style="width:auto" id="li-filter" onchange="liFilter()">';
    echo '<option value="all">All</option><option value="pending">Pending</option><option value="paid">Paid</option>';
    echo '</select>';
    echo '<button class="li-btn li-btn-sm" onclick="liLoadSplits()" style="margin-left:auto">Refresh</button>';
    echo '</div>';
    echo '<div class="li-table-wrap">';
    echo '<table class="li-table"><thead><tr>';
    echo '<th>Date</th><th>Order</th><th>Product</th><th>Rescue</th><th>Sale</th><th>To rescue</th><th>To Larry</th><th>Status</th>';
    echo '</tr></thead><tbody id="li-splits-tbody"><tr><td colspan="8" class="li-loading">Loading orders...</td></tr></tbody></table>';
    echo '</div>';
    echo '<script>';
    echo li_js_vars();
    echo '
    var liAllOrders = [];
    function liLoadSplits() {
        document.getElementById("li-splits-tbody").innerHTML = "<tr><td colspan=8 class=li-loading>Loading orders...</td></tr>";
        fetch(LI_URL+"/rest/v1/orders?select=*,products(name),rescues(name)&order=ordered_at.desc",{headers:{"apikey":LI_KEY,"Authorization":"Bearer "+LI_KEY}})
        .then(function(r){if(!r.ok)throw new Error(r.status);return r.json();})
        .then(function(data){
            liAllOrders=data;
            var tot=data.reduce(function(s,o){return s+o.sale_amount_cents;},0);
            var res=data.reduce(function(s,o){return s+o.rescue_split_cents;},0);
            var lar=data.reduce(function(s,o){return s+o.larry_split_cents;},0);
            document.getElementById("li-kpi-orders").textContent=data.length;
            document.getElementById("li-kpi-revenue").textContent="$"+(tot/100).toFixed(2);
            document.getElementById("li-kpi-rescue").textContent="$"+(res/100).toFixed(2);
            document.getElementById("li-kpi-larry").textContent="$"+(lar/100).toFixed(2);
            liRenderSplits(data);
        })
        .catch(function(e){document.getElementById("li-splits-tbody").innerHTML="<tr><td colspan=8 class=li-empty>Unable to load orders.</td></tr>";});
    }
    function liFilter(){
        var s=document.getElementById("li-filter").value;
        liRenderSplits(s==="all"?liAllOrders:liAllOrders.filter(function(o){return o.status===s;}));
    }
    function liRenderSplits(orders){
        var tbody=document.getElementById("li-splits-tbody");
        if(!orders||orders.length===0){tbody.innerHTML="<tr><td colspan=8 class=li-empty>No orders yet.</td></tr>";return;}
        tbody.innerHTML=orders.map(function(o){
            var d=new Date(o.ordered_at).toLocaleDateString("en-US",{month:"short",day:"numeric",year:"numeric"});
            var p=o.products?o.products.name:"Unknown";
            var r=o.rescues?o.rescues.name:"Unassigned";
            var b=o.status==="paid"?\'<span class="li-badge li-badge-approved">Paid</span>\':\'<span class="li-badge li-badge-pending">Pending</span>\';
            var oid=(o.shopify_order_id||"").split("::")[0];
            return "<tr><td>"+d+"</td><td style=font-size:11px;color:#a89880>#"+oid+"</td><td>"+p+"</td><td>"+r+"</td><td>$"+(o.sale_amount_cents/100).toFixed(2)+"</td><td style=color:#3a7a4a;font-weight:600>$"+(o.rescue_split_cents/100).toFixed(2)+"</td><td style=color:#9a6f2a;font-weight:600>$"+(o.larry_split_cents/100).toFixed(2)+"</td><td>"+b+"</td></tr>";
        }).join("");
    }
    document.addEventListener("DOMContentLoaded",liLoadSplits);
    </script>';
    li_admin_wrap( 'Split Dashboard', ob_get_clean() );
}

