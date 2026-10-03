<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Integration between PCIO VIS Boat and the core Member Event plugin.
 *
 * Wiring (all hooks live in the core, each degrades gracefully):
 *   pcio_me_all_caps / pcio_me_cap_labels         — vis_boat_manage in Roles matrix
 *   pcio_me_default_role_caps / pcio_me_sync_caps — default-cap sync
 *   pcio_me_member_fields                          — "Boat" column in member list
 *   pcio_me_member_row                             — populate boat name per row
 *   pcio_me_member_deleted                         — clean up boats on member delete
 *   pcio_me_home_cards                             — "Boats" tile on /vis/ landing
 *   pcio_me_nav_items                              — "Boats" nav link
 *   pcio_me_members_cell_html                      — boat name cell opens a popup
 */
class PCIO_VIS_Boat_Integration {

	/** True once a boat cell has been rendered, so the popup markup is needed. */
	private bool $has_boat_cells = false;

	public function register(): void {
		add_filter( 'pcio_me_all_caps',          [ $this, 'register_caps' ] );
		add_filter( 'pcio_me_cap_labels',        [ $this, 'register_cap_labels' ] );
		add_filter( 'pcio_me_default_role_caps', [ $this, 'register_default_role_caps' ] );
		add_action( 'pcio_me_sync_caps',         [ $this, 'grant_native_role_caps' ] );

		add_filter( 'pcio_me_member_fields',     [ $this, 'add_member_field' ] );
		add_filter( 'pcio_me_member_row',        [ $this, 'decorate_member_row' ] );
		add_action( 'pcio_me_member_deleted',    [ $this, 'on_member_deleted' ] );
		add_filter( 'pcio_me_members_cell_html', [ $this, 'render_boat_cell' ], 10, 3 );

		add_action( 'pcio_me_home_cards',        [ $this, 'render_home_card' ] );
		add_filter( 'pcio_me_nav_items',         [ $this, 'add_nav_item' ], 10, 2 );
		add_action( 'pcio_me_home_cards',        [ $this, 'render_harbours_home_card' ] );
		add_filter( 'pcio_me_nav_items',         [ $this, 'add_harbours_nav_item' ], 20, 2 );
		add_action( 'pcio_me_shortcodes_nav_items', [ $this, 'render_shortcode_nav_items' ] );
		add_action( 'pcio_me_shortcodes_after',  [ $this, 'render_shortcodes_docs' ] );
		add_filter( 'pcio_me_profile_config',    [ $this, 'inject_profile_config' ] );

		// Boat popup for the boat name cells in [pcio_me_members].
		add_action( 'wp_footer',                  [ $this, 'render_boat_popup' ], 5 );
	}

	// ── Capabilities ──────────────────────────────────────────────

	/**
	 * @param string[] $caps
	 * @return string[]
	 */
	public function register_caps( array $caps ): array {
		return array_merge( $caps, array_keys( PCIO_VIS_Boat_Plugin::CAP_ROLE_MAP ) );
	}

	/**
	 * @param array<string,string> $labels
	 * @return array<string,string>
	 */
	public function register_cap_labels( array $labels ): array {
		$labels['vis_boat_manage'] = __( 'Manage boat register', 'pcio-vis-boat' );
		return $labels;
	}

	/**
	 * Grant vis_boat_manage to the "System Admin" Vis role by default, so the
	 * core "Sync capabilities" action picks it up on existing installs.
	 *
	 * @param array<string,string[]> $defaults  Map of vis-role slug → cap slugs.
	 * @return array<string,string[]>
	 */
	public function register_default_role_caps( array $defaults ): array {
		$slug              = 'system-admin';
		$defaults[ $slug ] = array_values( array_unique( array_merge(
			$defaults[ $slug ] ?? [],
			array_keys( PCIO_VIS_Boat_Plugin::CAP_ROLE_MAP )
		) ) );
		return $defaults;
	}

