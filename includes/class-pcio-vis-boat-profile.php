<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the pcio-vis-boat plugin into the core member-profile edit dialog.
 *
 * Hooks provided:
 *   pcio_me_profile_extra_sections    – injects the Boat Details fieldset HTML.
 *   pcio_me_profile_validate_extra    – validates boat-specific fields and returns sanitized values.
 */
class PCIO_VIS_Boat_Profile {

	/**
	 * Boat field names this extension owns. A payload carrying any of them is
	 * treated as a boat save; the core member payload never does.
	 */
	private const BOAT_FIELDS = [
		'boat_name',
		'boat_type',
		'boat_model',
		'harbour_id',
		'sail_id',
		'mmsi',
		'build_year',
	];

	public function register(): void {
		$this->register_profile_alias();
		add_filter( 'pcio_me_profile_extra_sections',   [ $this, 'extra_sections'   ], 10, 2 );
		add_filter( 'pcio_me_profile_validate_extra',   [ $this, 'validate_extra'   ], 10, 2 );
		// The member profile form saves through PUT /members/{id}, which carries
		// the boat fields in the same payload as the core member fields.
		add_filter( 'pcio_me_member_update_validate',    [ $this, 'validate_update'  ], 10, 2 );
		add_action( 'pcio_me_member_saved',               [ $this, 'save_boat'        ], 10, 2 );
		// Shortcode reference page (Vis → Shortcodes): document what this plugin adds.
		add_action( 'pcio_me_shortcodes_after', [ $this, 'render_shortcode_docs' ], 5 );
	}

	/**
	 * Render the [pcio_boat_edit_profile] section on the core Shortcode
	 * Reference page. Action: pcio_me_shortcodes_after
	 */
	public function render_shortcode_docs(): void {
		?>
		<hr style="margin:32px 0">

		<h2 id="sc-boat-edit-profile"><?php esc_html_e( '[pcio_boat_edit_profile]', 'pcio-vis-boat' ); ?></h2>
		<p>
			<?php esc_html_e( 'Renders the same inline member edit form as [pcio_me_edit_profile], extended with a Boat details fieldset and a Crew list the boat owner can edit. Requires the vis_member capability; shows a message if no member record is linked to the account.', 'pcio-vis-boat' ); ?>
		</p>

		<p class="pcio-sc-note">
			<?php esc_html_e( 'Crew rules:', 'pcio-vis-boat' ); ?>
			<?php esc_html_e( 'crew shares the boat owner\'s membership number (and therefore the same subscription); the owner cannot remove themselves; only people who are not already Vis members can be added — an existing WordPress user without the vis_member capability is accepted and promoted. Removing a crew member clears their membership number and revokes vis_member.', 'pcio-vis-boat' ); ?>
		</p>

		<table class="pcio-sc-table wp-list-table widefat">
			<thead><tr>
				<th style="width:22%"><?php esc_html_e( 'Attribute', 'pcio-vis-boat' ); ?></th>
				<th style="width:12%"><?php esc_html_e( 'Type', 'pcio-vis-boat' ); ?></th>
				<th style="width:14%"><?php esc_html_e( 'Default', 'pcio-vis-boat' ); ?></th>
				<th><?php esc_html_e( 'Description', 'pcio-vis-boat' ); ?></th>
			</tr></thead>
			<tbody>
				<tr><td colspan="4"><em><?php esc_html_e( 'No attributes.', 'pcio-vis-boat' ); ?></em></td></tr>
			</tbody>
		</table>

		<p style="margin-top:8px">
			<strong><?php esc_html_e( 'Required capability:', 'pcio-vis-boat' ); ?></strong>
			<code class="pcio-sc-cap">vis_member</code>
		</p>

		<strong style="display:block;margin-top:16px"><?php esc_html_e( 'Form fields collected', 'pcio-vis-boat' ); ?></strong>
		<ul style="list-style:disc;margin-left:24px;margin-top:8px">
			<?php foreach ( self::boat_field_descriptions() as $pcio_boat_field_desc ) : ?>
				<li><?php echo esc_html( $pcio_boat_field_desc ); ?></li>
			<?php endforeach; ?>
		</ul>

		<strong style="display:block;margin-top:16px"><?php esc_html_e( 'Example', 'pcio-vis-boat' ); ?></strong>
		<code class="pcio-sc-block">[pcio_boat_edit_profile]</code>
		<?php
	}

