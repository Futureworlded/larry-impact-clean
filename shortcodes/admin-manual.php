<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', 'li_register_manual_page', 100 );
function li_register_manual_page() {
    remove_submenu_page( 'li-rescues', 'li-manual' );
    add_submenu_page(
        'li-rescues',
        'Operations Manual',
        'Operations Manual',
        'manage_options',
        'li-manual',
        'li_page_manual'
    );
}

function li_page_manual() {
    $saved_html = get_option( 'li_manual_html', '' );

    if ( isset( $_GET['li_reset_manual'] ) && check_admin_referer( 'li_reset_manual' ) && current_user_can( 'manage_options' ) ) {
        delete_option( 'li_manual_html' );
        $saved_html = '';
        echo '<div class="notice notice-success"><p>Operations Manual reset to Markdown.</p></div>';
    }

    if ( isset( $_POST['li_save_manual_html'] ) && check_admin_referer( 'li_save_manual_html' ) && current_user_can( 'manage_options' ) ) {
        $html = isset( $_POST['li_manual_html'] ) ? wp_unslash( $_POST['li_manual_html'] ) : '';
        $saved_html = li_sanitize_manual_html( $html );
        update_option( 'li_manual_html', $saved_html );
        echo '<div class="notice notice-success"><p>Operations Manual saved.</p></div>';
    }

    $reset_url = wp_nonce_url( admin_url( 'admin.php?page=li-manual&li_reset_manual=1' ), 'li_reset_manual' );
    ?>
    <div class="wrap" style="max-width:none;">
        <h1>Operations Manual</h1>

        <div style="width:100%;box-sizing:border-box;background:#fff;border:1px solid #ddd;padding:24px;border-radius:6px;margin-bottom:20px;word-wrap:break-word;overflow-wrap:break-word;">
            <?php echo li_get_manual_html(); ?>
        </div>

        <details style="width:100%;box-sizing:border-box;background:#f9f9f9;border:1px solid #ddd;border-radius:6px;padding:12px 16px;">
            <summary style="cursor:pointer;font-weight:600;font-size:14px;">Edit HTML</summary>
            <form method="post" style="margin-top:12px;">
                <p>Paste your own HTML below and click <strong>Save HTML</strong>. Leave it blank and click Save to use the Markdown file instead.</p>
                <?php wp_nonce_field( 'li_save_manual_html' ); ?>
                <textarea name="li_manual_html" rows="20" style="width:100%;font-family:monospace;"><?php echo esc_textarea( $saved_html ); ?></textarea>
                <p>
                    <?php submit_button( 'Save HTML', 'primary', 'li_save_manual_html', false ); ?>
                    <a href="<?php echo esc_url( $reset_url ); ?>" class="button" style="margin-left:10px;">Reset to Markdown</a>
                </p>
            </form>
        </details>
    </div>
    <?php
}

function li_get_manual_html() {
    $saved = get_option( 'li_manual_html', '' );
    if ( $saved !== '' ) {
        $html = li_sanitize_manual_html( $saved );
        // If the user pasted plain text, turn double line breaks into paragraphs.
        if ( ! preg_match( '/<(?:p|div|h[1-6]|ul|ol|li|table|section|article|aside|header|footer|main|nav|blockquote|pre|code|figure|style|script|iframe|dl|dt|dd)\b/i', $html ) ) {
            $html = wpautop( $html );
        }
        return $html;
    }

    $manual_path = plugin_dir_path( __FILE__ ) . '../LARRY_IMPACT_OPERATIONS_MANUAL.md';
    if ( ! file_exists( $manual_path ) ) {
        return '<p>Manual file not found.</p>';
    }

    $md = file_get_contents( $manual_path );
    if ( false === $md ) {
        return '<p>Could not read manual file.</p>';
    }

    return li_markdown_to_html( $md );
}

