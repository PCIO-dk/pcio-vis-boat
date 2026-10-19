<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Boat Register admin page for PCIO VIS Boat.
 *
 * Registers a rewrite rule for /vis/boats and renders the Boat Register admin
 * page, gated behind login + vis_boat_manage capability. The page is a
 * REST-driven single-page UI (boat-admin.js) that manages boats and crew.
 */
class PCIO_VIS_Boat_Admin {

	public function register(): void {
		add_action( 'init',              [ $this, 'register_rewrites' ] );
		add_action( 'template_redirect', [ $this, 'serve' ] );
	}

	public function register_rewrites(): void {
		add_rewrite_rule( '^vis/boats/?$', 'index.php?pcio_me_boat_admin=1', 'top' );
		add_rewrite_tag( '%pcio_me_boat_admin%', '([0-9]+)' );
	}

	public function serve(): void {
		if ( ! get_query_var( 'pcio_me_boat_admin' ) ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			auth_redirect();
			exit;
		}
		if ( ! current_user_can( 'vis_boat_manage' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access this page.', 'pcio-vis-boat' ),
				esc_html__( 'Access denied', 'pcio-vis-boat' ),
				[ 'response' => 403 ]
			);
		}

		$this->enqueue_assets();

		status_header( 200 );
		nocache_headers();
		require PCIO_VIS_BOAT_DIR . 'templates/admin.php';
		exit;
	}

	private function enqueue_assets(): void {
		// Reuse the core Vis stylesheet — table, header, modals, buttons, toast.
		if ( defined( 'PCIO_VIS_PLUGIN_FILE' ) ) {
			wp_enqueue_style(
				'pcio-me-app',
				plugins_url( 'assets/members.css', PCIO_VIS_PLUGIN_FILE ),
				[],
				'1.4.0'
			);
		}

		wp_enqueue_style(
			'pcio-me-boat-admin',
			plugins_url( 'assets/boat-admin.css', PCIO_VIS_BOAT_FILE ),
			[],
			PCIO_VIS_BOAT_VERSION
		);

		wp_enqueue_script(
			'pcio-me-boat-admin',
			plugins_url( 'assets/boat-admin.js', PCIO_VIS_BOAT_FILE ),
			[ 'pcio-vis-modal-guard' ],
			PCIO_VIS_BOAT_VERSION . '.1',
			true
		);

		// IMPORTANT: top-level `const` does NOT attach to window — assign explicitly.
		wp_add_inline_script(
			'pcio-me-boat-admin',
			'window.PCIO_VIS_Boat_ADMIN = ' . wp_json_encode( [
				'rest'        => rest_url( PCIO_VIS_REST_NS . '/boat' ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'harboursRest'=> rest_url( PCIO_VIS_REST_NS . '/boat/harbours' ),
				'membersUrl'  => home_url( 'vis/members' ),
				'canViewMembers' => current_user_can( 'vis_manage_users' ) || current_user_can( 'vis_member' ),
				'i18n'  => [
					'pageTitle'        => __( 'Boat Register', 'pcio-vis-boat' ),
					'newBoat'          => __( '+ New boat', 'pcio-vis-boat' ),
					'boatName'         => __( 'Boat name', 'pcio-vis-boat' ),
					'boatType'         => __( 'Type', 'pcio-vis-boat' ),
					'boatModel'        => __( 'Model', 'pcio-vis-boat' ),
					'homeHarbour'      => __( 'Home harbour', 'pcio-vis-boat' ),
					'buildYear'        => __( 'Build year', 'pcio-vis-boat' ),
					'sailId'           => __( 'Sail number', 'pcio-vis-boat' ),
					'mmsi'             => __( 'MMSI', 'pcio-vis-boat' ),
					'crew'             => __( 'Crew', 'pcio-vis-boat' ),
					'ownerMemberId'    => __( 'Owner member #', 'pcio-vis-boat' ),
					'addCrew'          => __( 'Add crew member', 'pcio-vis-boat' ),
					'memberNo'         => __( 'Member #', 'pcio-vis-boat' ),
					'subscriptionId'   => __( 'Subscription ID', 'pcio-vis-boat' ),
					'subscriptionHint' => __( 'Optional. Link to a pcio-vis-products subscription row.', 'pcio-vis-boat' ),
					'active'           => __( 'Active', 'pcio-vis-boat' ),
					'expires'          => __( 'Expires', 'pcio-vis-boat' ),
					'note'             => __( 'Notes', 'pcio-vis-boat' ),
					'edit'             => __( 'Edit', 'pcio-vis-boat' ),
					'delete'           => __( 'Delete', 'pcio-vis-boat' ),
					'remove'           => __( 'Remove', 'pcio-vis-boat' ),
					'save'             => __( 'Save', 'pcio-vis-boat' ),
					'cancel'           => __( 'Cancel', 'pcio-vis-boat' ),
					'confirmDelete'    => __( 'Delete this boat and all its crew links?', 'pcio-vis-boat' ),
					'noBoats'          => __( 'No boats registered yet.', 'pcio-vis-boat' ),
					'noCrew'           => __( 'No crew members linked.', 'pcio-vis-boat' ),
					'saveFailed'       => __( 'Save failed. Please try again.', 'pcio-vis-boat' ),
					'editBoat'         => __( 'Edit boat', 'pcio-vis-boat' ),
					'createBoat'       => __( 'New boat', 'pcio-vis-boat' ),
					'manageCrew'       => __( 'Crew', 'pcio-vis-boat' ),
					'deleteWarning'    => __( 'This will also remove all crew links.', 'pcio-vis-boat' ),
					'yes'              => __( 'Yes', 'pcio-vis-boat' ),
					'no'               => __( 'No', 'pcio-vis-boat' ),
					'search'           => __( 'Search…', 'pcio-vis-boat' ),
					'add'              => __( 'Add', 'pcio-vis-boat' ),
					'close'            => __( 'Close', 'pcio-vis-boat' ),
					'member'           => __( 'Member', 'pcio-vis-boat' ),
					'filterWord'       => __( 'Filter', 'pcio-vis-boat' ),
					'showing'          => __( 'Showing', 'pcio-vis-boat' ),
					'of'               => __( 'of', 'pcio-vis-boat' ),
					'boats'            => __( 'boats', 'pcio-vis-boat' ),
					'noMatch'          => __( 'No matches.', 'pcio-vis-boat' ),
				],
			] ) . ';',
			'before'
		);
	}
}
