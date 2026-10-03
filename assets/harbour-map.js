/* globals L, PCIO_VIS_Boat_MAP, PCIO_VIS_Boat_Harbours */
( function () {
	'use strict';

	// ── Shortcode map ─────────────────────────────────────────────
	document.querySelectorAll( '.pcio-boat-harbours-map' ).forEach( function ( el ) {
		initHarbourMap( el );
	} );

	// ── Sign-up picker ────────────────────────────────────────────
	var pickerInput = document.getElementById( 'pcio-bs-home-harbour' );
	if ( pickerInput ) {
		initHarbourPicker( pickerInput );
	}

	// ─────────────────────────────────────────────────────────────

	function initHarbourMap( el ) {
		var CFG  = window.PCIO_VIS_Boat_MAP || {};
		var i18n = CFG.i18n || {};

		var map = L.map( el ).setView( [ 56.0, 10.5 ], 6 );

		L.tileLayer( 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
			attribution: '\xa9 <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
			maxZoom: 18,
		} ).addTo( map );

		L.tileLayer( 'https://tiles.openseamap.org/seamark/{z}/{x}/{y}.png', {
			attribution: 'Map data: \xa9 <a href="http://www.openseamap.org">OpenSeaMap</a> contributors',
		} ).addTo( map );

		fetch( CFG.rest + '/harbour-members', {
			headers: { 'X-WP-Nonce': CFG.restNonce || '' },
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( harbours ) {
				if ( ! Array.isArray( harbours ) ) {
					return; // Not a member / no access — leave the map empty.
				}
				harbours.forEach( function ( h ) {
					if ( h.count > 0 ) {
						var icon = L.divIcon( {
							html: '<div class="pcio-hm-badge">' + h.count + '</div>',
							className: '',
							iconSize: [ 36, 36 ],
							iconAnchor: [ 18, 18 ],
						} );
						L.marker( [ h.lat, h.lon ], { icon: icon } )
							.bindPopup( buildMemberPopup( h, i18n ), { maxWidth: 280, maxHeight: 300 } )
							.addTo( map );
					} else {
						var emptyIcon = L.divIcon( {
							html: '<div class="pcio-hm-anchor">\u2693</div>',
							className: '',
							iconSize: [ 20, 20 ],
							iconAnchor: [ 10, 10 ],
						} );
						L.marker( [ h.lat, h.lon ], { icon: emptyIcon } )
							.bindPopup( '<strong>' + escHtml( h.name ) + '</strong><br><em>' + escHtml( i18n.noBoats || '' ) + '</em>' )
							.addTo( map );
					}
				} );
			} );
	}

	function buildMemberPopup( h, i18n ) {
		var label = h.count === 1 ? ( i18n.boat || 'boat' ) : ( i18n.boats || 'boats' );
		var html  = '<strong>' + escHtml( h.name ) + '</strong>'
			+ '<div class="pcio-hm-count">' + h.count + ' ' + escHtml( label ) + '</div>'
			+ '<ul class="pcio-hm-list">';
		h.members.forEach( function ( m ) {
			html += '<li>' + escHtml( m.name ) + ' <span class="pcio-hm-num">#' + escHtml( m.member_number ) + '</span></li>';
		} );
		html += '</ul>';
		return html;
	}

	// ─────────────────────────────────────────────────────────────

	function initHarbourPicker( input ) {
		var CFG        = window.PCIO_VIS_Boat_Harbours || {};
		var i18n       = CFG.i18n || {};
		var hiddenId   = document.getElementById( 'pcio-bs-harbour-id' );
		var newName    = document.getElementById( 'pcio-bs-new-harbour-name' );
		var newLat     = document.getElementById( 'pcio-bs-new-harbour-lat' );
		var newLon     = document.getElementById( 'pcio-bs-new-harbour-lon' );
		var suggestions= document.getElementById( 'pcio-bs-harbour-suggestions' );
		var addBtn     = document.getElementById( 'pcio-bs-add-harbour-btn' );
		var harbours   = [];
		var currentIndex = -1;
		var visibleHarbours = [];

		// Pre-load harbour list.
		if ( CFG.harboursRest ) {
			fetch( CFG.harboursRest )
				.then( function ( r ) { return r.json(); } )
				.then( function ( data ) { harbours = data; } );
		}

		// Filter on input. Only the id is stored, so any manual edit drops the
		// current selection instead of saving a name that belongs to another harbour.
		input.addEventListener( 'input', function () {
			if ( hiddenId ) { hiddenId.value = '0'; }
			clearNewHarbour();
			currentIndex = -1;
			renderSuggestions( input.value.trim() );
		} );

		input.addEventListener( 'keydown', function ( e ) {
			var items = suggestions.querySelectorAll( 'li' );
			if ( ! items.length ) {
				return;
			}

			if ( e.key === 'Escape' ) {
				hideSuggestions();
			} else if ( e.key === 'ArrowDown' ) {
				e.preventDefault();
				currentIndex = currentIndex < 0 ? 0 : Math.min( currentIndex + 1, items.length - 1 );
				highlightItem( items, currentIndex );
			} else if ( e.key === 'ArrowUp' ) {
				e.preventDefault();
				currentIndex = currentIndex <= 0 ? -1 : currentIndex - 1;
				if ( currentIndex < 0 ) {
					hideSuggestions();
				} else {
					highlightItem( items, currentIndex );
				}
			} else if ( e.key === 'Enter' ) {
				if ( currentIndex >= 0 ) {
					e.preventDefault();
					selectHarbour( visibleHarbours[ currentIndex ] );
				}
			}
		} );

		input.addEventListener( 'blur', function () {
			setTimeout( hideSuggestions, 200 );
		} );

		function renderSuggestions( q ) {
			suggestions.innerHTML = '';
			visibleHarbours = [];
			if ( ! q ) {
				hideSuggestions();
				return;
			}
			var ql = q.toLowerCase();
			var matches = harbours.filter( function ( h ) {
				return h.name.toLowerCase().indexOf( ql ) !== -1;
			} ).slice( 0, 12 );

			if ( ! matches.length ) {
				hideSuggestions();
				return;
			}
			matches.forEach( function ( h, i ) {
				var li = document.createElement( 'li' );
				li.setAttribute( 'role', 'option' );
				li.id = 'pcio-bs-harbour-sugg-' + i;
				li.textContent = h.name;
				li.addEventListener( 'mousedown', function ( e ) {
					e.preventDefault(); // Prevent blur before click registers.
					selectHarbour( h );
				} );
				suggestions.appendChild( li );
				visibleHarbours.push( h );
			} );
			currentIndex = -1;
			suggestions.style.display = 'block';
			input.setAttribute( 'aria-expanded', 'true' );
		}

		function highlightItem( items, index ) {
			items.forEach( function ( item, i ) {
				if ( i === index ) {
					item.classList.add( 'is-highlighted' );
					item.setAttribute( 'aria-selected', 'true' );
					input.setAttribute( 'aria-activedescendant', item.id );
					item.scrollIntoView( { block: 'nearest' } );
				} else {
					item.classList.remove( 'is-highlighted' );
					item.setAttribute( 'aria-selected', 'false' );
				}
			} );
		}

		function selectHarbour( h ) {
			input.value    = h.name;
			hiddenId.value = h.id;
			clearNewHarbour();
			hideSuggestions();
		}

		// A harbour the visitor placed on the map is not persisted now; its
		// details ride along with the sign-up and are created server-side once
		// membership is confirmed.
		function saveNewHarbour( name, lat, lon ) {
			input.value    = name;
			hiddenId.value = 0;
			if ( newName ) { newName.value = name; }
			if ( newLat )  { newLat.value  = lat; }
			if ( newLon )  { newLon.value  = lon; }
			hideSuggestions();
		}

		function clearNewHarbour() {
			if ( newName ) { newName.value = ''; }
			if ( newLat )  { newLat.value  = ''; }
			if ( newLon )  { newLon.value  = ''; }
		}

		function hideSuggestions() {
			suggestions.style.display = 'none';
			suggestions.innerHTML     = '';
			input.setAttribute( 'aria-expanded', 'false' );
			input.removeAttribute( 'aria-activedescendant' );
			currentIndex = -1;
			visibleHarbours = [];
		}

		// "Add on map" modal.
		if ( addBtn ) {
			addBtn.addEventListener( 'click', function () {
				openMapModal( i18n, CFG, harbours, saveNewHarbour );
			} );
		}
	}

	function openMapModal( i18n, CFG, harbours, saveNewHarbour ) {
		var overlay = document.createElement( 'div' );
		overlay.className = 'pcio-hm-overlay';

		var modal = document.createElement( 'div' );
		modal.className = 'pcio-hm-modal';
		modal.innerHTML =
			'<div class="pcio-hm-modal-header">'
			+ '<h3>' + escHtml( i18n.addHarbour || 'Add harbour' ) + '</h3>'
			+ '<button type="button" class="pcio-hm-modal-close" aria-label="' + escHtml( i18n.close || 'Close' ) + '">\xd7</button>'
			+ '</div>'
			+ '<p class="pcio-hm-modal-hint">' + escHtml( i18n.clickMapHint || 'Click the map to place the harbour.' ) + '</p>'
			+ '<div id="pcio-hm-modal-map" class="pcio-hm-modal-map"></div>'
			+ '<div id="pcio-hm-name-form" class="pcio-hm-name-form" style="display:none">'
			+ '<input type="text" id="pcio-hm-name-input" maxlength="128"'
			+ ' placeholder="' + escHtml( i18n.harbourName || 'Harbour name' ) + '">'
			+ '<button type="button" id="pcio-hm-save-btn">'
			+ escHtml( i18n.saveHarbour || 'Add harbour' ) + '</button>'
			+ '</div>';

		overlay.appendChild( modal );
		document.body.appendChild( overlay );

		var modalMap   = L.map( 'pcio-hm-modal-map' ).setView( [ 56.0, 10.5 ], 6 );
		var marker     = null;
		var chosenLat  = null;
		var chosenLon  = null;

		L.tileLayer( 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
			attribution: '\xa9 OpenStreetMap contributors',
			maxZoom: 18,
		} ).addTo( modalMap );

		L.tileLayer( 'https://tiles.openseamap.org/seamark/{z}/{x}/{y}.png' ).addTo( modalMap );

		// Delay resize so the modal is fully visible before Leaflet measures it.
		setTimeout( function () { modalMap.invalidateSize(); }, 120 );

		modalMap.on( 'click', function ( e ) {
			chosenLat = e.latlng.lat;
			chosenLon = e.latlng.lng;
			if ( marker ) {
				marker.setLatLng( e.latlng );
			} else {
				marker = L.marker( e.latlng, { draggable: true } ).addTo( modalMap );
				marker.on( 'dragend', function () {
					var p  = marker.getLatLng();
					chosenLat = p.lat;
					chosenLon = p.lng;
				} );
			}
			document.getElementById( 'pcio-hm-name-form' ).style.display = 'flex';
			document.getElementById( 'pcio-hm-name-input' ).focus();
		} );

		modal.querySelector( '.pcio-hm-modal-close' ).addEventListener( 'click', closeModal );
		overlay.addEventListener( 'click', function ( e ) {
			if ( e.target === overlay ) { closeModal(); }
		} );

		document.getElementById( 'pcio-hm-save-btn' ).addEventListener( 'click', function () {
			var name = document.getElementById( 'pcio-hm-name-input' ).value.trim();
			if ( ! name || chosenLat === null ) { return; }

			// Do not persist yet — hand the harbour to the sign-up form. It is
			// created server-side after membership is confirmed.
			saveNewHarbour( name, chosenLat, chosenLon );
			closeModal();
		} );

		function closeModal() {
			modalMap.remove();
			overlay.remove();
		}
	}

	function escHtml( s ) {
		return String( s )
			.replace( /&/g,  '&amp;' )
			.replace( /</g,  '&lt;' )
			.replace( />/g,  '&gt;' )
			.replace( /"/g,  '&quot;' );
	}
}() );
