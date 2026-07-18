<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function li_register_menu() {
    add_menu_page( 'Larry Impact', 'Larry Impact', 'manage_options', 'li-rescues', 'li_page_rescues', 'dashicons-heart', 30 );
    add_submenu_page( 'li-rescues', 'Rescues',          'Rescues',          'manage_options', 'li-rescues',    'li_page_rescues' );
    add_submenu_page( 'li-rescues', 'Applications',     'Applications',     'manage_options', 'li-apps',       'li_page_applications' );
    add_submenu_page( 'li-rescues', 'Split Dashboard',  'Split Dashboard',  'manage_options', 'li-splits',     'li_page_splits' );
    add_submenu_page( 'li-rescues', 'Split Configurator','Split Configurator','manage_options','li-split-config','li_page_split_config' );
    add_submenu_page( 'li-rescues', 'Payouts',          'Payouts',          'manage_options', 'li-payouts',    'li_page_payouts' );
    add_submenu_page( 'li-rescues', 'Settings',         'Settings',         'manage_options', 'li-settings',   'li_page_settings' );
}
add_action( 'admin_menu', 'li_register_menu' );

