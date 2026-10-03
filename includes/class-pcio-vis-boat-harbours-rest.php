<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST endpoints for harbour management.
 *
 *   GET  /boat/harbours          list all harbours — public, non-personal
 *                                reference data (marina names + coordinates)
 *                                needed by the public sign-up harbour picker.
 *   POST /boat/harbours          create a new harbour — requires vis_boat_manage.
 *   GET  /boat/harbour-members   harbours with member names/numbers for the map —
 *                                requires vis_member (personal data).
 */
class PCIO_VIS_Boat_Harbours_Rest {

	public function register(): void {
		$ns = PCIO_VIS_REST_NS;

		register_rest_route( $ns, '/boat/harbours', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'list_harbours' ],
				// Public: harbour names + coordinates only, no personal data. The
				// anonymous sign-up form needs this list to power the home-harbour
				// picker before the visitor has an account.
				'permission_callback' => '__return_true',
			],
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_harbour' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'args'                => [
					'name' => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
					'lat'  => [ 'type' => 'number', 'required' => true ],
					'lon'  => [ 'type' => 'number', 'required' => true ],
				],
			],
		] );

		register_rest_route( $ns, '/boat/harbours/manage', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'list_harbours_manage' ],
				'permission_callback' => [ $this, 'can_manage' ],
			],
		] );

		register_rest_route( $ns, '/boat/harbours/(?P<id>\d+)', [
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'update_harbour' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'args'                => [
					'id' => self::id_arg(),
					'name' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
					'lat'  => [ 'type' => 'number' ],
					'lon'  => [ 'type' => 'number' ],
				],
			],
			[
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_harbour' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'args'                => [ 'id' => self::id_arg() ],
			],
		] );

		register_rest_route( $ns, '/boat/harbour-members', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'list_harbour_members' ],
			'permission_callback' => [ $this, 'can_view_members' ],
		] );
	}

	// ── Permissions ───────────────────────────────────────────────

	/** Only staff with the boat-register capability may create shared harbours. */
	public function can_manage(): bool {
		return current_user_can( 'vis_boat_manage' );
	}

	/** Harbour-member data exposes member names/numbers — members only. */
	public function can_view_members(): bool {
		return current_user_can( 'vis_member' );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function id_arg(): array {
		return [
			'validate_callback' => static fn( $v ) => is_numeric( $v ),
			'sanitize_callback' => 'absint',
		];
	}

	// ── Handlers ──────────────────────────────────────────────────

	public function list_harbours(): WP_REST_Response {
		return new WP_REST_Response( PCIO_VIS_Boat_Harbours_DB::get_all(), 200 );
	}

	public function list_harbours_manage(): WP_REST_Response {
		return new WP_REST_Response( PCIO_VIS_Boat_Harbours_DB::get_all_for_manage(), 200 );
	}

	public function create_harbour( WP_REST_Request $req ): WP_REST_Response {
		$name = substr( sanitize_text_field( (string) $req->get_param( 'name' ) ), 0, 128 );
		$lat  = (float) $req->get_param( 'lat' );
		$lon  = (float) $req->get_param( 'lon' );

		if ( '' === $name ) {
			return new WP_REST_Response( [ 'message' => __( 'Harbour name is required.', 'pcio-vis-boat' ) ], 400 );
		}
		if ( $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180 ) {
			return new WP_REST_Response( [ 'message' => __( 'Invalid coordinates.', 'pcio-vis-boat' ) ], 400 );
		}
		if ( PCIO_VIS_Boat_Harbours_DB::name_exists( $name ) ) {
			return new WP_REST_Response(
				[ 'message' => sprintf( __( 'A harbour named %s already exists.', 'pcio-vis-boat' ), $name ) ],
				409
			);
		}

		$id = PCIO_VIS_Boat_Harbours_DB::create( $name, round( $lat, 6 ), round( $lon, 6 ) );
		if ( ! $id ) {
			return new WP_REST_Response( [ 'message' => __( 'Could not save harbour.', 'pcio-vis-boat' ) ], 500 );
		}

		return new WP_REST_Response( PCIO_VIS_Boat_Harbours_DB::get( $id ), 201 );
	}

	public function update_harbour( WP_REST_Request $req ): WP_REST_Response {
		$id   = (int) $req->get_param( 'id' );
		$name = substr( sanitize_text_field( (string) $req->get_param( 'name' ) ), 0, 128 );
		$lat  = (float) $req->get_param( 'lat' );
		$lon  = (float) $req->get_param( 'lon' );

		$harbour = PCIO_VIS_Boat_Harbours_DB::get( $id );
		if ( ! $harbour ) {
			return new WP_REST_Response( [ 'message' => __( 'Harbour not found.', 'pcio-vis-boat' ) ], 404 );
		}

		if ( '' === $name ) {
			return new WP_REST_Response( [ 'message' => __( 'Harbour name is required.', 'pcio-vis-boat' ) ], 400 );
		}
		if ( $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180 ) {
			return new WP_REST_Response( [ 'message' => __( 'Invalid coordinates.', 'pcio-vis-boat' ) ], 400 );
		}
		if ( PCIO_VIS_Boat_Harbours_DB::name_exists( $name, $id ) ) {
			return new WP_REST_Response(
				[ 'message' => sprintf( __( 'A harbour named %s already exists.', 'pcio-vis-boat' ), $name ) ],
				409
			);
		}

		PCIO_VIS_Boat_Harbours_DB::update( $id, $name, round( $lat, 6 ), round( $lon, 6 ) );
		return new WP_REST_Response( PCIO_VIS_Boat_Harbours_DB::get( $id ), 200 );
	}

	public function delete_harbour( WP_REST_Request $req ): WP_REST_Response {
		$id = (int) $req->get_param( 'id' );

		$harbour = PCIO_VIS_Boat_Harbours_DB::get( $id );
		if ( ! $harbour ) {
			return new WP_REST_Response( [ 'message' => __( 'Harbour not found.', 'pcio-vis-boat' ) ], 404 );
		}

		$count = PCIO_VIS_Boat_Harbours_DB::boat_count( $id );
		if ( $count > 0 ) {
			return new WP_REST_Response(
				[ 'message' => sprintf(
					_n(
						/* translators: %s: harbour name, %d: boat count */
						__( 'Harbour "%s" is used by %d boat. Move it to another harbour first.', 'pcio-vis-boat' ),
						__( 'Harbour "%s" is used by %d boats. Move them to another harbour first.', 'pcio-vis-boat' ),
						$count
					),
					$harbour['name'],
					$count
				) ],
				409
			);
		}

		PCIO_VIS_Boat_Harbours_DB::delete( $id );
		return new WP_REST_Response( [ 'deleted' => true ], 200 );
	}

	public function list_harbour_members(): WP_REST_Response {
		return new WP_REST_Response( PCIO_VIS_Boat_Harbours_DB::get_harbour_members(), 200 );
	}
}
