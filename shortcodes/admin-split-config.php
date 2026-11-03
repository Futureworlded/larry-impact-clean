<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function li_get_all_split_configs_indexed() {
    $configs = li_db_get( 'split_config?select=*&limit=10000' );
    $by_product = array();
    if ( is_array( $configs ) ) {
        foreach ( $configs as $c ) {
            $pid = $c['product_id'] ?? '';
            if ( $pid ) {
                $by_product[ $pid ] = $c;
            }
        }
    }
    return $by_product;
}

function li_get_all_supabase_products() {
    $products = array();
    $limit    = 1000;
    $offset   = 0;
    do {
        $batch = li_db_get( 'products?select=*&limit=' . $limit . '&offset=' . $offset );
        if ( ! is_array( $batch ) || empty( $batch ) ) {
            break;
        }
        $products = array_merge( $products, $batch );
        $offset  += $limit;
    } while ( count( $batch ) === $limit );
    return $products;
}

function li_page_split_config() {
    $default_split = floatval( get_option( 'li_default_split', 55 ) );

    $wc_by_sku     = li_get_all_wc_products_by_sku();
    $db_products   = li_get_all_supabase_products();
    $split_configs = li_get_all_split_configs_indexed();
    $db_by_sku     = array();
    foreach ( $db_products as $p ) {
        $sku = $p['sku'] ?? '';
        if ( $sku ) {
            $db_by_sku[ $sku ] = $p;
        }
    }

    $rows = array();

    foreach ( $wc_by_sku as $sku => $wc ) {
        $name        = li_wc_product_display_name( $wc );
        $price_cents = max( 0, intval( round( floatval( $wc->get_price() ) * 100 ) ) );
        $wc_id       = $wc->get_id();

        $rescue_cents = 0;
        $meta = get_post_meta( $wc_id, '_li_rescue_cents', true );
        if ( '' !== $meta ) {
            $rescue_cents = max( 0, intval( $meta ) );
        }

        if ( $rescue_cents <= 0 && $price_cents > 0 ) {
            $db_product = $db_by_sku[ $sku ] ?? null;
            if ( $db_product ) {
                $sp = isset( $split_configs[ $db_product['id'] ] ) ? $split_configs[ $db_product['id'] ] : null;
                if ( $sp && floatval( $sp['rescue_percent'] ?? 0 ) > 0 ) {
                    $rescue_cents = intval( round( $price_cents * floatval( $sp['rescue_percent'] ) / 100 ) );
                } else {
                    $rescue_cents = li_get_rescue_cents_default( array( 'price_cents' => $price_cents, 'cost_cents' => 0 ), $default_split );
                }
            } else {
                $rescue_cents = li_get_rescue_cents_default( array( 'price_cents' => $price_cents, 'cost_cents' => 0 ), $default_split );
            }
        }

        $rows[ $sku ] = array(
            'sku'          => $sku,
            'product_id'   => isset( $db_by_sku[ $sku ] ) ? $db_by_sku[ $sku ]['id'] : '',
            'name'         => $name,
            'price'        => $price_cents,
            'rescue_cents' => $rescue_cents,
            'format'       => isset( $db_by_sku[ $sku ] ) ? ( $db_by_sku[ $sku ]['format'] ?? 'Merch' ) : 'Merch',
            'size_oz'      => isset( $db_by_sku[ $sku ] ) ? ( $db_by_sku[ $sku ]['size_oz'] ?? '' ) : '',
            'in_wc'        => true,
        );
    }

    foreach ( $db_by_sku as $sku => $p ) {
        if ( isset( $rows[ $sku ] ) ) {
            continue;
        }
        if ( isset( $p['active'] ) && $p['active'] === false ) {
            continue;
        }
        $price_cents  = max( 0, intval( $p['price_cents'] ?? 0 ) );
        $rescue_cents = 0;
        $sp = isset( $split_configs[ $p['id'] ] ) ? $split_configs[ $p['id'] ] : null;
        if ( $sp && floatval( $sp['rescue_percent'] ?? 0 ) > 0 ) {
            $rescue_cents = intval( round( $price_cents * floatval( $sp['rescue_percent'] ) / 100 ) );
        } else {
            $rescue_cents = li_get_rescue_cents_default( $p, $default_split );
        }
        $rows[ $sku ] = array(
            'sku'          => $sku,
            'product_id'   => $p['id'] ?? '',
            'name'         => $p['name'] ?? $sku,
            'price'        => $price_cents,
            'rescue_cents' => $rescue_cents,
            'format'       => $p['format'] ?? 'Merch',
            'size_oz'      => $p['size_oz'] ?? '',
            'in_wc'        => false,
        );
    }

    uasort( $rows, function( $a, $b ) {
        return strnatcasecmp( $a['name'], $b['name'] );
    } );

    $ajax_url = esc_js( admin_url( 'admin-ajax.php' ) );
    $nonce    = esc_js( wp_create_nonce( 'li_save_split_configs' ) );

    ob_start();
    echo '<p style="font-size:13px;color:#6b6560;margin-bottom:20px;">Set the fixed dollar amount that goes to the rescue for each product. Larry Impact receives the remainder of the sale. The list is built from the current WooCommerce catalog, so it always reflects the products that actually exist.</p>';
    echo '<div style="margin-bottom:16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">';
    echo '<button class="li-btn" style="background:#b91c1c;" onclick="liScCleanup()">Clean up obsolete products</button>';
    echo '<span style="font-size:12px;color:#a89880;">This hides every product whose SKU is not a published WooCommerce product.</span>';
    echo '</div>';
    echo '<div class="li-table-wrap"><table class="li-table"><thead><tr>';
    echo '<th>Product</th><th>SKU</th><th>Sale price</th><th>Amount to rescue</th><th>Larry keeps</th><th>Actions</th>';
    echo '</tr></thead><tbody id="li-sc-tbody">';
    foreach ( $rows as $r ) {
        $dollars     = $r['rescue_cents'] > 0 ? number_format( $r['rescue_cents'] / 100, 2 ) : '';
        $larry_cents = max( 0, $r['price'] - $r['rescue_cents'] );
        $format      = esc_html( $r['format'] );
        $size        = ! empty( $r['size_oz'] ) ? esc_html( $r['size_oz'] ) . 'oz' : '';
        $missing     = ! $r['in_wc'] ? ' <span style="font-size:11px;color:#b91c1c;font-weight:600;">(not in WooCommerce)</span>' : '';
        echo '<tr data-sku="' . esc_attr( $r['sku'] ) . '" data-product-id="' . esc_attr( $r['product_id'] ) . '">';
        echo '<td><strong>' . esc_html( $r['name'] ) . '</strong>' . $missing . '<br><span style="font-size:11px;color:#a89880;">' . esc_html( $format ) . ( $size ? ' ' . $size : '' ) . '</span></td>';
        echo '<td style="font-size:11px;color:#a89880;">' . esc_html( $r['sku'] ) . '</td>';
        echo '<td>$' . number_format( $r['price'] / 100, 2 ) . '</td>';
        echo '<td><input type="number" class="li-sc-input" data-price="' . esc_attr( $r['price'] ) . '" data-initial="' . esc_attr( $dollars ) . '" min="0" step="0.01" value="' . esc_attr( $dollars ) . '" style="width:110px;border:1px solid #ddd8d0;border-radius:6px;padding:6px;font-size:13px;"' . ( $r['in_wc'] ? '' : ' disabled' ) . ' /> <span style="font-size:12px;color:#6b6560;">per unit</span></td>';
        echo '<td class="li-sc-larry">$' . number_format( $larry_cents / 100, 2 ) . '</td>';
        echo '<td>';
        if ( $r['in_wc'] ) {
            echo '<span style="font-size:12px;color:#3a7a4a;">Active</span>';
        } else {
            echo '<button type="button" class="li-btn li-btn-sm" onclick="liScHide(this)" style="background:#b91c1c;">Hide</button>';
        }
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    echo '<div style="margin-top:16px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;">';
    echo '<button id="li-sc-save" class="li-btn" onclick="liScSave()">Save changes</button>';
    echo '<button class="li-btn" style="background:#8B4A09;" onclick="liScDefaults()">Reset all to defaults</button>';
    echo '<div id="li-sc-msg" style="font-size:13px;display:none;"></div>';
    echo '</div>';
    echo '<script>
    var LI_AJAX="' . $ajax_url . '";
    var LI_SC_NONCE="' . $nonce . '";
    var LI_SC_DRAFT_KEY="li_split_amount_draft_v1";
    var liScDraft={};
    try{liScDraft=JSON.parse(localStorage.getItem(LI_SC_DRAFT_KEY)||"{}");}catch(e){liScDraft={};}
    function liScStoreDraft(){
        try{localStorage.setItem(LI_SC_DRAFT_KEY,JSON.stringify(liScDraft));}catch(e){}
    }
    function liScMarkDirty(input){
        var row=input.closest("tr");
        if(!row||!row.dataset.sku)return;
        liScDraft[row.dataset.sku]=Math.round((parseFloat(input.value)||0)*100);
        liScStoreDraft();
        var msg=document.getElementById("li-sc-msg");
        msg.style.display="inline";msg.style.color="#8B4A09";
        msg.textContent=Object.keys(liScDraft).length+" unsaved change(s).";
    }
    function liScUpdateRow(input){
        var price=parseInt(input.dataset.price)||0;
        var rc=Math.round((parseFloat(input.value)||0)*100);
        var larry=Math.max(0,price-rc);
        input.closest("tr").querySelector(".li-sc-larry").textContent="$"+(larry/100).toFixed(2);
    }
    document.querySelectorAll(".li-sc-input").forEach(function(input){
        var row=input.closest("tr"),sku=row?row.dataset.sku:"";
        if(sku&&Object.prototype.hasOwnProperty.call(liScDraft,sku)){
            input.value=(parseInt(liScDraft[sku],10)/100).toFixed(2);
            liScUpdateRow(input);
        }
        input.addEventListener("input",function(){liScUpdateRow(input);liScMarkDirty(input);});
    });
    if(Object.keys(liScDraft).length){
        var restored=document.getElementById("li-sc-msg");
        restored.style.display="inline";restored.style.color="#8B4A09";
        restored.textContent=Object.keys(liScDraft).length+" unsaved change(s) restored.";
    }
    async function liScSave(){
        var msg=document.getElementById("li-sc-msg");
        var btn=document.getElementById("li-sc-save");
        var skus=Object.keys(liScDraft);
        if(!skus.length){msg.style.display="inline";msg.style.color="#3a7a4a";msg.textContent="No unsaved changes.";return;}
        btn.disabled=true;msg.style.display="inline";msg.style.color="#6b6560";
        try{
            var total=skus.length,saved=0;
            for(var i=0;i<skus.length;i+=10){
                var batchSkus=skus.slice(i,i+10),payload={};
                batchSkus.forEach(function(sku){payload[sku]=liScDraft[sku];});
                msg.textContent="Saving "+Math.min(i+batchSkus.length,total)+" of "+total+" changes...";
                var fd=new FormData();
                fd.append("action","li_save_split_configs");
                fd.append("nonce",LI_SC_NONCE);
                fd.append("payload",JSON.stringify(payload));
                var res=await fetch(LI_AJAX,{method:"POST",body:fd});
                var json=null;
                try{json=await res.json();}catch(parseError){throw new Error("The server returned an invalid response. Your unsaved changes are preserved.");}
                if(!res.ok||!json.success){
                    var detail=json&&json.data&&json.data.message?json.data.message:("Save failed: "+res.status);
                    if(res.status===403)detail="Your login or page security token expired. Reload this page; your unsaved changes will be restored.";
                    throw new Error(detail);
                }
                batchSkus.forEach(function(sku){
                    var row=document.querySelector("#li-sc-tbody tr[data-sku="+CSS.escape(sku)+"]");
                    if(row){var input=row.querySelector(".li-sc-input");if(input)input.dataset.initial=(liScDraft[sku]/100).toFixed(2);}
                    delete liScDraft[sku];
                });
                saved+=batchSkus.length;liScStoreDraft();
            }
            msg.style.display="inline";msg.style.color="#3a7a4a";msg.textContent=saved+" change(s) saved successfully.";
        }catch(e){msg.style.display="inline";msg.style.color="#b91c1c";msg.textContent=e.message||"Save failed. Your unsaved changes are preserved.";}
        finally{btn.disabled=false;}
    }
    async function liScDefaults(){
        if(!confirm("This will reset every product to the default payout based on the current price and default split. Continue?")){return;}
        var msg=document.getElementById("li-sc-msg");
        msg.style.display="none";
        try{
            var fd=new FormData();
            fd.append("action","li_recalculate_splits");
            fd.append("nonce",LI_SC_NONCE);
            var res=await fetch(LI_AJAX,{method:"POST",body:fd});
            var json=await res.json();
            if(!res.ok||!json.success){throw new Error(json.data&&json.data.message?json.data.message:"Reset failed");}
            msg.style.display="inline";msg.style.color="#3a7a4a";msg.textContent="Defaults applied. Reloading...";
            setTimeout(function(){window.location.reload();},1000);
        }catch(e){msg.style.display="inline";msg.style.color="#b91c1c";msg.textContent=e.message||"Reset failed.";}
    }
    async function liScHide(btn){
        var row=btn.closest("tr");
        var product_id=row.dataset.productId;
        if(!confirm("Hide this product from the configurator?")){return;}
        btn.disabled=true;
        try{
            var fd=new FormData();
            fd.append("action","li_hide_split_product");
            fd.append("nonce",LI_SC_NONCE);
            fd.append("product_id",product_id);
            var res=await fetch(LI_AJAX,{method:"POST",body:fd});
            var json=await res.json();
            if(!res.ok||!json.success){throw new Error(json.data&&json.data.message?json.data.message:"Hide failed");}
            row.remove();
        }catch(e){
            btn.disabled=false;
            alert(e.message||"Hide failed.");
        }
    }
    async function liScCleanup(){
        if(!confirm("This will hide every product whose SKU is not a published WooCommerce product. Continue?")){return;}
        var msg=document.getElementById("li-sc-msg");
        msg.style.display="none";
        try{
            var fd=new FormData();
            fd.append("action","li_cleanup_split_products");
            fd.append("nonce",LI_SC_NONCE);
            var res=await fetch(LI_AJAX,{method:"POST",body:fd});
            var json=await res.json();
            if(!res.ok||!json.success){throw new Error(json.data&&json.data.message?json.data.message:"Cleanup failed");}
            msg.style.display="inline";msg.style.color="#3a7a4a";msg.textContent="Hidden "+(json.data&&json.data.count?json.data.count:0)+" obsolete products. Reloading...";
            setTimeout(function(){window.location.reload();},1500);
        }catch(e){msg.style.display="inline";msg.style.color="#b91c1c";msg.textContent=e.message||"Cleanup failed.";}
    }
    </script>';
    li_admin_wrap( 'Split Configurator', ob_get_clean() );
}

add_action( 'wp_ajax_li_save_split_configs', 'li_ajax_save_split_configs' );
function li_ajax_save_split_configs() {
    check_ajax_referer( 'li_save_split_configs', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
    }
    $raw = isset( $_POST['payload'] ) ? wp_unslash( $_POST['payload'] ) : '';
    $payload = json_decode( $raw, true );
    if ( ! is_array( $payload ) ) {
        wp_send_json_error( array( 'message' => 'Invalid data.' ) );
    }

    $db_products   = li_get_all_supabase_products();
    $db_by_sku     = array();
    foreach ( $db_products as $p ) {
        $sku = $p['sku'] ?? '';
        if ( $sku ) {
            $db_by_sku[ $sku ] = $p;
        }
    }
    $split_configs = li_get_all_split_configs_indexed();

    $saved = 0;
    foreach ( $payload as $sku => $rescue_cents ) {
        $sku          = sanitize_text_field( $sku );
        $rescue_cents = max( 0, intval( $rescue_cents ) );
        if ( ! $sku ) {
            continue;
        }

        $product = isset( $db_by_sku[ $sku ] ) ? $db_by_sku[ $sku ] : null;
        if ( ! $product && function_exists( 'wc_get_product_id_by_sku' ) ) {
            $wc_id = wc_get_product_id_by_sku( $sku );
            if ( $wc_id ) {
                li_sync_product( $wc_id );
                $product = li_get_product_by_sku( $sku );
                if ( $product ) {
                    $db_by_sku[ $sku ] = $product;
                }
            }
        }
        if ( ! $product ) {
            continue;
        }

        $product_id  = $product['id'];
        $price_cents = max( 1, intval( $product['price_cents'] ?? 1 ) );

        if ( function_exists( 'wc_get_product_id_by_sku' ) ) {
            $wc_id = wc_get_product_id_by_sku( $sku );
            if ( $wc_id ) {
                update_post_meta( $wc_id, '_li_rescue_cents', $rescue_cents );
                $wc_product = wc_get_product( $wc_id );
                if ( $wc_product ) {
                    $price_cents = max( 1, intval( round( floatval( $wc_product->get_price() ) * 100 ) ) );
                    li_db_patch( 'products?id=eq.' . urlencode( $product_id ), array(
                        'name'        => li_wc_product_display_name( $wc_product ),
                        'price_cents' => $price_cents,
                        'active'      => true,
                    ) );
                }
            }
        }

        $cost           = max( 0, intval( $product['cost_cents'] ?? 0 ) );
        $cost_percent   = ( $cost / $price_cents ) * 100;
        $net_percent    = max( 0, 100 - $cost_percent );
        $rescue_percent = ( $rescue_cents / $price_cents ) * 100;
        $rescue_percent = min( $rescue_percent, $net_percent );
        $larry_percent  = max( 0, $net_percent - $rescue_percent );
        $existing = isset( $split_configs[ $product_id ] ) ? $split_configs[ $product_id ] : null;
        if ( $existing ) {
            $r = li_db_patch( 'split_config?id=eq.' . urlencode( $existing['id'] ), array( 'rescue_percent' => round( $rescue_percent, 4 ), 'larry_percent' => round( $larry_percent, 4 ) ) );
        } else {
            $r = li_db_post( 'split_config', array( 'product_id' => $product_id, 'rescue_percent' => round( $rescue_percent, 4 ), 'larry_percent' => round( $larry_percent, 4 ) ) );
            if ( ! is_wp_error( $r ) && wp_remote_retrieve_response_code( $r ) < 400 ) {
                $split_configs[ $product_id ] = array( 'id' => null );
            }
        }
        $response_code = wp_remote_retrieve_response_code( $r );
        if ( is_wp_error( $r ) || $response_code < 200 || $response_code >= 300 ) {
            $reason = is_wp_error( $r ) ? $r->get_error_message() : trim( wp_remote_retrieve_body( $r ) );
            li_log( 'Split amount save failed for SKU ' . $sku . ': ' . $response_code . ' ' . $reason );
            wp_send_json_error( array( 'message' => 'Could not save ' . $sku . '. ' . $reason, 'saved' => $saved ), 502 );
        }
        li_create_split_version( $product_id, round( $rescue_percent, 4 ), round( $larry_percent, 4 ), round( $cost_percent, 4 ) );
        $saved++;
    }
    wp_send_json_success( array( 'message' => 'Saved ' . $saved . ' products.' ) );
}

add_action( 'wp_ajax_li_recalculate_splits', 'li_ajax_recalculate_splits' );
function li_ajax_recalculate_splits() {
    check_ajax_referer( 'li_save_split_configs', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
    }
    $products = li_db_get( 'products?select=*&limit=10000' );
    if ( ! is_array( $products ) ) {
        $products = array();
    }
    foreach ( $products as $p ) {
        $product_id  = $p['id'] ?? '';
        $price_cents = intval( $p['price_cents'] ?? 0 );
        $cost_cents  = intval( $p['cost_cents'] ?? 0 );
        if ( ! $product_id ) {
            continue;
        }
        li_ensure_split_config( $product_id, $price_cents, $cost_cents, true );
    }
    wp_send_json_success( array( 'message' => 'Reset ' . count( $products ) . ' products to defaults.' ) );
}

add_action( 'wp_ajax_li_hide_split_product', 'li_ajax_hide_split_product' );
function li_ajax_hide_split_product() {
    check_ajax_referer( 'li_save_split_configs', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
    }
    $product_id = sanitize_text_field( $_POST['product_id'] ?? '' );
    if ( ! $product_id ) {
        wp_send_json_error( array( 'message' => 'Invalid product.' ) );
    }
    $r = li_db_patch( 'products?id=eq.' . urlencode( $product_id ), array( 'active' => false ) );
    if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) >= 400 ) {
        wp_send_json_error( array( 'message' => 'Could not hide product.' ) );
    }
    wp_send_json_success( array( 'message' => 'Product hidden.' ) );
}

add_action( 'wp_ajax_li_cleanup_split_products', 'li_ajax_cleanup_split_products' );
function li_ajax_cleanup_split_products() {
    check_ajax_referer( 'li_save_split_configs', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
    }
    $count = li_deactivate_orphan_products();
    wp_send_json_success( array( 'message' => 'Hidden ' . $count . ' obsolete products.', 'count' => $count ) );
}