	/**
	 * Descriptions of the boat fields this plugin injects into the member form.
	 *
	 * @return string[]
	 */
	public static function boat_field_descriptions(): array {
		return [
			__( 'Boat name (required)', 'pcio-vis-boat' ),
			__( 'Home harbour with autocomplete + Add on map (optional)', 'pcio-vis-boat' ),
			__( 'Boat type, boat model, build year, sail number, MMSI (optional)', 'pcio-vis-boat' ),
		];
	}

	/**
	 * Register [pcio_boat_edit_profile] as a backward-compat alias for
	 * [pcio_me_edit_profile].
	 *
	 * The alias is bound to the core shortcode's own registered callback so the
	 * profile form — already escaped by core — is rendered by core directly. This
	 * avoids returning nested, unescaped shortcode output from this plugin.
	 */
	private function register_profile_alias(): void {
		global $shortcode_tags;
		if ( ! empty( $shortcode_tags['pcio_me_edit_profile'] ) ) {
			add_shortcode( 'pcio_boat_edit_profile', $shortcode_tags['pcio_me_edit_profile'] );
		}
	}

	/**
	 * Inject the Boat Details fieldset into the core form and enqueue harbour-picker assets.
	 * Filter: pcio_me_profile_extra_sections
	 *
	 * @param array<array{id:string,title:string,html:string}> $sections
	 * @return array<array{id:string,title:string,html:string}>
	 */
public function extra_sections( array $sections ): array {
		$this->enqueue_harbour_assets();
		$this->enqueue_profile_assets();
		$sections[] = [
			'id'    => 'boat',
			'title' => __( 'Boat details', 'pcio-vis-boat' ),
			'html'  => $this->boat_section_html(),
		];
		$sections[] = [
			'id'    => 'crew',
			'title' => __( 'Crew', 'pcio-vis-boat' ),
			'html'  => $this->crew_section_html(),
		];
		return $sections;
	}


	/**
	 * Validate boat-specific fields and return sanitized values in 'extra'.
	 * Filter: pcio_me_profile_validate_extra
	 *
	 * @param array{valid:bool,message:string,extra:array} $result
	 * @param array $params Raw request params.
	 * @return array{valid:bool,message:string,extra:array}
	 */
	public function validate_extra( array $result, array $params ): array {
		if ( ! ( $result['valid'] ?? true ) ) {
			return $result; // Earlier extension already failed.
		}

		$message = $this->boat_validation_error( $params );
		if ( '' !== $message ) {
			return [ 'valid' => false, 'message' => $message, 'extra' => [] ];
		}

		$result['extra'] = array_merge( (array) ( $result['extra'] ?? [] ), $this->boat_fields( $params ) );

		return $result;
	}

	/**
	 * Validate the boat fields carried by a member save (the profile form).
	 * Filter: pcio_me_member_update_validate
	 *
	 * @param array{valid:bool,message:string} $result
	 * @param array                            $data    Raw request params.
	 * @return array{valid:bool,message:string}
	 */
	public function validate_update( array $result, array $data ): array {
		if ( ! ( $result['valid'] ?? true ) || ! $this->has_boat_fields( $data ) ) {
			return $result;
		}

		$message = $this->boat_validation_error( $data );
		if ( '' !== $message ) {
			$result['valid']   = false;
			$result['message'] = $message;
		}

		return $result;
	}

	/**
	 * Persist the boat details submitted together with a member save.
	 * Action: pcio_me_member_saved
	 *
	 * The core members table only stores its own columns, so without this the
	 * Boat details fieldset of the profile form would be discarded on save.
	 *
	 * @param int   $member_id Member row ID.
	 * @param array $data      Raw submitted data (core + extension fields).
	 */
	public function save_boat( int $member_id, array $data ): void {
		if ( $member_id <= 0 || ! $this->has_boat_fields( $data ) || ! class_exists( 'PCIO_VIS_DB' ) ) {
			return;
		}

		$member_number = PCIO_VIS_DB::get_member_number_by_id( $member_id );
		if ( $member_number <= 0 ) {
			return; // No membership number → no boat to own.
		}

		$this->adopt_legacy_boat( $member_id, $member_number );

		// Only a signed-in member may add a harbour to the shared list. The core
		// pcio_me_member_saved also fires for anonymous member creations (the
		// public member-profile route), so this check is what keeps the public
		// harbour list closed to unauthenticated writes.
		PCIO_VIS_Boat_DB::save_boat_for_number(
			$member_number,
			$this->boat_fields( $data ),
			$this->may_create_harbour()
		);
	}