	/**
	 * Re-grant vis_boat_manage to its native WP roles when the core "Sync
	 * capabilities" action runs (mirrors plugin-activation behaviour).
	 */
	public function grant_native_role_caps(): void {
		foreach ( PCIO_VIS_Boat_Plugin::CAP_ROLE_MAP as $cap => $roles ) {
			foreach ( $roles as $role_slug ) {
				$role = get_role( $role_slug );
				if ( $role ) {
					$role->add_cap( $cap );
				}
			}
		}
	}

	// ── Member list ───────────────────────────────────────────────

	/**
	 * Add a read-only "Boat" column to the member list table.
	 * The value is populated by decorate_member_row() below.
	 *
	 * @param array<int,array<string,mixed>> $fields
	 * @return array<int,array<string,mixed>>
	 */
	public function add_member_field( array $fields ): array {
		$fields[] = [
			'key'            => 'boat_name',
			'label'          => __( 'Boat', 'pcio-vis-boat' ),
			'type'           => 'text',
			'sortable'       => false,
			'list_col'       => true,
			'profile_readonly' => true,
			'profile_managed'  => true, // The profile page's boat section renders this itself.
			'meta'           => true,
		];
		return $fields;
	}

	/**
	 * Merge the member's boat name(s) into each row before it is rendered.
	 *
	 * @param array<string,mixed> $member
	 * @return array<string,mixed>
	 */
	public function decorate_member_row( array $member ): array {
		$boat = PCIO_VIS_Boat_DB::get_boat_by_member_number( (int) ( $member['member_number'] ?? 0 ) );
		$member['boat_data'] = $boat ? $this->boat_popup_data( $boat ) : [];
		$member['boat_name'] = $boat['boat_name'] ?? '';
		return $member;
	}

	/**
	 * Boat fields shown in the boat popup, with the crew names that share the
	 * boat's membership number.
	 *
	 * @param array<string,mixed> $boat Boat row.
	 * @return array<string,mixed>
	 */
	private function boat_popup_data( array $boat ): array {
		$data = [
			'boat_name'     => (string) ( $boat['boat_name'] ?? '' ),
			'boat_type'     => (string) ( $boat['boat_type'] ?? '' ),
			'boat_model'    => (string) ( $boat['boat_model'] ?? '' ),
			'home_harbour'  => (string) ( $boat['home_harbour'] ?? '' ),
			'build_year'    => (string) ( $boat['build_year'] ?? '' ),
			'sail_id'       => (string) ( $boat['sail_id'] ?? '' ),
			'mmsi'          => (string) ( $boat['mmsi'] ?? '' ),
			'member_number' => (string) ( $boat['member_number'] ?? '' ),
			'note'          => (string) ( $boat['note'] ?? '' ),
			'crew'          => [],
		];

		if ( class_exists( 'PCIO_VIS_DB' ) ) {
			$group = PCIO_VIS_DB::get_members_by_member_number( (int) $boat['member_number'] );
			foreach ( (array) $group as $member ) {
				$name = trim( (string) ( $member['name'] ?? '' ) );
				if ( '' !== $name ) {
					$data['crew'][] = $name;
				}
			}
		}

		return $data;
	}

	/**
	 * Render boat names as a button that opens a detail popup.
	 * Hooked into the pcio_me_members_cell_html filter in [pcio_me_members].
	 *
	 * @param string              $html   Default cell HTML.
	 * @param array<string,mixed> $member Member row.
	 * @param array<string,mixed> $field  Field definition.
	 * @return string
	 */
	public function render_boat_cell( string $html, array $member, array $field ): string {
		if ( 'boat_name' !== ( $field['key'] ?? '' ) || empty( $member['boat_data'] ) ) {
			return $html;
		}
		$boat = (array) $member['boat_data'];
		$name = esc_html( $boat['boat_name'] ?? '' );
		if ( '' === $name ) {
			return $html;
		}

		$this->has_boat_cells = true;

		return sprintf(
			'<button type="button" class="pcio-boat-trigger" data-boat="%s" aria-haspopup="dialog">%s</button>',
			esc_attr( (string) wp_json_encode( $boat ) ),
			$name
		);
	}

