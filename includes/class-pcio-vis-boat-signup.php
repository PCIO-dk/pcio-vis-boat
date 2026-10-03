<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the pcio-vis-boat plugin into the core member-signup infrastructure.
 *
 * Hooks provided:
 *   pcio_me_signup_extra_sections    – injects the Boat Details fieldset HTML.
 *   pcio_me_signup_has_payment       – signals the core form that a payment step exists.
 *   pcio_me_signup_validate_extra    – validates boat-specific fields and returns sanitized values.
 *   pcio_me_signup_initiate_payment  – creates a pending order + Stripe session.
 *   pcio_me_member_signup_complete   – creates the boat record after the member is confirmed.
 *   pcio_me_signup_welcome_vars      – adds {boat_name} to the welcome email.
 *   pcio_me_mail_tags                – registers {boat_name} tag label for member_welcome mailtype.
 *   pcio_me_signup_help_fields / _tags / _notes – documents the boat additions on the Shortcodes page.
 *
 * Also re-registers [pcio_boat_signup] as a alias for [pcio_me_member_signup].
 */
class PCIO_VIS_Boat_Signup {

	public function register(): void {
		$this->register_signup_alias();
		add_filter( 'pcio_me_signup_extra_sections',   [ $this, 'extra_sections'   ], 10, 2 );
		add_filter( 'pcio_me_signup_has_payment',      [ $this, 'has_payment'      ] );
		add_filter( 'pcio_me_signup_validate_extra',   [ $this, 'validate_extra'   ], 10, 2 );
		add_filter( 'pcio_me_signup_initiate_payment', [ $this, 'initiate_payment' ], 10, 2 );
		add_action( 'pcio_me_member_signup_complete',  [ $this, 'on_signup_complete' ], 10, 4 );
		add_filter( 'pcio_me_signup_welcome_vars',     [ $this, 'welcome_vars'     ], 10, 4 );
		add_filter( 'pcio_me_mail_tags',               [ $this, 'register_mail_tags' ], 10, 2 );

		// Shortcode reference page (Vis → Shortcodes): document what this plugin adds.
		add_filter( 'pcio_me_signup_help_fields', [ $this, 'signup_help_fields' ] );
		add_filter( 'pcio_me_signup_help_tags',   [ $this, 'signup_help_tags'   ], 20 );
		add_filter( 'pcio_me_signup_help_notes',  [ $this, 'signup_help_notes'  ] );
	}

	/**
	 * Register [pcio_boat_signup] as a backward-compat alias for
	 * [pcio_me_member_signup].
	 *
	 * The alias is bound to the core shortcode's own registered callback so the
	 * signup form — already escaped by core — is rendered by core directly. This
	 * avoids returning nested, unescaped shortcode output from this plugin.
	 */
	private function register_signup_alias(): void {
		global $shortcode_tags;
		if ( ! empty( $shortcode_tags['pcio_me_member_signup'] ) ) {
			add_shortcode( 'pcio_boat_signup', $shortcode_tags['pcio_me_member_signup'] );
		}
	}

	/**
	 * Document the extra signup fields this plugin injects.
	 * Filter: pcio_me_signup_help_fields
	 *
	 * @param string[] $fields
	 * @return string[]
	 */
	public function signup_help_fields( array $fields ): array {
		$fields[] = __( 'Boat name (required)', 'pcio-vis-boat' );
		$fields[] = __( 'Home harbour with autocomplete + Add on map (optional)', 'pcio-vis-boat' );
		$fields[] = __( 'Boat type, boat model, build year, sail number, MMSI (optional)', 'pcio-vis-boat' );
		return $fields;
	}

	/**
	 * Document the welcome-email tag this plugin provides.
	 * Filter: pcio_me_signup_help_tags
	 *
	 * @param array<int,array{tag:string,source:string,desc:string}> $rows
	 * @return array<int,array{tag:string,source:string,desc:string}>
	 */
	public function signup_help_tags( array $rows ): array {
		$rows[] = [
			'tag'    => '{boat_name}',
			'source' => 'pcio-vis-boat',
			'desc'   => __( "Registrant's boat name", 'pcio-vis-boat' ),
		];
		return $rows;
	}

