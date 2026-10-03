<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for PCIO VIS Boat (namespace pcio-vis/v1).
 *
 * Admin (vis_boat_manage)
 *   GET    /boat/boats                          all boats
 *   POST   /boat/boats                          create boat
 *   GET    /boat/boats/{id}                     single boat
 *   PUT    /boat/boats/{id}                     update boat fields
 *   DELETE /boat/boats/{id}                     delete boat + crew
 *   GET    /boat/boats/{id}/crew                crew list for a boat
 *   POST   /boat/boats/{id}/crew                add crew member
 *   DELETE /boat/boats/{id}/crew/{mid}          remove crew member
 *
 * Member / Admin
 *   GET    /boat/member/{member_id}             boats for a member (owner or crew)
 */
class PCIO_VIS_Boat_Rest {

	public function register(): void {
		$ns = PCIO_VIS_REST_NS;

		register_rest_route( $ns, '/boat/boats', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'list_boats' ],
				'permission_callback' => [ $this, 'can_manage' ],
			],
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_boat' ],
				'permission_callback' => [ $this, 'can_manage' ],
			],
		] );

		register_rest_route( $ns, '/boat/boats/(?P<id>\d+)', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_boat_by_id' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'args'                => [ 'id' => self::id_arg() ],
			],
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'update_boat' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'args'                => [ 'id' => self::id_arg() ],
			],
			[
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_boat' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'args'                => [ 'id' => self::id_arg() ],
			],
		] );

		register_rest_route( $ns, '/boat/boats/(?P<id>\d+)/crew', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'list_crew' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'args'                => [ 'id' => self::id_arg() ],
			],
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'add_crew' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'args'                => [ 'id' => self::id_arg() ],
			],
		] );

		register_rest_route( $ns, '/boat/boats/(?P<id>\d+)/crew/(?P<mid>\d+)', [
			[
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'remove_crew' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'args'                => [ 'id' => self::id_arg(), 'mid' => self::id_arg() ],
			],
		] );

		// Member view: returns all boats where the given member is owner or crew.
		// Accessible by admins and by the member themselves (matched via WP user link).
		register_rest_route( $ns, '/boat/member/(?P<member_id>\d+)', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'member_boats' ],
				'permission_callback' => [ $this, 'can_view_member' ],
				'args'                => [ 'member_id' => self::id_arg() ],
			],
		] );

		// Member self-service: a vis_member may update their own boat's details.
		register_rest_route( $ns, '/boat/my-boat/(?P<id>\d+)', [
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'update_my_boat' ],
				'permission_callback' => [ $this, 'can_edit_own_boat' ],
				'args'                => [ 'id' => self::id_arg() ],
			],
		] );

		// Member self-service: an owner may list and add crew on their own boat.
		register_rest_route( $ns, '/boat/my-boat/(?P<id>\d+)/crew', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'list_my_crew' ],
				'permission_callback' => [ $this, 'can_edit_own_boat' ],
				'args'                => [ 'id' => self::id_arg() ],
			],
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'add_my_crew' ],
				'permission_callback' => [ $this, 'can_edit_own_boat' ],
				'args'                => [ 'id' => self::id_arg() ],
			],
		] );

		register_rest_route( $ns, '/boat/my-boat/(?P<id>\d+)/crew/(?P<mid>\d+)', [
			[
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'remove_my_crew' ],
				'permission_callback' => [ $this, 'can_edit_own_boat' ],
				'args'                => [ 'id' => self::id_arg(), 'mid' => self::id_arg() ],
			],
		] );
	}

	private static function id_arg(): array {
		return [
			'validate_callback' => static fn( $v ) => is_numeric( $v ),
			'sanitize_callback' => 'absint',
		];
	}

	// ── Permissions ───────────────────────────────────────────────

	public function can_manage(): bool {
		return current_user_can( 'vis_boat_manage' );
	}

	/** Member may update only their own boat (verified by member_number). */
	public function can_edit_own_boat( WP_REST_Request $req ): bool {
		if ( ! current_user_can( 'vis_member' ) || ! class_exists( 'PCIO_VIS_Roles_DB' ) ) {
			return false;
		}
		$member_id = PCIO_VIS_Roles_DB::get_member_id_by_wp_user( get_current_user_id() );
		$member_number = PCIO_VIS_Roles_DB::get_member_number_by_wp_user( get_current_user_id() );
		if ( ! $member_id ) {
			return false;
		}
		$boat = PCIO_VIS_Boat_DB::get_boat_by_id( (int) $req->get_param( 'id' ) );
		return $boat && (int) $boat['member_number'] === $member_number;
	}

	/**
	 * Admins may view any member's boats; a logged-in member may view their own.
	 */
	public function can_view_member( WP_REST_Request $req ): bool {
		if ( current_user_can( 'vis_boat_manage' ) ) {
			return true;
		}
		if ( ! is_user_logged_in() || ! class_exists( 'PCIO_VIS_Roles_DB' ) ) {
			return false;
		}
		$member_id = PCIO_VIS_Roles_DB::get_member_id_by_wp_user( get_current_user_id() );
		return $member_id > 0 && $member_id === (int) $req->get_param( 'member_id' );
	}

	// ── Handlers: boats ───────────────────────────────────────────

	public function list_boats(): WP_REST_Response {
		$boats = PCIO_VIS_Boat_DB::get_boats();
		return new WP_REST_Response( array_map( [ $this, 'enrich_boat' ], $boats ), 200 );
	}

	public function get_boat_by_id( WP_REST_Request $req ): WP_REST_Response {
		$boat = PCIO_VIS_Boat_DB::get_boat_by_id( (int) $req->get_param( 'id' ) );
		if ( ! $boat ) {
			return new WP_REST_Response( [ 'message' => __( 'Boat not found.', 'pcio-vis-boat' ) ], 404 );
		}
		return new WP_REST_Response( $this->enrich_boat( $boat ), 200 );
	}

	public function create_boat( WP_REST_Request $req ): WP_REST_Response {
		$body            = (array) $req->get_json_params();
		$member_number = absint( $body['member_number'] ?? 0 );
		if ( ! $member_number ) {
			return new WP_REST_Response(
				[ 'message' => __( 'An owner member ID is required.', 'pcio-vis-boat' ) ],
				422
			);
		}
		$id = PCIO_VIS_Boat_DB::create_boat( $member_number, $body );
		if ( ! $id ) {
			return new WP_REST_Response(
				[ 'message' => __( 'Could not create the boat. Please try again.', 'pcio-vis-boat' ) ],
				500
			);
		}
		return new WP_REST_Response( $this->enrich_boat( PCIO_VIS_Boat_DB::get_boat_by_id( $id ) ), 201 );
	}

	public function update_boat( WP_REST_Request $req ): WP_REST_Response {
		$id   = (int) $req->get_param( 'id' );
		$body = (array) $req->get_json_params();
		if ( ! PCIO_VIS_Boat_DB::get_boat_by_id( $id ) ) {
			return new WP_REST_Response( [ 'message' => __( 'Boat not found.', 'pcio-vis-boat' ) ], 404 );
		}
		PCIO_VIS_Boat_DB::update_boat( $id, $body );
		return new WP_REST_Response( $this->enrich_boat( PCIO_VIS_Boat_DB::get_boat_by_id( $id ) ), 200 );
	}

	public function delete_boat( WP_REST_Request $req ): WP_REST_Response {
		$id = (int) $req->get_param( 'id' );
		if ( ! PCIO_VIS_Boat_DB::get_boat_by_id( $id ) ) {
			return new WP_REST_Response( [ 'message' => __( 'Boat not found.', 'pcio-vis-boat' ) ], 404 );
		}
		PCIO_VIS_Boat_DB::delete_boat( $id );
		return new WP_REST_Response( [ 'deleted' => true ], 200 );
	}

	// ── Handlers: crew ────────────────────────────────────────────

	public function list_crew( WP_REST_Request $req ): WP_REST_Response {
		$boat_id = (int) $req->get_param( 'id' );
		return new WP_REST_Response(
			array_map( [ $this, 'enrich_crew_row' ], PCIO_VIS_Boat_DB::get_crew( $boat_id ) ),
			200
		);
	}

