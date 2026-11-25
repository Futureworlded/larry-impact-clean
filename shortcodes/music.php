<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PAWS & EFFECT Records music page: [li_music].
 *
 * Releases are Shopify products with product type "Music" (newest first, or by
 * the music.release_date metafield). Each card shows the product image, title,
 * music.artist, a music.preview audio clip, a music.video link and a buy button
 * that adds the song to the Shopify cart with the shopper's rescue (the plain
 * link opens the Shopify product page). Shopify's
 * Digital Products app emails the download after checkout.
 * Staging also shows draft products so new releases can be previewed.
 */

function li_music_releases() {
    $cached = get_transient( 'li_music_releases' );
    $fresh  = isset( $_GET['refresh'] ) && current_user_can( 'edit_pages' );
    if ( is_array( $cached ) && ! $fresh ) {
        return $cached;
    }
    $query = 'query LarryMusic {
        products(first: 100, sortKey: CREATED_AT, reverse: true, query: "product_type:Music") {
            nodes {
                title handle status onlineStoreUrl tags
                featuredImage { url altText }
                variants(first: 1) { nodes { legacyResourceId price sku } }
                artist: metafield(namespace: "music", key: "artist") { value }
                video: metafield(namespace: "music", key: "video") { value }
                released: metafield(namespace: "music", key: "release_date") { value }
                preview: metafield(namespace: "music", key: "preview") {
                    reference { ... on GenericFile { url } ... on Video { sources { url mimeType } } }
                }
            }
        }
    }';
    $data = li_music_graphql( $query );
    if ( is_wp_error( $data ) ) {
        $last = get_option( 'li_music_releases_last', array() );
        set_transient( 'li_music_releases', $last, 5 * MINUTE_IN_SECONDS );
        return $last;
    }
    $releases = li_music_parse_releases( (array) ( $data['products']['nodes'] ?? array() ), li_is_live_site() );
    update_option( 'li_music_releases_last', $releases, false );
    set_transient( 'li_music_releases', $releases, 10 * MINUTE_IN_SECONDS );
    return $releases;
}

/** Staging has no Shopify token of its own; a staging-only plugin can lend one for previews here. */
function li_music_graphql( $query ) {
    $token = (string) apply_filters( 'li_music_shopify_token', '' );
    if ( ! $token || li_shopify_admin_token() ) {
        return li_shopify_graphql( $query );
    }
    $filter = function() use ( $token ) { return $token; };
    add_filter( 'pre_option_li_shopify_admin_token', $filter );
    $data = li_shopify_graphql( $query );
    remove_filter( 'pre_option_li_shopify_admin_token', $filter );
    return $data;
}

function li_music_parse_releases( $nodes, $live ) {
    $out = array();
    foreach ( $nodes as $p ) {
        $published = 'ACTIVE' === ( $p['status'] ?? '' ) && ! empty( $p['onlineStoreUrl'] );
        $variant   = $p['variants']['nodes'][0] ?? null;
        if ( ! $variant || '' === trim( (string) ( $variant['sku'] ?? '' ) ) || ( $live && ! $published ) || 'ARCHIVED' === ( $p['status'] ?? '' ) ) {
            continue;
        }
        $ref     = $p['preview']['reference'] ?? array();
        $preview = (string) ( $ref['url'] ?? '' );
        foreach ( (array) ( $ref['sources'] ?? array() ) as $source ) {
            if ( ! $preview && false !== strpos( (string) ( $source['mimeType'] ?? '' ), 'mp4' ) ) {
                $preview = (string) $source['url'];
            }
        }
        $tags  = array_map( 'strtolower', array_map( 'trim', (array) ( $p['tags'] ?? array() ) ) );
        $out[] = array(
            'title'    => (string) ( $p['title'] ?? '' ),
            'handle'   => (string) ( $p['handle'] ?? '' ),
            'artist'   => (string) ( $p['artist']['value'] ?? '' ),
            'image'    => (string) ( $p['featuredImage']['url'] ?? '' ),
            'alt'      => (string) ( $p['featuredImage']['altText'] ?? '' ),
            'variant'  => (string) $variant['legacyResourceId'],
            'price'    => (float) $variant['price'],
            'video'    => (string) ( $p['video']['value'] ?? '' ),
            'preview'  => $preview,
            'released' => (string) ( $p['released']['value'] ?? '' ),
            'album'    => in_array( 'album', $tags, true ),
            'draft'    => ! $published,
        );
    }
    usort( $out, function( $a, $b ) {
        return strcmp( $b['released'], $a['released'] );
    } );
    return $out;
}