	/**
	 * Emit the shared boat popup (once per page) and its assets.
	 *
	 * Hooked on wp_footer: the markup is only needed on pages that actually
	 * rendered a boat cell, which is known only after the shortcode has run.
	 */
	public function render_boat_popup(): void {
		if ( ! $this->has_boat_cells ) {
			return;
		}

		wp_enqueue_style(
			'pcio-vis-boat-members',
			PCIO_VIS_BOAT_URL . 'assets/members-boat.css',
			[],
			PCIO_VIS_BOAT_VERSION
		);
		wp_enqueue_script(
			'pcio-vis-boat-members',
			PCIO_VIS_BOAT_URL . 'assets/members-boat.js',
			[],
			PCIO_VIS_BOAT_VERSION,
			true
		);
		wp_add_inline_script(
			'pcio-vis-boat-members',
			'window.PCIO_VIS_BOAT_MEMBERS = ' . wp_json_encode( [
				'i18n' => [
					'title'    => __( 'Boat details', 'pcio-vis-boat' ),
					'type'     => __( 'Type', 'pcio-vis-boat' ),
					'model'    => __( 'Model', 'pcio-vis-boat' ),
					'harbour'  => __( 'Home harbour', 'pcio-vis-boat' ),
					'year'     => __( 'Build year', 'pcio-vis-boat' ),
					'sail'     => __( 'Sail number', 'pcio-vis-boat' ),
					'mmsi'     => __( 'MMSI', 'pcio-vis-boat' ),
					'memberNo' => __( 'Membership number', 'pcio-vis-boat' ),
					'crew'     => __( 'Crew', 'pcio-vis-boat' ),
					'note'     => __( 'Notes', 'pcio-vis-boat' ),
				],
			] ) . ';',
			'before'
		);
		?>
		<div class="pcio-boat-modal" id="pcio-boat-modal" hidden>
			<div class="pcio-boat-modal__backdrop" data-boat-close></div>
			<div class="pcio-boat-modal__box" role="dialog" aria-modal="true"
			     aria-labelledby="pcio-boat-modal-title">
				<div class="pcio-boat-modal__head">
					<h2 class="pcio-boat-modal__title" id="pcio-boat-modal-title"></h2>
					<button type="button" class="pcio-boat-modal__close" data-boat-close
					        aria-label="<?php esc_attr_e( 'Close', 'pcio-vis-boat' ); ?>">&times;</button>
				</div>
				<dl class="pcio-boat-modal__body">
					<div class="pcio-boat-modal__row" data-boat-field="boat_type"></div>
					<div class="pcio-boat-modal__row" data-boat-field="boat_model"></div>
					<div class="pcio-boat-modal__row" data-boat-field="home_harbour"></div>
					<div class="pcio-boat-modal__row" data-boat-field="build_year"></div>
					<div class="pcio-boat-modal__row" data-boat-field="sail_id"></div>
					<div class="pcio-boat-modal__row" data-boat-field="mmsi"></div>
					<div class="pcio-boat-modal__row" data-boat-field="member_number"></div>
					<div class="pcio-boat-modal__row" data-boat-field="crew"></div>
					<div class="pcio-boat-modal__row" data-boat-field="note"></div>
				</dl>
			</div>
		</div>
		<?php
	}

	/**
	 * Remove all boat data for a deleted member.
	 */
	public function on_member_deleted( int $member_id ): void {
		PCIO_VIS_Boat_DB::delete_member_data( $member_id );
	}

	// ── /vis/ landing page ────────────────────────────────────────

