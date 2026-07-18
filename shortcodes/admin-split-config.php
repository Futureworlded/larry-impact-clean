<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function li_page_split_config() {
    ob_start();
    $url          = esc_js( LI_DB_URL );
    $key          = esc_js( LI_DB_KEY );
    $default_net  = floatval( get_option( 'li_default_split', 55 ) );
    $default_net_js = esc_js( $default_net );
    echo '<p style="font-size:13px;color:#6b6560;margin-bottom:20px;">Set the rescue share for each product. Larry gets the remainder after cost and the rescue share. Values are gross percentages of the sale price.</p>';
    echo '<div class="li-table-wrap"><table class="li-table"><thead><tr>';
    echo '<th>Product</th><th>SKU</th><th>Sale price</th><th>Rescue %</th><th>Larry %</th><th>Net to Larry</th>';
    echo '</tr></thead><tbody id="li-sc-tbody"><tr><td colspan="6" class="li-loading">Loading products...</td></tr></tbody></table></div>';
    echo '<div style="margin-top:16px;display:flex;align-items:center;gap:16px;">';
    echo '<button class="li-btn" onclick="liScSave()">Save changes</button>';
    echo '<button class="li-btn" style="background:#8B4A09;" onclick="liScRecalcAll()">Recalculate all</button>';
    echo '<div id="li-sc-msg" style="font-size:13px;display:none;"></div>';
    echo '</div>';
    $ajax_url = esc_js( admin_url( 'admin-ajax.php' ) );
    $nonce    = esc_js( wp_create_nonce( 'li_save_split_config' ) );
    $recalc_nonce = esc_js( wp_create_nonce( 'li_recalculate_splits' ) );
    echo '<script>';
    echo li_js_vars();
    echo 'var LI_AJAX="' . $ajax_url . '";var LI_SC_NONCE="' . $nonce . '";var LI_RECALC_NONCE="' . $recalc_nonce . '";var LI_DEFAULT_NET=' . $default_net_js . ';';
    echo '
    var liScData=[];
    function liScLoad(){
        fetch(LI_URL+"/rest/v1/products?select=*,split_config(id,rescue_percent,larry_percent)&order=name.asc",{headers:{"apikey":LI_KEY,"Authorization":"Bearer "+LI_KEY}})
        .then(function(r){return r.json();})
        .then(function(data){
            liScData=data;
            var tbody=document.getElementById("li-sc-tbody");
            if(!data||data.length===0){tbody.innerHTML="<tr><td colspan=6 class=li-empty>No products found.</td></tr>";return;}
            tbody.innerHTML=data.map(function(p){
                var sp=p.split_config&&p.split_config.length>0?p.split_config[0]:null;
                var price=parseFloat(p.price_cents)||0;
                var cost=parseFloat(p.cost_cents)||0;
                var costPercent=price>0?(cost/price)*100:0;
                var netPercent=Math.max(0,100-costPercent);
                var rp,lp;
                if(sp){
                    rp=parseFloat(sp.rescue_percent)||0;
                    lp=parseFloat(sp.larry_percent)||0;
                }else{
                    // Default is a net percentage. Convert to gross for display.
                    rp=netPercent>0?(LI_DEFAULT_NET*netPercent/100):0;
                    lp=Math.max(0,netPercent-rp);
                }
                var net=(price/100*lp/100).toFixed(2);
                var nc=(rp+costPercent>100)?"color:#b91c1c":"color:#3a3530";
                return "<tr><td><strong>"+p.name+"</strong><br><span style=font-size:11px;color:#a89880>"+(p.format||"")+" "+(p.size_oz?p.size_oz+"oz":"")+"</span></td>"
                    +"<td style=font-size:11px;color:#a89880>"+p.sku+"</td>"
                    +"<td>$"+(price/100).toFixed(2)+"</td>"
                    +"<td><input id=rp_"+p.id+" type=number min=0 max=100 step=0.01 value="+rp.toFixed(2)+" style=width:70px;border:1px solid #ddd8d0;border-radius:6px;padding:6px;font-size:13px; onchange=liScRecalc("+JSON.stringify(p.id)+") /> %</td>"
                    +"<td><input id=lp_"+p.id+" type=number min=0 max=100 step=0.01 value="+lp.toFixed(2)+" style=width:70px;border:1px solid #ddd8d0;border-radius:6px;padding:6px;font-size:13px; onchange=liScUpdateNet("+JSON.stringify(p.id)+") /> %</td>"
                    +"<td><span id=net_"+p.id+" style="+nc+">${"+net+"}</span></td></tr>";
            }).join("");
        });
    }
    function liScRecalc(pid){
        var p=liScData.find(function(x){return x.id===pid;});
        if(!p)return;
        var price=parseFloat(p.price_cents)||0;
        var cost=parseFloat(p.cost_cents)||0;
        var rpInput=document.getElementById("rp_"+pid);
        var lpInput=document.getElementById("lp_"+pid);
        var rp=parseFloat(rpInput.value)||0;
        var costPercent=price>0?(cost/price)*100:0;
        var lp=Math.max(0,100-costPercent-rp);
        lpInput.value=lp.toFixed(2);
        liScUpdateNet(pid);
    }
    function liScUpdateNet(pid){
        var p=liScData.find(function(x){return x.id===pid;});
        if(!p)return;
        var price=parseFloat(p.price_cents)||0;
        var cost=parseFloat(p.cost_cents)||0;
        var rp=parseFloat(document.getElementById("rp_"+pid).value)||0;
        var lp=parseFloat(document.getElementById("lp_"+pid).value)||0;
        var net=(price/100*lp/100).toFixed(2);
        var el=document.getElementById("net_"+pid);
        el.textContent="$"+net;
        var costPercent=price>0?(cost/price)*100:0;
        el.style.color=(rp+lp+costPercent>100)?"#b91c1c":"#3a3530";
    }
    async function liScSave(){
        var msg=document.getElementById("li-sc-msg");
        msg.style.display="none";
        try{
            for(var i=0;i<liScData.length;i++){
                var p=liScData[i];
                var rp=parseFloat(document.getElementById("rp_"+p.id).value)||0;
                var lp=parseFloat(document.getElementById("lp_"+p.id).value)||0;
                var fd=new FormData();
                fd.append("action","li_save_split_config");
                fd.append("nonce",LI_SC_NONCE);
                fd.append("product_id",p.id);
                fd.append("rescue_percent",rp);
                fd.append("larry_percent",lp);
                var res=await fetch(LI_AJAX,{method:"POST",body:fd});
                if(!res.ok)throw new Error("Save failed: "+res.status);
            }
            msg.style.display="inline";msg.style.color="#3a7a4a";msg.textContent="Changes saved.";
            liScLoad();
        }catch(e){msg.style.display="inline";msg.style.color="#b91c1c";msg.textContent=e.message||"Save failed.";}
    }
    async function liScRecalcAll(){
        var msg=document.getElementById("li-sc-msg");
        msg.style.display="none";
        try{
            var fd=new FormData();
            fd.append("action","li_recalculate_splits");
            fd.append("nonce",LI_RECALC_NONCE);
            var res=await fetch(LI_AJAX,{method:"POST",body:fd});
            var json=await res.json();
            if(!res.ok||!json.success) throw new Error(json.data&&json.data.message||"Recalc failed");
            msg.style.display="inline";msg.style.color="#3a7a4a";msg.textContent="All splits recalculated from default.";
            liScLoad();
        }catch(e){msg.style.display="inline";msg.style.color="#b91c1c";msg.textContent=e.message||"Recalc failed.";}
    }
    document.addEventListener("DOMContentLoaded",liScLoad);
    </script>';
    li_admin_wrap( 'Split Configurator', ob_get_clean() );
}

