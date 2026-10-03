/* globals PCIO_VIS_Boat_Harbours */
( function () {
	'use strict';

	var CFG = window.PCIO_VIS_Boat_Harbours || {};
	var form, errorBox, submitBtn, spinner;

	function init() {
		form      = document.getElementById( 'pcio-boat-signup-form' );
		errorBox  = document.querySelector( '.pcio-boat-signup__error' );
		submitBtn = form ? form.querySelector( '.pcio-boat-signup__submit' ) : null;
		spinner   = form ? form.querySelector( '.pcio-boat-signup__spinner' ) : null;

		if ( ! form ) {
			return;
		}

		form.addEventListener( 'submit', onSubmit );
	}

	function onSubmit( e ) {
		e.preventDefault();
		clearErrors();

		var errors = validate();
		if ( errors.length ) {
			showError( errors[ 0 ] );
			return;
		}

		setBusy( true );

		fetch( CFG.rest, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': CFG.restNonce,
			},
			body: JSON.stringify( buildPayload() ),
		} )
			.then( function ( res ) {
				return res.json().then( function ( data ) {
					return { ok: res.ok, data: data };
				} );
			} )
			.then( function ( r ) {
				if ( ! r.ok ) {
					setBusy( false );
					showError( r.data.message || CFG.i18n.serverError );
					return;
				}
				if ( r.data.redirect ) {
					window.location.href = r.data.redirect;
				}
			} )
			.catch( function () {
				setBusy( false );
				showError( CFG.i18n.serverError );
			} );
	}

	function buildPayload() {
		var year = val( 'build_year' );
		return {
			nonce:        CFG.nonce,
			cancel_url:   CFG.cancelUrl,
			name:         val( 'name' ),
			email:        val( 'email' ),
			phone:        val( 'phone' ),
			address:      val( 'address' ),
			harbour_id:   parseInt( val( 'harbour_id' ) || '0', 10 ),
			boat_name:    val( 'boat_name' ),
			boat_type:    val( 'boat_type' ),
			boat_model:   val( 'boat_model' ),
			build_year:   year !== '' ? parseInt( year, 10 ) : null,
			sail_id:      val( 'sail_id' ),
			mmsi:         val( 'mmsi' ),
		};
	}

	function validate() {
		var errors = [];
		var i18n   = CFG.i18n || {};

		var name  = val( 'name' );
		var email = val( 'email' );
		var boat  = val( 'boat_name' );
		var mmsi  = val( 'mmsi' );
		var year  = val( 'build_year' );

		if ( name === '' ) {
			errors.push( fieldError( 'name', i18n.required ) );
		}
		if ( email === '' || ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( email ) ) {
			errors.push( fieldError( 'email', i18n.emailInvalid ) );
		}
		if ( boat === '' ) {
			errors.push( fieldError( 'boat_name', i18n.required ) );
		}
		if ( mmsi !== '' && ! /^\d{9}$/.test( mmsi ) ) {
			errors.push( fieldError( 'mmsi', i18n.mmsiInvalid ) );
		}
		if ( year !== '' ) {
			var y = parseInt( year, 10 );
			if ( isNaN( y ) || y < 1900 || y > new Date().getFullYear() ) {
				errors.push( fieldError( 'build_year', i18n.yearInvalid ) );
			}
		}

		return errors;
	}

	function fieldError( name, message ) {
		var el = form.querySelector( '[name="' + name + '"]' );
		if ( el ) {
			el.classList.add( 'pcio-boat-signup__input--error' );
		}
		return message;
	}

	function clearErrors() {
		form.querySelectorAll( '.pcio-boat-signup__input--error' ).forEach( function ( el ) {
			el.classList.remove( 'pcio-boat-signup__input--error' );
		} );
		errorBox.style.display = 'none';
		errorBox.textContent   = '';
	}

	function showError( message ) {
		errorBox.textContent   = message;
		errorBox.style.display = '';
		errorBox.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
	}

	function setBusy( busy ) {
		if ( submitBtn ) {
			submitBtn.disabled = busy;
		}
		if ( spinner ) {
			spinner.style.display = busy ? '' : 'none';
		}
	}

	function val( name ) {
		var el = form ? form.querySelector( '[name="' + name + '"]' ) : null;
		return el ? el.value.trim() : '';
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