/** Embeddable player URL for a YouTube or Vimeo link, or '' if it isn't one. */
function li_music_video_embed( $url ) {
    if ( preg_match( '~(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})~', (string) $url, $m ) ) {
        return 'https://www.youtube-nocookie.com/embed/' . $m[1] . '?autoplay=1&rel=0';
    }
    if ( preg_match( '~vimeo\.com/(?:video/)?(\d+)~', (string) $url, $m ) ) {
        return 'https://player.vimeo.com/video/' . $m[1] . '?autoplay=1';
    }
    return '';
}

/**
 * Rescue payout for a song or album: the given share (default 50%) of what is
 * left after Shopify's card fee on a single-item order (2.9% + 30¢).
 */
function li_music_rescue_cents( $price_cents, $share = null ) {
    $share = null === $share ? floatval( get_option( 'li_music_rescue_share', 50 ) ) : floatval( $share );
    $net   = $price_cents * ( 1 - 0.029 ) - 30;
    return max( 0, intval( round( $net * $share / 100 ) ) );
}

function li_music_page_has_shortcode() {
    $post = get_post();
    return $post && has_shortcode( (string) $post->post_content, 'li_music' );
}

add_filter( 'body_class', function( $classes ) {
    if ( li_music_page_has_shortcode() ) {
        $classes[] = 'li-music-page';
    }
    return $classes;
} );

add_action( 'wp_enqueue_scripts', function() {
    if ( li_music_page_has_shortcode() ) {
        wp_enqueue_style( 'li-music-fonts', 'https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700&family=Oswald:wght@500;600&display=swap', array(), null );
    }
} );