	/**
	 * Document the [pcio_boat_signup] backward-compat alias.
	 * Filter: pcio_me_signup_help_notes
	 *
	 * @param string[] $notes
	 * @return string[]
	 */
	public function signup_help_notes( array $notes ): array {
		$notes[] = sprintf(
			/* translators: %s: the [pcio_boat_signup] shortcode wrapped in a code tag. */
			__( '<strong>Backward-compat alias:</strong> the pcio-vis-boat plugin also registers %s as an alias that renders this same form. Existing pages using that shortcode continue to work unchanged.', 'pcio-vis-boat' ),
			'<code class="pcio-sc-code">[pcio_boat_signup]</code>'
		);
		return $notes;
	}

	/**
	 * Inject the Boat Details fieldset into the core form and enqueue harbour-picker assets.
	 * Filter: pcio_me_signup_extra_sections
	 *
	 * @param array<array{id:string,title:string,html:string}> $sections
	 * @return array<array{id:string,title:string,html:string}>
	 */
	public function extra_sections( array $sections, array $atts ): array {
		$this->enqueue_harbour_assets( $atts );
		$sections[] = [
			'id'    => 'boat',
			'title' => __( 'Boat details', 'pcio-vis-boat' ),
			'html'  => $this->boat_section_html(),
		];
		return $sections;
	}

	/** Signals that a payment step will be present when the membership product is available. */
	public function has_payment( bool $has ): bool {
		if ( $has ) {
			return true;
		}
		return $this->membership_product_available();
	}

	/**
	 * Validate boat-specific fields and return sanitized values in 'extra'.
	 * Filter: pcio_me_signup_validate_extra
	 *
	 * @param array{valid:bool,message:string,extra:array} $result
	 * @param array $params Raw request params.
	 * @return array{valid:bool,message:string,extra:array}
	 */
	public function validate_extra( array $result, array $params ): array {
		if ( ! ( $result['valid'] ?? true ) ) {
			return $result; // Earlier extension already failed.
		}

		$boat_name  = sanitize_text_field( (string) ( $params['boat_name']    ?? '' ) );
		$boat_type  = sanitize_text_field( (string) ( $params['boat_type']    ?? '' ) );
		$boat_model = sanitize_text_field( (string) ( $params['boat_model']   ?? '' ) );
		$sail_id    = sanitize_text_field( (string) ( $params['sail_id']      ?? '' ) );
		$mmsi       = sanitize_text_field( (string) ( $params['mmsi']         ?? '' ) );
		$harbour_id = absint( $params['harbour_id'] ?? 0 );

		// New harbour placed on the map during sign-up. It is NOT persisted here;
		// creation is deferred to on_signup_complete (after the member is confirmed).
		$new_harbour_name = substr( sanitize_text_field( (string) ( $params['new_harbour_name'] ?? '' ) ), 0, 128 );
		$new_harbour_lat  = isset( $params['new_harbour_lat'] ) && '' !== $params['new_harbour_lat'] ? (float) $params['new_harbour_lat'] : null;
		$new_harbour_lon  = isset( $params['new_harbour_lon'] ) && '' !== $params['new_harbour_lon'] ? (float) $params['new_harbour_lon'] : null;

		$build_year_raw = $params['build_year'] ?? null;
		$build_year     = $build_year_raw !== null && $build_year_raw !== '' ? absint( $build_year_raw ) : null;

		if ( '' === $boat_name ) {
			return [ 'valid' => false, 'message' => __( 'Boat name is required.', 'pcio-vis-boat' ), 'extra' => [] ];
		}
		if ( '' !== $mmsi && ! preg_match( '/^\d{9}$/', $mmsi ) ) {
			return [ 'valid' => false, 'message' => __( 'MMSI must be exactly 9 digits.', 'pcio-vis-boat' ), 'extra' => [] ];
		}
		if ( null !== $build_year && ( $build_year < 1900 || $build_year > (int) gmdate( 'Y' ) ) ) {
			return [ 'valid' => false, 'message' => __( 'Please enter a valid build year.', 'pcio-vis-boat' ), 'extra' => [] ];
		}
		// A new harbour requires both a name and valid coordinates, or none at all.
		$has_new_harbour = '' !== $new_harbour_name && null !== $new_harbour_lat && null !== $new_harbour_lon;
		if ( $has_new_harbour
			&& ( $new_harbour_lat < -90 || $new_harbour_lat > 90 || $new_harbour_lon < -180 || $new_harbour_lon > 180 ) ) {
			return [ 'valid' => false, 'message' => __( 'Invalid harbour coordinates.', 'pcio-vis-boat' ), 'extra' => [] ];
		}

		$result['extra'] = array_merge( $result['extra'] ?? [], [
			'boat_name'    => $boat_name,
			'boat_type'    => $boat_type,
			'boat_model'   => $boat_model,
			'harbour_id'   => $harbour_id,
			'sail_id'      => $sail_id,
			'mmsi'         => $mmsi,
			'build_year'   => $build_year,
			'new_harbour_name' => $has_new_harbour ? $new_harbour_name : '',
			'new_harbour_lat'  => $has_new_harbour ? $new_harbour_lat : null,
			'new_harbour_lon'  => $has_new_harbour ? $new_harbour_lon : null,
		] );

		return $result;
	}

