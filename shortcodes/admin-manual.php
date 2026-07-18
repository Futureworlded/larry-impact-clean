<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', 'li_register_manual_page', 20 );
function li_register_manual_page() {
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
    $manual_path = plugin_dir_path( __FILE__ ) . '../LARRY_IMPACT_OPERATIONS_MANUAL.md';
    if ( ! file_exists( $manual_path ) ) {
        echo '<div class="wrap"><h1>Operations Manual</h1><p>Manual file not found.</p></div>';
        return;
    }

    $md = file_get_contents( $manual_path );
    if ( false === $md ) {
        echo '<div class="wrap"><h1>Operations Manual</h1><p>Could not read manual file.</p></div>';
        return;
    }

    echo '<div class="wrap li-manual-page">';
    echo '<h1>Larry Impact Operations Manual</h1>';
    echo '<div style="max-width:1000px;background:#fff;border:1px solid #ddd;padding:24px;border-radius:6px;">';
    echo li_markdown_to_html( $md );
    echo '</div></div>';
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
            $level = strlen( $matches[1] );
            $output .= '<h' . $level . ' style="margin-top:24px;margin-bottom:12px;">' . esc_html( $matches[2] ) . '</h' . $level . '>';
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
    $text = preg_replace( '/\[([^\]]+)\]\(([^\)]+)\)/', '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>', $text );
    return $text;
}