add_shortcode( 'li_music', 'li_music_shortcode' );
function li_music_shortcode( $attr ) {
    $attr     = shortcode_atts( array(
        'limit'      => 6,
        'artist_url' => home_url( '/contact-us/' ),
    ), (array) $attr );
    $releases = li_music_releases();
    $limit    = max( 1, intval( $attr['limit'] ) );
    $store    = 'https://' . li_storefront_store();
    $hero     = plugins_url( 'assets/paws-effect-records-hero.webp', dirname( __FILE__ ) );
    $rescue   = function_exists( 'li_default_rescue' ) ? li_default_rescue() : null;

    ob_start();
    ?>
    <div class="pe-music" data-store="<?php echo esc_url( $store ); ?>" data-rescue="<?php echo esc_attr( $rescue['slug'] ?? '' ); ?>">
        <section class="pe-hero">
            <img src="<?php echo esc_url( $hero ); ?>" width="1600" height="819" alt="PAWS &amp; EFFECT Records. Songs That Save. Real artists. Real music. Real impact." fetchpriority="high">
            <h1 class="pe-sr">PAWS &amp; EFFECT Records: Music &amp; Videos</h1>
        </section>

        <ul class="pe-steps">
            <li><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 14v-2a8 8 0 0 1 16 0v2M4 14h3v6H5a1 1 0 0 1-1-1v-5zm16 0h-3v6h2a1 1 0 0 0 1-1v-5z"/></svg><span><b>Listen</b>Preview the music</span></li>
            <li><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12m-5-5 5 5 5-5M4 17v3h16v-3"/></svg><span><b>Download</b>Get the song or album</span></li>
            <li><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="7" cy="9" r="1.8"/><circle cx="12" cy="6.5" r="1.8"/><circle cx="17" cy="9" r="1.8"/><path d="M12 12c-3 0-5.5 4-5.5 5.5S8.5 20 12 20s5.5-1 5.5-2.5S15 12 12 12z"/></svg><span><b>Support</b>Choose your rescue</span></li>
            <li><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z"/></svg><span><b>Save lives</b>Turn music into impact</span></li>
        </ul>

        <section class="pe-releases">
            <h2 class="pe-title">Latest Releases</h2>
            <?php if ( ! $releases ) : ?>
                <p class="pe-empty">New releases are coming soon.</p>
            <?php else : ?>
                <div class="pe-grid">
                    <?php foreach ( $releases as $i => $r ) :
                        $embed = li_music_video_embed( $r['video'] );
                        $buy   = $store . '/products/' . rawurlencode( $r['handle'] );
                        $label = ( $r['album'] ? 'Buy Album' : 'Buy Download' ) . ' &ndash; ' . esc_html( li_currency_symbol() . number_format( $r['price'], 2 ) );
                        ?>
                        <article class="pe-card<?php echo $i >= $limit ? ' pe-more' : ''; ?>">
                            <div class="pe-cover">
                                <?php if ( $r['image'] ) : ?>
                                    <img src="<?php echo esc_url( add_query_arg( 'width', 600, $r['image'] ) ); ?>" width="600" height="600" loading="lazy" alt="<?php echo esc_attr( $r['alt'] ? $r['alt'] : $r['title'] . ' cover art' ); ?>">
                                <?php endif; ?>
                                <?php if ( $r['draft'] ) : ?><span class="pe-draft">Draft &ndash; staging only</span><?php endif; ?>
                                <?php if ( $r['preview'] ) : ?>
                                    <button type="button" class="pe-cover-play" aria-label="Play preview of <?php echo esc_attr( $r['title'] ); ?>"></button>
                                <?php endif; ?>
                            </div>
                            <h3><?php echo esc_html( $r['title'] ); ?></h3>
                            <?php if ( $r['artist'] ) : ?><p class="pe-artist"><?php echo esc_html( $r['artist'] ); ?></p><?php endif; ?>
                            <?php if ( $r['preview'] ) : ?>
                                <div class="pe-player">
                                    <audio preload="none" src="<?php echo esc_url( $r['preview'] ); ?>"></audio>
                                    <button type="button" class="pe-play" aria-label="Play preview"></button>
                                    <div class="pe-bar"><span></span></div>
                                    <time>0:00</time>
                                </div>
                            <?php endif; ?>
                            <a class="pe-btn pe-buy" href="<?php echo esc_url( $buy ); ?>" data-variant="<?php echo esc_attr( $r['variant'] ); ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4h2l2.4 10.4a1 1 0 0 0 1 .8h8.8a1 1 0 0 0 1-.8L20 8H6.2M9 20a1 1 0 1 0 0-.1M17 20a1 1 0 1 0 0-.1"/></svg><?php echo $label; // phpcs:ignore -- escaped above ?></a>
                            <?php if ( $embed ) : ?>
                                <button type="button" class="pe-btn pe-video" data-embed="<?php echo esc_url( $embed ); ?>" data-title="<?php echo esc_attr( $r['title'] ); ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 4v16l13-8z"/></svg>Watch Music Video</button>
                            <?php elseif ( $r['video'] ) : ?>
                                <a class="pe-btn pe-video" href="<?php echo esc_url( $r['video'] ); ?>" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 4v16l13-8z"/></svg>Watch Music Video</a>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
                <?php if ( count( $releases ) > $limit ) : ?>
                    <p class="pe-center"><button type="button" class="pe-btn pe-all">View All Music <span aria-hidden="true">&rarr;</span></button></p>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <section class="pe-impact">
            <div>
                <h2><span>Your music.</span> Their second chance.</h2>
                <p>Every song you purchase helps support an animal rescue of your choice. Choose your rescue at checkout and turn the music you love into real help for dogs in need.</p>
            </div>
        </section>

        <section class="pe-artist-cta">
            <h2>Are you a <span>real artist?</span></h2>
            <p class="pe-tag">Real talent. Real music. Real purpose.</p>
            <p>What if your music could do more than reach an audience?<br><strong>What if it could save lives?</strong></p>
            <a class="pe-btn pe-buy pe-big" href="<?php echo esc_url( $attr['artist_url'] ); ?>">Apply to join PAWS &amp; EFFECT Records <span aria-hidden="true">&rarr;</span></a>
        </section>

        <div class="pe-modal" hidden><div class="pe-modal-box"><button type="button" class="pe-close" aria-label="Close video">&times;</button><div class="pe-frame"></div></div></div>
    </div>
    <style><?php echo li_music_css(); // phpcs:ignore -- static CSS ?></style>
    <script><?php echo li_music_js(); // phpcs:ignore -- static JS ?></script>
    <?php
    return ob_get_clean();
}