	/**
	 * True when the current request is made by a member (or a boat admin), i.e.
	 * someone allowed to add a new harbour to the shared list.
	 */
	private function may_create_harbour(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		return current_user_can( 'vis_member' ) || current_user_can( 'vis_boat_manage' );
	}

	/**
	 * Move a boat row that was written under the member row id onto the member's
	 * real membership number, so this save updates that boat instead of leaving
	 * it behind and inserting a second one.
	 */
	private function adopt_legacy_boat( int $member_id, int $member_number ): void {
		if ( PCIO_VIS_Boat_DB::get_boat_by_member_number( $member_number ) ) {
			return; // This group already owns a correctly keyed boat.
		}
		$legacy = PCIO_VIS_Boat_DB::get_boat_by_member_number( $member_id );
		if ( $legacy ) {
			PCIO_VIS_Boat_DB::rekey_boat( (int) $legacy['id'], $member_number );
		}
	}


	// ── Private helpers ───────────────────────────────────────────

	/** True when a payload carries at least one boat field. */
	private function has_boat_fields( array $data ): bool {
		return (bool) array_intersect( self::BOAT_FIELDS, array_keys( $data ) );
	}

	/**
	 * First boat-specific problem with the payload, or '' when it is acceptable.
	 */
	private function boat_validation_error( array $params ): string {
		$fields = $this->boat_fields( $params );

		if ( '' === $fields['boat_name'] ) {
			return __( 'Boat name is required.', 'pcio-vis-boat' );
		}
		if ( '' !== $fields['mmsi'] && ! preg_match( '/^\d{9}$/', $fields['mmsi'] ) ) {
			return __( 'MMSI must be exactly 9 digits.', 'pcio-vis-boat' );
		}
		if ( null !== $fields['build_year'] && ( $fields['build_year'] < 1900 || $fields['build_year'] > (int) gmdate( 'Y' ) ) ) {
			return __( 'Please enter a valid build year.', 'pcio-vis-boat' );
		}
		$lat = $fields['new_harbour_lat'];
		$lon = $fields['new_harbour_lon'];
		if ( '' !== $fields['new_harbour_name'] && null !== $lat && null !== $lon
			&& ( $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180 ) ) {
			return __( 'Invalid harbour coordinates.', 'pcio-vis-boat' );
		}

		return '';
	}

	/**
	 * Sanitized boat fields, ready for persistence.
	 *
	 * A harbour placed on the map is not created here: only its coordinates ride
	 * along, and PCIO_VIS_Boat_DB::save_boat_for_number() creates it with the boat.
	 *
	 * @return array<string,mixed>
	 */
	private function boat_fields( array $params ): array {
		$build_year_raw = $params['build_year'] ?? null;

		$new_harbour_name = substr( sanitize_text_field( (string) ( $params['new_harbour_name'] ?? '' ) ), 0, 128 );
		$new_harbour_lat  = ( isset( $params['new_harbour_lat'] ) && '' !== $params['new_harbour_lat'] ) ? (float) $params['new_harbour_lat'] : null;
		$new_harbour_lon  = ( isset( $params['new_harbour_lon'] ) && '' !== $params['new_harbour_lon'] ) ? (float) $params['new_harbour_lon'] : null;

		// A new harbour is only carried when it is complete; a half-filled one is
		// dropped so the member keeps the harbour already on record.
		$has_new_harbour = '' !== $new_harbour_name && null !== $new_harbour_lat && null !== $new_harbour_lon;

		return [
			'boat_name'    => sanitize_text_field( (string) ( $params['boat_name']    ?? '' ) ),
			'boat_type'    => sanitize_text_field( (string) ( $params['boat_type']    ?? '' ) ),
			'boat_model'   => sanitize_text_field( (string) ( $params['boat_model']   ?? '' ) ),
			'harbour_id'   => absint( $params['harbour_id'] ?? 0 ),
			'sail_id'      => sanitize_text_field( (string) ( $params['sail_id']      ?? '' ) ),
			'mmsi'         => sanitize_text_field( (string) ( $params['mmsi']         ?? '' ) ),
			'build_year'   => ( null !== $build_year_raw && '' !== $build_year_raw ) ? absint( $build_year_raw ) : null,
			'new_harbour_name' => $has_new_harbour ? $new_harbour_name : '',
			'new_harbour_lat'  => $has_new_harbour ? $new_harbour_lat  : null,
			'new_harbour_lon'  => $has_new_harbour ? $new_harbour_lon  : null,
		];
	}

