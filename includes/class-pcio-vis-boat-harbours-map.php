<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the [pcio_boat_harbours_map] shortcode.
 *
 * Attributes:
 *   height  – CSS height of the map container (default: "500px").
 */
class PCIO_VIS_Boat_Harbours_Map {

	public function register(): void {
		add_shortcode( 'pcio_boat_harbours_map', [ $this, 'render' ] );
		add_action( 'pcio_me_shortcodes_after', [ $this, 'render_shortcode_docs' ] );
	}

	public function render_shortcode_docs(): void {
		?>
		<hr style="margin:32px 0">

		<h2 id="sc-boat-harbours-map"><?php esc_html_e( '[pcio_boat_harbours_map]', 'pcio-vis-boat' ); ?></h2>
		<p><?php esc_html_e( 'Displays an interactive map with all registered harbours. Each harbour with at least one member shows a numbered badge with the count of members that have it as their home harbour. Clicking a marker opens a popup listing the members. Harbours with no members show a small anchor icon. Provided by the PCIO VIS Boat plugin.', 'pcio-vis-boat' ); ?></p>

		<p>
			<strong><?php esc_html_e( 'Required capability:', 'pcio-vis-boat' ); ?></strong>
			<code class="pcio-sc-cap">vis_member</code>
			<?php esc_html_e( '— member names and numbers are only loaded for logged-in members; visitors without the capability see the map without member badges.', 'pcio-vis-boat' ); ?>
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
					<td><code class="pcio-sc-code">height</code></td>
					<td><?php esc_html_e( 'string', 'pcio-vis-boat' ); ?></td>
					<td><code class="pcio-sc-code">500px</code></td>
					<td><?php esc_html_e( 'CSS height of the map container.', 'pcio-vis-boat' ); ?></td>
				</tr>
			</tbody>
		</table>

		<strong style="display:block;margin-top:16px"><?php esc_html_e( 'Examples', 'pcio-vis-boat' ); ?></strong>
		<code class="pcio-sc-block">[pcio_boat_harbours_map]
[pcio_boat_harbours_map height="700px"]</code>
		<?php
	}

	/**
	 * @param array<string,string>|string $atts
	 */
	public function render( $atts ): string {
		$atts   = shortcode_atts( [ 'height' => '500px' ], $atts, 'pcio_boat_harbours_map' );
		$height = sanitize_text_field( $atts['height'] );

		$this->enqueue_assets();

		$cfg = wp_json_encode( [
			'rest'      => rest_url( PCIO_VIS_REST_NS . '/boat' ),
			'restNonce' => wp_create_nonce( 'wp_rest' ),
			'i18n'      => [
				'boat'    => __( 'boat', 'pcio-vis-boat' ),
				'boats'   => __( 'boats', 'pcio-vis-boat' ),
				'noBoats' => __( 'No boats registered here yet.', 'pcio-vis-boat' ),
			],
		] );

		wp_add_inline_script( 'pcio-vis-boat-harbour-map', 'window.PCIO_VIS_Boat_MAP = ' . $cfg . ';', 'before' );

		return '<div class="pcio-boat-harbours-map" style="height:' . esc_attr( $height ) . ';" aria-label="'
			. esc_attr__( 'Harbour map', 'pcio-vis-boat' ) . '"></div>';
	}

	private function enqueue_assets(): void {
		$this->register_leaflet();

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
	}

	/** Register Leaflet from our bundle unless boat-position already registered it. */
	public static function register_leaflet(): void {
		if ( ! wp_style_is( 'leaflet', 'registered' ) ) {
			wp_register_style(
				'leaflet',
				PCIO_VIS_BOAT_URL . 'assets/vendor/leaflet/leaflet.css',
				[],
				'1.9.4'
			);
		}
		if ( ! wp_script_is( 'leaflet', 'registered' ) ) {
			wp_register_script(
				'leaflet',
				PCIO_VIS_BOAT_URL . 'assets/vendor/leaflet/leaflet.js',
				[],
				'1.9.4',
				false
			);
		}
	}
}
