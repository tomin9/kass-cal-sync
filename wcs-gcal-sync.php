<?php
/**
 * Plugin Name:  WCS Google Calendar Sync
 * Description:  Synchronizuje podujatia z Events Schedule WP Plugin (Curly Themes) do Google Calendar.
 * Version:      1.2.0
 * Author:       Custom
 * Text Domain:  wcs-gcal-sync
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'WCS_GCAL_VERSION',    '1.2.0' );
define( 'WCS_GCAL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCS_GCAL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once WCS_GCAL_PLUGIN_DIR . 'includes/class-wcs-gcal-auth.php';
require_once WCS_GCAL_PLUGIN_DIR . 'includes/class-wcs-gcal-api.php';
require_once WCS_GCAL_PLUGIN_DIR . 'includes/class-wcs-gcal-sync.php';
require_once WCS_GCAL_PLUGIN_DIR . 'includes/class-wcs-gcal-admin.php';

/**
 * Inicializácia pluginu – spustí sa po načítaní všetkých pluginov.
 */
function wcs_gcal_init(): void {
    $auth  = new WCS_GCal_Auth();
    $api   = new WCS_GCal_API( $auth );
    $sync  = new WCS_GCal_Sync( $api );
    $admin = new WCS_GCal_Admin( $auth, $api, $sync );

    $sync->register_hooks();
    $admin->register_hooks();
}
add_action( 'plugins_loaded', 'wcs_gcal_init' );
