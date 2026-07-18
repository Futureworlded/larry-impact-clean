<?php
// Larry Impact - Rescue Auto Pages

if ( ! defined( 'ABSPATH' ) ) exit;

// Admin submenu

add_action( 'admin_menu', function() {
    add_submenu_page(
        'li-rescues',
        'Rescue Pages',
        'Rescue Pages',
        'manage_options',
        'larry-rescue-pages',
        'li_rescue_pages_admin_page'
    );
}, 99 );

// Admin page UI

function li_rescue_pages_admin_page() {
    $message = '';

    if ( isset( $_POST['li_sync_pages'] ) && check_admin_referer( 'li_sync_rescue_pages' ) ) {
        $result  = li_create_rescue_pages();
        $message = $result['message'];
    }

    if ( isset( $_POST['li_delete_page'] ) && check_admin_referer( 'li_delete_rescue_page' ) ) {
        wp_delete_post( intval( $_POST['li_page_id'] ), true );
        $message = 'Post deleted.';
    }
    ?>
    <div class="wrap">
        <h1>🐾 Larry Impact - Rescue Pages</h1>
        <p>Fetches all rescues from Supabase and auto-creates a post under the <strong>Rescue Partners</strong> category for each one. Safe to run multiple times — existing posts are updated, not duplicated.</p>

        <?php if ( $message ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
        <?php endif; ?>

        <form method="post" style="margin-bottom:24px;">
            <?php wp_nonce_field( 'li_sync_rescue_pages' ); ?>
            <input type="submit" name="li_sync_pages" class="button button-primary button-large" value="⟳ Sync All Rescue Pages Now" />
            <p class="description" style="margin-top:8px;">Also runs automatically every day via cron.</p>
        </form>

        <hr>
        <h2>Current Rescue Posts</h2>
        <?php
        $posts = get_posts( [
            'post_type'      => 'post',
            'post_status'    => [ 'publish', 'draft' ],
            'meta_key'       => '_li_rescue_slug',
            'posts_per_page' => 50,
        ] );

        if ( $posts ) {
            echo '<table class="widefat striped"><thead><tr><th>Rescue Name</th><th>Slug</th><th>Status</th><th>URL</th><th>Actions</th></tr></thead><tbody>';
            foreach ( $posts as $post ) {
                $slug = get_post_meta( $post->ID, '_li_rescue_slug', true );
                $url  = get_permalink( $post->ID );
                echo '<tr>';
                echo '<td><strong>' . esc_html( $post->post_title ) . '</strong></td>';
                echo '<td><code>' . esc_html( $slug ) . '</code></td>';
                echo '<td>' . ucfirst( $post->post_status ) . '</td>';
                echo '<td><a href="' . esc_url( $url ) . '" target="_blank">' . esc_url( $url ) . '</a></td>';
                echo '<td>
                    <a href="' . esc_url( get_edit_post_link( $post->ID ) ) . '" class="button button-small">Edit</a>
                    <form method="post" style="display:inline;margin-left:4px;" onsubmit="return confirm(\'Delete this post?\')">
                        ' . wp_nonce_field( 'li_delete_rescue_page', '_wpnonce', true, false ) . '
                        <input type="hidden" name="li_page_id" value="' . esc_attr( $post->ID ) . '" />
                        <input type="submit" name="li_delete_page" class="button button-small button-link-delete" value="Delete" />
                    </form>
                </td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>No rescue posts yet. Click <strong>Sync All Rescue Pages Now</strong> to generate them.</p>';
        }
        ?>
    </div>
    <?php
}

// Ensure rescue partners category exists

function li_get_rescue_category_id() {
    $cat = get_term_by( 'slug', 'rescue-partners', 'category' );
    if ( $cat ) return $cat->term_id;
    $result = wp_insert_term( 'Rescue Partners', 'category', [
        'slug' => 'rescue-partners',
        'description' => 'Individual rescue partner profile pages.',
    ] );
    return is_wp_error( $result ) ? 1 : $result['term_id'];
}

// Core sync

function li_create_rescue_pages() {
    $response = wp_remote_get(
        LI_DB_URL . '/rest/v1/rescues?select=id,name,slug,city,state,mission,about,website,email,phone,ein,status,logo_url,hero_photo_url&order=name.asc',
        [
            'headers' => [
                'apikey'        => LI_DB_KEY,
                'Authorization' => 'Bearer ' . LI_DB_KEY,
            ],
            'timeout' => 15,
        ]
    );

    if ( is_wp_error( $response ) ) {
        return [ 'message' => 'Connection error: ' . $response->get_error_message() ];
    }

    $rescues = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( ! is_array( $rescues ) || empty( $rescues ) ) {
        return [ 'message' => 'No rescues found in database.' ];
    }

    $cat_id  = li_get_rescue_category_id();
    $created = 0; $updated = 0; $skipped = 0;

    foreach ( $rescues as $rescue ) {
        $name = sanitize_text_field( $rescue['name'] ?? '' );
        $slug = sanitize_title( $rescue['slug'] ?? '' );
        $rid  = sanitize_text_field( $rescue['id'] ?? '' );

        if ( ! $name || ! $slug || ! $rid ) { $skipped++; continue; }

        $meta = [
            '_li_rescue_slug'    => $slug,
            '_li_rescue_id'      => $rid,
            '_li_rescue_city'    => $rescue['city']           ?? '',
            '_li_rescue_state'   => $rescue['state']          ?? '',
            '_li_rescue_mission' => $rescue['mission']        ?? '',
            '_li_rescue_website' => $rescue['website']        ?? '',
            '_li_rescue_email'   => $rescue['email']          ?? '',
            '_li_rescue_phone'   => $rescue['phone']          ?? '',
            '_li_rescue_ein'     => $rescue['ein']            ?? '',
            '_li_rescue_status'  => $rescue['status']         ?? '',
            '_li_rescue_logo'    => $rescue['logo_url']       ?? '',
            '_li_rescue_hero'    => $rescue['hero_photo_url'] ?? '',
        ];

        $content = '[larry_rescue_public id="' . $rid . '"]';

        // only approved rescues get published - pending/rejected stay draft
        $rescue_status  = strtolower( $rescue['status'] ?? 'pending' );
        $wp_post_status = in_array( $rescue_status, [ 'approved', 'active', 'verified' ] ) ? 'publish' : 'draft';

        // check if post already exists by rescue Id (most reliable) or slug
        $existing = get_posts( [
            'post_type'      => 'post',
            'post_status'    => [ 'publish', 'draft' ],
            'meta_key'       => '_li_rescue_id',
            'meta_value'     => $rid,
            'posts_per_page' => 1,
        ] );

        // fallback: find by slug meta
        if ( ! $existing ) {
            $existing = get_posts( [
                'post_type'      => 'post',
                'post_status'    => [ 'publish', 'draft' ],
                'meta_key'       => '_li_rescue_slug',
                'meta_value'     => $slug,
                'posts_per_page' => 1,
            ] );
        }

        if ( $existing ) {
            $post_id = $existing[0]->ID;
            // force the post_name to match Supabase slug so URL stays correct
            wp_update_post( [
                'ID'           => $post_id,
                'post_content' => $content,
                'post_title'   => $name,
                'post_name'    => $slug,
                'post_status'  => $wp_post_status,
            ] );
            wp_set_post_categories( $post_id, [ $cat_id ] );
            foreach ( $meta as $key => $val ) update_post_meta( $post_id, $key, $val );
            $updated++;
        } else {
            $post_id = wp_insert_post( [
                'post_title'    => $name,
                'post_name'     => $slug,
                'post_content'  => $content,
                'post_status'   => $wp_post_status,
                'post_type'     => 'post',
                'post_category' => [ $cat_id ],
            ] );
            if ( ! is_wp_error( $post_id ) ) {
                foreach ( $meta as $key => $val ) update_post_meta( $post_id, $key, $val );
                $created++;
            } else {
                $skipped++;
            }
        }
    }

    return [ 'message' => "Done - Created: {$created} | Updated: {$updated} | Skipped: {$skipped}" ];
}

// Public shortcode [larry_rescue_public id="uuid"]

add_shortcode( 'larry_rescue_public', 'li_rescue_public_shortcode' );

function li_rescue_public_shortcode( $atts ) {
    $atts = shortcode_atts( [ 'id' => '' ], $atts );
    $id   = sanitize_text_field( $atts['id'] );
    if ( ! $id ) return '';

    $response = wp_remote_get(
        LI_DB_URL . '/rest/v1/rescues?id=eq.' . urlencode( $id ) . '&select=id,name,slug,city,state,mission,about,website,email,phone,ein,status,logo_url,hero_photo_url&limit=1',
        [
            'headers' => [
                'apikey'        => LI_DB_KEY,
                'Authorization' => 'Bearer ' . LI_DB_KEY,
            ],
            'timeout' => 10,
        ]
    );

    if ( is_wp_error( $response ) ) return '';
    $data = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( empty( $data[0] ) ) return '';
    $r = $data[0];

    $name     = esc_html( $r['name']           ?? '' );
    $city     = esc_html( $r['city']           ?? '' );
    $state    = esc_html( $r['state']          ?? '' );
    $mission  = esc_html( $r['mission']        ?? 'Together with Larry Impact we are changing lives, saving animals, and empowering the heroes who rescue them.' );
    $about    = isset( $r['about'] ) && $r['about'] ? nl2br( esc_html( $r['about'] ) ) : '';
    $website  = esc_url(  $r['website']        ?? '' );
    $email    = esc_html( $r['email']          ?? '' );
    $phone    = esc_html( $r['phone']          ?? '' );
    $ein      = esc_html( $r['ein']            ?? '' );
    $status   = $r['status']                   ?? '';
    $rescue_split = floatval( $r['rescue_split_percent'] ?? get_option( 'li_default_split', 55 ) );
    $logo     = esc_url(  $r['logo_url']       ?? '' );
    $hero_bg  = esc_url(  $r['hero_photo_url'] ?? '' );
    $slug     = $r['slug']                     ?? '';

    // Featured image takes priority over Supabase URL
    global $post;
    if ( $post && has_post_thumbnail( $post->ID ) ) {
        $hero_bg = get_the_post_thumbnail_url( $post->ID, 'full' );
    }

    $location    = trim( $city . ( $city && $state ? ', ' : '' ) . $state );
    $is_verified = in_array( strtolower( $status ), [ 'active', 'verified', 'approved' ] );
    $bg_style    = $hero_bg ? "background-image:url('{$hero_bg}');" : 'background:linear-gradient(135deg,#1B4D2E,#0d1a10);';
    $logo_html   = $logo ? "<img src='{$logo}' alt='{$name}' style='width:100%;height:100%;object-fit:cover;' />" : '<span style="font-size:40px;color:#CEA83D;">🐾</span>';
    $loc_html    = $location ? "<p style='font-size:12px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:#CEA83D;margin:0 0 14px;'>📍 {$location}</p>" : '';
    $coffee_url  = 'https://larrybev.myshopify.com/collections/all?rescue=' . urlencode( $slug );
    $uid         = 'lrp-' . substr( md5( $id ), 0, 8 );

    ob_start();
    ?>
    <style>
    .li-rp { font-family:'Montserrat',Arial,sans-serif; width:100%; overflow:hidden; margin:0; padding:0; }

    /* HERO */
    .li-rp-hero {
        position:relative; width:100%; min-height:540px;
        display:flex; align-items:center; justify-content:center;
        overflow:hidden; padding:80px 20px; box-sizing:border-box;
    }
    .li-rp-hero-bg {
        position:absolute; inset:0;
        background-size:cover; background-position:center;
        z-index:1;
    }
    .li-rp-hero-paw-l { position:absolute;top:10%;left:4%;font-size:90px;opacity:0.1;color:#c8936a;transform:rotate(-15deg);z-index:2;pointer-events:none;user-select:none; }
    .li-rp-hero-paw-r { position:absolute;bottom:8%;right:4%;font-size:130px;opacity:0.1;color:#c8936a;transform:rotate(15deg);z-index:2;pointer-events:none;user-select:none; }
    .li-rp-hero-card {
        position:relative;z-index:3;background:#faf7f2;border-radius:12px;
        padding:60px 56px 44px;text-align:center;max-width:680px;width:88%;
        box-shadow:0 8px 40px rgba(0,0,0,0.35);
    }
    .li-rp-hero-logo {
        width:140px;height:140px;border-radius:50%;overflow:hidden;
        border:4px solid #fff;box-shadow:0 4px 20px rgba(0,0,0,0.3);
        margin:-110px auto 20px;background:#fff;
        display:flex;align-items:center;justify-content:center;
    }
    .li-rp-hero-divider { display:flex;align-items:center;justify-content:center;gap:10px;margin:0 0 16px;color:#CEA83D; }
    .li-rp-hero-divider-line { flex:1;height:1px;max-width:100px; }
    .li-rp-hero-name { font-size:clamp(26px,4vw,42px);font-weight:900;color:#1a1a1a;text-transform:uppercase;letter-spacing:0.5px;margin:0 0 10px;line-height:1.1; }
    .li-rp-hero-tagline { font-size:11px;font-weight:800;letter-spacing:3px;text-transform:uppercase;color:#B8860B;margin:0; }
    .li-rp-hero-heart-div { display:flex;align-items:center;justify-content:center;gap:12px;margin:10px 0 16px; }
    .li-rp-hero-heart-line { flex:1;height:1px;max-width:80px; }
    .li-rp-hero-mission { font-size:15px;color:#333;line-height:1.75;margin:0 0 22px; }
    .li-rp-hero-badges { display:flex;align-items:center;justify-content:center;gap:16px;flex-wrap:wrap; }
    .li-rp-hero-badge { display:flex;align-items:center;gap:7px;font-size:11px;font-weight:900;letter-spacing:1.5px;text-transform:uppercase;color:#333; }
    .li-rp-hero-badge-icon { width:28px;height:28px;border:2px solid #CEA83D;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#CEA83D; }
    .li-rp-hero-sep { color:#ccc;font-size:20px; }

    /* HOW IT WORKS */
    .li-rp-how { background:#fff;border-top:3px solid #CEA83D;border-bottom:3px solid #CEA83D;padding:48px 40px;box-sizing:border-box; }
    .li-rp-how-grid { display:grid;grid-template-columns:repeat(4,1fr);gap:24px;max-width:1200px;margin:0 auto; }
    .li-rp-how-item { text-align:center;padding:28px 20px;border:1px solid rgba(206,168,61,0.2);border-radius:10px; }
    .li-rp-how-icon { width:68px;height:68px;border-radius:50%;border:2px solid #CEA83D;background:rgba(206,168,61,0.08);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:28px; }
    .li-rp-how-title { font-size:12px;font-weight:900;letter-spacing:2px;text-transform:uppercase;color:#1a1a1a;margin:0 0 8px; }
    .li-rp-how-desc { font-size:13px;color:#666;line-height:1.6;margin:0; }

    /* BODY */
    .li-rp-body { display:grid;grid-template-columns:1fr 320px;gap:32px;width:100%;margin:40px 0;padding:0 40px 60px;align-items:start;box-sizing:border-box; }
    .li-rp-section-title { font-size:11px;font-weight:800;letter-spacing:2.5px;text-transform:uppercase;color:#CEA83D;margin:0 0 10px;padding-bottom:8px;border-bottom:2px solid rgba(206,168,61,0.25); }
    .li-rp-mission-text { font-size:15px;color:#333;line-height:1.8;margin:0 0 36px; }

    /* ABOUT FLYOUT */
    .li-rp-about-toggle {
        display:flex;align-items:center;justify-content:space-between;
        background:#faf7f2;border:1px solid #e8e2d8;border-radius:10px;
        padding:16px 20px;cursor:pointer;margin-bottom:8px;
        font-size:13px;font-weight:800;letter-spacing:1px;text-transform:uppercase;color:#1a1a1a;
    }
    .li-rp-about-toggle:hover { background:#f5f0e5; }
    .li-rp-about-arrow { font-size:18px;color:#CEA83D;transition:transform 0.3s; }
    .li-rp-about-arrow.open { transform:rotate(180deg); }
    .li-rp-about-content {
        display:none;background:#faf7f2;border:1px solid #e8e2d8;border-top:none;
        border-radius:0 0 10px 10px;padding:20px 20px 24px;
        font-size:14px;color:#444;line-height:1.8;margin-bottom:36px;
    }
    .li-rp-about-content.open { display:block; }

    /* ABOUT FLYOUT */
    .li-rp-about-toggle {
        display:flex;align-items:center;justify-content:space-between;
        background:#faf7f2;border:1px solid #e8e2d8;border-radius:10px;
        padding:16px 20px;cursor:pointer;margin-bottom:4px;
        font-size:13px;font-weight:800;letter-spacing:1px;text-transform:uppercase;color:#1a1a1a;
    }
    .li-rp-about-toggle:hover { background:#f5f0e5; }
    .li-rp-about-arrow { font-size:16px;color:#CEA83D;transition:transform 0.3s; }
    .li-rp-about-arrow.open { transform:rotate(180deg); }
    .li-rp-about-content {
        display:none;background:#faf7f2;border:1px solid #e8e2d8;border-top:none;
        border-radius:0 0 10px 10px;padding:20px 20px 24px;
        font-size:14px;color:#444;line-height:1.8;margin-bottom:24px;
    }
    .li-rp-about-content.open { display:block; }

    /* SHOP SECTIONS */
    .li-rp-shop-section { padding:36px 0 0; font-family:'Montserrat',Arial,sans-serif; }
    .li-rp-shop-header { display:flex;align-items:flex-end;justify-content:space-between;margin:0 0 20px;border-bottom:2px solid #ede8e0;padding-bottom:12px; }
    .li-rp-shop-header h2 { font-size:16px;font-weight:900;letter-spacing:2px;text-transform:uppercase;color:#1a1a1a;margin:0 0 3px; }
    .li-rp-shop-header p { font-size:12px;color:#888;margin:0; }
    .li-rp-shop-view-all { font-size:11px;font-weight:900;letter-spacing:1.5px;text-transform:uppercase;color:#CEA83D;text-decoration:none;white-space:nowrap; }
    .li-rp-shop-view-all:hover { color:#B8860B; }

    /* COFFEE GRID */
    .li-rp-coffee-grid { display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:36px; }
    .li-rp-coffee-card { background:#fff;border:1px solid #ede8e0;border-radius:8px;overflow:hidden;display:flex;flex-direction:column;text-align:center;transition:transform 0.25s,box-shadow 0.25s; }
    .li-rp-coffee-card:hover { transform:translateY(-3px);box-shadow:0 6px 16px rgba(0,0,0,0.08); }
    .li-rp-coffee-card-img { width:100%;height:180px;object-fit:cover;display:block; }
    .li-rp-coffee-card-body { padding:16px 16px 18px;display:flex;flex-direction:column;flex:1; }
    .li-rp-coffee-card h3 { font-size:14px;font-weight:900;color:#1a1a1a;margin:0 0 4px;line-height:1.2; }
    .li-rp-coffee-roast { font-size:11px;font-weight:700;color:#CEA83D;margin:0 0 10px;letter-spacing:0.5px; }
    .li-rp-coffee-desc { font-size:12px;color:#666;line-height:1.6;margin:0 0 12px;flex:1; }
    .li-rp-coffee-price { font-size:17px;font-weight:900;color:#CEA83D;margin:0 0 10px; }
    .li-rp-coffee-buy { display:block;background:#8B4A09;color:#fff;font-size:11px;font-weight:900;letter-spacing:1px;text-transform:uppercase;padding:9px 16px;border-radius:5px;text-decoration:none;text-align:center;transition:background 0.2s; }
    .li-rp-coffee-buy:hover { background:#a05510; }

    /* SHIPS STRIP */
    .li-rp-ships-strip { display:flex;align-items:stretch;border:1px solid #ede8e0;border-radius:8px;overflow:hidden;margin-top:16px;margin-bottom:36px; }
    .li-rp-ships-item { flex:1;font-size:12px;color:#555;font-weight:600;display:flex;align-items:center;justify-content:center;gap:8px;padding:10px 12px;border-right:1px solid #ede8e0;background:#fff;text-align:center; }
    .li-rp-ships-item:last-child { border-right:none; }

    /* WOOCOMMERCE OVERRIDES - tighten up default styles */
    .li-rp .woocommerce ul.products { margin:0 !important; }
    .li-rp .woocommerce ul.products li.product { margin-bottom:16px !important; }
    .li-rp .woocommerce ul.products li.product .price { font-size:14px !important; color:#CEA83D !important; font-weight:700 !important; margin-bottom:8px !important; }
    .li-rp .woocommerce ul.products li.product .button { font-size:11px !important; padding:7px 12px !important; background:#0d0e0c !important; color:#fff !important; border-radius:5px !important; font-weight:700 !important; letter-spacing:0.5px !important; text-transform:uppercase !important; }
    .li-rp .woocommerce ul.products li.product .button:hover { background:#CEA83D !important; color:#0d0e0c !important; }
    .li-rp .woocommerce ul.products li.product h2.woocommerce-loop-product__title { font-size:13px !important; padding:8px 0 4px !important; }
    .li-rp-woo-wrap { padding:0 2px; }

    @media(max-width:900px){ .li-rp-coffee-grid { grid-template-columns:repeat(2,1fr); } }
    @media(max-width:600px){ .li-rp-coffee-grid { grid-template-columns:1fr; } }

    /* PRODUCTS */
    .li-rp-products-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:20px;margin-top:16px; }
    .li-rp-product-card { border:1px solid #ebe6de;border-radius:10px;overflow:hidden;background:#fff;text-align:center; }
    .li-rp-product-img { width:100%;height:160px;object-fit:cover;background:#f5f0e8;display:block; }
    .li-rp-product-img-placeholder { width:100%;height:160px;background:#f5f0e8;display:flex;align-items:center;justify-content:center;font-size:40px; }
    .li-rp-product-info { padding:14px 14px 16px; }
    .li-rp-product-name { font-size:13px;font-weight:700;color:#1a1a1a;margin-bottom:4px;line-height:1.4; }
    .li-rp-product-price { font-size:15px;font-weight:800;color:#CEA83D;margin-bottom:10px; }
    .li-rp-product-btn { display:block;background:#0d0e0c;color:#fff;font-size:12px;font-weight:700;letter-spacing:1px;text-transform:uppercase;padding:9px 16px;border-radius:6px;text-decoration:none; }
    .li-rp-product-btn:hover { background:#CEA83D;color:#0d0e0c; }

    /* SIDEBAR */
    .li-rp-sidebar-card { background:#fff;border:1px solid #ebe6de;border-radius:12px;padding:20px;margin-bottom:20px; }
    .li-rp-sidebar-label { font-size:10px;font-weight:800;letter-spacing:2px;text-transform:uppercase;color:#CEA83D;margin-bottom:14px;padding-bottom:8px;border-bottom:1px solid #f0ece5; }
    .li-rp-detail-row { display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid #f5f2ed;font-size:13px; }
    .li-rp-detail-row:last-child { border-bottom:none; }
    .li-rp-detail-key { color:#888;font-weight:600; }
    .li-rp-detail-val { color:#1a1a1a;font-weight:700;text-align:right; }
    .li-rp-detail-val.active { color:#2e7d32; }
    .li-rp-contact-row { display:flex;align-items:center;gap:10px;padding:7px 0;font-size:13px;color:#333;border-bottom:1px solid #f5f2ed;word-break:break-all; }
    .li-rp-contact-row:last-child { border-bottom:none; }
    .li-rp-contact-icon { color:#CEA83D;font-size:15px;flex-shrink:0; }
    .li-rp-contact-row a { color:#1a1a1a;text-decoration:none;font-weight:600; }
    .li-rp-contact-row a:hover { color:#CEA83D; }

    @media(max-width:768px){
        .li-rp-hero-card { padding:40px 24px 32px; }
        .li-rp-how-grid { grid-template-columns:repeat(2,1fr); }
        .li-rp-body { grid-template-columns:1fr;padding:0 16px 40px; }
    }
    </style>

    <div class="li-rp">

        <!-- HERO -->
        <div class="li-rp-hero">
            <div class="li-rp-hero-bg" style="<?php echo $bg_style; ?>"></div>
            <div class="li-rp-hero-paw-l">🐾</div>
            <div class="li-rp-hero-paw-r">🐾</div>
            <div class="li-rp-hero-card">
                <div class="li-rp-hero-logo"><?php echo $logo_html; ?></div>
                <div class="li-rp-hero-divider">
                    <div class="li-rp-hero-divider-line" style="background:linear-gradient(to right,transparent,#CEA83D);"></div>
                    <span style="font-size:22px;">🐾</span>
                    <div class="li-rp-hero-divider-line" style="background:linear-gradient(to left,transparent,#CEA83D);"></div>
                </div>
                <h1 class="li-rp-hero-name"><?php echo $name; ?></h1>
                <?php echo $loc_html; ?>
                <p class="li-rp-hero-tagline">Every Purchase Makes An Impact</p>
                <div class="li-rp-hero-heart-div">
                    <div class="li-rp-hero-heart-line" style="background:linear-gradient(to right,transparent,rgba(206,168,61,0.4));"></div>
                    <span style="font-size:20px;color:#CEA83D;">♥</span>
                    <div class="li-rp-hero-heart-line" style="background:linear-gradient(to left,transparent,rgba(206,168,61,0.4));"></div>
                </div>
                <p class="li-rp-hero-mission"><?php echo $mission; ?></p>
                <div class="li-rp-hero-badges">
                    <div class="li-rp-hero-badge"><div class="li-rp-hero-badge-icon">✓</div> TRUSTED.</div>
                    <span class="li-rp-hero-sep">|</span>
                    <div class="li-rp-hero-badge"><div class="li-rp-hero-badge-icon">♡</div> TRANSPARENT.</div>
                    <span class="li-rp-hero-sep">|</span>
                    <div class="li-rp-hero-badge"><div class="li-rp-hero-badge-icon">🐾</div> RESCUE-POWERED.</div>
                </div>
            </div>
        </div>

        <!-- HOW IT WORKS -->
        <div class="li-rp-how">
            <div class="li-rp-how-grid">
                <div class="li-rp-how-item"><div class="li-rp-how-icon">🛒</div><h4 class="li-rp-how-title">You Shop</h4><p class="li-rp-how-desc">Choose coffee, supplies or merch.</p></div>
                <div class="li-rp-how-item"><div class="li-rp-how-icon">🐾</div><h4 class="li-rp-how-title">They Earn</h4><p class="li-rp-how-desc">Rescues earn revenue + ownership points.</p></div>
                <div class="li-rp-how-item"><div class="li-rp-how-icon">♡</div><h4 class="li-rp-how-title">Lives Change</h4><p class="li-rp-how-desc">More resources today. Stronger rescues tomorrow.</p></div>
                <div class="li-rp-how-item"><div class="li-rp-how-icon">✓</div><h4 class="li-rp-how-title">Greater Impact</h4><p class="li-rp-how-desc">Together, we build a better future for rescue animals.</p></div>
            </div>
        </div>

        <!-- BODY -->
        <div class="li-rp-body">
            <div class="li-rp-main">

                <?php if ( $about || $mission ) : ?>
                <div class="li-rp-about-toggle" onclick="liToggleAbout('<?php echo $uid; ?>')">
                    <span>About <?php echo $name; ?></span>
                    <span class="li-rp-about-arrow" id="arrow-<?php echo $uid; ?>">▼</span>
                </div>
                <div class="li-rp-about-content" id="about-<?php echo $uid; ?>">
                    <?php echo $about ? $about : $mission; ?>
                </div>
                <?php endif; ?>

                <?php echo li_rescue_shop_sections( $name, $slug ); ?>

            </div>

            <div class="li-rp-sidebar">

                <div class="li-rp-sidebar-card">
                    <div class="li-rp-sidebar-label">About <?php echo $name; ?></div>
                    <?php if ( $mission ) : ?>
                        <p style="font-size:13px;color:#666;line-height:1.7;margin:0 0 12px;font-style:italic;"><?php echo wp_trim_words( $mission, 30 ); ?></p>
                    <?php endif; ?>
                    <div class="li-rp-detail-row">
                        <span class="li-rp-detail-key">Status</span>
                        <span class="li-rp-detail-val active"><?php echo $is_verified ? 'Verified Partner' : ucfirst( $status ); ?></span>
                    </div>
                    <?php if ( $ein ) : ?>
                    <div class="li-rp-detail-row">
                        <span class="li-rp-detail-key">EIN</span>
                        <span class="li-rp-detail-val"><?php echo $ein; ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ( $website || $email || $phone || $location ) : ?>
                <div class="li-rp-sidebar-card">
                    <div class="li-rp-sidebar-label">Contact</div>
                    <?php if ( $location ) : ?><div class="li-rp-contact-row"><span class="li-rp-contact-icon">📍</span><?php echo $location; ?></div><?php endif; ?>
                    <?php if ( $email ) : ?><div class="li-rp-contact-row"><span class="li-rp-contact-icon">✉</span><a href="mailto:<?php echo $email; ?>"><?php echo $email; ?></a></div><?php endif; ?>
                    <?php if ( $phone ) : ?><div class="li-rp-contact-row"><span class="li-rp-contact-icon">📞</span><a href="tel:<?php echo $phone; ?>"><?php echo $phone; ?></a></div><?php endif; ?>
                    <?php if ( $website ) : ?><div class="li-rp-contact-row"><span class="li-rp-contact-icon">🌐</span><a href="<?php echo $website; ?>" target="_blank"><?php echo preg_replace('#^https?://#', '', rtrim( $website, '/' ) ); ?></a></div><?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="li-rp-sidebar-card">
                    <div class="li-rp-sidebar-label">How It Works</div>
                    <div style="font-size:13px;color:#555;line-height:1.9;">
                        <div style="margin-bottom:8px;">🛒 <strong>You shop</strong> coffee or merch</div>
                        <div style="margin-bottom:8px;">🐾 <strong><?php echo $name; ?> earns</strong> <?php echo number_format( $rescue_split, 0 ); ?>% of net profits</div>
                        <div style="margin-bottom:8px;">♡ <strong>Animals are saved</strong> with every purchase</div>
                        <div>✓ <strong>Transparent</strong> — tracked in real time</div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script>
    function liToggleAbout(uid) {
        var content = document.getElementById('about-' + uid);
        var arrow   = document.getElementById('arrow-' + uid);
        if ( content.classList.contains('open') ) {
            content.classList.remove('open');
            arrow.classList.remove('open');
        } else {
            content.classList.add('open');
            arrow.classList.add('open');
        }
    }
    </script>
    <?php
    return ob_get_clean();
}

// Shop sections — coffee cards + impactlist + merch

function li_rescue_shop_sections( $rescue_name, $rescue_slug ) {
    ob_start();
    ?>

    <!-- COFFEE SECTION -->
    <div class="li-rp-shop-section">
        <div class="li-rp-shop-header">
            <div>
                <h2>Shop Coffee</h2>
                <p>Every bag supports <?php echo esc_html( $rescue_name ); ?> and rescue missions nationwide.</p>
            </div>
            <a href="/coffee/" class="li-rp-shop-view-all">View All Coffee &rarr;</a>
        </div>
        <div class="li-rp-coffee-grid">
            <div class="li-rp-coffee-card">
                <img class="li-rp-coffee-card-img" src="https://larryimpact.com/wp-content/uploads/2026/06/Pony-Espresso-website.jpg" alt="Pony Espresso" />
                <div class="li-rp-coffee-card-body">
                <h3>Pony Espresso</h3>
                <p class="li-rp-coffee-roast">Espresso Blend</p>
                <p class="li-rp-coffee-desc">A rich and intense espresso blend with deep flavor and a smooth finish.</p>
                <p class="li-rp-coffee-price">$24.99</p>
                <a href="https://larrybev.myshopify.com/products/pony-espresso?rescue=<?php echo urlencode($rescue_slug); ?>" target="_blank" class="li-rp-coffee-buy">Buy Now</a>
                </div>
            </div>
            <div class="li-rp-coffee-card">
                <img class="li-rp-coffee-card-img" src="https://larryimpact.com/wp-content/uploads/2026/06/Rescue-Roast-Ground-Website.jpg" alt="Rescue Roast" />
                <div class="li-rp-coffee-card-body">
                <h3>Rescue Roast</h3>
                <p class="li-rp-coffee-roast">Medium Roast</p>
                <p class="li-rp-coffee-desc">A smooth and balanced medium roast blend. Every bag puts money into the hands of a rescue doing real work.</p>
                <p class="li-rp-coffee-price">$24.99</p>
                <a href="https://larrybev.myshopify.com/products/rescue-roast?rescue=<?php echo urlencode($rescue_slug); ?>" target="_blank" class="li-rp-coffee-buy">Buy Now</a>
                </div>
            </div>
            <div class="li-rp-coffee-card">
                <img class="li-rp-coffee-card-img" src="https://larryimpact.com/wp-content/uploads/2026/06/Whiskey-Barrel-website.jpg" alt="Whiskey Barrel" />
                <div class="li-rp-coffee-card-body">
                <h3>Whiskey Barrel</h3>
                <p class="li-rp-coffee-roast">Flavored Medium Roast</p>
                <p class="li-rp-coffee-desc">A medium roast with subtle whiskey barrel notes. Smooth, complex, and unlike anything else in the lineup.</p>
                <p class="li-rp-coffee-price">$25.99</p>
                <a href="https://larrybev.myshopify.com/products/whiskey-barrel?rescue=<?php echo urlencode($rescue_slug); ?>" target="_blank" class="li-rp-coffee-buy">Buy Now</a>
                </div>
            </div>
            <div class="li-rp-coffee-card">
                <img class="li-rp-coffee-card-img" src="https://larryimpact.com/wp-content/uploads/2026/06/Bark-Roast-Website.jpg" alt="Bark Roast" />
                <div class="li-rp-coffee-card-body">
                <h3>Bark Roast</h3>
                <p class="li-rp-coffee-roast">Medium-Dark Roast</p>
                <p class="li-rp-coffee-desc">A bold medium-dark roast with a rich and full-bodied flavor. Built for coffee drinkers who like it strong.</p>
                <p class="li-rp-coffee-price">$24.99</p>
                <a href="https://larrybev.myshopify.com/products/bark-roast?rescue=<?php echo urlencode($rescue_slug); ?>" target="_blank" class="li-rp-coffee-buy">Buy Now</a>
                </div>
            </div>
            <div class="li-rp-coffee-card">
                <img class="li-rp-coffee-card-img" src="https://larryimpact.com/wp-content/uploads/2026/06/Frenchie-Vanilla-website.jpg" alt="Frenchie Vanilla" />
                <div class="li-rp-coffee-card-body">
                <h3>Frenchie Vanilla</h3>
                <p class="li-rp-coffee-roast">Flavored Medium Roast</p>
                <p class="li-rp-coffee-desc">A medium roast with a smooth natural vanilla flavor. Easy drinking and crowd-pleasing.</p>
                <p class="li-rp-coffee-price">$24.99</p>
                <a href="https://larrybev.myshopify.com/products/frenchie-vanilla?rescue=<?php echo urlencode($rescue_slug); ?>" target="_blank" class="li-rp-coffee-buy">Buy Now</a>
                </div>
            </div>
            <div class="li-rp-coffee-card">
                <img class="li-rp-coffee-card-img" src="https://larryimpact.com/wp-content/uploads/2026/06/Howlin-at-the-Moon-website.jpg" alt="Howlin At The Moon" />
                <div class="li-rp-coffee-card-body">
                <h3>Howlin At The Moon</h3>
                <p class="li-rp-coffee-roast">Functional Wellness Blend</p>
                <p class="li-rp-coffee-desc">A functional wellness medium blend designed to do more than wake you up. Eight ounces of intentional coffee.</p>
                <p class="li-rp-coffee-price">$27.99</p>
                <a href="https://larrybev.myshopify.com/products/howlin-at-the-moon?rescue=<?php echo urlencode($rescue_slug); ?>" target="_blank" class="li-rp-coffee-buy">Buy Now</a>
                </div>
            </div>
        </div>
    </div>

    <!-- IMPACTLIST SECTION -->
    <div class="li-rp-shop-section" style="background:#f7f4f0;padding:36px 20px;">
        <div class="li-rp-shop-header">
            <div>
                <h2>Shop Impactlist</h2>
                <p>Buy what they need. <?php echo esc_html( $rescue_name ); ?> earns more.</p>
            </div>
            <a href="/product-category/impactlist/" class="li-rp-shop-view-all">View All Impactlist &rarr;</a>
        </div>
        <div class="li-rp-woo-wrap"><?php echo do_shortcode('[products limit="6" columns="6" category="impactlist" orderby="menu_order" order="ASC"]'); ?></div>
        <div class="li-rp-ships-strip">
            <div class="li-rp-ships-item">🚚 Ships directly to the rescue</div>
            <div class="li-rp-ships-item">💰 Rescue earns commission</div>
            <div class="li-rp-ships-item">⭐ Points added to their account</div>
        </div>
    </div>

    <!-- MERCH SECTION -->
    <div class="li-rp-shop-section" style="padding:36px 20px 0;">
        <div class="li-rp-shop-header">
            <div>
                <h2>Shop Merch</h2>
                <p>Wear it. Share it. Support <?php echo esc_html( $rescue_name ); ?> bigger.</p>
            </div>
            <a href="/product-category/merch/" class="li-rp-shop-view-all">View All Merch &rarr;</a>
        </div>
        <div class="li-rp-woo-wrap"><?php echo do_shortcode('[products limit="4" columns="4" category="merch" orderby="menu_order" order="ASC"]'); ?></div>
    </div>

    <?php
    return ob_get_clean();
}

// Daily cron

add_action( 'li_daily_rescue_sync', function() { li_create_rescue_pages(); } );
add_action( 'wp', function() {
    // don't run inside Beaver Builder editor
    if ( isset( $_GET['fl_builder'] ) ) return;
    if ( ! wp_next_scheduled( 'li_daily_rescue_sync' ) ) {
        wp_schedule_event( time(), 'daily', 'li_daily_rescue_sync' );
    }
} );

