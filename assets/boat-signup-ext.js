/* globals PCIO_VIS_BOAT_EXT */
/**
 * Boat-signup validation extension for the core [pcio_me_member_signup] form.
 * Listens to the pcioSignupValidate event dispatched by member-signup.js and
 * validates MMSI and build year (boat-specific rules the core JS does not know about).
 */
( function () {
	'use strict';

	var EXT = window.PCIO_VIS_BOAT_EXT || {};

	document.addEventListener( 'pcioSignupValidate', function ( e ) {
		var form = e.detail && e.detail.form;
		if ( ! form ) {
			return;
		}

		var errors = e.detail.errors;
		var i18n   = EXT.i18n || {};

		var mmsi = fieldVal( form, 'mmsi' );
		if ( mmsi !== '' && ! /^\d{9}$/.test( mmsi ) ) {
			errors.push( fieldError( form, 'mmsi', i18n.mmsiInvalid ) );
		}

		var yearRaw = fieldVal( form, 'build_year' );
		if ( yearRaw !== '' ) {
			var year = parseInt( yearRaw, 10 );
			if ( isNaN( year ) || year < 1900 || year > new Date().getFullYear() ) {
				errors.push( fieldError( form, 'build_year', i18n.yearInvalid ) );
			}
		}
	} );

	function fieldVal( form, name ) {
		var el = form.querySelector( '[name="' + name + '"]' );
		return el ? el.value.trim() : '';
	}

	function fieldError( form, name, message ) {
		var el = form.querySelector( '[name="' + name + '"]' );
		if ( el ) {
			el.classList.add( 'pcio-ms-signup__input--error' );
		}
		return message;
	}
}() );
