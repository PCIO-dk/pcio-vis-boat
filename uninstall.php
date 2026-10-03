<?php
/**
 * Uninstall handler for PCIO VIS Boat.
 *
 * Runs only when the plugin is deleted from the WordPress admin. This file runs
 * in a minimal WP context, so plugin classes are not loaded and the option name
 * is listed explicitly.
 *
 * Removes the schema-version option so that a later reinstall rebuilds the
 * tables from scratch instead of inheriting a stale version marker.
 *
 * NOTE: the two custom tables are intentionally NOT dropped:
 *
 *     {prefix}me_boat_boats
 *     {prefix}me_boat_harbours
 *
 * They hold the club's boat register and harbour list — real club data that an
 * administrator may want to export before the plugin goes away — so we do not
 * destroy them automatically on delete. A pre-1.1 site may also still carry the
 * now-unused {prefix}me_boat_crew table; 1.1 drops it on upgrade, and it can be
 * removed by hand afterwards.
 *
 * To wipe everything, drop the two tables above from phpMyAdmin or a SQL client,
 * or call PCIO_VIS_Boat_DB::drop_tables() deliberately from your own code.
 *
 * @package PCIO_VIS_Boat
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'pcio_me_boat_db_version' );