	/**
	 * Render a "Boats" tile on the /vis/ home page for admins.
	 */
	public function render_home_card(): void {
		if ( ! current_user_can( 'vis_boat_manage' ) ) {
			return;
		}
		$url = home_url( 'vis/boats/' );
		?>
		<a class="me-home-card" href="<?php echo esc_url( $url ); ?>">
			<div class="me-home-card-icon">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
				     stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
					<line x1="12" y1="2" x2="12" y2="20"/>
					<path d="M12 5 L19 16 H12"/>
					<path d="M12 9 L6 16 H12"/>
					<path d="M4 20 Q12 23.5 20 20"/>
				</svg>
			</div>
			<div class="me-home-card-body">
				<h2 class="me-home-card-title">
					<?php esc_html_e( 'Boats', 'pcio-vis-boat' ); ?>
				</h2>
				<p class="me-home-card-desc">
					<?php esc_html_e( 'Browse and manage the boat register.', 'pcio-vis-boat' ); ?>
				</p>
			</div>
			<div class="me-home-card-arrow">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
				     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
					<path d="M5 12h14M12 5l7 7-7 7"/>
				</svg>
			</div>
		</a>
		<?php
	}

	/**
	 * Render a "Harbours" tile on the /vis/ home page for admins.
	 */
	public function render_harbours_home_card(): void {
		if ( ! current_user_can( 'vis_boat_manage' ) ) {
			return;
		}
		$url = home_url( 'vis/harbours/' );
		?>
		<a class="me-home-card" href="<?php echo esc_url( $url ); ?>">
			<div class="me-home-card-icon">
				<svg viewBox="0 0 24 24"
					fill="none"
					stroke="currentColor"
					stroke-width="2"
					stroke-linecap="round"
					stroke-linejoin="round">

					<!-- Ring -->
					<circle cx="12" cy="3.5" r="2.5"/>

					<!-- Shaft -->
					<path d="M12 6 V20"/>

					<!-- Crossbar -->
					<path d="M8 9 H16"/>

					<!-- Anchor arms -->
					<path d="M12 20
							C7 20 4 17 3 13"/>

					<path d="M12 20
							C17 20 20 17 21 13"/>

					<!-- Arrow heads -->
					<path d="M3 13 L3 16"/>
					<path d="M3 13 L6 14"/>

					<path d="M21 13 L21 16"/>
					<path d="M21 13 L18 14"/>

				</svg>

			</div>
			<div class="me-home-card-body">
				<h2 class="me-home-card-title">
					<?php esc_html_e( 'Harbours', 'pcio-vis-boat' ); ?>
				</h2>
				<p class="me-home-card-desc">
					<?php esc_html_e( 'Manage harbour locations and home-harbour assignments.', 'pcio-vis-boat' ); ?>
				</p>
			</div>
			<div class="me-home-card-arrow">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
				     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
					<path d="M5 12h14M12 5l7 7-7 7"/>
				</svg>
			</div>
		</a>
		<?php
	}

	/**
	 * Add a "Boats" entry to the Vis navigation for admins.
	 *
	 * @param array<int,array<string,mixed>> $items
	 * @param string                         $current_path
	 * @return array<int,array<string,mixed>>
	 */
	public function add_nav_item( array $items, ?string $current_path ): array {
		if ( ! current_user_can( 'vis_boat_manage' ) ) {
			return $items;
		}
		$items[] = [
			'label'  => __( 'Boats', 'pcio-vis-boat' ),
			'url'    => home_url( 'vis/boats/' ),
			'active' => str_contains( (string) $current_path, '/vis/boats' ),
		];
		return $items;
	}

	/**
	 * Add a "Harbours" entry to the WP Admin Top horizontal Vis menu.
	 *
	 * Uses the string key 'harbours' so the front-end nav template
	 * ($pcio_me_active_page === $pcio_me_key) correctly marks it active
	 * — numeric keys from $items[] would never match the page slug.
	 *
	 * @param array<int|string,array<string,mixed>> $items
	 * @param string|null                           $current_path
	 * @return array<int|string,array<string,mixed>>
	 */
	public function add_harbours_nav_item( array $items, ?string $current_path ): array {
		if ( ! current_user_can( 'vis_boat_manage' ) ) {
			return $items;
		}
		$items['harbours'] = [
			'label'  => __( 'Harbours', 'pcio-vis-boat' ),
			'url'    => home_url( 'vis/harbours/' ),
			'active' => str_contains( (string) $current_path, '/vis/harbours' ),
		];
		return $items;
	}