	/** Enqueue Leaflet + harbour-map.js and inject the PCIO_VIS_Boat_Harbours config. */
	private function enqueue_harbour_assets( ): void {
		PCIO_VIS_Boat_Harbours_Map::register_leaflet();
		wp_enqueue_style( 'leaflet' );
		wp_enqueue_script( 'leaflet' );
		wp_enqueue_style(
			'pcio-vis-boat-harbour-map',
			PCIO_VIS_BOAT_URL . 'assets/harbour-map.css',
			[ 'leaflet' ],
			PCIO_VIS_BOAT_VERSION
		);
		wp_enqueue_script(
			'pcio-vis-boat-harbour-map',
			PCIO_VIS_BOAT_URL . 'assets/harbour-map.js',
			[ 'leaflet', 'pcio-vis-modal-guard' ],
			PCIO_VIS_BOAT_VERSION . '.1',
			true
		);
		$boat_cfg = wp_json_encode( [
			'harboursRest' => rest_url( PCIO_VIS_REST_NS . '/boat/harbours' ),
			'i18n'         => [
				'addHarbour'   => __( 'Add harbour', 'pcio-vis-boat' ),
				'clickMapHint' => __( 'Click the map to place the harbour.', 'pcio-vis-boat' ),
				'harbourName'  => __( 'Harbour name', 'pcio-vis-boat' ),
				'saveHarbour'  => __( 'Add harbour', 'pcio-vis-boat' ),
				'close'        => __( 'Close', 'pcio-vis-boat' ),
			],
		] );
		wp_add_inline_script( 'pcio-vis-boat-harbour-map', 'window.PCIO_VIS_Boat_MAP = window.PCIO_VIS_Boat_MAP || {};', 'before' );
		wp_add_inline_script( 'pcio-vis-boat-harbour-map', 'window.PCIO_VIS_Boat_Harbours = ' . $boat_cfg . ';', 'before' );
	}

	private function enqueue_profile_assets(): void {
		$member_number = PCIO_VIS_Roles_DB::get_member_number_by_wp_user( get_current_user_id() );
		$boat_data = PCIO_VIS_Boat_DB::get_boat_by_member_number( $member_number );

		// Boat-specific validation extension for the core profile form.
		$ext_cfg = wp_json_encode( [
			'i18n' => [
				'boatNameRequired' => __( 'Boat name is required.', 'pcio-vis-boat' ),
				'mmsiInvalid' => __( 'MMSI must be exactly 9 digits.', 'pcio-vis-boat' ),
				'yearInvalid' => __( 'Please enter a valid build year.', 'pcio-vis-boat' ),
			],
			'member_number' => $member_number,
			'boat_data' => $boat_data,
		] );
		wp_enqueue_script(
			'pcio-vis-boat-profile-ext',
			PCIO_VIS_BOAT_URL . 'assets/boat-profile-ext.js',
			[ 'pcio-vis-boat-harbour-map', 'pcio-vis-member-profile' ],
			PCIO_VIS_BOAT_VERSION,
			true
		);
		wp_add_inline_script( 'pcio-vis-boat-profile-ext', 'window.PCIO_VIS_BOAT_EXT = ' . $ext_cfg . ';', 'before' );

		$this->enqueue_crew_assets( $boat_data );
	}

