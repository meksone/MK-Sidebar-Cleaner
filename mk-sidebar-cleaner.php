<?php
/**
 * Plugin Name: MK Sidebar Cleaner
 * Description: Tidy up the WP admin sidebar. Hide or relocate items, create custom groups. Superadmin bypass, per-admin personal config, global default.
 * Version:     1.2.9
 * Author:      MK
 * Text Domain: mk-sidebar-cleaner
 */

defined( 'ABSPATH' ) || exit;

define( 'MKSC_VERSION', '1.2.9' );
define( 'MKSC_DIR',     plugin_dir_path( __FILE__ ) );
define( 'MKSC_URL',     plugin_dir_url( __FILE__ ) );

require_once MKSC_DIR . 'includes/class-config.php';
require_once MKSC_DIR . 'includes/class-rules-engine.php';
require_once MKSC_DIR . 'includes/class-admin-page.php';

add_action( 'plugins_loaded', function () {
	$config = new MK_Sidebar_Cleaner_Config();
	( new MK_Sidebar_Cleaner_Rules_Engine( $config ) )->hook();
	( new MK_Sidebar_Cleaner_Admin_Page( $config ) )->hook();
} );