	// ── Profile config injection ──────────────────────────────────

	/**
	 * Inject boat REST URLs and i18n into the edit-profile JS config.
	 * Only runs when this extension is active.
	 *
	 * @param array<string,mixed> $config
	 * @return array<string,mixed>
	 */
	public function inject_profile_config( array $config ): array {
		if ( ! defined( 'PCIO_VIS_REST_NS' ) ) {
			return $config;
		}
		$config['boatRest']     = rest_url( PCIO_VIS_REST_NS . '/boat' );
		$config['harboursRest'] = rest_url( PCIO_VIS_REST_NS . '/boat/harbours' );

		// A member may add crew only when their own membership is active.
		$config['canAddCrew'] = $this->owner_membership_active( (int) ( $config['memberId'] ?? 0 ) );

		$config['i18n'] = array_merge( $config['i18n'] ?? [], [
			'labelBoatName'  => __( 'Boat name',    'pcio-vis-boat' ),
			'labelBoatType'  => __( 'Type',         'pcio-vis-boat' ),
			'labelBoatModel' => __( 'Model',        'pcio-vis-boat' ),
			'labelBuilt'     => __( 'Built',        'pcio-vis-boat' ),
			'labelSailId'    => __( 'Sail no.',     'pcio-vis-boat' ),
			'labelMMSI'      => __( 'MMSI',         'pcio-vis-boat' ),
			'labelHarbour'   => __( 'Harbour',      'pcio-vis-boat' ),
			'labelNote'      => __( 'Note',         'pcio-vis-boat' ),
			'btnSaveBoat'    => __( 'Save boat',    'pcio-vis-boat' ),
			'toastBoatSaved' => __( 'Boat saved.',  'pcio-vis-boat' ),
			'errBoatSave'    => __( 'Save failed: ','pcio-vis-boat' ),
			// Crew self-service
			'crewSection'    => __( 'Crew on this boat',           'pcio-vis-boat' ),
			'crewEmpty'      => __( 'No crew added yet.',          'pcio-vis-boat' ),
			'crewActive'     => __( 'Active',                      'pcio-vis-boat' ),
			'crewInactive'   => __( 'Inactive',                    'pcio-vis-boat' ),
			'addCrewTitle'   => __( 'Add a crew member',           'pcio-vis-boat' ),
			'crewName'       => __( 'Name',                        'pcio-vis-boat' ),
			'crewEmail'      => __( 'Email',                       'pcio-vis-boat' ),
			'crewPhone'      => __( 'Phone',                       'pcio-vis-boat' ),
			'btnAddCrew'     => __( 'Add crew member',             'pcio-vis-boat' ),
			'btnRemoveCrew'  => __( 'Remove',                      'pcio-vis-boat' ),
			'confirmRemoveCrew' => __( 'Remove this crew member from the boat?', 'pcio-vis-boat' ),
			'crewNote'       => __( 'New crew share your membership and receive an email to set their password.', 'pcio-vis-boat' ),
			'crewNeedActive' => __( 'You can add crew once your membership is active.', 'pcio-vis-boat' ),
			'toastCrewAdded' => __( 'Crew member added.',          'pcio-vis-boat' ),
			'toastCrewRemoved' => __( 'Crew member removed.',      'pcio-vis-boat' ),
			'errCrew'        => __( 'Could not update crew: ',     'pcio-vis-boat' ),
		] );
		return $config;
	}

	/** True when the member's shared membership number has active coverage. */
	private function owner_membership_active( int $member_id ): bool {
		if ( $member_id <= 0 || ! class_exists( 'PCIO_VIS_DB' ) ) {
			return false;
		}
		$msn = PCIO_VIS_DB::get_member_number_by_id( $member_id );
		if ( $msn <= 0 ) {
			return false;
		}
		if ( ! class_exists( 'PCIO_VIS_Products_DB' ) ) {
			return true; // No shop/subscription system → no activity gate.
		}
		$expiry = PCIO_VIS_Products_DB::coverage_expiry_for_number( $msn );
		return '' !== $expiry && strtotime( $expiry ) >= strtotime( gmdate( 'Y-m-d' ) );
	}