add_action( 'wp_ajax_li_save_split_config', 'li_ajax_save_split_config' );
function li_ajax_save_split_config() {
    check_ajax_referer( 'li_save_split_config', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
    }
    $product_id = sanitize_text_field( $_POST['product_id'] ?? '' );
    $rp = floatval( $_POST['rescue_percent'] ?? 0 );
    $lp = floatval( $_POST['larry_percent'] ?? 0 );
    if ( ! $product_id ) {
        wp_send_json_error( array( 'message' => 'Missing product.' ) );
    }
    $existing = li_get_split_config( $product_id );
    if ( $existing ) {
        $r = li_db_patch( 'split_config?id=eq.' . urlencode( $existing['id'] ), array( 'rescue_percent' => $rp, 'larry_percent' => $lp ) );
    } else {
        $r = li_db_post( 'split_config', array( 'product_id' => $product_id, 'rescue_percent' => $rp, 'larry_percent' => $lp ) );
    }
    if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) >= 400 ) {
        wp_send_json_error( array( 'message' => wp_remote_retrieve_response_code( $r ) . ' ' . wp_remote_retrieve_body( $r ) ) );
    }
    wp_send_json_success();
}


add_action( 'wp_ajax_li_recalculate_splits', 'li_ajax_recalculate_splits' );
function li_ajax_recalculate_splits() {
    check_ajax_referer( 'li_recalculate_splits', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
    }
    $products = li_db_get( 'products?select=*' );
    if ( is_wp_error( $products ) ) {
        wp_send_json_error( array( 'message' => 'Could not load products.' ) );
    }
    $default_net = floatval( get_option( 'li_default_split', 55 ) );
    if ( $default_net <= 0 ) { $default_net = 55; }
    foreach ( $products as $p ) {
        $product_id = $p['id'] ?? '';
        $price = max( 1, intval( $p['price_cents'] ?? 0 ) );
        $cost  = max( 0, intval( $p['cost_cents'] ?? 0 ) );
        $cost_percent = ( $cost / $price ) * 100;
        $net_percent  = max( 0, 100 - $cost_percent );
        $rp = ( $net_percent > 0 ) ? ( $default_net * $net_percent / 100 ) : 0;
        $lp = max( 0, $net_percent - $rp );
        $payload = array(
            'rescue_percent' => round( $rp, 2 ),
            'larry_percent'  => round( $lp, 2 ),
        );
        $existing = li_get_split_config( $product_id );
        if ( $existing ) {
            li_db_patch( 'split_config?id=eq.' . urlencode( $existing['id'] ), $payload );
        } else {
            $payload['product_id'] = $product_id;
            li_db_post( 'split_config', $payload );
        }
    }
    wp_send_json_success( array( 'message' => 'Recalculated ' . count( $products ) . ' products.' ) );
}