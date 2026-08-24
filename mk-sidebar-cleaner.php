<?php
/**
 * Plugin Name: MK Sidebar Cleaner
 * Plugin URI:  https://github.com/meksone/mk-sidebar-cleaner
 * Description: Tidy up the WP admin sidebar. Hide or relocate items, create custom groups. Superadmin bypass, per-admin personal config, global default.
 * Version:     1.2.12
 * Author:      Manuel Serrenti (meksONE)
 * Author URI:  https://meksone.com
 * License:     GPL-2.0+
 * Text Domain: mk-sidebar-cleaner
 */

defined( 'ABSPATH' ) || exit;

define( 'MKSC_VERSION', '1.2.12' );
define( 'MKSC_GITHUB',  'meksone/mk-sidebar-cleaner' );
define( 'MKSC_SLUG',    'mk-sidebar-cleaner/mk-sidebar-cleaner.php' );
define( 'MKSC_DIR',     plugin_dir_path( __FILE__ ) );
define( 'MKSC_URL',     plugin_dir_url( __FILE__ ) );

// ─────────────────────────────────────────────
// GITHUB UPDATER
// ─────────────────────────────────────────────

/**
 * Fetches the latest GitHub release, cached in a transient to avoid hitting
 * the (unauthenticated, 60 req/hr) GitHub API on every admin page load.
 */
function mksc_get_latest_release() {
    $cache_key = 'mksc_latest_release';
    $cached    = get_transient( $cache_key );
    if ( $cached !== false ) return $cached;

    $response = wp_remote_get(
        'https://api.github.com/repos/' . MKSC_GITHUB . '/releases/latest',
        [ 'headers' => [ 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) ] ]
    );

    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) return null;

    $release = json_decode( wp_remote_retrieve_body( $response ) );
    if ( empty( $release->tag_name ) ) return null;

    set_transient( $cache_key, $release, 12 * HOUR_IN_SECONDS );
    return $release;
}

/**
 * Picks the .zip release asset if present, falling back to the source zipball.
 */
function mksc_get_release_zip_url( $release ) {
    if ( ! empty( $release->assets ) ) {
        foreach ( $release->assets as $asset ) {
            if ( str_ends_with( $asset->name, '.zip' ) ) {
                return $asset->browser_download_url;
            }
        }
    }
    return $release->zipball_url;
}

add_filter( 'pre_set_site_transient_update_plugins', function ( $transient ) {
    if ( empty( $transient->checked ) ) return $transient;

    $release = mksc_get_latest_release();
    if ( ! $release ) return $transient;

    $latest = ltrim( $release->tag_name, 'v' );
    if ( ! version_compare( $latest, MKSC_VERSION, '>' ) ) return $transient;

    $transient->response[ MKSC_SLUG ] = (object) [
        'slug'        => 'mk-sidebar-cleaner',
        'plugin'      => MKSC_SLUG,
        'new_version' => $latest,
        'url'         => 'https://github.com/' . MKSC_GITHUB,
        'package'     => mksc_get_release_zip_url( $release ),
    ];

    return $transient;
} );

add_filter( 'plugins_api', function ( $result, $action, $args ) {
    if ( $action !== 'plugin_information' || ( $args->slug ?? '' ) !== 'mk-sidebar-cleaner' ) return $result;

    $release = mksc_get_latest_release();
    if ( ! $release ) return $result;

    return (object) [
        'name'          => 'MK Sidebar Cleaner',
        'slug'          => 'mk-sidebar-cleaner',
        'version'       => ltrim( $release->tag_name, 'v' ),
        'author'        => '<a href="https://meksone.com">meksONE</a>',
        'homepage'      => 'https://github.com/' . MKSC_GITHUB,
        'download_link' => mksc_get_release_zip_url( $release ),
        'sections'      => [ 'description' => $release->body ?? '' ],
    ];
}, 10, 3 );

require_once MKSC_DIR . 'includes/class-config.php';
require_once MKSC_DIR . 'includes/class-rules-engine.php';
require_once MKSC_DIR . 'includes/class-admin-page.php';

add_action( 'plugins_loaded', function () {
	$config = new MK_Sidebar_Cleaner_Config();
	( new MK_Sidebar_Cleaner_Rules_Engine( $config ) )->hook();
	( new MK_Sidebar_Cleaner_Admin_Page( $config ) )->hook();
} );