	// ── Shortcode reference ───────────────────────────────────────

	/**
	 * Sidebar entries on the core Shortcode Reference page (Vis → Shortcodes).
	 * Action: pcio_me_shortcodes_nav_items
	 */
	public function render_shortcode_nav_items(): void {
		?>
		<div class="pcio-sc-nav-group">
			<div class="pcio-sc-nav-heading"><?php esc_html_e( 'PCIO VIS Boat', 'pcio-vis-boat' ); ?></div>
			<a href="#sc-boat-signup"       class="pcio-sc-nav-item">[pcio_boat_signup]</a>
			<a href="#sc-boat-edit-profile" class="pcio-sc-nav-item">[pcio_boat_edit_profile]</a>
			<a href="#sc-boat-harbours-map" class="pcio-sc-nav-item">[pcio_boat_harbours_map]</a>
		</div>
		<?php
	}

	public function render_shortcodes_docs(): void {
		?>
		<hr style="margin:32px 0">

		<h2 id="sc-boat-signup"><?php esc_html_e( '[pcio_boat_signup]', 'pcio-vis-boat' ); ?></h2>
		<p>
			<?php esc_html_e( 'Public sign-up form for new boat-club members. Collects personal details and boat information, then directs the applicant to a Stripe payment page for the club membership subscription. After successful payment the member and boat records are created automatically.', 'pcio-vis-boat' ); ?>
		</p>
		<p>
			<strong><?php esc_html_e( 'Requires:', 'pcio-vis-boat' ); ?></strong>
			<?php esc_html_e( 'Plugins pcio-vis-tickets and pcio-vis-products must be active. A product with', 'pcio-vis-boat' ); ?>
			<code class="pcio-sc-code">product_name = club-membership</code>
			<?php esc_html_e( 'must exist in the shop and be set to active.', 'pcio-vis-boat' ); ?>
		</p>

		<table class="pcio-sc-table wp-list-table widefat">
			<thead><tr>
				<th style="width:22%"><?php esc_html_e( 'Attribute', 'pcio-vis-boat' ); ?></th>
				<th style="width:12%"><?php esc_html_e( 'Type', 'pcio-vis-boat' ); ?></th>
				<th style="width:14%"><?php esc_html_e( 'Default', 'pcio-vis-boat' ); ?></th>
				<th><?php esc_html_e( 'Description', 'pcio-vis-boat' ); ?></th>
			</tr></thead>
			<tbody>
				<tr>
					<td><code class="pcio-sc-code">cancel_url</code></td>
					<td><?php esc_html_e( 'URL', 'pcio-vis-boat' ); ?></td>
					<td><code class="pcio-sc-code">/</code></td>
					<td><?php esc_html_e( 'Page the visitor is sent to if they cancel at the Stripe payment step. Defaults to the site home page.', 'pcio-vis-boat' ); ?></td>
				</tr>
			</tbody>
		</table>

		<strong><?php esc_html_e( 'Form fields collected', 'pcio-vis-boat' ); ?></strong>
		<ul style="list-style:disc;margin-left:24px;margin-top:8px">
			<li><strong><?php esc_html_e( 'Member details:', 'pcio-vis-boat' ); ?></strong>
				<?php esc_html_e( 'Full name (required), email (required), phone, address.', 'pcio-vis-boat' ); ?></li>
			<li><strong><?php esc_html_e( 'Boat details:', 'pcio-vis-boat' ); ?></strong>
				<?php esc_html_e( 'Boat name (required), home harbour, type, model, build year, sail number, MMSI (9 digits).', 'pcio-vis-boat' ); ?></li>
		</ul>

		<strong style="display:block;margin-top:16px"><?php esc_html_e( 'Examples', 'pcio-vis-boat' ); ?></strong>
		<code class="pcio-sc-block">[pcio_boat_signup]
[pcio_boat_signup cancel_url="/join/"]</code>
		<?php
	}
}
