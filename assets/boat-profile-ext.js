/* globals PCIO_VIS_BOAT_EXT */
/**
 * Boat-Profile validation extension for the core [pcio_me_member_profile] form.
 * Listens to the pcioProfileValidate event dispatched by member-profile.js and
 * validates MMSI and build year (boat-specific rules the core JS does not know about).
 */
( function () {
	'use strict';

	var EXT = window.PCIO_VIS_BOAT_EXT || {};
	const FORM = () => document.getElementById( 'pcio-ms-profile-form' );
    var form, errorBox, successBox, submitBtn, spinner;
   
	console.log( 'PCIO_VIS_BOAT_EXT:', EXT );
    
	document.addEventListener( 'DOMContentLoaded', () => {
        init();
    } );

	function init() {
		form = FORM();

		if ( !form ) {
           console.log('form missing');
           return;
		}

		errorBox   = document.querySelector( '.pcio-ms-profile__error' );
		successBox = document.querySelector( '.pcio-ms-profile__success' );
		submitBtn  = form ? form.querySelector( '.pcio-ms-profile__submit' ) : null;
		spinner    = form ? form.querySelector( '.pcio-ms-profile__spinner' ) : null;

        fillForm( );
		initCrew();

		//form.addEventListener( 'submit', onSubmit );
	}

	function fillForm() {
        var txtBoatName = document.getElementById( 'pcio-bs-boat-name' );
		var txtHarbour = document.getElementById( 'pcio-bs-home-harbour' );
		var txtBoatType = document.getElementById( 'pcio-bs-boat-type' );
		var txtBoatModel = document.getElementById( 'pcio-bs-boat-model' );
		var txtBuildYear = document.getElementById( 'pcio-bs-build-year' );
		var txtSailNumber = document.getElementById( 'pcio-bs-sail-id' );
		var txtMMSI = document.getElementById( 'pcio-bs-mmsi' );

		const m = firstBoat( EXT.boat_data );

		setValue( txtBoatName, m.boat_name );
		setValue( txtHarbour, m.home_harbour );
		// Only the id is persisted, so the stored harbour has to be restored here
		// or a profile save would silently drop the boat's harbour.
		setValue( document.getElementById( 'pcio-bs-harbour-id' ), m.harbour_id );
		setValue( txtBoatType, m.boat_type );
		setValue( txtBoatModel, m.boat_model );
		setValue( txtBuildYear, m.build_year );
		setValue( txtSailNumber, m.sail_id );
		setValue( txtMMSI, m.mmsi );
	}

	/**
	 * Normalize the localized boat payload.
	 *
	 * The payload may arrive as a JSON string (double-encoded in PHP) and the
	 * DB helper returns a list of boats, so unwrap both layers and use the
	 * first boat for the single-boat profile form.
	 *
	 * @param {unknown} raw Raw value of PCIO_VIS_BOAT_EXT.boat_data.
	 * @return {Object} Boat fields, empty object when nothing usable is present.
	 */
	function firstBoat( raw ) {
		let data = raw;

		if ( typeof data === 'string' ) {
			try {
				data = JSON.parse( data );
			} catch ( e ) {
				console.log( 'boat_data is not valid JSON:', e );
				return {};
			}
		}

		if ( Array.isArray( data ) ) {
			data = data.length ? data[ 0 ] : {};
		}

		return ( data && typeof data === 'object' ) ? data : {};
	}

	/**
	 * Assign a value to an input, ignoring missing elements.
	 *
	 * @param {HTMLInputElement|null} el   Target input.
	 * @param {unknown}                val Value to set.
	 */
	function setValue( el, val ) {
		if ( ! el ) {
			return;
		}
		el.value = ( val === null || val === undefined ) ? '' : val;
	}
    
	
	document.addEventListener( 'pcioProfileValidate', function ( e ) {
		console.log( 'pcioProfileValidate event:', e );
		var form = e.detail && e.detail.form;
		if ( ! form ) {
			return;
		}

		var errors = e.detail.errors;
		var i18n   = EXT.i18n || {};
		console.log( 'Errors before validation:', errors );

		if ( fieldVal( form, 'boat_name' ) === '' ) {
			errors.push( fieldError( form, 'boat_name', i18n.boatNameRequired ) );
		}

		var mmsi = fieldVal( form, 'mmsi' );
		if ( mmsi !== '' && ! /^\d{9}$/.test( mmsi ) ) {
			errors.push( fieldError( form, 'mmsi', i18n.mmsiInvalid ) );
		console.log( 'Errors after MMSI validation:', errors );
		}

		var yearRaw = fieldVal( form, 'build_year' );
		if ( yearRaw !== '' ) {
			var year = parseInt( yearRaw, 10 );
			if ( isNaN( year ) || year < 1900 || year > new Date().getFullYear() ) {
				errors.push( fieldError( form, 'build_year', i18n.yearInvalid ) );
		console.log( 'Errors after build year validation:', errors );
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
			el.classList.add( 'pcio-ms-profile__input--error' );
		}
		return message;
	}

	// ── Crew editor ─────────────────────────────────────────────
	//
	// Add/remove run through the member self-service crew routes, which enforce
	// the membership rules server-side: an owner may only change their own
	// boat's crew, nobody can remove themselves, and only people who are not
	// already Vis members can be added.

	var CREW = window.PCIO_VIS_BOAT_CREW || {};
	var crewList, crewStatus, crewBusy = false;

	function initCrew() {
		crewList   = document.getElementById( 'pcio-bs-crew-list' );
		crewStatus = document.getElementById( 'pcio-bs-crew-status' );

		if ( !crewList || !CREW.rest ) {
			return; // No boat on record yet — the section only explains that.
		}

		document.getElementById( 'pcio-bs-crew-add' )
			?.addEventListener( 'click', addCrew );

		crewList.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '[data-remove-crew]' );
			if ( btn ) {
				removeCrew( parseInt( btn.dataset.removeCrew, 10 ), btn.dataset.removeName || '' );
			}
		} );

		loadCrew();
	}

	function crewI18n( key, fallback ) {
		var i18n = CREW.i18n || {};
		return i18n[ key ] || fallback;
	}

	function setStatus( message, kind ) {
		if ( !crewStatus ) {
			return;
		}
		crewStatus.textContent = message || '';
		crewStatus.classList.remove( 'is-error', 'is-success' );
		if ( kind ) {
			crewStatus.classList.add( 'is-' + kind );
		}
	}

	function crewRequest( method, url, body ) {
		return fetch( url, {
			method: method,
			headers: Object.assign(
				{ 'Content-Type': 'application/json' },
				CREW.nonce ? { 'X-WP-Nonce': CREW.nonce } : {}
			),
			body: body ? JSON.stringify( body ) : undefined,
		} ).then( function ( res ) {
			return res.json().catch( function () { return {}; } )
				.then( function ( data ) {
					if ( !res.ok ) {
						throw new Error( data.message || crewI18n( 'failed', 'Request failed.' ) );
					}
					return data;
				} );
		} );
	}

	function loadCrew() {
		crewRequest( 'GET', CREW.rest )
			.then( renderCrew )
			.catch( function ( err ) {
				setStatus( err.message, 'error' );
			} );
	}

	function renderCrew( rows ) {
		crewList.innerHTML = '';

		var list = Array.isArray( rows ) ? rows : [];
		if ( !list.length ) {
			var empty = document.createElement( 'li' );
			empty.textContent = crewI18n( 'noCrew', 'No crew members yet.' );
			crewList.appendChild( empty );
			return;
		}

		list.forEach( function ( row ) {
			var isSelf = parseInt( row.member_id, 10 ) === parseInt( CREW.selfMemberId, 10 );

			var li = document.createElement( 'li' );

			var left = document.createElement( 'span' );
			var name = document.createElement( 'span' );
			name.className = 'pcio-boat-profile__crew-name';
			name.textContent = row.member_name || '';
			left.appendChild( name );

			if ( row.member_number ) {
				var no = document.createElement( 'span' );
				no.className = 'pcio-boat-profile__crew-no';
				no.textContent = '#' + row.member_number;
				left.appendChild( no );
			}
			if ( isSelf ) {
				var you = document.createElement( 'span' );
				you.className = 'pcio-boat-profile__crew-you';
				you.textContent = crewI18n( 'you', 'You' );
				left.appendChild( you );
			}
			li.appendChild( left );

			// The owner cannot remove themselves, so no button on their own row.
			if ( !isSelf ) {
				var btn = document.createElement( 'button' );
				btn.type = 'button';
				btn.className = 'pcio-boat-profile__crew-remove';
				btn.dataset.removeCrew = row.member_id;
				btn.dataset.removeName = row.member_name || '';
				btn.textContent = crewI18n( 'remove', 'Remove' );
				li.appendChild( btn );
			}

			crewList.appendChild( li );
		} );
	}

	function setCrewBusy( busy ) {
		crewBusy = busy;
		var btn = document.getElementById( 'pcio-bs-crew-add' );
		if ( btn ) {
			btn.disabled = busy;
		}
		crewList.querySelectorAll( '[data-remove-crew]' ).forEach( function ( b ) {
			b.disabled = busy;
		} );
	}

	function addCrew() {
		if ( crewBusy ) {
			return;
		}

		var name  = document.getElementById( 'pcio-bs-crew-name' );
		var email = document.getElementById( 'pcio-bs-crew-email' );
		var phone = document.getElementById( 'pcio-bs-crew-phone' );

		var payload = {
			name:  ( name?.value  || '' ).trim(),
			email: ( email?.value || '' ).trim(),
			phone: ( phone?.value || '' ).trim(),
		};

		if ( !payload.name ) {
			setStatus( crewI18n( 'nameReq', 'A name is required.' ), 'error' );
			name?.focus();
			return;
		}
		if ( !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( payload.email ) ) {
			setStatus( crewI18n( 'emailReq', 'A valid email address is required.' ), 'error' );
			email?.focus();
			return;
		}

		setStatus( crewI18n( 'working', 'Working…' ) );
		setCrewBusy( true );

		crewRequest( 'POST', CREW.rest, payload )
			.then( function ( rows ) {
				name.value = '';
				email.value = '';
				phone.value = '';
				renderCrew( rows );
				setStatus( crewI18n( 'added', 'Crew member added.' ), 'success' );
			} )
			.catch( function ( err ) {
				setStatus( err.message, 'error' );
			} )
			.finally( function () {
				setCrewBusy( false );
			} );
	}

	function removeCrew( memberId, name ) {
		if ( crewBusy || !memberId ) {
			return;
		}
		if ( !window.confirm( crewI18n( 'removeQ', 'Remove this crew member?' ) + ( name ? ' (' + name + ')' : '' ) ) ) {
			return;
		}

		setStatus( crewI18n( 'working', 'Working…' ) );
		setCrewBusy( true );

		crewRequest( 'DELETE', CREW.rest + '/' + memberId )
			.then( function ( rows ) {
				renderCrew( rows );
				setStatus( crewI18n( 'removed', 'Crew member removed.' ), 'success' );
			} )
			.catch( function ( err ) {
				setStatus( err.message, 'error' );
			} )
			.finally( function () {
				setCrewBusy( false );
			} );
	}
	



}() );