public function add_crew( WP_REST_Request $req ): WP_REST_Response {
		$boat_id   = (int) $req->get_param( 'id' );
		$body      = (array) $req->get_json_params();
		$member_id = absint( $body['member_id'] ?? 0 );

		if ( ! $member_id ) {
			return new WP_REST_Response( [ 'message' => __( 'A member ID is required.', 'pcio-vis-boat' ) ], 422 );
		}
		$member = class_exists( 'PCIO_VIS_DB' ) ? PCIO_VIS_DB::get( $member_id ) : null;
		if ( ! $member ) {
			return new WP_REST_Response( [ 'message' => __( 'Member not found.', 'pcio-vis-boat' ) ], 404 );
		}

		$boat = PCIO_VIS_Boat_DB::get_boat_by_id( $boat_id );
		if ( ! $boat ) {
			return new WP_REST_Response( [ 'message' => __( 'Boat not found.', 'pcio-vis-boat' ) ], 404 );
		}

		$boat_member_number = (int) $boat['member_number'];
		if ( $boat_member_number <= 0 ) {
			return new WP_REST_Response(
				[ 'message' => __( 'This boat has no owner membership number.', 'pcio-vis-boat' ) ],
				409
			);
		}

		$member_number = (int) ( $member['member_number'] ?? 0 );
		if ( $member_number > 0 && $member_number !== $boat_member_number ) {
			return new WP_REST_Response(
				[ 'message' => __( 'This member already belongs to another boat.', 'pcio-vis-boat' ) ],
				409
			);
		}

		// Crew membership is the shared membership number: join the owner group.
		PCIO_VIS_DB::set_member_number( $member_id, $boat_member_number );

		return new WP_REST_Response(
			array_map( [ $this, 'enrich_crew_row' ], PCIO_VIS_Boat_DB::get_crew( $boat_id ) ),
			200
		);
	}

	public function remove_crew( WP_REST_Request $req ): WP_REST_Response {
		$this->unlink_member_from_group( (int) $req->get_param( 'mid' ) );
		return new WP_REST_Response( [ 'removed' => true ], 200 );
	}

	/**
	 * Take a member out of their boat's membership group: clear the shared
	 * membership number, revoke the vis_member capability, and refresh the
	 * coverage status of the membership they just left.
	 */
	private function unlink_member_from_group( int $member_id ): void {
		if ( $member_id <= 0 || ! class_exists( 'PCIO_VIS_DB' ) ) {
			return;
		}
		$old_msn = PCIO_VIS_DB::get_member_number_by_id( $member_id );
		if ( $old_msn <= 0 ) {
			return;
		}
		PCIO_VIS_DB::set_member_number( $member_id, 0 );

		if ( class_exists( 'PCIO_VIS_Roles_DB' ) && class_exists( 'PCIO_VIS_Caps' ) ) {
			$role       = PCIO_VIS_Roles_DB::get_member_role( $member_id );
			$wp_user_id = $role ? (int) ( $role['wp_user_id'] ?? 0 ) : 0;
			if ( $wp_user_id > 0 ) {
				PCIO_VIS_Caps::revoke_membership( $wp_user_id );
			}
		}

		if ( class_exists( 'PCIO_VIS_Products_Fulfillment' ) ) {
			PCIO_VIS_Products_Fulfillment::refresh_number_status( $old_msn );
		}
	}

	/**
	 * True when the person is already part of the membership system: either the
	 * member record is linked to a WordPress user holding vis_member, or a
	 * WordPress account with that email already carries the capability.
	 *
	 * Such a person may not be added as crew — only brand-new people and
	 * existing "guest" WordPress users (accounts without vis_member) can.
	 */
	private function is_active_vis_member( int $member_id, string $email ): bool {
		if ( ! class_exists( 'PCIO_VIS_Caps' ) ) {
			return false;
		}

		$wp_user_id = 0;
		if ( class_exists( 'PCIO_VIS_Roles_DB' ) ) {
			$role       = PCIO_VIS_Roles_DB::get_member_role( $member_id );
			$wp_user_id = $role ? (int) ( $role['wp_user_id'] ?? 0 ) : 0;
		}
		if ( $wp_user_id > 0 ) {
			return PCIO_VIS_Caps::is_member( $wp_user_id );
		}

		$by_email = email_exists( $email );
		return $by_email ? PCIO_VIS_Caps::is_member( (int) $by_email ) : false;
	}

	// ── Handler: member self-service boat update ──────────────────

	public function update_my_boat( WP_REST_Request $req ): WP_REST_Response {
		$id   = (int) $req->get_param( 'id' );
		$body = (array) $req->get_json_params();
		unset( $body['member_number'], $body['id'], $body['created'], $body['updated'] );
		if ( ! PCIO_VIS_Boat_DB::update_boat( $id, $body ) ) {
			return new WP_REST_Response(
				[ 'message' => __( 'Could not save boat. Please try again.', 'pcio-vis-boat' ) ],
				500
			);
		}
		return new WP_REST_Response( PCIO_VIS_Boat_DB::get_boat_by_id( $id ), 200 );
	}

	// ── Handlers: member self-service crew ────────────────────────

	/** List the crew on a boat the current member owns. */
	public function list_my_crew( WP_REST_Request $req ): WP_REST_Response {
		$boat_id = (int) $req->get_param( 'id' );
		return new WP_REST_Response(
			array_map( [ $this, 'enrich_crew_row' ], PCIO_VIS_Boat_DB::get_crew( $boat_id ) ),
			200
		);
	}

	/**
	 * Add a crew member to a boat the current member owns.
	 *
	 * The new crew person is created as a full member record that SHARES the
	 * owner's membership (subscription) number, so they belong to the same paid
	 * membership. An existing member (matched by email) is reused when possible.
	 */
	public function add_my_crew( WP_REST_Request $req ): WP_REST_Response {
		$boat_id = (int) $req->get_param( 'id' );
		$boat    = PCIO_VIS_Boat_DB::get_boat_by_id( $boat_id );
		if ( ! $boat ) {
			return new WP_REST_Response( [ 'message' => __( 'Boat not found.', 'pcio-vis-boat' ) ], 404 );
		}
		if ( ! class_exists( 'PCIO_VIS_DB' ) ) {
			return new WP_REST_Response( [ 'message' => __( 'Membership system unavailable.', 'pcio-vis-boat' ) ], 500 );
		}

		$member_id = PCIO_VIS_Roles_DB::get_member_id_by_wp_user( get_current_user_id() );
		if ( ! $member_id ) {
			return new WP_REST_Response( [ 'message' => __( 'Members not found.', 'pcio-vis-boat' ) ], 500 );
		}
        $member_number = PCIO_VIS_DB::get_member_number_by_id( $member_id );

		$boat_member_number  = (int) $boat['member_number'];

		if ( empty( $boat_member_number ) || $boat_member_number !== $member_number ) {
			return new WP_REST_Response(
				[ 'message' => __( 'You do not have ownership of this boat.', 'pcio-vis-boat' ) ],
				409
			);
		}
		if ( ! $this->membership_is_active( $member_number ) ) {
			return new WP_REST_Response(
				[ 'message' => __( 'Your membership is not active, so crew cannot be added.', 'pcio-vis-boat' ) ],
				403
			);
		}

		$body  = (array) $req->get_json_params();
		$name  = sanitize_text_field( (string) ( $body['name'] ?? '' ) );
		$email = sanitize_email( (string) ( $body['email'] ?? '' ) );
		$phone = sanitize_text_field( (string) ( $body['phone'] ?? '' ) );

		if ( '' === $name ) {
			return new WP_REST_Response( [ 'message' => __( 'A name is required.', 'pcio-vis-boat' ) ], 422 );
		}
		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_REST_Response( [ 'message' => __( 'A valid email address is required.', 'pcio-vis-boat' ) ], 422 );
		}

		$existing_id = PCIO_VIS_DB::find_id_by_email( $email );

		if ( $existing_id > 0 ) {
			// A member may belong to only one boat.
			$existing_member_number = PCIO_VIS_DB::get_member_number_by_id( $existing_id );
			if ( $existing_member_number === $member_number ) {
				return new WP_REST_Response(
					[ 'message' => __( 'This person already belongs to your boat.', 'pcio-vis-boat' ) ],
					409
				);
			}
			if ( $existing_member_number > 0 ) {
				return new WP_REST_Response(
					[ 'message' => __( 'This person already belongs to another boat.', 'pcio-vis-boat' ) ],
					409
				);
			}
			// Only people who are not yet Vis members may join: an existing record
			// (or WordPress account) that already carries the vis_member capability
			// belongs to the membership system and must not be pulled into a boat group.
			if ( $this->is_active_vis_member( $existing_id, $email ) ) {
				return new WP_REST_Response(
					[ 'message' => __( 'This person is already a Vis member and cannot be added as crew.', 'pcio-vis-boat' ) ],
					409
				);
			}
			// Join the owner's membership group.
			PCIO_VIS_DB::set_member_number( $existing_id, $member_number );
			$crew_id = $existing_id;
		} else {
			$crew_id = (int) PCIO_VIS_DB::create( [
				'member_number' => $member_number,
				'name'          => $name,
				'email'         => $email,
				'phone'         => $phone,
			] );
			if ( ! $crew_id ) {
				return new WP_REST_Response( [ 'message' => __( 'Could not create the crew member.', 'pcio-vis-boat' ) ], 500 );
			}
		}

		// Provision a WP login and send the welcome / set-password email, but only
		// when a brand-new WP account is created (avoid mailing existing accounts).
		if ( class_exists( 'PCIO_VIS_Caps' ) ) {
			$provision = PCIO_VIS_Caps::provision_member_wp_account( $crew_id, $email, $name );
			if ( ! empty( $provision['is_new'] ) && class_exists( 'PCIO_VIS_Member_Signup_Fulfillment' ) ) {
				PCIO_VIS_Member_Signup_Fulfillment::send_welcome(
					$crew_id,
					null,
					[ 'name' => $name, 'email' => $email, 'phone' => $phone ],
					$provision
				);
			}
		}

		// Refresh the shared membership coverage status across the whole group.
		do_action( 'pcio_me_member_saved', $crew_id, [] );

		return new WP_REST_Response(
			array_map( [ $this, 'enrich_crew_row' ], PCIO_VIS_Boat_DB::get_crew( $boat_id ) ),
			201
		);
	}

	/** Remove a crew member from the boat's membership group. */
	public function remove_my_crew( WP_REST_Request $req ): WP_REST_Response {
		$member_id = (int) $req->get_param( 'mid' );
		$boat      = PCIO_VIS_Boat_DB::get_boat_by_id( (int) $req->get_param( 'id' ) );
		if ( ! $boat ) {
			return new WP_REST_Response( [ 'message' => __( 'Boat not found.', 'pcio-vis-boat' ) ], 404 );
		}
		// Never let the boat owner remove themselves.
		$self = PCIO_VIS_Roles_DB::get_member_id_by_wp_user( get_current_user_id() );
		if ( $self > 0 && $self === $member_id ) {
			return new WP_REST_Response(
				[ 'message' => __( 'You cannot remove yourself from your own boat.', 'pcio-vis-boat' ) ],
				409
			);
		}

		$this->unlink_member_from_group( $member_id );

		return new WP_REST_Response( [ 'removed' => true ], 200 );
	}


	/** True when the membership number has active (non-expired) coverage. */
	private function membership_is_active( int $msn ): bool {
		if ( ! class_exists( 'PCIO_VIS_Products_DB' ) ) {
			return true; // No shop/subscription system → no activity gate.
		}
		$expiry = PCIO_VIS_Products_DB::coverage_expiry_for_number( $msn );
		return '' !== $expiry && strtotime( $expiry ) >= strtotime( gmdate( 'Y-m-d' ) );
	}

	// ── Handler: member boats ─────────────────────────────────────

	public function member_boats( WP_REST_Request $req ): WP_REST_Response {
		$member_id = (int) $req->get_param( 'member_id' );
		$member_number = class_exists( 'PCIO_VIS_DB' )
			? PCIO_VIS_DB::get_member_number_by_id( $member_id )
			: 0;
		if ( $member_number <= 0 ) {
			return new WP_REST_Response( [], 200 );
		}
		$boat = PCIO_VIS_Boat_DB::get_boat_by_member_number( $member_number );
		return new WP_REST_Response( $boat ? [ $this->enrich_boat( $boat ) ] : [], 200 );
	}

	// ── Enrichment ────────────────────────────────────────────────

	/**
	 * Add the crew names of a boat row for the API response.
	 *
	 * `member_number` is the shared membership number, so the crew is every
	 * member record carrying that number — not only the member whose id it is.
	 *
	 * @param array<string,mixed>|null $boat
	 * @return array<string,mixed>
	 */
	private function enrich_boat( ?array $boat ): array {
		if ( ! $boat ) {
			return [];
		}
		$group = class_exists( 'PCIO_VIS_DB' )
			? PCIO_VIS_DB::get_members_by_member_number( (int) $boat['member_number'] )
			: [];
		$boat['owner_name']   = $group ? (string) ( $group[0]['name'] ?? '' ) : '';
		$boat['owner_number'] = (string) $boat['member_number'];
		$boat['crew']         = array_values( array_filter( array_map(
			static function ( array $member ): string {
				return trim( (string) ( $member['name'] ?? '' ) );
			},
			$group
		) ) );
		return $boat;
	}

	/**
	 * Add member display name and subscription activity to a crew row.
	 *
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function enrich_crew_row( array $row ): array {
		$member                = class_exists( 'PCIO_VIS_DB' ) ? PCIO_VIS_DB::get( $row['member_id'] ) : null;
		$row['member_name']    = $member ? (string) $member['name'] : '';
		$row['member_number']  = $member ? (string) $member['member_number'] : '';

		// Subscription activity — requires pcio-vis-products to be active.
		if ( $row['subscription_id'] > 0 && class_exists( 'PCIO_VIS_Products_DB' ) ) {
			$sub                       = PCIO_VIS_Products_DB::get_subscription( $row['subscription_id'] );
			$row['subscription_active'] = $sub
				&& ! empty( $sub['expire_date'] )
				&& strtotime( (string) $sub['expire_date'] ) >= strtotime( gmdate( 'Y-m-d' ) );
			$row['subscription_expires'] = $sub ? (string) $sub['expire_date'] : '';
		} elseif ( $member && class_exists( 'PCIO_VIS_Products_DB' ) ) {
			// Crew without their own subscription inherit the shared membership's status.
			$msn                         = (int) ( $member['member_number'] ?? 0 );
			$expiry                      = $msn > 0 ? PCIO_VIS_Products_DB::coverage_expiry_for_number( $msn ) : '';
			$row['subscription_active']  = '' !== $expiry && strtotime( $expiry ) >= strtotime( gmdate( 'Y-m-d' ) );
			$row['subscription_expires'] = $expiry;
		} else {
			$row['subscription_active']  = null; // no subscription linked
			$row['subscription_expires'] = '';
		}

		return $row;
	}
}