	/**
	 * Assets and REST config for the crew editor on the profile page.
	 *
	 * The editor drives the member self-service crew routes
	 * (/boat/my-boat/{id}/crew), which only work for the boat's own membership
	 * group — so the section is only rendered when this member owns the boat.
	 *
	 * @param array<string,mixed>|null $boat The current member's boat.
	 */
	private function enqueue_crew_assets( ?array $boat ): void {
		wp_enqueue_style(
			'pcio-vis-boat-profile',
			PCIO_VIS_BOAT_URL . 'assets/boat-profile.css',
			[],
			PCIO_VIS_BOAT_VERSION
		);

		$boat_id = $boat ? (int) $boat['id'] : 0;
		$cfg     = wp_json_encode( [
			'rest'          => $boat_id ? rest_url( PCIO_VIS_REST_NS . '/boat/my-boat/' . $boat_id . '/crew' ) : '',
			'nonce'         => wp_create_nonce( 'wp_rest' ),
			'boatId'        => $boat_id,
			'selfMemberId'  => class_exists( 'PCIO_VIS_Roles_DB' )
				? PCIO_VIS_Roles_DB::get_member_id_by_wp_user( get_current_user_id() )
				: 0,
			'i18n'          => [
				'loading'    => __( 'Loading crew…', 'pcio-vis-boat' ),
				'noCrew'     => __( 'No crew members yet.', 'pcio-vis-boat' ),
				'you'        => __( 'You', 'pcio-vis-boat' ),
				'remove'     => __( 'Remove', 'pcio-vis-boat' ),
				'removeQ'    => __( 'Remove this crew member from the boat?', 'pcio-vis-boat' ),
				'added'      => __( 'Crew member added.', 'pcio-vis-boat' ),
				'removed'    => __( 'Crew member removed.', 'pcio-vis-boat' ),
				'working'    => __( 'Working…', 'pcio-vis-boat' ),
				'nameReq'    => __( 'A name is required.', 'pcio-vis-boat' ),
				'emailReq'   => __( 'A valid email address is required.', 'pcio-vis-boat' ),
				'failed'     => __( 'Could not complete the request. Please try again.', 'pcio-vis-boat' ),
			],
		] );
		wp_add_inline_script(
			'pcio-vis-boat-profile-ext',
			'window.PCIO_VIS_BOAT_CREW = ' . $cfg . ';',
			'before'
		);
	}

	/**
	 * Render the Crew fieldset: an editable list of the boat's crew plus a form
	 * for adding a new one.
	 *
	 * The inputs deliberately carry no `name` attribute: this markup sits inside
	 * the core profile <form>, and crew changes are saved through their own REST
	 * routes, never with the profile update.
	 */
	private function crew_section_html(): string {
		$member_number = PCIO_VIS_Roles_DB::get_member_number_by_wp_user( get_current_user_id() );
		$boat          = PCIO_VIS_Boat_DB::get_boat_by_member_number( $member_number );

		ob_start();
		if ( ! $boat ) {
			?>
			<fieldset class="pcio-ms-profile__section">
				<legend class="pcio-ms-profile__legend">
					<?php esc_html_e( 'Crew', 'pcio-vis-boat' ); ?>
				</legend>
				<p class="pcio-ms-profile__note">
					<?php esc_html_e( 'Save your boat details first. Crew can be added once the boat is registered.', 'pcio-vis-boat' ); ?>
				</p>
			</fieldset>
			<?php
			return (string) ob_get_clean();
		}
		?>
		<fieldset class="pcio-ms-profile__section">
			<legend class="pcio-ms-profile__legend">
				<?php esc_html_e( 'Crew', 'pcio-vis-boat' ); ?>
			</legend>

			<p class="pcio-ms-profile__note">
				<?php esc_html_e( 'Everyone on this boat shares your membership number and subscription. Crew members cannot remove themselves, and only people who are not yet Vis members can be added.', 'pcio-vis-boat' ); ?>
			</p>

			<ul id="pcio-bs-crew-list" class="pcio-boat-profile__crew-list"></ul>

			<p id="pcio-bs-crew-status" class="pcio-boat-profile__crew-status"
			   role="status" aria-live="polite"></p>

			<div class="pcio-boat-profile__crew-add">
				<div class="pcio-ms-profile__field">
					<label for="pcio-bs-crew-name">
						<?php esc_html_e( 'Name', 'pcio-vis-boat' ); ?>
						<span class="pcio-ms-profile__req" aria-hidden="true">*</span>
					</label>
					<input type="text" id="pcio-bs-crew-name" autocomplete="name">
				</div>

				<div class="pcio-ms-profile__field">
					<label for="pcio-bs-crew-email">
						<?php esc_html_e( 'Email', 'pcio-vis-boat' ); ?>
						<span class="pcio-ms-profile__req" aria-hidden="true">*</span>
					</label>
					<input type="email" id="pcio-bs-crew-email" autocomplete="email">
				</div>

				<div class="pcio-ms-profile__field">
					<label for="pcio-bs-crew-phone">
						<?php esc_html_e( 'Phone', 'pcio-vis-boat' ); ?>
					</label>
					<input type="tel" id="pcio-bs-crew-phone" autocomplete="tel">
				</div>

				<button type="button" id="pcio-bs-crew-add" class="pcio-ms-profile__submit">
					<?php esc_html_e( 'Add crew member', 'pcio-vis-boat' ); ?>
				</button>
			</div>
		</fieldset>
		<?php
		return (string) ob_get_clean();
	}