	/**
	 * Create a pending order + Stripe checkout session for the membership product.
	 * Filter: pcio_me_signup_initiate_payment
	 *
	 * @param array|null $result  null when no earlier extension handled it.
	 * @param array      $payload Sanitized form data (core + extension fields).
	 * @return array{payment_url:string,order_id:int}|null
	 */
	public function initiate_payment( $result, array $payload ) {
		if ( null !== $result ) {
			return $result; // Another extension already handled it.
		}

		if ( ! class_exists( 'PCIO_VIS_Products_DB' ) || ! class_exists( 'PCIO_VIS_Products_Stripe' ) ) {
			return null;
		}

		$product = PCIO_VIS_Products_DB::get_product_by_name( PCIO_VIS_BOAT_MEMBERSHIP_PRODUCT );
		if ( ! $product || empty( $product['active'] ) ) {
			return null; // Product missing/inactive — fall through to direct path.
		}

		if ( ! class_exists( 'PCIO_VIS_Products_Plugin' ) || ! PCIO_VIS_Products_Plugin::is_configured() ) {
			return null;
		}

		$email       = (string) ( $payload['email'] ?? '' );
		$name        = (string) ( $payload['name']  ?? '' );
		$customer_id = PCIO_VIS_Products_DB::upsert_customer( $email, $name );

		$order_id = PCIO_VIS_Products_DB::create_order( [
			'customer_id'  => $customer_id,
			'email'        => $email,
			'status'       => 'pending',
			/* translators: label shown on the Stripe receipt */
			'summary'      => __( 'Club membership', 'pcio-vis-boat' ),
			'amount_cents' => (int) $product['price_cents'],
			'currency'     => $product['currency'] ?: 'DKK',
			'kind'         => 'shop',
		] );

		if ( ! $order_id ) {
			return null;
		}

		PCIO_VIS_Products_DB::add_order_item(
			$order_id,
			(int) $product['id'],
			(string) $product['product_name'],
			1,
			(int) $product['price_cents']
		);

		$order       = PCIO_VIS_Products_DB::get_order( $order_id );
		$token       = (string) ( $order['token'] ?? '' );
		$success_url = add_query_arg( 'order', $token, home_url( '/shop/accepted/', 'https' ) );
		$cancel_raw  = esc_url_raw( (string) ( $payload['cancel_url'] ?? '' ) );
		if ( '' === $cancel_raw || ! str_contains( $cancel_raw, '://' ) ) {
			$cancel_url = home_url( '/' . ltrim( $cancel_raw, '/' ), 'https' );
		} else {
			$cancel_url = set_url_scheme( $cancel_raw, 'https' );
		}

		$session = PCIO_VIS_Products_Stripe::create_checkout_session( [
			'email'       => $email,
			'currency'    => $product['currency'] ?: 'DKK',
			'order_id'    => $order_id,
			'order_no'    => $order['order_no'] ?? '',
			'token'       => $token,
			'success_url' => $success_url,
			'cancel_url'  => $cancel_url,
			'line_items'  => [
				[
					'name'              => ( $product['title'] ?: $product['product_name'] ),
					'unit_amount_cents' => (int) $product['price_cents'],
					'qty'               => 1,
				],
			],
		] );

		if ( is_wp_error( $session ) ) {
			PCIO_VIS_Products_DB::update_order( $order_id, [ 'status' => 'cancelled' ], [ '%s' ] );
		//	error_log( sprintf( '[pcio-vis-boat] Stripe error (order %d): %s', $order_id, $session->get_error_message() ) );
			return null;
		}

		PCIO_VIS_Products_DB::update_order( $order_id, [ 'stripe_session_id' => $session['id'] ], [ '%s' ] );

		return [ 'payment_url' => $session['url'], 'order_id' => $order_id ];
	}

