<?php
// Larry Impact - Rescue Selection Modal

if ( ! defined( 'ABSPATH' ) ) exit;

// Auto-set rescue cookie from rescue partner pages

add_action( 'template_redirect', 'li_set_rescue_cookie_early', 20 );
add_action( 'wp_footer', 'li_auto_set_rescue_from_page', 5 );

function li_set_rescue_cookie_early() {
    if ( isset( $_GET['fl_builder'] ) ) return;
    if ( is_singular() ) {
        $post_id = get_the_ID();
        $slug    = get_post_meta( $post_id, '_li_rescue_slug', true );
        $name    = get_the_title( $post_id );
        if ( $slug ) {
            if ( function_exists( 'WC' ) && WC()->session ) {
                WC()->session->set( 'li_rescue_slug', $slug );
                WC()->session->set( 'li_rescue_name', $name );
            }
            $exp = time() + ( 30 * DAY_IN_SECONDS );
            setcookie( 'li_rescue_slug', $slug, $exp, '/' );
            setcookie( 'li_rescue_name', $name, $exp, '/' );
            if ( isset( $_COOKIE['li_rescue_skipped'] ) ) {
                setcookie( 'li_rescue_skipped', '', time() - 3600, '/' );
            }
        }
    }
    if ( ! empty( $_GET['rescue'] ) ) {
        $slug = sanitize_text_field( $_GET['rescue'] );
        $name = '';
        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->set( 'li_rescue_slug', $slug );
        }
        setcookie( 'li_rescue_slug', $slug, time() + ( 30 * DAY_IN_SECONDS ), '/' );
        $rescue = li_get_rescue_by_slug( $slug );
        if ( $rescue ) {
            $name = $rescue['name'] ?? '';
            setcookie( 'li_rescue_name', $name, time() + ( 30 * DAY_IN_SECONDS ), '/' );
            if ( function_exists( 'WC' ) && WC()->session ) {
                WC()->session->set( 'li_rescue_name', $name );
            }
        }
        if ( isset( $_COOKIE['li_rescue_skipped'] ) ) {
            setcookie( 'li_rescue_skipped', '', time() - 3600, '/' );
        }
    }
}

function li_auto_set_rescue_from_page() {
    if ( isset( $_GET['fl_builder'] ) ) return;
    if ( is_singular( 'post' ) ) {
        $post_id = get_the_ID();
        $slug    = get_post_meta( $post_id, '_li_rescue_slug', true );
        $name    = get_the_title( $post_id );
        if ( $slug ) {
            if ( function_exists( 'WC' ) && WC()->session ) {
                WC()->session->set( 'li_rescue_slug', $slug );
                WC()->session->set( 'li_rescue_name', $name );
            }
            echo '<script>
(function(){
    var slug = ' . json_encode( $slug ) . ';
    var name = ' . json_encode( $name ) . ';
    var exp  = new Date(); exp.setTime(exp.getTime()+(30*24*60*60*1000));
    var e    = "; expires="+exp.toUTCString();
    document.cookie="li_rescue_slug="+encodeURIComponent(slug)+e+"; path=/";
    document.cookie="li_rescue_name="+encodeURIComponent(name)+e+"; path=/";
    document.cookie="li_rescue_skipped=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/";
})();
</script>' . "\n";
        }
    }
    if ( ! empty( $_GET['rescue'] ) ) {
        $slug = sanitize_text_field( $_GET['rescue'] );
        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->set( 'li_rescue_slug', $slug );
        }
        echo '<script>
(function(){
    var slug=' . json_encode( $slug ) . ';
    var exp=new Date(); exp.setTime(exp.getTime()+(30*24*60*60*1000));
    var e="; expires="+exp.toUTCString();
    document.cookie="li_rescue_slug="+encodeURIComponent(slug)+e+"; path=/";
})();
</script>' . "\n";
    }
}

// Show rescue confirmation banner on checkout/cart

add_action( 'wp_footer', 'li_rescue_confirmation_banner', 15 );