function li_sanitize_manual_html( $html ) {
    // Build a broad allowed-tags list so pasted manual HTML keeps its formatting.
    $common_attrs = array(
        'style'  => true,
        'class'  => true,
        'id'     => true,
        'title'  => true,
        'dir'    => true,
        'lang'   => true,
        'role'   => true,
        'data-*' => true,
        'aria-*' => true,
    );

    $allowed = array();
    foreach ( array(
        'a', 'abbr', 'address', 'area', 'article', 'aside', 'audio', 'b', 'bdo', 'bdi', 'big',
        'blockquote', 'br', 'button', 'caption', 'cite', 'code', 'col', 'colgroup', 'dd', 'del',
        'details', 'dfn', 'dialog', 'div', 'dl', 'dt', 'em', 'figcaption', 'figure', 'font',
        'footer', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hgroup', 'hr', 'i', 'img', 'ins',
        'kbd', 'label', 'legend', 'li', 'main', 'map', 'mark', 'menu', 'nav', 'ol', 'p', 'pre', 'q',
        'rp', 'rt', 'ruby', 's', 'samp', 'section', 'small', 'span', 'strike', 'strong', 'sub',
        'summary', 'sup', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'track', 'tt', 'u',
        'ul', 'var', 'video', 'wbr',
    ) as $tag ) {
        $allowed[ $tag ] = $common_attrs;
    }

    // Link/media specific attributes.
    $allowed['a']   = array_merge( $common_attrs, array('href'=>true,'target'=>true,'rel'=>true,'name'=>true,'download'=>true) );
    $allowed['img'] = array_merge( $common_attrs, array('src'=>true,'srcset'=>true,'sizes'=>true,'alt'=>true,'width'=>true,'height'=>true,'loading'=>true,'decoding'=>true,'align'=>true,'border'=>true) );
    $allowed['area']= array_merge( $common_attrs, array('shape'=>true,'coords'=>true,'href'=>true,'alt'=>true,'target'=>true,'rel'=>true) );
    $allowed['audio'] = array_merge( $common_attrs, array('src'=>true,'preload'=>true,'autoplay'=>true,'loop'=>true,'muted'=>true,'controls'=>true) );
    $allowed['video'] = array_merge( $common_attrs, array('src'=>true,'preload'=>true,'autoplay'=>true,'loop'=>true,'muted'=>true,'controls'=>true,'width'=>true,'height'=>true,'poster'=>true) );
    $allowed['source'] = array_merge( $common_attrs, array('src'=>true,'srcset'=>true,'sizes'=>true,'type'=>true,'media'=>true) );
    $allowed['track'] = array_merge( $common_attrs, array('src'=>true,'srclang'=>true,'kind'=>true,'label'=>true,'default'=>true) );
    $allowed['embed'] = array_merge( $common_attrs, array('src'=>true,'type'=>true,'width'=>true,'height'=>true) );
    $allowed['iframe'] = array_merge( $common_attrs, array('src'=>true,'width'=>true,'height'=>true,'frameborder'=>true,'allowfullscreen'=>true,'allow'=>true,'sandbox'=>true,'loading'=>true) );
    $allowed['object'] = array_merge( $common_attrs, array('data'=>true,'type'=>true,'width'=>true,'height'=>true) );
    $allowed['param'] = array('name'=>true,'value'=>true);

    // Table attributes.
    foreach ( array('table','thead','tbody','tfoot','tr','td','th','caption','col','colgroup') as $tag ) {
        $allowed[ $tag ] = array_merge( $common_attrs, array('align'=>true,'valign'=>true,'width'=>true,'height'=>true,'border'=>true,'cellpadding'=>true,'cellspacing'=>true) );
    }
    $allowed['td'] = array_merge( $allowed['td'], array('colspan'=>true,'rowspan'=>true,'scope'=>true,'headers'=>true) );
    $allowed['th'] = array_merge( $allowed['th'], array('colspan'=>true,'rowspan'=>true,'scope'=>true,'headers'=>true) );
    $allowed['col'] = array_merge( $allowed['col'], array('span'=>true,'width'=>true) );
    $allowed['colgroup'] = array_merge( $allowed['colgroup'], array('span'=>true,'width'=>true) );

    // List attributes.
    $allowed['ol'] = array_merge( $common_attrs, array('start'=>true,'type'=>true,'reversed'=>true) );
    $allowed['ul'] = array_merge( $common_attrs, array('type'=>true) );
    $allowed['li'] = array_merge( $common_attrs, array('value'=>true) );

    // Allow inline <style> blocks for custom manual CSS.
    $allowed['style'] = array('type'=>true,'media'=>true,'scoped'=>true);

    return wp_kses( $html, $allowed );
}