	/**
	 * Create the boat record after the core fulfillment has confirmed the member.
	 * Action: pcio_me_member_signup_complete
	 *
	 * @param int      $member_id     Member row ID.
	 * @param int|null $order_id      Products order ID; null on direct signup.
	 * @param array    $payload       Sanitized form data.
	 * @param bool     $is_new_member True when a new me_members row was just created.
	 */
	public function on_signup_complete( int $member_id, ?int $order_id, array $payload, bool $is_new_member ): void {
		// Only write a boat record when boat fields are present in the payload.
		// Note: never return early here — the subscription linking below must run
		// for every completed sign-up.
		$member_number = class_exists( 'PCIO_VIS_DB' )
			? PCIO_VIS_DB::get_member_number_by_id( $member_id )
			: 0;

		if ( $member_number > 0 && ! empty( $payload['boat_name'] ) ) {
			// A harbour the member placed on the map during sign-up is created by
			// save_boat_for_number(), together with the boat that references it —
			// but only once a real order has been confirmed. The core fires this
			// action with $order_id = null when the sign-up completes without a
			// payment (no active product / Stripe not configured), and that path
			// must not be able to add harbours to the shared list.
			$boat_id = PCIO_VIS_Boat_DB::save_boat_for_number( $member_number, [
				'harbour_id'       => (int) ( $payload['harbour_id'] ?? 0 ),
				'boat_name'        => (string) ( $payload['boat_name']   ?? '' ),
				'boat_type'        => (string) ( $payload['boat_type']   ?? '' ),
				'boat_model'       => (string) ( $payload['boat_model']  ?? '' ),
				'build_year'       => isset( $payload['build_year'] ) ? (int) $payload['build_year'] : null,
				'sail_id'          => (string) ( $payload['sail_id']    ?? '' ),
				'mmsi'             => (string) ( $payload['mmsi']       ?? '' ),
				'new_harbour_name' => (string) ( $payload['new_harbour_name'] ?? '' ),
				'new_harbour_lat'  => $payload['new_harbour_lat'] ?? null,
				'new_harbour_lon'  => $payload['new_harbour_lon'] ?? null,
			], ! empty( $order_id ) );

			if ( ! $boat_id ) {
				error_log( 'pcio-vis-boat: boat creation failed for member ' . $member_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Surfaced in the server log; the signup itself already succeeded.
			}
		}

		// Link subscriptions created by products fulfillment to this member.
		if ( $order_id && class_exists( 'PCIO_VIS_Products_DB' ) ) {
			foreach ( PCIO_VIS_Products_DB::get_subscriptions_for_order( $order_id ) as $sub ) {
				if ( empty( $sub['member_id'] ) ) {
					PCIO_VIS_Products_DB::update_subscription(
						(int) $sub['id'],
						[ 'member_id' => $member_id ],
						[ '%d' ]
					);
				}
			}
		}
	}

	/**
	 * Add {boat_name} tag value to the welcome email.
	 * Filter: pcio_me_signup_welcome_vars
	 */
	public function welcome_vars( array $vars, int $member_id, ?int $order_id, array $payload ): array {
		$vars['{boat_name}'] = (string) ( $payload['boat_name'] ?? '' );
		return $vars;
	}

	/**
	 * Register {boat_name} tag label for the member_welcome mailtype in the mail editor.
	 * Filter: pcio_me_mail_tags
	 */
	public function register_mail_tags( array $tags, string $mailtype ): array {
		if ( class_exists( 'PCIO_VIS_Member_Signup_Fulfillment' )
			&& PCIO_VIS_Member_Signup_Fulfillment::MAIL_TYPE_WELCOME === $mailtype ) {
			$tags[] = [ 'tag' => '{boat_name}', 'label' => __( 'Boat name', 'pcio-vis-boat' ) ];
		}
		return $tags;
	}

	// ── Private helpers ───────────────────────────────────────────

	/** True when the configured membership product exists and is active. */
	private function membership_product_available(): bool {
		if ( ! class_exists( 'PCIO_VIS_Products_DB' ) ) {
			return false;
		}
		$product = PCIO_VIS_Products_DB::get_product_by_name( PCIO_VIS_BOAT_MEMBERSHIP_PRODUCT );
		return $product && ! empty( $product['active'] );
	}

	/** Enqueue Leaflet + harbour-map.js and inject the PCIO_VIS_Boat_Harbours config. */
	private function enqueue_harbour_assets( array $atts ): void {
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
			[ 'leaflet' ],
			PCIO_VIS_BOAT_VERSION,
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

		// Boat-specific validation extension for the core signup form.
		$ext_cfg = wp_json_encode( [
			'i18n' => [
				'mmsiInvalid' => __( 'MMSI must be exactly 9 digits.', 'pcio-vis-boat' ),
				'yearInvalid' => __( 'Please enter a valid build year.', 'pcio-vis-boat' ),
			],
		] );
		wp_enqueue_script(
			'pcio-vis-boat-signup-ext',
			PCIO_VIS_BOAT_URL . 'assets/boat-signup-ext.js',
			[ 'pcio-vis-boat-harbour-map', 'pcio-vis-member-signup' ],
			PCIO_VIS_BOAT_VERSION,
			true
		);
		wp_add_inline_script( 'pcio-vis-boat-signup-ext', 'window.PCIO_VIS_BOAT_EXT = ' . $ext_cfg . ';', 'before' );
	}

	/** Render the Boat Details fieldset HTML. */
	private function boat_section_html(): string {
		ob_start();
		?>
		<fieldset class="pcio-ms-signup__section">
			<legend class="pcio-ms-signup__legend">
				<?php esc_html_e( 'Boat details', 'pcio-vis-boat' ); ?>
			</legend>

			<div class="pcio-ms-signup__field">
				<label for="pcio-bs-boat-name">
					<?php esc_html_e( 'Boat name', 'pcio-vis-boat' ); ?>
					<span class="pcio-ms-signup__req" aria-hidden="true">*</span>
				</label>
				<input type="text" id="pcio-bs-boat-name" name="boat_name" required>
			</div>

			<div class="pcio-ms-signup__field">
				<label for="pcio-bs-home-harbour">
					<?php esc_html_e( 'Home harbour', 'pcio-vis-boat' ); ?>
				</label>
				<input type="hidden" id="pcio-bs-harbour-id" name="harbour_id" value="0">
				<input type="hidden" id="pcio-bs-new-harbour-name" name="new_harbour_name" value="">
				<input type="hidden" id="pcio-bs-new-harbour-lat" name="new_harbour_lat" value="">
				<input type="hidden" id="pcio-bs-new-harbour-lon" name="new_harbour_lon" value="">
				<div class="pcio-boat-signup__harbour-picker">
					<input type="text" id="pcio-bs-home-harbour"
						autocomplete="off" role="combobox" aria-expanded="false"
						aria-autocomplete="list" aria-controls="pcio-bs-harbour-suggestions">
					<ul id="pcio-bs-harbour-suggestions"
						class="pcio-boat-signup__harbour-suggestions"
						role="listbox" style="display:none;"></ul>
				</div>
				<p class="pcio-boat-signup__harbour-hint">
					<?php esc_html_e( 'Pick a harbour from the list — only the selection is saved. Free text alone is not stored.', 'pcio-vis-boat' ); ?>
				</p>
				<button type="button" id="pcio-bs-add-harbour-btn"
					class="pcio-boat-signup__harbour-add-btn">
					<?php esc_html_e( 'Not in list? Add on map', 'pcio-vis-boat' ); ?>
				</button>
			</div>

			<div class="pcio-ms-signup__field">
				<label for="pcio-bs-boat-type">
					<?php esc_html_e( 'Boat type', 'pcio-vis-boat' ); ?>
				</label>
				<input type="text" id="pcio-bs-boat-type" name="boat_type"
					placeholder="<?php esc_attr_e( 'e.g. Sailing Yacht', 'pcio-vis-boat' ); ?>">
			</div>

			<div class="pcio-ms-signup__field">
				<label for="pcio-bs-boat-model">
					<?php esc_html_e( 'Boat model', 'pcio-vis-boat' ); ?>
				</label>
				<input type="text" id="pcio-bs-boat-model" name="boat_model">
			</div>

			<div class="pcio-ms-signup__row">
				<div class="pcio-ms-signup__field">
					<label for="pcio-bs-build-year">
						<?php esc_html_e( 'Build year', 'pcio-vis-boat' ); ?>
					</label>
					<input type="number" id="pcio-bs-build-year" name="build_year"
						min="1900" max="<?php echo esc_attr( (string) (int) gmdate( 'Y' ) ); ?>"
						inputmode="numeric">
				</div>

				<div class="pcio-ms-signup__field">
					<label for="pcio-bs-sail-id">
						<?php esc_html_e( 'Sail number', 'pcio-vis-boat' ); ?>
					</label>
					<input type="text" id="pcio-bs-sail-id" name="sail_id">
				</div>
			</div>

			<div class="pcio-ms-signup__field">
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