function li_rescue_confirmation_banner() {
    if ( isset( $_GET['fl_builder'] ) ) return;
    if ( is_admin() ) return;
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $is_shop = strpos($uri,'/cart') !== false || strpos($uri,'/checkout') !== false
        || strpos($uri,'/product') !== false || strpos($uri,'/impactlist') !== false
        || strpos($uri,'/merch') !== false || strpos($uri,'/coffee') !== false
        || strpos($uri,'/shop') !== false;
    if ( ! $is_shop ) return;
    ?>
    <div id="liRescueBanner" style="display:none;position:fixed;bottom:0;left:0;right:0;background:#0d0e0c;border-top:2px solid #CEA83D;padding:10px 24px;z-index:99998;font-family:Arial,sans-serif;display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
        <span style="font-size:11px;font-weight:900;letter-spacing:2px;text-transform:uppercase;color:#CEA83D;">🐾 Supporting:</span>
        <span id="liRescueBannerName" style="font-size:14px;font-weight:700;color:#fff;"></span>
        <button onclick="liChangeBannerRescue()" style="font-size:11px;color:#CEA83D;text-decoration:underline;background:none;border:none;cursor:pointer;font-family:Arial,sans-serif;padding:0;">Change</button>
        <button onclick="document.getElementById('liRescueBanner').style.display='none'" style="margin-left:auto;font-size:18px;color:#888;background:none;border:none;cursor:pointer;line-height:1;padding:0;">×</button>
    </div>
    <script>
    (function(){
        function getCookie(n){var v=document.cookie.match('(^|;) ?'+n+'=([^;]*)(;|$)');return v?decodeURIComponent(v[2]):null;}
        function showBanner(){
            var name=getCookie('li_rescue_name');
            if(!name)return;
            var banner=document.getElementById('liRescueBanner');
            var nameEl=document.getElementById('liRescueBannerName');
            if(banner&&nameEl){
                nameEl.textContent=name;
                banner.style.display='flex';
            }
        }
        window.liChangeBannerRescue=function(){
            // clear cookie and reload to show modal
            document.cookie='li_rescue_slug=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/';
            document.cookie='li_rescue_name=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/';
            document.cookie='li_rescue_skipped=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/';
            location.reload();
        };
        document.addEventListener('DOMContentLoaded', showBanner);
    })();
    </script>
    <?php
}

// Output modal on shop pages

add_action( 'wp_footer', 'li_rescue_modal_output', 20 );