	/** Render the Boat Details fieldset HTML. */
	private function boat_section_html(): string {
		ob_start();
		?>
		<fieldset class="pcio-ms-profile__section">
			<legend class="pcio-ms-profile__legend">
				<?php esc_html_e( 'Boat details', 'pcio-vis-boat' ); ?>
			</legend>

			<div class="pcio-ms-profile__field">
				<label for="pcio-bs-boat-name">
					<?php esc_html_e( 'Boat name', 'pcio-vis-boat' ); ?>
					<span class="pcio-ms-profile__req" aria-hidden="true">*</span>
				</label>
				<input type="text" id="pcio-bs-boat-name" name="boat_name" required>
			</div>

			<div class="pcio-ms-profile__field">
				<label for="pcio-bs-home-harbour">
					<?php esc_html_e( 'Home harbour', 'pcio-vis-boat' ); ?>
				</label>
				<input type="hidden" id="pcio-bs-harbour-id" name="harbour_id" value="0">
				<input type="hidden" id="pcio-bs-new-harbour-name" name="new_harbour_name" value="">
				<input type="hidden" id="pcio-bs-new-harbour-lat" name="new_harbour_lat" value="">
				<input type="hidden" id="pcio-bs-new-harbour-lon" name="new_harbour_lon" value="">
				<div class="pcio-boat-profile__harbour-picker">
					<input type="text" id="pcio-bs-home-harbour"
						autocomplete="off" role="combobox" aria-expanded="false"
						aria-autocomplete="list" aria-controls="pcio-bs-harbour-suggestions">
					<ul id="pcio-bs-harbour-suggestions"
						class="pcio-boat-profile__harbour-suggestions"
						role="listbox" style="display:none;"></ul>
				</div>
				<p class="pcio-boat-profile__harbour-hint">
					<?php esc_html_e( 'Pick a harbour from the list — only the selection is saved. Free text alone is not stored.', 'pcio-vis-boat' ); ?>
				</p>
				<button type="button" id="pcio-bs-add-harbour-btn"
					class="pcio-boat-profile__harbour-add-btn">
					<?php esc_html_e( 'Not in list? Add on map', 'pcio-vis-boat' ); ?>
				</button>
			</div>

			<div class="pcio-ms-profile__field">
				<label for="pcio-bs-boat-type">
					<?php esc_html_e( 'Boat type', 'pcio-vis-boat' ); ?>
				</label>
				<input type="text" id="pcio-bs-boat-type" name="boat_type"
					placeholder="<?php esc_attr_e( 'e.g. Sailing Yacht', 'pcio-vis-boat' ); ?>">
			</div>

			<div class="pcio-ms-profile__field">
				<label for="pcio-bs-boat-model">
					<?php esc_html_e( 'Boat model', 'pcio-vis-boat' ); ?>
				</label>
				<input type="text" id="pcio-bs-boat-model" name="boat_model">
			</div>

			<div class="pcio-ms-profile__row">
				<div class="pcio-ms-profile__field">
					<label for="pcio-bs-build-year">
						<?php esc_html_e( 'Build year', 'pcio-vis-boat' ); ?>
					</label>
					<input type="number" id="pcio-bs-build-year" name="build_year"
						min="1900" max="<?php echo esc_attr( (string) (int) gmdate( 'Y' ) ); ?>"
						inputmode="numeric">
				</div>

				<div class="pcio-ms-profile__field">
					<label for="pcio-bs-sail-id">
						<?php esc_html_e( 'Sail number', 'pcio-vis-boat' ); ?>
					</label>
					<input type="text" id="pcio-bs-sail-id" name="sail_id">
				</div>
			</div>

			<div class="pcio-ms-profile__field">
				<label for="pcio-bs-mmsi">
					<?php esc_html_e( 'MMSI', 'pcio-vis-boat' ); ?>
				</label>
				<input type="text" id="pcio-bs-mmsi" name="mmsi"
					pattern="[0-9]{9}" inputmode="numeric" maxlength="9"
					placeholder="<?php esc_attr_e( '9 digits', 'pcio-vis-boat' ); ?>">
			</div>
		</fieldset>
		<?php
		return (string) ob_get_clean();
	}
}
