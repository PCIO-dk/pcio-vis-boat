<?php
/*
Plugin Name:  PCIO VIS Boat
Plugin URI:   https://www.pcio.dk/
Description:  Extends PCIO VIS Member Event with a boat club register: boats owned by a membership number with everyone on that number as crew, a member self-service boat profile, and Boat Register and Harbours admin pages. Fields: home harbour, boat name, type, model, build year, sail number and MMSI.
Version:      1.2.0
Author:       PCIO
Author URI:   https://www.pcio.dk
Requires at least: 6.2
Requires PHP: 8.1
Requires Plugins: pcio-vis-member-event, pcio-vis-tickets, pcio-vis-products
License:      GPL-2.0-or-later
License URI:  https://www.gnu.org/licenses/gpl-2.0.html
Text Domain:  pcio-vis-boat
Domain Path:  /languages
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PCIO_VIS_BOAT_FILE',    __FILE__ );
define( 'PCIO_VIS_BOAT_DIR',     plugin_dir_path( __FILE__ ) );
define( 'PCIO_VIS_BOAT_URL',     plugin_dir_url( __FILE__ ) );
define( 'PCIO_VIS_BOAT_VERSION', '1.1' );
define( 'PCIO_VIS_BOAT_MEMBERSHIP_PRODUCT', 'club-membership' );

require_once PCIO_VIS_BOAT_DIR . 'includes/class-pcio-vis-boat-db.php';
require_once PCIO_VIS_BOAT_DIR . 'includes/class-pcio-vis-boat-rest.php';
require_once PCIO_VIS_BOAT_DIR . 'includes/class-pcio-vis-boat-admin.php';
require_once PCIO_VIS_BOAT_DIR . 'includes/class-pcio-vis-boat-integration.php';
require_once PCIO_VIS_BOAT_DIR . 'includes/class-pcio-vis-boat-signup.php';
require_once PCIO_VIS_BOAT_DIR . 'includes/class-pcio-vis-boat-harbours-db.php';
require_once PCIO_VIS_BOAT_DIR . 'includes/class-pcio-vis-boat-harbours-rest.php';
require_once PCIO_VIS_BOAT_DIR . 'includes/class-pcio-vis-boat-harbours-map.php';
require_once PCIO_VIS_BOAT_DIR . 'includes/class-pcio-vis-boat-harbours-admin.php';
require_once PCIO_VIS_BOAT_DIR . 'includes/class-pcio-vis-boat-profile.php';

/**
 * Main plugin bootstrap for PCIO VIS Boat.
 */
class PCIO_VIS_Boat_Plugin {

	/**
	 * Capabilities granted to WP roles on activation.
	 */
	const CAP_ROLE_MAP = [
		'vis_boat_manage' => [ 'editor', 'administrator' ],
	];

	public static function register(): void {
		add_action( 'admin_init',    [ 'PCIO_VIS_Boat_DB', 'install' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'register_rest' ] );
		add_filter( 'pcio_me_history_tables', [ 'PCIO_VIS_Boat_DB', 'history_tables' ] );
		add_filter( 'pcio_me_history_tables', [ 'PCIO_VIS_Boat_Harbours_DB', 'history_tables' ] );

		( new PCIO_VIS_Boat_Admin() )->register();
		( new PCIO_VIS_Boat_Harbours_Admin() )->register();
		( new PCIO_VIS_Boat_Integration() )->register();
		( new PCIO_VIS_Boat_Signup() )->register();
		( new PCIO_VIS_Boat_Harbours_Map() )->register();
		( new PCIO_VIS_Boat_Profile() )->register();
	}

	public static function register_rest(): void {
		( new PCIO_VIS_Boat_Rest() )->register();
		( new PCIO_VIS_Boat_Harbours_Rest() )->register();
	}

	// ── Activation / deactivation ─────────────────────────────────

	public static function activate(): void {
		PCIO_VIS_Boat_DB::install();
		foreach ( self::CAP_ROLE_MAP as $cap => $roles ) {
			foreach ( $roles as $role_slug ) {
				$role = get_role( $role_slug );
				if ( $role ) {
					$role->add_cap( $cap );
				}
			}
		}
		( new PCIO_VIS_Boat_Admin() )->register_rewrites();
		( new PCIO_VIS_Boat_Harbours_Admin() )->register_rewrites();
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		foreach ( array_keys( self::CAP_ROLE_MAP ) as $cap ) {
			foreach ( wp_roles()->role_objects as $role ) {
				$role->remove_cap( $cap );
			}
		}
		flush_rewrite_rules();
	}
}

register_activation_hook( __FILE__, [ 'PCIO_VIS_Boat_Plugin', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'PCIO_VIS_Boat_Plugin', 'deactivate' ] );

add_action( 'plugins_loaded', static function (): void {
	// "Requires Plugins" header blocks activation on WP 6.5+; these guards cover
	// older cores and runtime deactivation of a dependency.
	$pcio_vis_boat_missing = [];
	if ( ! class_exists( 'PCIO_VIS_Plugin' ) ) {
		$pcio_vis_boat_missing[] = 'pcio-vis-member-event';
	}
	if ( ! class_exists( 'PCIO_VIS_Products_DB' ) ) {
		$pcio_vis_boat_missing[] = 'pcio-vis-products';
	}
	if ( $pcio_vis_boat_missing ) {
		add_action( 'admin_notices', static function () use ( $pcio_vis_boat_missing ): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p>'
				. sprintf(
					/* translators: %s: comma-separated plugin slugs */
					esc_html__( 'Member Event – Boat requires these plugins to be active: %s.', 'pcio-vis-boat' ),
					esc_html( implode( ', ', $pcio_vis_boat_missing ) )
				)
				. '</p></div>';
		} );
		return;
	}
	PCIO_VIS_Boat_Plugin::register();
} );
