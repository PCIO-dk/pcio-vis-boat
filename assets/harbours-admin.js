/* ================================================================
   PCIO VIS Boat — harbours-admin.js
   Harbours management page (/vis/harbours).

   Builds the shell into #pcio-me-app: page header, harbour table,
   create/edit modal with a draggable Leaflet marker, and a delete
   confirm overlay. Reuses me-* styles from members.css and pcio-hm-*
   modal/overlay classes from harbour-map.css.
   ================================================================ */

( function () {
	'use strict';

	var CFG  = window.PCIO_VIS_Boat_Harbours_ADMIN || {};
	var T    = CFG.i18n  || {};
	var REST = ( CFG.rest || '' ).replace( /\/$/, '' );

	// ── SVG icon strings ──────────────────────────────────────────
	var ICON_EDIT = '<svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>';
	var ICON_DEL  = '<svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>';
	var ICON_EMPTY = '<svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>';

	// ── State ─────────────────────────────────────────────────────
	var allHarbours = [];
	var editId      = null;
	var deleteId    = null;
	var toastTimer  = null;
	var mapInstance = null;
	var marker      = null;
	var chosenLat   = null;
	var chosenLon   = null;

	// ── Bootstrap ─────────────────────────────────────────────────
	document.addEventListener( 'DOMContentLoaded', function () {
		buildShell();
		loadHarbours();
	} );

	// ── Shell ─────────────────────────────────────────────────────

	function buildShell() {
		var app = document.getElementById( 'pcio-me-app' );
		if ( ! app ) { return; }

		app.innerHTML =
			'<div class="me-page-header">' +
				'<h1 class="me-page-title">' + escHtml( T.pageTitle || 'Harbours' ) + '</h1>' +
				'<div class="me-header-right">' +
					'<button class="me-btn me-btn-primary" id="hm-new">' + escHtml( T.newHarbour || '+ New harbour' ) + '</button>' +
				'</div>' +
			'</div>' +

			'<div class="me-table-card">' +
				'<div class="me-toolbar">' +
					'<span class="me-toolbar-info" id="hm-count"></span>' +
					'<div class="me-toolbar-actions">' +
						'<div class="me-search-box">' +
							'<input type="search" id="hm-search" placeholder="' +
								escAttr( T.search || 'Search\u2026' ) + '" autocomplete="off">' +
						'</div>' +
					'</div>' +
				'</div>' +
				'<div class="me-table-wrap">' +
					'<table id="me-table">' +
						'<thead>' +
							'<tr class="me-head-row"><th>' + escHtml( T.harbourName || 'Harbour' ) + '</th><th>' + escHtml( T.latitude || 'Lat' ) + '</th><th>' + escHtml( T.longitude || 'Lon' ) + '</th><th>' + escHtml( T.boats || 'Boats' ) + '</th><th></th></tr>' +
						'</thead>' +
						'<tbody id="hm-tbody"></tbody>' +
					'</table>' +
				'</div>' +
				'<div class="me-empty-state me-hidden" id="hm-empty">' +
					ICON_EMPTY + '<p id="hm-empty-msg"></p>' +
				'</div>' +
			'</div>' +

			// Create / edit modal (reuses pcio-hm-* overlay/modal classes)
			'<div class="pcio-hm-overlay me-hidden" id="hm-overlay" role="dialog" aria-modal="true" aria-labelledby="hm-modal-title">' +
				'<div class="pcio-hm-modal">' +
					'<div class="pcio-hm-modal-header">' +
						'<h3 class="pcio-hm-modal-title" id="hm-modal-title"></h3>' +
						'<button type="button" class="pcio-hm-modal-close" id="hm-modal-close" aria-label="' + escAttr( T.close || 'Close' ) + '">\xd7</button>' +
					'</div>' +
					'<p class="pcio-hm-modal-error" id="hm-modal-error"></p>' +
					'<div class="pcio-hm-modal-body">' +
						'<div class="pcio-hm-form-fields">' +
							'<div class="me-form-row">' +
								'<div class="me-form-group">' +
									'<label class="me-form-label" for="hm-name">' + escHtml( T.harbourName || 'Harbour name' ) + ' *</label>' +
									'<input class="me-form-input" type="text" id="hm-name" maxlength="128" required>' +
								'</div>' +
							'</div>' +
							'<div class="me-form-row">' +
								'<div class="me-form-group">' +
									'<label class="me-form-label" for="hm-lat">' + escHtml( T.latitude || 'Latitude' ) + '</label>' +
									'<input class="me-form-input" type="number" id="hm-lat" step="0.000001" min="-90" max="90">' +
								'</div>' +
								'<div class="me-form-group">' +
									'<label class="me-form-label" for="hm-lon">' + escHtml( T.longitude || 'Longitude' ) + '</label>' +
									'<input class="me-form-input" type="number" id="hm-lon" step="0.000001" min="-180" max="180">' +
								'</div>' +
							'</div>' +
						'</div>' +
						'<div id="pcio-hm-modal-map" class="pcio-hm-modal-map"></div>' +
					'</div>' +
					'<div class="pcio-hm-modal-footer">' +
						'<button class="me-btn me-btn-secondary" id="hm-cancel">' + escHtml( T.cancel || 'Cancel' ) + '</button>' +
						'<button class="me-btn me-btn-primary" id="hm-save">' + escHtml( T.save || 'Save' ) + '</button>' +
					'</div>' +
				'</div>' +
			'</div>' +

			// Delete confirm modal — reuses me-delete-* from members.css
			'<div class="me-overlay me-hidden" id="hm-del-overlay" role="dialog" aria-modal="true">' +
				'<div class="me-modal me-modal-sm">' +
					'<div class="me-modal-header">' +
						'<h2 class="me-modal-title">' + escHtml( T.delete || 'Delete' ) + '</h2>' +
						'<button class="me-modal-close" id="hm-del-close" aria-label="' + escAttr( T.cancel || 'Close' ) + '">&times;</button>' +
					'</div>' +
					'<div class="me-delete-body">' +
						'<div class="me-delete-icon">' + ICON_DEL + '</div>' +
						'<p class="me-delete-title" id="hm-del-title">' + escHtml( T.confirmDelete || 'Delete this harbour?' ) + '</p>' +
						'<p class="me-delete-name" id="hm-del-name"></p>' +
						'<p class="me-delete-warning pcio-hm-delete-boats" id="hm-del-boats"></p>' +
					'</div>' +
					'<div class="me-delete-footer">' +
						'<button class="me-btn me-btn-secondary" id="hm-del-cancel">' + escHtml( T.cancel || 'Cancel' ) + '</button>' +
						'<button class="me-btn me-btn-danger" id="hm-del-confirm">' + escHtml( T.delete || 'Delete' ) + '</button>' +
					'</div>' +
				'</div>' +
			'</div>' +

			// Toast
			'<div class="me-toast me-hidden" id="hm-toast" role="status" aria-live="polite"></div>';

		// Event bindings
		document.getElementById( 'hm-new'         ).addEventListener( 'click', function () { openModal( null ); } );
		document.getElementById( 'hm-search'      ).addEventListener( 'input', renderTable );
		document.getElementById( 'hm-modal-close' ).addEventListener( 'click', closeModal );
		document.getElementById( 'hm-cancel'      ).addEventListener( 'click', closeModal );
		document.getElementById( 'hm-save'        ).addEventListener( 'click', saveHarbour );
		document.getElementById( 'hm-del-close'  ).addEventListener( 'click', closeDeleteModal );
		document.getElementById( 'hm-del-cancel' ).addEventListener( 'click', closeDeleteModal );
		document.getElementById( 'hm-del-confirm').addEventListener( 'click', doDelete );

		// Confirmation dialogs may be dismissed by clicking their backdrop.
		[ 'hm-del-overlay' ].forEach( function ( id ) {
			document.getElementById( id )?.addEventListener( 'click', function ( e ) {
				if ( e.target === e.currentTarget ) {
					closeDeleteModal();
				}
			} );
		} );

		// Close on Escape
		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key !== 'Escape' ) { return; }
			if ( ! isHidden( 'hm-del-overlay' ) ) { closeDeleteModal(); }
			else if ( ! isHidden( 'hm-overlay' ) ) { closeModal(); }
		} );
	}

	// ── Data ──────────────────────────────────────────────────────

	function loadHarbours() {
		apiFetch( REST + '/harbours/manage' )
			.then( function ( data ) {
				allHarbours = Array.isArray( data ) ? data : [];
				renderTable();
			} )
			.catch( function ( err ) {
				allHarbours = [];
				var msg = document.getElementById( 'hm-empty-msg' );
				if ( msg ) { msg.textContent = err.message; }
				document.getElementById( 'hm-empty' )?.classList.remove( 'me-hidden' );
			} );
	}

	// ── Render table ──────────────────────────────────────────────

	function renderTable() {
		var tbody   = document.getElementById( 'hm-tbody' );
		var empty   = document.getElementById( 'hm-empty' );
		var emptyMsg = document.getElementById( 'hm-empty-msg' );
		var countEl = document.getElementById( 'hm-count' );
		if ( ! tbody ) { return; }

		var q = ( document.getElementById( 'hm-search' )?.value || '' ).toLowerCase().trim();

		var filtered = allHarbours.filter( function ( h ) {
			if ( ! q ) { return true; }
			var hay = ( h.name + ' ' + h.lat + ' ' + h.lon ).toLowerCase();
			return hay.includes( q );
		} );

		if ( countEl ) {
			if ( allHarbours.length === 0 ) {
				countEl.textContent = '';
			} else if ( filtered.length < allHarbours.length ) {
				countEl.textContent = ( T.showing || 'Showing' ) + ' ' + filtered.length + ' ' + ( T.of || 'of' ) + ' ' + allHarbours.length;
			} else {
				countEl.textContent = allHarbours.length + ' ' + ( T.boats || 'boats' );
			}
		}

		if ( ! filtered.length ) {
			tbody.innerHTML = '';
			if ( emptyMsg ) {
				emptyMsg.textContent = allHarbours.length === 0
					? ( T.noHarbours || 'No harbours registered yet.' )
					: ( T.noMatch || 'No matches.' );
			}
			empty?.classList.remove( 'me-hidden' );
			return;
		}
		empty?.classList.add( 'me-hidden' );

		tbody.innerHTML = filtered.map( function ( h ) {
			var boatWord = h.boat_count === 1 ? ( T.boat || 'boat' ) : ( T.boats || 'boats' );
			return '<tr>' +
				'<td>' + escHtml( h.name ) + '</td>' +
				'<td>' + escHtml( fmtCoord( h.lat ) ) + '</td>' +
				'<td>' + escHtml( fmtCoord( h.lon ) ) + '</td>' +
				'<td>' + escHtml( h.boat_count + ' ' + boatWord ) + '</td>' +
				'<td><div class="me-row-actions">' +
					'<button class="me-icon-btn edit" data-edit="' + h.id + '" title="' + escAttr( T.edit || 'Edit' ) + '">' + ICON_EDIT + '</button>' +
					'<button class="me-icon-btn delete" data-del="' + h.id + '" title="' + escAttr( T.delete || 'Delete' ) + '">' + ICON_DEL + '</button>' +
				'</div></td>' +
			'</tr>';
		} ).join( '' );

		tbody.querySelectorAll( '[data-edit]' ).forEach( function ( b ) {
			b.addEventListener( 'click', function () { openModal( +b.dataset.edit ); } );
		} );
		tbody.querySelectorAll( '[data-del]' ).forEach( function ( b ) {
			b.addEventListener( 'click', function () { confirmDelete( +b.dataset.del ); } );
		} );
	}

	function fmtCoord( v ) {
		return Number( v ).toFixed( 6 );
	}

	// ── Modal ─────────────────────────────────────────────────────

	function openModal( id ) {
		editId = id;
		var harbour = id ? allHarbours.find( function ( h ) { return Number( h.id ) === Number( id ); } ) : null;

		document.getElementById( 'hm-modal-title' ).textContent =
			id ? ( T.edit || 'Edit' ) : ( T.newHarbour || '+ New harbour' );
		document.getElementById( 'hm-modal-error' ).textContent = '';
		setF( 'hm-name', harbour ? harbour.name : '' );

		var lat = harbour ? parseFloat( harbour.lat ) : NaN;
		var lon = harbour ? parseFloat( harbour.lon ) : NaN;
		setF( 'hm-lat', ( Number.isNaN( lat ) || lat === 0 ) ? '' : String( lat ) );
		setF( 'hm-lon', ( Number.isNaN( lon ) || lon === 0 ) ? '' : String( lon ) );

		show( 'hm-overlay' );
		initMap( harbour, lat, lon );
		PCIOVisModal.open( 'hm-overlay', closeModal );
		setTimeout( function () { document.getElementById( 'hm-name' )?.focus(); }, 40 );
	}

	function closeModal( saved = false ) {
		if ( ! PCIOVisModal.close( 'hm-overlay', saved ) ) { return false; }
		hide( 'hm-overlay' );
		editId = null;
		destroyMap();
		return true;
	}

	function initMap( harbour, lat, lon ) {
		var mapEl = document.getElementById( 'pcio-hm-modal-map' );
		if ( ! mapEl || ! window.L ) { return; }

		// Use default centre for new harbours or unset coords.
		var isUnset = Number.isNaN( lat ) || Number.isNaN( lon ) || ( lat === 0 && lon === 0 );
		var center  = isUnset ? ( CFG.mapCenter || [ 56.0, 10.5 ] ) : [ lat, lon ];
		var zoom    = isUnset ? ( CFG.mapZoom || 6 ) : 10;

		destroyMap();

		mapInstance = L.map( 'pcio-hm-modal-map' ).setView( center, zoom );

		L.tileLayer( 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
			attribution: '\xa9 <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
			maxZoom: 18,
		} ).addTo( mapInstance );

		L.tileLayer( 'https://tiles.openseamap.org/seamark/{z}/{x}/{y}.png', {
			attribution: 'Map data: \xa9 <a href="http://www.openseamap.org">OpenSeaMap</a> contributors',
		} ).addTo( mapInstance );

		// Invalidate size so the map renders after the modal becomes visible.
		setTimeout( function () { mapInstance.invalidateSize(); }, 120 );

		marker = L.marker( center, { draggable: true } ).addTo( mapInstance );
		chosenLat = isUnset ? ( CFG.mapCenter || [ 56.0, 10.5 ] )[0] : lat;
		chosenLon = isUnset ? ( CFG.mapCenter || [ 56.0, 10.5 ] )[1] : lon;
		marker.setLatLng( [ chosenLat, chosenLon ] );

		marker.on( 'dragend', function () {
			var p = marker.getLatLng();
			chosenLat = p.lat;
			chosenLon = p.lng;
			setF( 'hm-lat', fmtCoord( p.lat ) );
			setF( 'hm-lon', fmtCoord( p.lng ) );
		} );

		// Input → marker sync
		document.getElementById( 'hm-lat' ).addEventListener( 'change', function () {
			var v = parseFloat( this.value );
			if ( ! Number.isNaN( v ) && v >= -90 && v <= 90 ) {
				chosenLat = v;
				if ( marker ) { marker.setLatLng( [ v, chosenLon ] ); }
				mapInstance.panTo( [ v, chosenLon ] );
			}
		} );
		document.getElementById( 'hm-lon' ).addEventListener( 'change', function () {
			var v = parseFloat( this.value );
			if ( ! Number.isNaN( v ) && v >= -180 && v <= 180 ) {
				chosenLon = v;
				if ( marker ) { marker.setLatLng( [ chosenLat, v ] ); }
				mapInstance.panTo( [ chosenLat, v ] );
			}
		} );
	}

	function destroyMap() {
		if ( mapInstance ) {
			mapInstance.remove();
			mapInstance = null;
		}
		marker = null;
		chosenLat = null;
		chosenLon = null;
	}

	function saveHarbour() {
		var name = ( getF( 'hm-name' ) || '' ).trim();
		if ( ! name ) {
			document.getElementById( 'hm-name' )?.focus();
			return;
		}

		var latInput = getF( 'hm-lat' );
		var lonInput = getF( 'hm-lon' );
		var lat = parseFloat( latInput );
		var lon = parseFloat( lonInput );
		// If the inputs are empty, fall back to the marker's current position.
		if ( Number.isNaN( lat ) ) { lat = chosenLat; }
		if ( Number.isNaN( lon ) ) { lon = chosenLon; }
		if ( Number.isNaN( lat ) || lat < -90 || lat > 90 ) {
			showError( T.errCoords || 'Please enter a valid latitude (-90 to 90).' );
			return;
		}
		if ( Number.isNaN( lon ) || lon < -180 || lon > 180 ) {
			showError( T.errCoords || 'Please enter a valid longitude (-180 to 180).' );
			return;
		}

		var payload = {
			name: name,
			lat:  Math.round( lat * 1e6 ) / 1e6,
			lon:  Math.round( lon * 1e6 ) / 1e6,
		};

		var btn = document.getElementById( 'hm-save' );
		btn.disabled = true;

		var method = editId ? 'PUT' : 'POST';
		var url    = editId ? ( REST + '/harbours/' + editId ) : ( REST + '/harbours' );

		apiFetch( url, method, payload )
			.then( function () {
				closeModal( true );
				loadHarbours();
				toast( '\u2713', 'success' );
			} )
			.catch( function ( err ) {
				if ( err.status === 409 ) {
					showError( err.message );
				} else {
					toast( ( T.saveFailed || 'Save failed.' ) + ' ' + err.message, 'error' );
				}
			} )
			.finally( function () {
				btn.disabled = false;
			} );
	}

	function showError( msg ) {
		var el = document.getElementById( 'hm-modal-error' );
		if ( el ) { el.textContent = msg; }
	}

	// ── Delete ────────────────────────────────────────────────────

	function confirmDelete( id ) {
		deleteId = id;
		var harbour = allHarbours.find( function ( h ) { return Number( h.id ) === Number( id ); } );
		var titleEl = document.getElementById( 'hm-del-title' );
		if ( titleEl ) { titleEl.textContent = harbour ? ( T.delete || 'Delete' ) + ' ' + harbour.name + '?' : ( T.confirmDelete || 'Delete this harbour?' ); }
		var nameEl = document.getElementById( 'hm-del-name' );
		if ( nameEl ) { nameEl.textContent = harbour ? harbour.name : ''; }
		var boatsEl = document.getElementById( 'hm-del-boats' );
		if ( boatsEl ) {
			boatsEl.textContent = harbour && harbour.boat_count > 0
				? ( T.moveBoats || 'Move the boats to another harbour first.' ) + ' (' + harbour.boat_count + ' ' + ( harbour.boat_count === 1 ? ( T.boat || 'boat' ) : ( T.boats || 'boats' ) ) + ')'
				: '';
		}
		if ( ! closeModal() ) { return; }
		show( 'hm-del-overlay' );
	}

	function closeDeleteModal() { hide( 'hm-del-overlay' ); deleteId = null; }

	function doDelete() {
		if ( ! deleteId ) { return; }
		var btn = document.getElementById( 'hm-del-confirm' );
		btn.disabled = true;
		apiFetch( REST + '/harbours/' + deleteId, 'DELETE' )
			.then( function () {
				closeDeleteModal();
				loadHarbours();
				toast( '\u2713', 'info' );
			} )
			.catch( function ( err ) {
				if ( err.status === 409 ) {
					// Show the server's boat-count message inline in the confirm modal.
					var boatsEl = document.getElementById( 'hm-del-boats' );
					if ( boatsEl ) { boatsEl.textContent = err.message; }
				} else {
					toast( ( T.errDelete || 'Delete failed.' ) + ' ' + err.message, 'error' );
				}
			} )
			.finally( function () {
				btn.disabled = false;
			} );
	}

	// ── Utilities ─────────────────────────────────────────────────

	function apiFetch( url, method, body ) {
		var opts = {
			method: method || 'GET',
			headers: { 'X-WP-Nonce': CFG.nonce || '' },
		};
		if ( body ) {
			opts.headers[ 'Content-Type' ] = 'application/json';
			opts.body = JSON.stringify( body );
		}
		return fetch( url, opts )
			.then( function ( res ) {
				return res.json().catch( function () { return {}; } ).then( function ( json ) {
					if ( ! res.ok ) {
						var err = new Error( json.message || ( 'HTTP ' + res.status ) );
						err.status = res.status;
						throw err;
					}
					return json;
				} );
			} );
	}

	function escHtml( s ) {
		return String( s ?? '' ).replace( /[&<>"']/g,
			function ( c ) {
				return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
			} );
	}
	function escAttr( s ) { return escHtml( s ); }

	function getF( id ) { return document.getElementById( id )?.value || ''; }
	function setF( id, v ) { var el = document.getElementById( id ); if ( el ) { el.value = v; } }
	function show( id ) { document.getElementById( id )?.classList.remove( 'me-hidden' ); }
	function hide( id ) { document.getElementById( id )?.classList.add( 'me-hidden' ); }
	function isHidden( id ) { return document.getElementById( id )?.classList.contains( 'me-hidden' ) ?? true; }

	function toast( msg, type ) {
		var el = document.getElementById( 'hm-toast' );
		if ( ! el ) { return; }
		el.textContent = msg;
		el.className   = 'me-toast ' + ( type || 'info' );
		clearTimeout( toastTimer );
		toastTimer = setTimeout( function () { hide( 'hm-toast' ); }, 3500 );
	}

} )();