function li_markdown_to_html( $md ) {
    $lines = preg_split( '/\r?\n/', $md );
    $list_tag = '';
    $output   = '';

    foreach ( $lines as $line ) {
        $trimmed = trim( $line );

        if ( $trimmed === '' ) {
            if ( $list_tag ) {
                $output .= '</' . $list_tag . '>';
                $list_tag = '';
            }
            continue;
        }

        if ( strpos( $trimmed, '---' ) === 0 ) {
            if ( $list_tag ) {
                $output .= '</' . $list_tag . '>';
                $list_tag = '';
            }
            $output .= '<hr style="margin:24px 0;border:none;border-top:1px solid #ddd;">';
            continue;
        }

        if ( preg_match( '/^(#{1,6})\s+(.+)$/', $trimmed, $matches ) ) {
            if ( $list_tag ) {
                $output .= '</' . $list_tag . '>';
                $list_tag = '';
            }
            $level  = strlen( $matches[1] );
            $anchor = li_manual_anchor( $matches[2] );
            $output .= '<h' . $level . ' id="' . esc_attr( $anchor ) . '" style="margin-top:24px;margin-bottom:12px;">' . esc_html( $matches[2] ) . '</h' . $level . '>';
            continue;
        }

        if ( preg_match( '/^[-*]\s+(.+)$/', $trimmed, $matches ) ) {
            if ( $list_tag !== 'ul' ) {
                if ( $list_tag ) {
                    $output .= '</' . $list_tag . '>';
                }
                $output .= '<ul style="list-style:disc;margin-left:20px;margin-bottom:16px;">';
                $list_tag = 'ul';
            }
            $output .= '<li style="margin-bottom:6px;">' . li_format_manual_text( $matches[1] ) . '</li>';
            continue;
        }

        if ( preg_match( '/^(\d+)\.\s+(.+)$/', $trimmed, $matches ) ) {
            if ( $list_tag !== 'ol' ) {
                if ( $list_tag ) {
                    $output .= '</' . $list_tag . '>';
                }
                $output .= '<ol style="list-style:decimal;margin-left:20px;margin-bottom:16px;">';
                $list_tag = 'ol';
            }
            $output .= '<li style="margin-bottom:6px;">' . li_format_manual_text( $matches[2] ) . '</li>';
            continue;
        }

        if ( $list_tag ) {
            $output .= '</' . $list_tag . '>';
            $list_tag = '';
        }

        $output .= '<p style="margin-bottom:16px;line-height:1.6;">' . li_format_manual_text( $trimmed ) . '</p>';
    }

    if ( $list_tag ) {
        $output .= '</' . $list_tag . '>';
    }

    return $output;
}

function li_format_manual_text( $text ) {
    $text = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text );
    $text = preg_replace( '/\*(.+?)\*/', '<em>$1</em>', $text );
    $text = preg_replace( '/`(.+?)`/', '<code style="background:#f5f5f5;padding:2px 4px;border-radius:3px;">$1</code>', $text );
    $text = preg_replace_callback(
        '/\[([^\]]+)\]\(([^\)]+)\)/',
        function( $m ) {
            $label = $m[1];
            $href  = $m[2];
            if ( strpos( $href, '#' ) === 0 ) {
                return '<a href="' . esc_attr( $href ) . '">' . esc_html( $label ) . '</a>';
            }
            return '<a href="' . esc_attr( $href ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label ) . '</a>';
        },
        $text
    );
    return $text;
}

function li_manual_anchor( $text ) {
    $text = strtolower( $text );
    $text = preg_replace( '/[^a-z0-9\- ]+/', '', $text );
    $text = str_replace( ' ', '-', trim( $text ) );
    $text = preg_replace( '/-+/', '-', $text );
    return $text;
}