function li_music_css() {
    return <<<'CSS'
body.li-music-page .fl-post-header,body.li-music-page .fl-page-header{display:none}
body.li-music-page{overflow-x:hidden}
body.li-music-page .fl-content{margin:0;padding:0}
body.li-music-page .fl-content-full .container,body.li-music-page .fl-page-content{background:#000}
.pe-music{--gold:#f4b73f;--gold2:#ffd772;--deep:#c5841a;background:#000;color:#eee;font-family:Helvetica,Arial,sans-serif;width:100vw;margin-left:calc(50% - 50vw);overflow:hidden}
.pe-music *{box-sizing:border-box}
.pe-sr{position:absolute!important;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}
.pe-hero{max-width:1600px;margin:0 auto}
.pe-hero img{display:block;width:100%;height:auto}
.pe-steps{list-style:none;margin:0 auto;padding:18px 16px;max-width:1200px;display:grid;grid-template-columns:repeat(4,1fr);border-top:1px solid #4a3410;border-bottom:1px solid #4a3410}
.pe-steps li{display:flex;align-items:center;gap:14px;justify-content:center;padding:4px 10px;border-left:1px solid #3a2a0e}
.pe-steps li:first-child{border-left:0}
.pe-steps svg{width:38px;height:38px;fill:none;stroke:var(--gold);stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;flex:none}
.pe-steps li:nth-child(3) svg{fill:var(--gold);stroke:none}
.pe-steps b{display:block;font:600 19px/1.1 Oswald,Helvetica,sans-serif;text-transform:uppercase;color:#fff;letter-spacing:.5px}
.pe-steps span{font-size:13px;color:#bbb}
.pe-releases{max-width:1200px;margin:0 auto;padding:40px 20px 50px}
.pe-title{font:700 clamp(26px,3.4vw,40px)/1.2 Cinzel,Georgia,serif;text-transform:uppercase;letter-spacing:4px;color:#fff;text-align:center;margin:0 0 30px;display:flex;align-items:center;gap:20px}
.pe-title:before,.pe-title:after{content:"";flex:1;height:2px;background:linear-gradient(90deg,transparent,var(--gold))}
.pe-title:after{background:linear-gradient(90deg,var(--gold),transparent)}
.pe-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:34px}
.pe-card{text-align:center;display:flex;flex-direction:column}
.pe-card .pe-player{margin-top:auto}
.pe-card .pe-player+.pe-buy,.pe-card h3+.pe-buy,.pe-card .pe-artist+.pe-buy{margin-top:auto}
.pe-card.pe-more{display:none}
.pe-music.pe-show-all .pe-card.pe-more{display:flex}
.pe-cover{position:relative;aspect-ratio:1;border:2px solid var(--deep);border-radius:6px;overflow:hidden;background:#111;box-shadow:0 0 22px rgba(244,183,63,.25)}
.pe-cover img{width:100%;height:100%;object-fit:cover;display:block}
.pe-draft{position:absolute;top:10px;left:10px;background:#b00;color:#fff;font-size:12px;padding:3px 8px;border-radius:3px}
.pe-cover-play{position:absolute;right:12px;bottom:12px;width:44px;height:44px;border-radius:50%;border:2px solid #fff;background:rgba(0,0,0,.6);cursor:pointer}
.pe-cover-play:after,.pe-play:after{content:"";position:absolute;left:50%;top:50%;border-style:solid;border-width:8px 0 8px 13px;border-color:transparent transparent transparent #fff;transform:translate(-35%,-50%)}
.pe-playing .pe-cover-play:after,.pe-playing .pe-play:after{border:0;width:12px;height:14px;border-left:4px solid #fff;border-right:4px solid #fff;transform:translate(-50%,-50%)}
.pe-card h3{font:600 19px/1.25 Helvetica,Arial,sans-serif;color:#fff;margin:14px 0 2px}
.pe-artist{color:#bbb;font-size:14px;margin:0 0 10px}
.pe-player{display:flex;align-items:center;gap:10px;border:1px solid #555;border-radius:8px;padding:8px 12px;margin:0 0 10px;background:#111}
.pe-card .pe-player+.pe-buy{margin-top:0}
.pe-play{position:relative;width:24px;height:24px;border:0;background:none;cursor:pointer;flex:none;padding:0}
.pe-play:after{border-width:7px 0 7px 12px}
.pe-bar{flex:1;height:4px;background:#444;border-radius:2px;cursor:pointer;position:relative}
.pe-bar span{position:absolute;left:0;top:0;bottom:0;width:0;background:var(--gold);border-radius:2px}
.pe-player time{font-size:12px;color:#ccc;min-width:34px;text-align:right}
.pe-btn{display:flex;align-items:center;justify-content:center;gap:10px;width:100%;padding:10px 16px;margin:0 0 10px;border-radius:7px;font:600 16px/1.2 Oswald,Helvetica,sans-serif;text-transform:uppercase;letter-spacing:.5px;text-decoration:none!important;cursor:pointer;transition:filter .15s,transform .15s}
.pe-btn svg{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.pe-buy{background:linear-gradient(180deg,var(--gold2),var(--gold) 55%,var(--deep));color:#111!important;border:0;box-shadow:0 2px 10px rgba(244,183,63,.35)}
.pe-video{background:#000;color:#fff!important;border:1.5px solid var(--gold)}
.pe-video svg{fill:#fff;stroke:none}
.pe-btn:hover{filter:brightness(1.1);transform:translateY(-1px)}
.pe-center{text-align:center;margin:34px 0 0}
.pe-all{display:inline-flex;width:auto;padding:10px 34px;background:#000;color:var(--gold)!important;border:2px solid var(--gold);border-radius:30px}
.pe-music.pe-show-all .pe-all{display:none}
.pe-empty{text-align:center;color:#ccc;font-size:18px}
.pe-impact{background:linear-gradient(90deg,rgba(0,0,0,.2),rgba(0,0,0,.75) 45%,rgba(0,0,0,.8)),linear-gradient(180deg,#1a0a02 0%,#8a3a07 45%,#e2861c 70%,#3a1604 100%);border-top:1px solid #6b4612;border-bottom:1px solid #6b4612;padding:44px 20px}
.pe-impact div{max-width:820px;margin:0 auto 0 max(20px,calc(50% - 300px))}
.pe-impact h2{font:italic 700 clamp(24px,3.2vw,38px)/1.15 Oswald,Helvetica,sans-serif;text-transform:uppercase;color:var(--gold2);margin:0 0 10px}
.pe-impact h2 span{color:#fff}
.pe-impact p{color:#f1f1f1;font-size:18px;line-height:1.5;margin:0}
.pe-artist-cta{text-align:center;padding:60px 20px 70px;background:radial-gradient(ellipse at 15% 60%,rgba(244,183,63,.25),transparent 45%),#000}
.pe-artist-cta h2{font:italic 700 clamp(32px,5vw,62px)/1.05 Oswald,Helvetica,sans-serif;text-transform:uppercase;color:#fff;margin:0 0 8px}
.pe-artist-cta h2 span{color:var(--gold2)}
.pe-artist-cta .pe-tag{font-style:italic;font-size:20px;color:#eee;margin:0 0 18px}
.pe-artist-cta p{font-size:20px;color:#ddd;font-style:italic;margin:0 0 26px}
.pe-artist-cta strong{color:var(--gold2);font-size:clamp(22px,3vw,32px)}
.pe-big{display:inline-flex;width:auto;font-size:clamp(17px,2.2vw,26px);padding:14px 36px;border-radius:40px}
.pe-modal{position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:100000;display:flex;align-items:center;justify-content:center;padding:20px}
.pe-modal[hidden]{display:none}
.pe-modal-box{position:relative;width:min(960px,100%,calc((100vh - 120px) * 16 / 9))}
.pe-frame{aspect-ratio:16/9;background:#000}
.pe-frame iframe{width:100%;height:100%;border:0}
.pe-close{position:absolute;top:-44px;right:0;background:none;border:0;color:#fff;font-size:38px;line-height:1;cursor:pointer}
@media (max-width:768px){.pe-steps{grid-template-columns:repeat(2,1fr);gap:14px 0}.pe-steps li{justify-content:flex-start;border-left:0}.pe-steps b{font-size:16px}.pe-impact div{margin:0 auto}.pe-impact p,.pe-artist-cta p{font-size:16px}}
CSS;
}

function li_music_js() {
    return <<<'JS'
(function(){
  var root=document.currentScript&&document.currentScript.previousElementSibling&&document.currentScript.previousElementSibling.previousElementSibling;
  if(!root||!root.classList.contains('pe-music'))root=document.querySelector('.pe-music');
  if(!root)return;
  function cookie(n){var v=document.cookie.match('(^|;) ?'+n+'=([^;]*)(;|$)');return v?decodeURIComponent(v[2]):'';}
  var slug=cookie('li_rescue_slug')||root.getAttribute('data-rescue');
  root.querySelectorAll('a.pe-buy[data-variant]').forEach(function(a){
    a.addEventListener('click',function(e){
      if(e.metaKey||e.ctrlKey||e.shiftKey)return;
      e.preventDefault();
      var s=cookie('li_rescue_slug')||slug;
      window.location.href=root.getAttribute('data-store')+'/cart/add?id='+encodeURIComponent(a.getAttribute('data-variant'))+'&quantity=1&return_to='+encodeURIComponent('/cart'+(s?'?rescue='+encodeURIComponent(s):''));
    });
  });
  function fmt(s){s=Math.max(0,Math.floor(s||0));return Math.floor(s/60)+':'+('0'+s%60).slice(-2);}
  var current=null;
  root.querySelectorAll('.pe-card').forEach(function(card){
    var audio=card.querySelector('audio');if(!audio)return;
    var bar=card.querySelector('.pe-bar span'),time=card.querySelector('.pe-player time');
    function toggle(){
      if(audio.paused){if(current&&current!==audio)current.pause();current=audio;audio.play();}else{audio.pause();}
    }
    card.querySelectorAll('.pe-play,.pe-cover-play').forEach(function(b){b.addEventListener('click',toggle);});
    audio.addEventListener('play',function(){card.classList.add('pe-playing');});
    audio.addEventListener('pause',function(){card.classList.remove('pe-playing');});
    audio.addEventListener('ended',function(){card.classList.remove('pe-playing');});
    audio.addEventListener('timeupdate',function(){time.textContent=fmt(audio.currentTime);if(audio.duration)bar.style.width=(audio.currentTime/audio.duration*100)+'%';});
    audio.addEventListener('loadedmetadata',function(){if(audio.paused)time.textContent=fmt(audio.duration);});
    card.querySelector('.pe-bar').addEventListener('click',function(e){if(!audio.duration)return;var r=this.getBoundingClientRect();audio.currentTime=(e.clientX-r.left)/r.width*audio.duration;});
  });
  var modal=root.querySelector('.pe-modal'),frame=root.querySelector('.pe-frame');
  function close(){modal.hidden=true;frame.innerHTML='';}
  root.querySelectorAll('button.pe-video').forEach(function(b){
    b.addEventListener('click',function(){
      if(current)current.pause();
      var f=document.createElement('iframe');f.src=b.getAttribute('data-embed');f.title=b.getAttribute('data-title')+' music video';
      f.allow='autoplay; encrypted-media; picture-in-picture; fullscreen';f.allowFullscreen=true;
      frame.innerHTML='';frame.appendChild(f);modal.hidden=false;
    });
  });
  if(modal){modal.addEventListener('click',function(e){if(e.target===modal||e.target.classList.contains('pe-close'))close();});
  document.addEventListener('keydown',function(e){if(e.key==='Escape'&&!modal.hidden)close();});}
  var all=root.querySelector('.pe-all');if(all)all.addEventListener('click',function(){root.classList.add('pe-show-all');});
})();
JS;
}
