/* ================================================================
   PCIO VIS Boat — boat popup for the [pcio_me_members] table.
   Clicking a Boat cell (button.pcio-boat-trigger) opens a read-only
   dialog with the boat's details. All values are written with
   textContent, so nothing from the data attribute is ever parsed as HTML.
   ================================================================ */

( function () {
	'use strict';

	const CFG   = window.PCIO_VIS_BOAT_MEMBERS || {};
	const T     = ( CFG.i18n || {} );
	const LABELS = {
		boat_type:     T.type,
		boat_model:    T.model,
		home_harbour:  T.harbour,
		build_year:    T.year,
		sail_id:       T.sail,
		mmsi:          T.mmsi,
		member_number: T.memberNo,
		crew:          T.crew,
		note:          T.note,
	};

	let modal = null;
	let lastTrigger = null;

	document.addEventListener( 'DOMContentLoaded', init );

	function init() {
		modal = document.getElementById( 'pcio-boat-modal' );
		if ( !modal ) {
			return;
		}

		document.addEventListener( 'click', function ( e ) {
			const trigger = e.target.closest( '.pcio-boat-trigger' );
			if ( trigger ) {
				openBoat( trigger );
				return;
			}
			if ( e.target.closest( '[data-boat-close]' ) ) {
				closeBoat();
			}
		} );

		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' && !modal.hidden ) {
				closeBoat();
			}
		} );
	}

	function parseBoat( trigger ) {
		try {
			const data = JSON.parse( trigger.dataset.boat || '{}' );
			return ( data && typeof data === 'object' ) ? data : {};
		} catch ( err ) {
			return {};
		}
	}

	function openBoat( trigger ) {
		const boat = parseBoat( trigger );
		if ( ! boat.boat_name ) {
			return;
		}

		lastTrigger = trigger;
		modal.querySelector( '.pcio-boat-modal__title' ).textContent = boat.boat_name;

		modal.querySelectorAll( '[data-boat-field]' ).forEach( function ( row ) {
			const key  = row.dataset.boatField;
			const raw  = boat[ key ];
			const text = key === 'crew'
				? ( Array.isArray( raw ) ? raw.filter( Boolean ).join( ', ' ) : '' )
				: ( raw === null || raw === undefined ? '' : String( raw ).trim() );

			if ( ! LABELS[ key ] || ! text ) {
				row.hidden = true;
				return;
			}

			const dt = document.createElement( 'dt' );
			dt.textContent = LABELS[ key ];
			const dd = document.createElement( 'dd' );
			dd.textContent = text;

			row.replaceChildren( dt, dd );
			row.hidden = false;
		} );

		modal.hidden = false;
		modal.querySelector( '.pcio-boat-modal__close' )?.focus();
	}

	function closeBoat() {
		modal.hidden = true;
		if ( lastTrigger ) {
			lastTrigger.focus();
			lastTrigger = null;
		}
	}
}() );