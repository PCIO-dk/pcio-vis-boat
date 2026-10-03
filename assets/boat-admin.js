/* ================================================================
   PCIO VIS Boat — boat-admin.js
   Boat Register admin page (/vis/boats).
   Follows the same shell-build pattern as the other Vis list pages:
   buildShell() renders page header + table#me-table + modals + toast
   into #pcio-me-app so all members.css styles apply automatically.
   ================================================================ */

( function () {
	'use strict';

	const CFG  = window.PCIO_VIS_Boat_ADMIN || {};
	const T    = CFG.i18n  || {};
	const REST = ( CFG.rest || '' ).replace( /\/$/, '' );

	// ── SVG icon strings ──────────────────────────────────────────
	const ICON_EDIT  = '<svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>';
	const ICON_DEL   = '<svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>';
	const ICON_CREW  = '<svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>';
	const ICON_EMPTY = '<svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>';

	// ── State ─────────────────────────────────────────────────────
	let allBoats    = [];
	let allHarbours = [];
	let sortCol     = 'boat_name';
	let sortDir    = 'asc';
	let editId     = null;
	let deleteId   = null;
	let crewBoatId = null;
	let toastTimer = null;

	// ── Column definitions ────────────────────────────────────────
	const COLS = [
		{ key: 'boat_name',    label: T.boatName    || 'Boat name',    sortable: true,  filter: true },
		{ key: 'home_harbour', label: T.homeHarbour || 'Home harbour', sortable: true,  filter: true },
		{ key: 'boat_type',    label: T.boatType    || 'Type',         sortable: true,  filter: true },
		{ key: 'boat_model',   label: T.boatModel   || 'Model',        sortable: false, filter: false },
		{
			key: 'build_year', label: T.buildYear   || 'Build year',   sortable: true,  filter: false,
			text:   ( r ) => r.build_year ? String( r.build_year ) : '',
			render: ( r ) => r.build_year ? escHtml( String( r.build_year ) ) : '<span class="mep-muted">&ndash;</span>',
		},
		{ key: 'sail_id',      label: T.sailId      || 'Sail no.',     sortable: true,  filter: true },
		{
			key: 'mmsi',       label: T.mmsi        || 'MMSI',         sortable: false, filter: false,
			render: ( r ) => r.mmsi ? escHtml( r.mmsi ) : '<span class="mep-muted">&ndash;</span>',
		},
		{
			key: 'crew', label: T.crew || 'Crew', sortable: false, filter: false,
			text:   ( r ) => ( r.crew || [] ).join( ', ' ),
			render: ( r ) => renderCrew( r ),
		},
	];

	// Link to the member directory filtered to this boat's membership number,
	// so every crew name on the row opens the same pre-filtered member table.
	function memberUrl( r ) {
		var base = CFG.membersUrl || '';
		if ( ! base ) { return ''; }
		return base + ( base.indexOf( '?' ) >= 0 ? '&' : '?' ) +
			'member_number=' + encodeURIComponent( String( r.member_number || '' ) );
	}

	function renderCrew( r ) {
		var names = Array.isArray( r.crew ) ? r.crew.filter( Boolean ) : [];
		if ( ! names.length ) {
			return r.member_number
				? '<span class="mep-muted">' + escHtml( '#' + r.member_number ) + '</span>'
				: '<span class="mep-muted">&ndash;</span>';
		}
		var href = CFG.canViewMembers ? memberUrl( r ) : '';
		return names.map( function ( name ) {
			return href
				? '<a class="mep-link" href="' + escAttr( href ) + '">' + escHtml( name ) + '</a>'
				: escHtml( name );
		} ).join( ', ' );
	}

	// ── Bootstrap ─────────────────────────────────────────────────
	console.log( '[pcio-vis-boat] IIFE running. CFG:', CFG, 'REST:', REST );
	document.addEventListener( 'DOMContentLoaded', () => {
		console.log( '[pcio-vis-boat] DOMContentLoaded fired. #pcio-me-app:', document.getElementById( 'pcio-me-app' ) );
		buildShell();
		initHarbourPicker();
		console.log( '[pcio-vis-boat] buildShell() complete. Starting loadBoats().' );
		loadBoats();
		loadHarbours();
	} );

	// ── Shell ─────────────────────────────────────────────────────

	function buildShell() {
		const app = document.getElementById( 'pcio-me-app' );
		if ( ! app ) { return; }

		app.innerHTML =
			// Page header — dark gradient, matches every other Vis list
			'<div class="me-page-header">' +
				'<h1 class="me-page-title">' + escHtml( T.pageTitle || 'Boat Register' ) + '</h1>' +
				'<div class="me-header-right">' +
					'<button class="me-btn me-btn-primary" id="me-boat-new">' + escHtml( T.newBoat || '+ New boat' ) + '</button>' +
				'</div>' +
			'</div>' +

			// Table card
			'<div class="me-table-card">' +
				'<div class="me-toolbar">' +
					'<span class="me-toolbar-info" id="me-boat-count"></span>' +
					'<div class="me-toolbar-actions">' +
						'<div class="me-search-box">' +
							'<input type="search" id="me-boat-search" placeholder="' +
								escAttr( T.search || 'Search\u2026' ) + '" autocomplete="off">' +
						'</div>' +
					'</div>' +
				'</div>' +
				'<div class="me-table-wrap">' +
					'<table id="me-table">' +
						'<thead>' +
							'<tr class="me-head-row" id="me-boat-head"></tr>' +
							'<tr class="me-filter-row" id="me-boat-filters"></tr>' +
						'</thead>' +
						'<tbody id="me-boat-tbody"></tbody>' +
					'</table>' +
				'</div>' +
				'<div class="me-empty-state me-hidden" id="me-boat-empty">' +
					ICON_EMPTY + '<p id="me-boat-empty-msg"></p>' +
				'</div>' +
			'</div>' +

			// Boat create / edit modal
			'<div class="me-overlay me-hidden" id="me-boat-overlay" role="dialog" aria-modal="true" aria-labelledby="me-boat-modal-title">' +
				'<div class="me-modal me-modal-lg">' +
					'<div class="me-modal-header">' +
						'<h2 class="me-modal-title" id="me-boat-modal-title"></h2>' +
						'<button class="me-modal-close" id="me-boat-modal-close" aria-label="' + escAttr( T.cancel || 'Close' ) + '">&times;</button>' +
					'</div>' +
					'<div class="me-modal-body">' +
						'<form id="me-boat-form" novalidate>' +
							'<div class="me-form-row">' +
								fGroup( 'f-boat-name',    T.boatName    || 'Boat name',    'text',   200, true  ) +
								fGroup( 'f-boat-owner',   T.ownerMemberId || 'Owner member #', 'number', '',  true, 'min="1"' ) +
							'</div>' +
							'<div class="me-form-row">' +
								fGroup( 'f-boat-type',    T.boatType    || 'Type',         'text',   100 ) +
								fGroup( 'f-boat-model',   T.boatModel   || 'Model',        'text',   100 ) +
							'</div>' +
							'<div class="me-form-row">' +
							'<div class="me-form-group">' +
								'<label class="me-form-label" for="f-boat-harbour">' + escHtml( T.homeHarbour || 'Home harbour' ) + '</label>' +
								'<div class="pcio-harbour-picker">' +
									'<input class="me-form-input" type="text" id="f-boat-harbour" autocomplete="off" maxlength="200">' +
									'<input type="hidden" id="f-boat-harbour-id" value="0">' +
									'<ul id="f-boat-harbour-sugg" class="pcio-harbour-sugg" role="listbox"></ul>' +
								'</div>' +
							'</div>' +
							fGroup( 'f-boat-year', T.buildYear || 'Build year', 'number', '', false, 'min="1800" max="2100"' ) +
							'</div>' +
							'<div class="me-form-row">' +
								fGroup( 'f-boat-sail',    T.sailId      || 'Sail number',  'text',   50  ) +
								fGroup( 'f-boat-mmsi',    T.mmsi        || 'MMSI',         'text',   20  ) +
							'</div>' +
							'<div class="me-form-row full">' +
								'<div class="me-form-group">' +
									'<label class="me-form-label" for="f-boat-note">' + escHtml( T.note || 'Notes' ) + '</label>' +
									'<textarea class="me-form-textarea" id="f-boat-note" name="note" rows="3"></textarea>' +
								'</div>' +
							'</div>' +
						'</form>' +
					'</div>' +
					'<div class="me-modal-footer">' +
						'<div style="flex:1"><button class="me-btn me-btn-danger me-hidden" id="me-boat-delete">' + escHtml( T.delete || 'Delete' ) + '</button></div>' +
						'<button class="me-btn me-btn-secondary" id="me-boat-cancel">' + escHtml( T.cancel || 'Cancel' ) + '</button>' +
						'<button class="me-btn me-btn-primary" id="me-boat-save">' + escHtml( T.save || 'Save' ) + '</button>' +
					'</div>' +
				'</div>' +
			'</div>' +

			// Delete confirm modal — uses .me-delete-body / .me-delete-footer from members.css
			'<div class="me-overlay me-hidden" id="me-boat-del-overlay" role="dialog" aria-modal="true">' +
				'<div class="me-modal me-modal-sm">' +
					'<div class="me-modal-header">' +
						'<h2 class="me-modal-title">' + escHtml( T.delete || 'Delete' ) + '</h2>' +
						'<button class="me-modal-close" id="me-boat-del-close">&times;</button>' +
					'</div>' +
					'<div class="me-delete-body">' +
						'<div class="me-delete-icon">' + ICON_DEL + '</div>' +
						'<p class="me-delete-title">' + escHtml( T.confirmDelete || 'Delete this boat?' ) + '</p>' +
						'<p class="me-delete-name" id="me-boat-del-name"></p>' +
						'<p class="me-delete-warning">' + escHtml( T.deleteWarning || 'This will also remove all crew links.' ) + '</p>' +
					'</div>' +
					'<div class="me-delete-footer">' +
						'<button class="me-btn me-btn-secondary" id="me-boat-del-cancel">' + escHtml( T.cancel || 'Cancel' ) + '</button>' +
						'<button class="me-btn me-btn-danger" id="me-boat-del-confirm">' + escHtml( T.delete || 'Delete' ) + '</button>' +
					'</div>' +
				'</div>' +
			'</div>' +

			// Crew management modal
			'<div class="me-overlay me-hidden" id="me-crew-overlay" role="dialog" aria-modal="true" aria-labelledby="me-crew-modal-title">' +
				'<div class="me-modal me-modal-lg">' +
					'<div class="me-modal-header">' +
						'<h2 class="me-modal-title" id="me-crew-modal-title"></h2>' +
						'<button class="me-modal-close" id="me-crew-modal-close">&times;</button>' +
					'</div>' +
					'<div class="me-modal-body">' +
						'<div id="me-crew-list"></div>' +
						'<hr class="me-crew-divider">' +
						'<h3 class="me-crew-add-title">' + escHtml( T.addCrew || 'Add crew member' ) + '</h3>' +
						'<div class="me-form-row">' +
							fGroup( 'f-crew-member-id', T.memberNo || 'Member #', 'number', '', true, 'min="1"' ) +
							fGroup( 'f-crew-sub-id', T.subscriptionId || 'Subscription ID', 'number', '', false, 'min="0" value="0"' ) +
						'</div>' +
						'<p class="me-form-hint">' + escHtml( T.subscriptionHint || 'Optional. Links to a pcio-vis-products subscription.' ) + '</p>' +
						'<button class="me-btn me-btn-primary" id="me-crew-add">' + escHtml( T.add || 'Add' ) + '</button>' +
					'</div>' +
					'<div class="me-modal-footer">' +
						'<button class="me-btn me-btn-secondary" id="me-crew-close">' + escHtml( T.close || 'Close' ) + '</button>' +
					'</div>' +
				'</div>' +
			'</div>' +

			// Toast
			'<div class="me-toast me-hidden" id="me-boat-toast" role="status" aria-live="polite"></div>';

		// Static event bindings
		document.getElementById( 'me-boat-new'       ).addEventListener( 'click', () => openBoatModal( null ) );
		document.getElementById( 'me-boat-search'    ).addEventListener( 'input', renderTable );
		document.getElementById( 'me-boat-modal-close' ).addEventListener( 'click', closeBoatModal );
		document.getElementById( 'me-boat-cancel'    ).addEventListener( 'click', closeBoatModal );
		document.getElementById( 'me-boat-save'      ).addEventListener( 'click', saveBoat );
		document.getElementById( 'me-boat-delete'    ).addEventListener( 'click', () => confirmDelete( editId ) );
		document.getElementById( 'me-boat-del-close' ).addEventListener( 'click', closeDeleteModal );
		document.getElementById( 'me-boat-del-cancel').addEventListener( 'click', closeDeleteModal );
		document.getElementById( 'me-boat-del-confirm').addEventListener('click', doDelete );
		document.getElementById( 'me-crew-modal-close').addEventListener( 'click', closeCrewModal );
		document.getElementById( 'me-crew-close'     ).addEventListener( 'click', closeCrewModal );
		document.getElementById( 'me-crew-add'       ).addEventListener( 'click', addCrew );

		// Backdrop click
		[ 'me-boat-overlay', 'me-boat-del-overlay', 'me-crew-overlay' ].forEach( id => {
			document.getElementById( id )?.addEventListener( 'click', e => {
				if ( e.target !== e.currentTarget ) return;
				if ( id === 'me-boat-overlay'     ) closeBoatModal();
				else if ( id === 'me-boat-del-overlay' ) closeDeleteModal();
				else closeCrewModal();
			} );
		} );

		// Escape key
		document.addEventListener( 'keydown', e => {
			if ( e.key !== 'Escape' ) return;
			if ( ! isHidden( 'me-crew-overlay'     ) ) closeCrewModal();
			else if ( ! isHidden( 'me-boat-del-overlay' ) ) closeDeleteModal();
			else if ( ! isHidden( 'me-boat-overlay' ) ) closeBoatModal();
		} );

		buildHeadRow();
	}

	// ── Form-group helper ─────────────────────────────────────────

	function fGroup( id, label, type, maxLen, required, extra ) {
		const req  = required ? ' <span class="req">*</span>' : '';
		const maxA = maxLen   ? ' maxlength="' + maxLen + '"' : '';
		const reqA = required ? ' required' : '';
		const exA  = extra    ? ' ' + extra : '';
		return '<div class="me-form-group">' +
			'<label class="me-form-label" for="' + escAttr( id ) + '">' + escHtml( label ) + req + '</label>' +
			'<input class="me-form-input" type="' + escAttr( type ) + '" id="' + escAttr( id ) +
			'" name="' + escAttr( id ) + '"' + maxA + reqA + exA + '>' +
			'</div>';
	}

	// ── Head row ──────────────────────────────────────────────────

	function buildHeadRow() {
		const head    = document.getElementById( 'me-boat-head' );
		const filters = document.getElementById( 'me-boat-filters' );
		if ( ! head ) { return; }

		COLS.forEach( col => {
			const th = document.createElement( 'th' );
			th.dataset.col = col.key;
			if ( col.sortable ) {
				th.classList.add( 'sortable' );
				th.innerHTML = escHtml( col.label ) + '<span class="sort-icon" aria-hidden="true"></span>';
				th.addEventListener( 'click', () => toggleSort( col.key ) );
			} else {
				th.textContent = col.label;
			}
			head.appendChild( th );

			const td = document.createElement( 'td' );
			if ( col.filter ) {
				const inp = document.createElement( 'input' );
				inp.type = 'text';
				inp.className = 'me-filter-input';
				inp.dataset.col = col.key;
				inp.placeholder = ( T.filterWord || 'Filter' ) + ' ' + String( col.label ).toLowerCase() + '\u2026';
				inp.autocomplete = 'off';
				inp.addEventListener( 'input', renderTable );
				td.appendChild( inp );
			}
			filters.appendChild( td );
		} );

		// Actions column
		head.appendChild( document.createElement( 'th' ) );
		filters.appendChild( document.createElement( 'td' ) );

		updateSortClasses();
	}

	function updateSortClasses() {
		document.querySelectorAll( '#me-boat-head th[data-col]' ).forEach( th => {
			th.classList.remove( 'sort-asc', 'sort-desc' );
			if ( th.dataset.col === sortCol ) {
				th.classList.add( sortDir === 'asc' ? 'sort-asc' : 'sort-desc' );
			}
		} );
	}

	function toggleSort( col ) {
		sortDir = sortCol === col ? ( sortDir === 'asc' ? 'desc' : 'asc' ) : 'asc';
		sortCol = col;
		updateSortClasses();
		renderTable();
	}

	// ── Data ──────────────────────────────────────────────────────

	async function loadBoats() {
		console.log( '[pcio-vis-boat] loadBoats() fetching:', REST + '/boats' );
		try {
			const data = await apiFetch( REST + '/boats' );
			console.log( '[pcio-vis-boat] loadBoats() response:', data );
			allBoats   = Array.isArray( data ) ? data : [];
			renderTable();
		} catch ( err ) {
			console.error( '[pcio-vis-boat] loadBoats() error:', err );
			allBoats = [];
			const msg = document.getElementById( 'me-boat-empty-msg' );
			if ( msg ) { msg.textContent = err.message; }
			document.getElementById( 'me-boat-empty' )?.classList.remove( 'me-hidden' );
		}
	}

	async function loadHarbours() {
		if ( ! CFG.harboursRest ) { return; }
		try {
			const data = await apiFetch( CFG.harboursRest );
			allHarbours = Array.isArray( data ) ? data : [];
		} catch {
			allHarbours = [];
		}
	}

	function initHarbourPicker() {
		const input  = document.getElementById( 'f-boat-harbour' );
		const hidden = document.getElementById( 'f-boat-harbour-id' );
		const sugg   = document.getElementById( 'f-boat-harbour-sugg' );
		if ( ! input || ! hidden || ! sugg ) { return; }

		input.addEventListener( 'input', () => {
			const q = input.value.trim().toLowerCase();
			hidden.value = '0';
			if ( ! q ) { hideSugg(); return; }
			const matches = allHarbours.filter( h => h.name.toLowerCase().includes( q ) ).slice( 0, 12 );
			if ( ! matches.length ) { hideSugg(); return; }
			sugg.innerHTML = '';
			matches.forEach( h => {
				const li = document.createElement( 'li' );
				li.textContent = h.name;
				li.setAttribute( 'role', 'option' );
				li.addEventListener( 'mousedown', e => {
					e.preventDefault();
					input.value  = h.name;
					hidden.value = String( h.id );
					hideSugg();
				} );
				sugg.appendChild( li );
			} );
			sugg.style.display = 'block';
		} );

		input.addEventListener( 'blur',    () => setTimeout( hideSugg, 150 ) );
		input.addEventListener( 'keydown', e => { if ( e.key === 'Escape' ) { hideSugg(); } } );

		function hideSugg() { sugg.style.display = 'none'; sugg.innerHTML = ''; }
	}

	// ── Render ────────────────────────────────────────────────────

	function colText( col, row ) {
		if ( col.text ) { return col.text( row ); }
		return String( row[ col.key ] == null ? '' : row[ col.key ] );
	}

	function renderTable() {
		const tbody    = document.getElementById( 'me-boat-tbody' );
		const empty    = document.getElementById( 'me-boat-empty' );
		const emptyMsg = document.getElementById( 'me-boat-empty-msg' );
		const countEl  = document.getElementById( 'me-boat-count' );
		if ( ! tbody ) { return; }

		const q = ( document.getElementById( 'me-boat-search' )?.value || '' ).toLowerCase().trim();

		const colFilters = {};
		document.querySelectorAll( '.me-filter-input' ).forEach( inp => {
			const v = inp.value.trim().toLowerCase();
			if ( v ) { colFilters[ inp.dataset.col ] = v; }
		} );

		let filtered = allBoats.filter( row => {
			if ( q ) {
				const hay = COLS.map( c => colText( c, row ) ).join( ' ' ).toLowerCase();
				if ( ! hay.includes( q ) ) { return false; }
			}
			for ( const [ key, val ] of Object.entries( colFilters ) ) {
				const c    = COLS.find( cc => cc.key === key );
				const cell = ( c ? colText( c, row ) : String( row[ key ] || '' ) ).toLowerCase();
				if ( ! cell.includes( val ) ) { return false; }
			}
			return true;
		} );

		if ( sortCol ) {
			const sc = COLS.find( c => c.key === sortCol );
			filtered = [ ...filtered ].sort( ( a, b ) => {
				const av  = sc ? colText( sc, a ) : String( a[ sortCol ] || '' );
				const bv  = sc ? colText( sc, b ) : String( b[ sortCol ] || '' );
				const cmp = av.localeCompare( bv, undefined, { numeric: true, sensitivity: 'base' } );
				return sortDir === 'asc' ? cmp : -cmp;
			} );
		}

		if ( countEl ) {
			if ( allBoats.length === 0 ) {
				countEl.textContent = '';
			} else {
				countEl.textContent = filtered.length < allBoats.length
					? ( T.showing || 'Showing' ) + ' ' + filtered.length + ' ' + ( T.of || 'of' ) + ' ' + allBoats.length
					: allBoats.length + ' ' + ( T.boats || 'boats' );
			}
		}

		if ( ! filtered.length ) {
			tbody.innerHTML = '';
			if ( emptyMsg ) {
				emptyMsg.textContent = allBoats.length === 0
					? ( T.noBoats || 'No boats registered yet.' )
					: ( T.noMatch || 'No matches.' );
			}
			empty?.classList.remove( 'me-hidden' );
			return;
		}
		empty?.classList.add( 'me-hidden' );

		tbody.innerHTML = filtered.map( row => {
			const tds = COLS.map( col =>
				'<td>' + ( col.render ? col.render( row ) : escHtml( colText( col, row ) ) ) + '</td>'
			).join( '' );
			return '<tr>' + tds +
				'<td><div class="me-row-actions">' +
					'<button class="me-icon-btn" data-crew="' + row.id + '" title="' + escAttr( T.manageCrew || 'Crew' ) + '">' + ICON_CREW + '</button>' +
					'<button class="me-icon-btn edit" data-edit="' + row.id + '" title="' + escAttr( T.edit || 'Edit' ) + '">' + ICON_EDIT + '</button>' +
					'<button class="me-icon-btn delete" data-del="' + row.id + '" title="' + escAttr( T.delete || 'Delete' ) + '">' + ICON_DEL + '</button>' +
				'</div></td>' +
			'</tr>';
		} ).join( '' );

		tbody.querySelectorAll( '[data-crew]' ).forEach( b => b.addEventListener( 'click', () => openCrewModal( +b.dataset.crew ) ) );
		tbody.querySelectorAll( '[data-edit]' ).forEach( b => b.addEventListener( 'click', () => openBoatModal( +b.dataset.edit ) ) );
		tbody.querySelectorAll( '[data-del]'  ).forEach( b => b.addEventListener( 'click', () => confirmDelete( +b.dataset.del  ) ) );
	}

	// ── Boat modal ────────────────────────────────────────────────

	function openBoatModal( id ) {
		editId = id;
		const boat = id ? allBoats.find( b => b.id === id ) : null;
		document.getElementById( 'me-boat-modal-title' ).textContent =
			id ? ( T.editBoat || 'Edit boat' ) : ( T.createBoat || 'New boat' );
		setF( 'f-boat-name',    boat?.boat_name          ?? '' );
		setF( 'f-boat-owner',   boat?.member_number != null ? String( boat.member_number ) : '' );
		setF( 'f-boat-type',    boat?.boat_type          ?? '' );
		setF( 'f-boat-model',   boat?.boat_model         ?? '' );
		setF( 'f-boat-harbour', boat?.home_harbour ?? '' );
		const hhid = document.getElementById( 'f-boat-harbour-id' );
		if ( hhid ) { hhid.value = String( boat?.harbour_id ?? 0 ); }
		setF( 'f-boat-year', boat?.build_year != null ? String( boat.build_year ) : '' );
		setF( 'f-boat-sail',    boat?.sail_id            ?? '' );
		setF( 'f-boat-mmsi',    boat?.mmsi               ?? '' );
		setF( 'f-boat-note',    boat?.note               ?? '' );
		document.getElementById( 'me-boat-delete' ).classList.toggle( 'me-hidden', ! id );
		show( 'me-boat-overlay' );
		setTimeout( () => document.getElementById( 'f-boat-name' )?.focus(), 40 );
	}

	function closeBoatModal() { hide( 'me-boat-overlay' ); editId = null; }

	async function saveBoat() {
		const name    = getF( 'f-boat-name' ).trim();
		const ownerId = parseInt( getF( 'f-boat-owner' ) || '0', 10 );
		if ( ! name    ) { document.getElementById( 'f-boat-name' )?.focus();  return; }
		if ( ! editId && ! ownerId ) { document.getElementById( 'f-boat-owner' )?.focus(); return; }

		const yr      = getF( 'f-boat-year' );
		const payload = {
			boat_name:    name,
			// The harbour is stored by id only; the text box is just the picker.
			harbour_id:   parseInt( document.getElementById( 'f-boat-harbour-id' )?.value || '0', 10 ),
			boat_type:    getF( 'f-boat-type'    ).trim(),
			boat_model:   getF( 'f-boat-model'   ).trim(),
			build_year:   yr ? parseInt( yr, 10 ) : null,
			sail_id:      getF( 'f-boat-sail'    ).trim(),
			mmsi:         getF( 'f-boat-mmsi'    ).trim(),
			note:         getF( 'f-boat-note'    ).trim(),
		};
		if ( ! editId ) { payload.member_number = ownerId; }

		const btn = document.getElementById( 'me-boat-save' );
		btn.disabled = true;
		try {
			editId
				? await apiFetch( REST + '/boats/' + editId, 'PUT',  payload )
				: await apiFetch( REST + '/boats',           'POST', payload );
			closeBoatModal();
			await loadBoats();
			toast( '\u2713', 'success' );
		} catch ( err ) {
			toast( ( T.saveFailed || 'Save failed.' ) + ' ' + err.message, 'error' );
		} finally {
			btn.disabled = false;
		}
	}

	// ── Delete confirm modal ──────────────────────────────────────

	function confirmDelete( id ) {
		deleteId = id;
		const boat  = allBoats.find( b => b.id === id );
		const nameEl = document.getElementById( 'me-boat-del-name' );
		if ( nameEl ) { nameEl.textContent = boat?.boat_name || ''; }
		closeBoatModal();
		show( 'me-boat-del-overlay' );
	}

	function closeDeleteModal() { hide( 'me-boat-del-overlay' ); deleteId = null; }

	async function doDelete() {
		if ( ! deleteId ) { return; }
		const btn = document.getElementById( 'me-boat-del-confirm' );
		btn.disabled = true;
		try {
			await apiFetch( REST + '/boats/' + deleteId, 'DELETE' );
			closeDeleteModal();
			await loadBoats();
			toast( '\u2713', 'info' );
		} catch ( err ) {
			toast( ( T.saveFailed || 'Delete failed.' ) + ' ' + err.message, 'error' );
		} finally {
			btn.disabled = false;
		}
	}

	// ── Crew modal ────────────────────────────────────────────────

	async function openCrewModal( boatId ) {
		crewBoatId = boatId;
		const boat = allBoats.find( b => b.id === boatId );
		document.getElementById( 'me-crew-modal-title' ).textContent =
			( T.manageCrew || 'Crew' ) + ( boat ? ' \u2014 ' + boat.boat_name : '' );
		setF( 'f-crew-member-id', '' );
		setF( 'f-crew-sub-id',    '0' );
		show( 'me-crew-overlay' );
		await loadCrew();
		document.getElementById( 'f-crew-member-id' )?.focus();
	}

	function closeCrewModal() { hide( 'me-crew-overlay' ); crewBoatId = null; }

	async function loadCrew() {
		const wrap = document.getElementById( 'me-crew-list' );
		if ( ! wrap || ! crewBoatId ) { return; }

		wrap.innerHTML = '<div class="me-loading"><div class="me-spinner"></div></div>';
		try {
			const crew = await apiFetch( REST + '/boats/' + crewBoatId + '/crew' );
			if ( ! crew.length ) {
				wrap.innerHTML = '<p class="mep-muted" style="margin:0 0 8px">' +
					escHtml( T.noCrew || 'No crew members linked.' ) + '</p>';
				return;
			}

			wrap.innerHTML =
				'<table class="me-crew-table">' +
					'<thead><tr class="me-head-row">' +
						'<th>' + escHtml( T.memberNo        || 'Member #'        ) + '</th>' +
						'<th>' + escHtml( T.member          || 'Member'          ) + '</th>' +
						'<th>' + escHtml( T.subscriptionId  || 'Sub ID'          ) + '</th>' +
						'<th>' + escHtml( T.active          || 'Active'          ) + '</th>' +
						'<th>' + escHtml( T.expires         || 'Expires'         ) + '</th>' +
						'<th></th>' +
					'</tr></thead>' +
					'<tbody>' +
					crew.map( c => {
						let badge = '<span class="mep-muted">&ndash;</span>';
						if ( c.subscription_active === true  ) { badge = '<span class="me-status-badge me-status-active">' + escHtml( T.yes || 'Yes' ) + '</span>'; }
						if ( c.subscription_active === false ) { badge = '<span class="me-status-badge me-status-inactive">' + escHtml( T.no || 'No' ) + '</span>'; }
						return '<tr>' +
							'<td>' + escHtml( c.member_number || String( c.member_id ) ) + '</td>' +
							'<td>' + escHtml( c.member_name || '' ) + '</td>' +
							'<td>' + ( c.subscription_id ? escHtml( String( c.subscription_id ) ) : '<span class="mep-muted">&ndash;</span>' ) + '</td>' +
							'<td>' + badge + '</td>' +
							'<td>' + ( c.subscription_expires ? escHtml( c.subscription_expires ) : '<span class="mep-muted">&ndash;</span>' ) + '</td>' +
							'<td class="me-row-actions">' +
								'<button class="me-icon-btn delete" data-remove="' + c.member_id + '" title="' + escAttr( T.remove || 'Remove' ) + '">' + ICON_DEL + '</button>' +
							'</td>' +
						'</tr>';
					} ).join( '' ) +
					'</tbody>' +
				'</table>';

			wrap.querySelectorAll( '[data-remove]' ).forEach( b =>
				b.addEventListener( 'click', () => removeCrew( +b.dataset.remove ) )
			);
		} catch ( err ) {
			wrap.innerHTML = '<p style="color:#ef4444">' + escHtml( err.message ) + '</p>';
		}
	}

	async function addCrew() {
		const memberId = parseInt( getF( 'f-crew-member-id' ) || '0', 10 );
		const subId    = parseInt( getF( 'f-crew-sub-id'    ) || '0', 10 );
		if ( ! memberId || ! crewBoatId ) { document.getElementById( 'f-crew-member-id' )?.focus(); return; }

		const btn = document.getElementById( 'me-crew-add' );
		btn.disabled = true;
		try {
			await apiFetch( REST + '/boats/' + crewBoatId + '/crew', 'POST', { member_id: memberId, subscription_id: subId } );
			setF( 'f-crew-member-id', '' );
			setF( 'f-crew-sub-id',    '0' );
			await loadCrew();
		} catch ( err ) {
			toast( ( T.saveFailed || 'Save failed.' ) + ' ' + err.message, 'error' );
		} finally {
			btn.disabled = false;
		}
	}

	async function removeCrew( memberId ) {
		if ( ! crewBoatId ) { return; }
		try {
			await apiFetch( REST + '/boats/' + crewBoatId + '/crew/' + memberId, 'DELETE' );
			await loadCrew();
		} catch ( err ) {
			toast( err.message, 'error' );
		}
	}

	// ── Utilities ─────────────────────────────────────────────────

	async function apiFetch( url, method = 'GET', body = null ) {
		const opts = { method, headers: { 'X-WP-Nonce': CFG.nonce || '' } };
		if ( body ) { opts.headers[ 'Content-Type' ] = 'application/json'; opts.body = JSON.stringify( body ); }
		const res  = await fetch( url, opts );
		const json = await res.json().catch( () => ( {} ) );
		if ( ! res.ok ) { throw new Error( json.message || 'HTTP ' + res.status ); }
		return json;
	}

	function escHtml( s ) {
		return String( s ?? '' ).replace( /[&<>"']/g,
			c => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' } )[ c ] );
	}
	function escAttr( s ) { return escHtml( s ); }

	function getF( id ) { return document.getElementById( id )?.value || ''; }
	function setF( id, v ) { const el = document.getElementById( id ); if ( el ) { el.value = v; } }
	function show( id ) { document.getElementById( id )?.classList.remove( 'me-hidden' ); }
	function hide( id ) { document.getElementById( id )?.classList.add( 'me-hidden' ); }
	function isHidden( id ) { return document.getElementById( id )?.classList.contains( 'me-hidden' ) ?? true; }

	function toast( msg, type ) {
		const el = document.getElementById( 'me-boat-toast' );
		if ( ! el ) { return; }
		el.textContent = msg;
		el.className   = 'me-toast ' + ( type || 'info' );
		clearTimeout( toastTimer );
		toastTimer = setTimeout( () => hide( 'me-boat-toast' ), 3500 );
	}

} )();

