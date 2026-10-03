<?php
/**
 * Shortcode template: boat club member sign-up form.
 * Rendered by PCIO_VIS_Boat_Harbours::render() via output buffer.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="pcio-boat-signup">

	<div class="pcio-boat-signup__error" role="alert" aria-live="polite" style="display:none;"></div>

	<form id="pcio-boat-signup-form" class="pcio-boat-signup__form" novalidate>

		<fieldset class="pcio-boat-signup__section">
			<legend class="pcio-boat-signup__legend">
				<?php esc_html_e( 'Your details', 'pcio-vis-boat' ); ?>
			</legend>

			<div class="pcio-boat-signup__field">
				<label for="pcio-bs-name">
					<?php esc_html_e( 'Full name', 'pcio-vis-boat' ); ?>
					<span class="pcio-boat-signup__req" aria-hidden="true">*</span>
				</label>
				<input type="text" id="pcio-bs-name" name="name" autocomplete="name" required>
			</div>

			<div class="pcio-boat-signup__field">
				<label for="pcio-bs-email">
					<?php esc_html_e( 'Email', 'pcio-vis-boat' ); ?>
					<span class="pcio-boat-signup__req" aria-hidden="true">*</span>
				</label>
				<input type="email" id="pcio-bs-email" name="email" autocomplete="email" required>
			</div>

			<div class="pcio-boat-signup__field">
				<label for="pcio-bs-phone">
					<?php esc_html_e( 'Phone', 'pcio-vis-boat' ); ?>
				</label>
				<input type="tel" id="pcio-bs-phone" name="phone" autocomplete="tel">
			</div>

			<div class="pcio-boat-signup__field">
				<label for="pcio-bs-address">
					<?php esc_html_e( 'Address', 'pcio-vis-boat' ); ?>
				</label>
				<textarea id="pcio-bs-address" name="address" autocomplete="street-address" rows="3"></textarea>
			</div>
		</fieldset>

		<fieldset class="pcio-boat-signup__section">
			<legend class="pcio-boat-signup__legend">
				<?php esc_html_e( 'Boat details', 'pcio-vis-boat' ); ?>
			</legend>

			<div class="pcio-boat-signup__field">
				<label for="pcio-bs-boat-name">
					<?php esc_html_e( 'Boat name', 'pcio-vis-boat' ); ?>
					<span class="pcio-boat-signup__req" aria-hidden="true">*</span>
				</label>
				<input type="text" id="pcio-bs-boat-name" name="boat_name" required>
			</div>

			<div class="pcio-boat-signup__field">
				<label for="pcio-bs-home-harbour">
					<?php esc_html_e( 'Home harbour', 'pcio-vis-boat' ); ?>
				</label>
				<input type="hidden" id="pcio-bs-harbour-id" name="harbour_id" value="0">
				<div class="pcio-boat-signup__harbour-picker">
					<input type="text" id="pcio-bs-home-harbour"
						autocomplete="off" role="combobox" aria-expanded="false"
						aria-autocomplete="list" aria-controls="pcio-bs-harbour-suggestions">
					<ul id="pcio-bs-harbour-suggestions"
						class="pcio-boat-signup__harbour-suggestions"
						role="listbox" style="display:none;"></ul>
				</div>
				<button type="button" id="pcio-bs-add-harbour-btn"
					class="pcio-boat-signup__harbour-add-btn">
					<?php esc_html_e( 'Not in list? Add on map', 'pcio-vis-boat' ); ?>
				</button>
			</div>

			<div class="pcio-boat-signup__field">
				<label for="pcio-bs-boat-type">
					<?php esc_html_e( 'Boat type', 'pcio-vis-boat' ); ?>
				</label>
				<input type="text" id="pcio-bs-boat-type" name="boat_type"
					placeholder="<?php esc_attr_e( 'e.g. Sailing Yacht', 'pcio-vis-boat' ); ?>">
			</div>

			<div class="pcio-boat-signup__field">
				<label for="pcio-bs-boat-model">
					<?php esc_html_e( 'Boat model', 'pcio-vis-boat' ); ?>
				</label>
				<input type="text" id="pcio-bs-boat-model" name="boat_model">
			</div>

			<div class="pcio-boat-signup__row">
				<div class="pcio-boat-signup__field">
					<label for="pcio-bs-build-year">
						<?php esc_html_e( 'Build year', 'pcio-vis-boat' ); ?>
					</label>
					<input type="number" id="pcio-bs-build-year" name="build_year"
						min="1900" max="<?php echo esc_attr( (string) (int) gmdate( 'Y' ) ); ?>"
						inputmode="numeric">
				</div>

				<div class="pcio-boat-signup__field">
					<label for="pcio-bs-sail-id">
						<?php esc_html_e( 'Sail number', 'pcio-vis-boat' ); ?>
					</label>
					<input type="text" id="pcio-bs-sail-id" name="sail_id">
				</div>

				<div class="pcio-boat-signup__field">
					<label for="pcio-bs-mmsi">
						<?php esc_html_e( 'MMSI', 'pcio-vis-boat' ); ?>
					</label>
					<input type="text" id="pcio-bs-mmsi" name="mmsi"
						pattern="[0-9]{9}" inputmode="numeric" maxlength="9"
						placeholder="<?php esc_attr_e( '9 digits', 'pcio-vis-boat' ); ?>">
				</div>
			</div>
		</fieldset>

		<p class="pcio-boat-signup__note">
			<?php esc_html_e( '* Required', 'pcio-vis-boat' ); ?>
		</p>

		<div class="pcio-boat-signup__actions">
			<button type="submit" class="pcio-boat-signup__submit">
				<?php esc_html_e( 'Continue to payment', 'pcio-vis-boat' ); ?>
			</button>
			<span class="pcio-boat-signup__spinner" style="display:none;" aria-hidden="true"></span>
		</div>

	</form>
</div>