function li_rescue_modal_output() {
    if ( is_admin() ) return;
    if ( isset( $_GET['fl_builder'] ) ) return;

    $nonce = wp_create_nonce( 'li_cart_rescue' );
    $ajax  = admin_url( 'admin-ajax.php' );
    ?>
    <style>
    .li-modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(13,14,12,0.82);
        z-index: 999999;
        align-items: center;
        justify-content: center;
        padding: 20px;
        font-family: Arial, sans-serif;
    }
    .li-modal-overlay.active { display: flex; }
    .li-modal-box {
        background: #fff;
        border-radius: 14px;
        padding: 40px 36px 32px;
        max-width: 700px;
        width: 100%;
        max-height: 90vh;
        overflow-y: auto;
        position: relative;
        animation: liModalIn 0.25s ease;
        box-shadow: 0 24px 64px rgba(0,0,0,0.35);
    }
    @keyframes liModalIn {
        from { opacity:0; transform:translateY(20px); }
        to   { opacity:1; transform:translateY(0); }
    }
    .li-modal-close {
        position: absolute;
        top: 14px; right: 18px;
        font-size: 24px; color: #aaa;
        cursor: pointer; background: none; border: none; line-height: 1; padding: 4px;
    }
    .li-modal-close:hover { color: #1a1a1a; }
    .li-modal-eyebrow {
        font-size: 11px; font-weight: 900; letter-spacing: 3px;
        text-transform: uppercase; color: #CEA83D; margin: 0 0 10px;
    }
    .li-modal-title {
        font-size: clamp(20px, 3vw, 28px); font-weight: 900; color: #1a1a1a;
        margin: 0 0 8px; text-transform: uppercase; line-height: 1.1;
    }
    .li-modal-sub {
        font-size: 13px; color: #666; margin: 0 0 24px; line-height: 1.65; max-width: 520px;
    }
    .li-modal-rescues {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 14px; margin-bottom: 16px;
    }
    .li-modal-rescue-card {
        border: 2px solid #ede8e0; border-radius: 10px; overflow: hidden;
        cursor: pointer; transition: border-color 0.2s, transform 0.18s, box-shadow 0.18s;
        text-align: center; background: #fff;
    }
    .li-modal-rescue-card:hover {
        border-color: #CEA83D; transform: translateY(-3px);
        box-shadow: 0 6px 20px rgba(206,168,61,0.18);
    }
    .li-modal-rescue-card.selected {
        border-color: #CEA83D; background: #faf7f2;
    }
    .li-modal-rescue-hero {
        width: 100%; height: 90px; background: #1B4D2E;
        background-size: cover; background-position: center;
    }
    .li-modal-rescue-logo {
        width: 54px; height: 54px; border-radius: 50%;
        border: 2px solid #CEA83D; background: #fff;
        margin: -27px auto 8px; position: relative; z-index: 2;
        overflow: hidden; display: flex; align-items: center; justify-content: center;
        font-size: 22px; box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    }
    .li-modal-rescue-logo img { width:100%; height:100%; object-fit:cover; }
    .li-modal-rescue-check {
        display: none; width: 20px; height: 20px; background: #CEA83D;
        border-radius: 50%; color: #0d0e0c; font-size: 12px; font-weight: 900;
        align-items: center; justify-content: center; margin: 0 auto 6px;
    }
    .li-modal-rescue-card.selected .li-modal-rescue-check { display: flex; }
    .li-modal-rescue-name {
        font-size: 11px; font-weight: 900; color: #1a1a1a;
        text-transform: uppercase; letter-spacing: 0.5px;
        padding: 0 8px; margin: 0 0 3px; line-height: 1.3;
    }
    .li-modal-rescue-location {
        font-size: 10px; color: #CEA83D; font-weight: 700; padding: 0 8px 8px;
    }
    .li-modal-rescue-mission {
        font-size: 11px; color: #888; line-height: 1.5; padding: 0 10px 12px;
    }
    .li-modal-divider {
        display: flex; align-items: center; gap: 12px; margin: 4px 0 16px;
    }
    .li-modal-divider-line {
        flex: 1; height: 1px; background: #ede8e0;
    }
    .li-modal-divider-text {
        font-size: 11px; color: #aaa; font-weight: 700;
        letter-spacing: 1px; text-transform: uppercase; white-space: nowrap;
    }
    .li-modal-dropdown-row {
        display: flex; gap: 10px; align-items: center; margin-bottom: 20px;
    }
    .li-modal-dropdown {
        flex: 1; border: 1px solid #ddd8d0; border-radius: 6px;
        padding: 10px 12px; font-size: 13px; font-family: Arial, sans-serif;
        color: #333; background: #fff; outline: none;
    }
    .li-modal-dropdown:focus { border-color: #CEA83D; }
    .li-modal-loading {
        text-align: center; padding: 40px 20px; color: #888;
        font-size: 14px; grid-column: 1 / -1;
    }
    .li-modal-btn {
        width: 100%; background: #0d0e0c; color: #CEA83D; border: none;
        border-radius: 8px; padding: 14px; font-size: 13px; font-weight: 900;
        letter-spacing: 1.5px; text-transform: uppercase; cursor: pointer;
        font-family: Arial, sans-serif; transition: background 0.2s, color 0.2s;
    }
    .li-modal-btn:hover:not(:disabled) { background: #CEA83D; color: #0d0e0c; }
    .li-modal-btn:disabled { background: #ddd; color: #aaa; cursor: not-allowed; }
    .li-modal-skip {
        text-align: center; margin-top: 12px; font-size: 12px; color: #bbb;
    }
    .li-modal-skip button {
        color: #bbb; text-decoration: underline; cursor: pointer;
        background: none; border: none; font-size: 12px;
        font-family: Arial, sans-serif; padding: 0;
    }
    .li-modal-skip button:hover { color: #888; }
    @media(max-width:600px){
        .li-modal-box { padding: 32px 18px 24px; }
        .li-modal-rescues { grid-template-columns: 1fr; }
    }
    </style>

    <div class="li-modal-overlay" id="liRescueModal" role="dialog" aria-modal="true">
        <div class="li-modal-box">
            <button class="li-modal-close" onclick="liModalSkip()" aria-label="Close">×</button>
            <p class="li-modal-eyebrow">🐾 Before You Shop</p>
            <h2 class="li-modal-title">Choose a Rescue to Support</h2>
            <p class="li-modal-sub">Every purchase earns revenue and ownership points for your chosen rescue. 55% of net profits go directly to them.</p>

            <div class="li-modal-rescues" id="liModalRescues">
                <div class="li-modal-loading">Loading rescue partners...</div>
            </div>

            <div class="li-modal-divider" id="liModalDivider" style="display:none;">
                <div class="li-modal-divider-line"></div>
                <span class="li-modal-divider-text">Or choose any rescue</span>
                <div class="li-modal-divider-line"></div>
            </div>

            <div class="li-modal-dropdown-row" id="liModalDropdownRow" style="display:none;">
                <select class="li-modal-dropdown" id="liModalDropdown" onchange="liSelectFromDropdown()">
                    <option value="">-- All rescue partners --</option>
                </select>
            </div>

            <button class="li-modal-btn" id="liModalBtn" onclick="liModalConfirm()" disabled>
                Choose a Rescue to Continue
            </button>
            <div class="li-modal-skip">
                <button onclick="liModalSkip()">Skip for now - I'll choose later</button>
            </div>
        </div>
    </div>

    <script>
    (function() {
        var SUPABASE_URL = 'https://sjcdeetrkztmbtmjbcgd.supabase.co';
        var SUPABASE_KEY = '<?php echo esc_js( LI_DB_KEY ); ?>';
        var AJAX_URL    = '<?php echo esc_js( $ajax ); ?>';
        var NONCE       = '<?php echo esc_js( $nonce ); ?>';
        var CARDS_TO_SHOW = 3;

        var selectedSlug = '';
        var selectedName = '';
        var allRescues   = [];

        // Cookie helpers
        function getCookie(name) {
            var v = document.cookie.match('(^|;) ?' + name + '=([^;]*)(;|$)');
            return v ? decodeURIComponent(v[2]) : null;
        }
        function setCookie(name, value, days) {
            var exp = '';
            if (days) {
                var d = new Date(); d.setTime(d.getTime() + (days*24*60*60*1000));
                exp = '; expires=' + d.toUTCString();
            }
            document.cookie = name + '=' + encodeURIComponent(value) + exp + '; path=/';
        }
        function deleteCookie(name) {
            document.cookie = name + '=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/';
        }

        // Page detection via URL
        function isShopPage() {
            var path = window.location.pathname;
            var shopPaths = ['/cart', '/checkout', '/product-category', '/product/', '/impactlist', '/merch', '/coffee', '/shop'];
            for (var i = 0; i < shopPaths.length; i++) {
                if (path.indexOf(shopPaths[i]) !== -1) return true;
            }
            return false;
        }

        // Already chosen?
        function alreadyChosen() {
            return !!getCookie('li_rescue_slug') || !!getCookie('li_rescue_skipped');
        }

        // Pick 3 random rescues for cards
        function pickRandom(arr, n) {
            var shuffled = arr.slice().sort(function() { return Math.random() - 0.5; });
            return shuffled.slice(0, n);
        }

        // Render 3 cards
        function renderCards(featured) {
            var container = document.getElementById('liModalRescues');
            if (!featured || !featured.length) {
                container.innerHTML = '<div class="li-modal-loading">No rescue partners found.</div>';
                return;
            }
            container.innerHTML = featured.map(function(r) {
                var loc      = [r.city, r.state].filter(Boolean).join(', ');
                var heroSt   = r.hero_photo_url ? 'background-image:url(' + r.hero_photo_url + ')' : 'background:#1B4D2E';
                var logoHtml = r.logo_url ? '<img src="' + r.logo_url + '" alt="' + r.name + '" />' : '🐾';
                var mission  = r.mission ? '<div class="li-modal-rescue-mission">' + r.mission.substring(0,80) + (r.mission.length > 80 ? '...' : '') + '</div>' : '';
                return '<div class="li-modal-rescue-card" onclick="liSelectRescue(\'' + r.slug + '\',\'' + r.name.replace(/'/g,"\\'") + '\')" data-slug="' + r.slug + '">'
                    + '<div class="li-modal-rescue-hero" style="' + heroSt + '"></div>'
                    + '<div class="li-modal-rescue-logo">' + logoHtml + '</div>'
                    + '<div class="li-modal-rescue-check">✓</div>'
                    + '<div class="li-modal-rescue-name">' + r.name + '</div>'
                    + (loc ? '<div class="li-modal-rescue-location">📍 ' + loc + '</div>' : '')
                    + mission
                    + '</div>';
            }).join('');
        }

        // Populate dropdown with all rescues
        function populateDropdown(rescues) {
            var select = document.getElementById('liModalDropdown');
            rescues.forEach(function(r) {
                var opt = document.createElement('option');
                opt.value = r.slug;
                opt.dataset.name = r.name;
                opt.textContent = r.name + (r.city ? ' — ' + r.city + (r.state ? ', ' + r.state : '') : '');
                select.appendChild(opt);
            });
            document.getElementById('liModalDivider').style.display = 'flex';
            document.getElementById('liModalDropdownRow').style.display = 'flex';
        }

        // Select from card
        window.liSelectRescue = function(slug, name) {
            selectedSlug = slug;
            selectedName = name;
            // clear dropdown
            document.getElementById('liModalDropdown').value = '';
            // update card UI
            document.querySelectorAll('.li-modal-rescue-card').forEach(function(c) {
                c.classList.toggle('selected', c.dataset.slug === slug);
            });
            var btn = document.getElementById('liModalBtn');
            btn.disabled = false;
            btn.textContent = 'Support ' + name + ' →';
        };

        // Select from dropdown
        window.liSelectFromDropdown = function() {
            var select = document.getElementById('liModalDropdown');
            var slug   = select.value;
            if (!slug) return;
            var name   = select.options[select.selectedIndex].dataset.name || select.options[select.selectedIndex].text.split(' — ')[0];
            selectedSlug = slug;
            selectedName = name;
            // deselect cards
            document.querySelectorAll('.li-modal-rescue-card').forEach(function(c) {
                c.classList.remove('selected');
            });
            var btn = document.getElementById('liModalBtn');
            btn.disabled = false;
            btn.textContent = 'Support ' + name + ' →';
        };

        // Confirm
        window.liModalConfirm = function() {
            if (!selectedSlug) return;
            setCookie('li_rescue_slug', selectedSlug, 30);
            setCookie('li_rescue_name', selectedName, 30);
            deleteCookie('li_rescue_skipped');

            // Sync to WooCommerce session
            fetch(AJAX_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=li_save_cart_rescue'
                    + '&nonce=' + encodeURIComponent(NONCE)
                    + '&rescue_slug=' + encodeURIComponent(selectedSlug)
                    + '&rescue_name=' + encodeURIComponent(selectedName)
            });

            document.getElementById('liRescueModal').classList.remove('active');

            // Show the banner immediately
            var banner = document.getElementById('liRescueBanner');
            var nameEl = document.getElementById('liRescueBannerName');
            if (banner && nameEl) {
                nameEl.textContent = selectedName;
                banner.style.display = 'flex';
            }
        };

        // Skip
        window.liModalSkip = function() {
            setCookie('li_rescue_skipped', '1', 0);
            document.getElementById('liRescueModal').classList.remove('active');
        };

        // Close on overlay click
        document.getElementById('liRescueModal').addEventListener('click', function(e) {
            if (e.target === this) liModalSkip();
        });

        // Init
        function init() {
            if (!isShopPage()) return;
            if (alreadyChosen()) return;

            fetch(SUPABASE_URL + '/rest/v1/rescues?select=id,name,slug,city,state,mission,logo_url,hero_photo_url&status=eq.approved&order=name.asc', {
                headers: { 'apikey': SUPABASE_KEY, 'Authorization': 'Bearer ' + SUPABASE_KEY }
            })
            .then(function(r) { return r.json(); })
            .then(function(rescues) {
                if (!rescues || !rescues.length) return;
                allRescues = rescues;

                // Pick 3 random for cards
                var featured = pickRandom(rescues, CARDS_TO_SHOW);
                renderCards(featured);

                // Show dropdown only if more than 3 rescues
                if (rescues.length > CARDS_TO_SHOW) {
                    populateDropdown(rescues);
                }

                document.getElementById('liRescueModal').classList.add('active');
            })
            .catch(function() { /* Supabase unreachable - skip silently */ });
        }

        document.addEventListener('DOMContentLoaded', init);
    })();
    </script>
    <?php
}

// Read cookie into WooCommerce session on every page load

add_action( 'wp', function() {
    if ( isset( $_GET['fl_builder'] ) ) return;
    if ( ! function_exists( 'WC' ) || ! WC()->session ) return;
    $session_slug = WC()->session->get( 'li_rescue_slug', '' );
    if ( empty( $session_slug ) && ! empty( $_COOKIE['li_rescue_slug'] ) ) {
        WC()->session->set( 'li_rescue_slug', sanitize_text_field( $_COOKIE['li_rescue_slug'] ) );
        WC()->session->set( 'li_rescue_name', sanitize_text_field( $_COOKIE['li_rescue_name'] ?? '' ) );
    }
} );

// Attach rescue to WooCommerce order

add_action( 'woocommerce_checkout_create_order', function( $order ) {
    $slug = '';
    $name = '';
    if ( function_exists( 'WC' ) && WC()->session ) {
        $slug = WC()->session->get( 'li_rescue_slug', '' );
        $name = WC()->session->get( 'li_rescue_name', '' );
    }
    if ( empty( $slug ) ) {
        $slug = sanitize_text_field( $_COOKIE['li_rescue_slug'] ?? '' );
        $name = sanitize_text_field( $_COOKIE['li_rescue_name'] ?? '' );
    }
    if ( $slug ) {
        $order->update_meta_data( '_li_rescue_slug', $slug );
        $order->update_meta_data( '_li_rescue_name', $name );
    }
}, 20 );

// Show rescue in WP admin order view

add_action( 'woocommerce_admin_order_data_after_billing_address', function( $order ) {
    $slug = $order->get_meta( '_li_rescue_slug' );
    $name = $order->get_meta( '_li_rescue_name' );
    if ( $slug ) {
        echo '<p><strong>🐾 Supporting rescue:</strong> ' . esc_html( $name ?: $slug ) . '</p>';
    }
} );

// Ajax handler

if ( ! function_exists( 'li_save_cart_rescue' ) ) {
    add_action( 'wp_ajax_li_save_cart_rescue',        'li_save_cart_rescue' );
    add_action( 'wp_ajax_nopriv_li_save_cart_rescue', 'li_save_cart_rescue' );
    function li_save_cart_rescue() {
        check_ajax_referer( 'li_cart_rescue', 'nonce' );
        $slug = sanitize_text_field( $_POST['rescue_slug'] ?? '' );
        $name = sanitize_text_field( $_POST['rescue_name'] ?? '' );
        if ( $slug && function_exists( 'WC' ) && WC()->session ) {
            WC()->session->set( 'li_rescue_slug', $slug );
            WC()->session->set( 'li_rescue_name', $name );
        }
        wp_send_json_success( [ 'slug' => $slug ] );
    }
}

// Shortcode (fallback for manual placement)

if ( ! function_exists( 'li_rescue_selector_output' ) ) {
    function li_rescue_selector_output() {}
}
add_shortcode( 'li_rescue_selector', function() {
    ob_start();
    li_rescue_modal_output();
    return ob_get_clean();
} );

