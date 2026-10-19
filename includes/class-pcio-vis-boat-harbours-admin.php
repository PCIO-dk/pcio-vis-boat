<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Harbours management admin page for PCIO VIS Boat.
 *
 * Registers a rewrite rule for /vis/harbours and renders the Harbours management
 * page, gated behind login + vis_boat_manage capability. The page is a
 * REST-driven single-page UI (harbours-admin.js) that manages harbours with a
 * Leaflet draggable-marker modal.
 *
 * A wp-admin submenu entry under Vis → Harbours redirects to the front-end page.
 */
class PCIO_VIS_Boat_Harbours_Admin {

	public function register(): void {
		add_action( 'init',              [ $this, 'register_rewrites' ] );
		add_action( 'template_redirect', [ $this, 'serve' ] );
	}

	public function register_rewrites(): void {
		add_rewrite_rule( '^vis/harbours/?$', 'index.php?pcio_me_harbour_admin=1', 'top' );
		add_rewrite_tag( '%pcio_me_harbour_admin%', '([0-9]+)' );
	}

	/**
	 * wp-admin submenu callback — redirect to the front-end management page.
	 */
	public function serve_wp_admin(): void {
		if ( ! current_user_can( 'vis_boat_manage' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access this page.', 'pcio-vis-boat' ),
				esc_html__( 'Access denied', 'pcio-vis-boat' ),
				[ 'response' => 403 ]
			);
		}
		wp_safe_redirect( home_url( 'vis/harbours/' ) );
		exit;
	}

	public function serve(): void {
		if ( ! get_query_var( 'pcio_me_harbour_admin' ) ) {
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
		require PCIO_VIS_BOAT_DIR . 'templates/harbours-admin.php';
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

		// Leaflet map (bundled, no CDN).
		PCIO_VIS_Boat_Harbours_Map::register_leaflet();
		wp_enqueue_style( 'leaflet' );
		wp_enqueue_script( 'leaflet' );

		wp_enqueue_style(
			'pcio-vis-boat-harbour-map',
			PCIO_VIS_BOAT_URL . 'assets/harbour-map.css',
			[ 'leaflet' ],
			PCIO_VIS_BOAT_VERSION
		);

		wp_enqueue_style(
			'pcio-vis-boat-harbours-admin',
			plugins_url( 'assets/harbours-admin.css', PCIO_VIS_BOAT_FILE ),
			[ 'pcio-vis-boat-harbour-map' ],
			PCIO_VIS_BOAT_VERSION
		);

		wp_enqueue_script(
			'pcio-vis-boat-harbours-admin',
			plugins_url( 'assets/harbours-admin.js', PCIO_VIS_BOAT_FILE ),
			[ 'leaflet', 'pcio-vis-modal-guard' ],
			PCIO_VIS_BOAT_VERSION . '.1',
			true
		);

		// IMPORTANT: top-level `const` does NOT attach to window — assign explicitly.
		wp_add_inline_script(
			'pcio-vis-boat-harbours-admin',
			'window.PCIO_VIS_Boat_Harbours_ADMIN = ' . wp_json_encode( [
				'rest'     => rest_url( PCIO_VIS_REST_NS . '/boat' ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'mapCenter' => [ 56.0, 10.5 ],
				'mapZoom'   => 6,
				'i18n'     => [
					'pageTitle'      => __( 'Harbours', 'pcio-vis-boat' ),
					'newHarbour'     => __( '+ New harbour', 'pcio-vis-boat' ),
					'harbourName'    => __( 'Harbour name', 'pcio-vis-boat' ),
					'latitude'       => __( 'Latitude', 'pcio-vis-boat' ),
					'longitude'      => __( 'Longitude', 'pcio-vis-boat' ),
					'boats'          => __( 'boats', 'pcio-vis-boat' ),
					'boat'           => __( 'boat', 'pcio-vis-boat' ),
					'edit'           => __( 'Edit', 'pcio-vis-boat' ),
					'delete'         => __( 'Delete', 'pcio-vis-boat' ),
					'save'           => __( 'Save', 'pcio-vis-boat' ),
					'cancel'         => __( 'Cancel', 'pcio-vis-boat' ),
					'close'          => __( 'Close', 'pcio-vis-boat' ),
					'confirmDelete'  => __( 'Delete this harbour?', 'pcio-vis-boat' ),
					'moveBoats'      => __( 'Move the boats to another harbour first.', 'pcio-vis-boat' ),
					'noHarbours'     => __( 'No harbours registered yet.', 'pcio-vis-boat' ),
					'noMatch'        => __( 'No matches.', 'pcio-vis-boat' ),
					'search'         => __( 'Search…', 'pcio-vis-boat' ),
					'saveFailed'     => __( 'Save failed. Please try again.', 'pcio-vis-boat' ),
					'showing'        => __( 'Showing', 'pcio-vis-boat' ),
					'of'             => __( 'of', 'pcio-vis-boat' ),
					'errCoords'      => __( 'Please enter valid coordinates within the allowed range.', 'pcio-vis-boat' ),
					'errDelete'      => __( 'Delete failed. Please try again.', 'pcio-vis-boat' ),
				],
			] ) . ';',
			'before'
		);
	}
}
