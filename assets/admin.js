/**
 * Arttime Short Links — dashboard.
 *
 * Paste a link, press "შექმნა" (or Enter) → POST /links → the short link is
 * copied and appears on top of the table. Talks to /wp-json/atsl/v1 (see
 * includes/class-atsl-rest.php).
 */
( () => {
	'use strict';

	const cfg = window.ATSL;
	if ( ! cfg ) {
		return;
	}
	const t = cfg.i18n;
	const $ = ( id ) => document.getElementById( id );

	const urlInput   = $( 'atsl-url' );
	const createBtn  = $( 'atsl-create' );
	const slugInput  = $( 'atsl-slug' );
	const slugStatus = $( 'atsl-slug-status' );
	const result     = $( 'atsl-result' );
	const rows       = $( 'atsl-rows' );
	const search     = $( 'atsl-search' );
	const count      = $( 'atsl-count' );
	const pager      = $( 'atsl-pager' );
	const toast      = $( 'atsl-toast' );

	const state = { search: '', page: 1, pages: 1, total: 0, busy: false };

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	function endpoint( route, query ) {
		const url = cfg.api + route;
		const qs  = query ? new URLSearchParams( query ).toString() : '';
		return qs ? url + ( url.includes( '?' ) ? '&' : '?' ) + qs : url;
	}

	/** PATCH/DELETE travel as POST + override header — some firewalls drop the verbs. */
	async function api( method, route, body, query ) {
		const headers = { 'X-WP-Nonce': cfg.nonce };
		if ( method !== 'GET' && method !== 'POST' ) {
			headers[ 'X-HTTP-Method-Override' ] = method;
		}
		if ( body ) {
			headers[ 'Content-Type' ] = 'application/json';
		}
		const res = await fetch( endpoint( route, query ), {
			method: method === 'GET' ? 'GET' : 'POST',
			headers,
			credentials: 'same-origin',
			body: body ? JSON.stringify( body ) : undefined,
		} );
		const data = await res.json().catch( () => ( {} ) );
		if ( ! res.ok ) {
			throw new Error( ( data && data.message ) || t.error );
		}
		return data;
	}

	function el( tag, props, kids ) {
		const node = document.createElement( tag );
		Object.entries( props || {} ).forEach( ( [ key, value ] ) => {
			if ( key === 'text' ) {
				node.textContent = value;
			} else if ( key === 'on' ) {
				Object.entries( value ).forEach( ( [ ev, fn ] ) => node.addEventListener( ev, fn ) );
			} else if ( key === 'class' ) {
				node.className = value;
			} else {
				node.setAttribute( key, value );
			}
		} );
		( kids || [] ).forEach( ( kid ) => {
			if ( kid ) {
				node.append( kid );
			}
		} );
		return node;
	}

	const icon = ( name ) => el( 'span', { class: 'dashicons dashicons-' + name, 'aria-hidden': 'true' } );

	const iconButton = ( name, label, onClick ) =>
		el( 'button', { type: 'button', class: 'atsl-icon', title: label, 'aria-label': label, on: { click: onClick } }, [ icon( name ) ] );

	/** "%s" / "%d" / "%1$d %2$d" placeholders, as in the PHP strings. */
	function fill( template, ...values ) {
		let out = template;
		values.forEach( ( v, i ) => {
			out = out.replace( '%' + ( i + 1 ) + '$d', v );
		} );
		return out.replace( '%d', values[ 0 ] ).replace( '%s', values[ 0 ] );
	}

	function fallbackCopy( text ) {
		const area = el( 'textarea', { readonly: '', class: 'atsl-offscreen' } );
		area.value = text;
		document.body.append( area );
		area.select();
		let ok = false;
		try {
			ok = document.execCommand( 'copy' );
		} catch ( e ) {}
		area.remove();
		return ok;
	}

	async function copy( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			try {
				await navigator.clipboard.writeText( text );
				return true;
			} catch ( e ) {}
		}
		return fallbackCopy( text );
	}

	let toastTimer;
	function notify( message, isError ) {
		toast.textContent = message;
		toast.className = 'atsl-toast' + ( isError ? ' is-error' : '' );
		toast.hidden = false;
		clearTimeout( toastTimer );
		toastTimer = setTimeout( () => {
			toast.hidden = true;
		}, isError ? 5000 : 2200 );
	}

	async function copyAndNotify( text ) {
		const ok = await copy( text );
		notify( ok ? t.copied : t.error, ! ok );
	}

	// ---------------------------------------------------------------------
	// Create
	// ---------------------------------------------------------------------

	/** Success notice fades out on its own; errors stay until the next action. */
	const RESULT_TTL = 6000;
	let resultTimer;

	function showStatus( kind, message ) {
		clearTimeout( resultTimer );
		result.hidden = false;
		result.className = 'atsl-result is-' + kind;
		result.replaceChildren(
			kind === 'busy' ? el( 'span', { class: 'spinner is-active' } ) : icon( 'warning' ),
			el( 'span', { class: 'atsl-result-msg', text: message } )
		);
	}

	function showCreated( link, message ) {
		clearTimeout( resultTimer );
		result.hidden = false;
		result.className = 'atsl-result is-ok';
		result.replaceChildren(
			icon( 'yes-alt' ),
			el( 'span', { class: 'atsl-result-msg', text: message } ),
			el( 'a', { href: link.short_url, target: '_blank', rel: 'noopener', class: 'atsl-result-link', text: link.short_display } ),
			el( 'button', { type: 'button', class: 'button', on: { click: () => copyAndNotify( link.short_url ) } }, [ t.copy ] )
		);
		resultTimer = setTimeout( () => {
			result.classList.add( 'is-fading' );
			resultTimer = setTimeout( () => {
				result.hidden = true;
			}, 400 );
		}, RESULT_TTL );
	}

	async function create() {
		const url = urlInput.value.trim();
		if ( state.busy ) {
			return;
		}
		if ( ! url ) {
			urlInput.focus();
			return;
		}
		state.busy = true;
		createBtn.disabled = true;
		urlInput.classList.add( 'is-busy' );
		showStatus( 'busy', t.working );

		try {
			const link = await api( 'POST', '/links', { url, slug: slugInput.value.trim() } );
			urlInput.value = '';
			slugInput.value = '';
			setSlugStatus( '', '' );
			addToTable( link );
			const copied = await copy( link.short_url );
			showCreated( link, copied ? ( link.existing ? t.existing : t.created ) : t.createdNoCp );
		} catch ( err ) {
			showStatus( 'error', err.message );
		} finally {
			state.busy = false;
			createBtn.disabled = false;
			urlInput.classList.remove( 'is-busy' );
			urlInput.focus();
		}
	}

	createBtn.addEventListener( 'click', create );

	urlInput.addEventListener( 'keydown', ( e ) => {
		if ( e.key === 'Enter' ) {
			e.preventDefault();
			create();
		}
	} );

	// ---------------------------------------------------------------------
	// Custom ending — live availability
	// ---------------------------------------------------------------------

	function setSlugStatus( kind, message ) {
		slugStatus.className = 'atsl-slug-status' + ( kind ? ' is-' + kind : '' );
		slugStatus.textContent = message;
	}

	let slugTimer;
	let slugSeq = 0;
	slugInput.addEventListener( 'input', () => {
		const caret = slugInput.selectionStart;
		const tidy  = slugInput.value.toLowerCase().replace( /[\s_]+/g, '-' );
		if ( tidy !== slugInput.value ) {
			slugInput.value = tidy;
			slugInput.setSelectionRange( caret, caret );
		}

		clearTimeout( slugTimer );
		const wanted = slugInput.value.trim();
		if ( ! wanted ) {
			slugSeq++;
			setSlugStatus( '', '' );
			return;
		}
		setSlugStatus( 'busy', t.checking );
		slugTimer = setTimeout( async () => {
			const seq = ++slugSeq;
			try {
				const res = await api( 'GET', '/check', null, { slug: wanted } );
				if ( seq !== slugSeq ) {
					return;
				}
				if ( res.available ) {
					setSlugStatus( 'ok', '✓ ' + cfg.base + res.slug + ' — ' + t.free );
				} else {
					setSlugStatus( 'error', '✗ ' + res.reason );
				}
			} catch ( err ) {
				if ( seq === slugSeq ) {
					setSlugStatus( 'error', err.message );
				}
			}
		}, 300 );
	} );

	slugInput.addEventListener( 'keydown', ( e ) => {
		if ( e.key === 'Enter' ) {
			e.preventDefault();
			create();
		}
	} );

	// ---------------------------------------------------------------------
	// Table
	// ---------------------------------------------------------------------

	const destLink = ( link ) =>
		el( 'a', { href: link.url, target: '_blank', rel: 'noopener noreferrer', class: 'atsl-dest', title: link.url_display, text: link.url_display } );

	function renderRow( link ) {
		const tr = el( 'tr', { 'data-id': String( link.id ) } );
		tr.append(
			el( 'td', { class: 'col-short' }, [
				el( 'a', { href: link.short_url, target: '_blank', rel: 'noopener', class: 'atsl-short', text: link.short_display } ),
				link.is_custom ? el( 'span', { class: 'atsl-badge', text: t.custom } ) : null,
				link.warning ? el( 'span', { class: 'atsl-warn', title: link.warning, 'aria-label': link.warning }, [ icon( 'warning' ) ] ) : null,
			] ),
			el( 'td', { class: 'col-dest' }, [ destLink( link ) ] ),
			el( 'td', { class: 'col-num', 'data-label': t.clicks, text: String( link.clicks ) } ),
			el( 'td', { class: 'col-date', text: link.last_click || '—' } ),
			el( 'td', { class: 'col-date', text: link.created } ),
			el( 'td', { class: 'col-actions' }, [
				iconButton( 'admin-page', t.copy, () => copyAndNotify( link.short_url ) ),
				iconButton( 'edit', t.edit, () => editRow( tr, link ) ),
				iconButton( 'trash', t.delete, () => removeRow( tr, link ) ),
			] )
		);
		return tr;
	}

	function editRow( tr, link ) {
		const cell = tr.querySelector( '.col-dest' );
		if ( cell.querySelector( 'input' ) ) {
			return;
		}
		const input  = el( 'input', { type: 'text', class: 'atsl-edit', spellcheck: 'false' } );
		input.value  = link.url_display;
		const cancel = () => cell.replaceChildren( destLink( link ) );
		const save   = async () => {
			const value = input.value.trim();
			if ( ! value || value === link.url_display ) {
				cancel();
				return;
			}
			input.disabled = true;
			try {
				const updated = await api( 'PATCH', '/links/' + link.id, { url: value } );
				tr.replaceWith( renderRow( updated ) );
				notify( t.saved );
			} catch ( err ) {
				input.disabled = false;
				input.focus();
				notify( err.message, true );
			}
		};
		input.addEventListener( 'keydown', ( e ) => {
			if ( e.key === 'Enter' ) {
				save();
			} else if ( e.key === 'Escape' ) {
				cancel();
			}
		} );
		cell.replaceChildren(
			input,
			el( 'span', { class: 'atsl-edit-actions' }, [
				el( 'button', { type: 'button', class: 'button button-primary button-small', on: { click: save } }, [ t.save ] ),
				el( 'button', { type: 'button', class: 'button button-small', on: { click: cancel } }, [ t.cancel ] ),
			] )
		);
		input.focus();
		input.select();
	}

	async function removeRow( tr, link ) {
		if ( ! window.confirm( fill( t.confirmDel, link.short_display ) ) ) {
			return;
		}
		try {
			await api( 'DELETE', '/links/' + link.id );
			tr.remove();
			state.total = Math.max( 0, state.total - 1 );
			renderCount();
			if ( ! rows.children.length ) {
				load( Math.max( 1, state.page - 1 ) );
			}
			notify( t.deleted );
		} catch ( err ) {
			notify( err.message, true );
		}
	}

	function addToTable( link ) {
		if ( state.page !== 1 || state.search ) {
			return;
		}
		const old = rows.querySelector( 'tr[data-id="' + link.id + '"]' );
		if ( old ) {
			old.remove();
		} else {
			state.total++;
		}
		const empty = rows.querySelector( '.atsl-empty' );
		if ( empty ) {
			empty.remove();
		}
		rows.prepend( renderRow( link ) );
		renderCount();
	}

	const emptyRow = ( message ) =>
		el( 'tr', { class: 'atsl-empty' }, [ el( 'td', { colspan: '6', text: message } ) ] );

	function renderCount() {
		count.textContent = fill( t.total, state.total );
	}

	function renderPager() {
		pager.replaceChildren();
		if ( state.pages <= 1 ) {
			return;
		}
		const prev = el( 'button', { type: 'button', class: 'button', on: { click: () => load( state.page - 1 ) } }, [ t.prev ] );
		const next = el( 'button', { type: 'button', class: 'button', on: { click: () => load( state.page + 1 ) } }, [ t.next ] );
		prev.disabled = state.page <= 1;
		next.disabled = state.page >= state.pages;
		pager.append( prev, el( 'span', { text: fill( t.pageOf, state.page, state.pages ) } ), next );
	}

	function render( data ) {
		state.page  = data.page;
		state.pages = data.pages;
		state.total = data.total;
		if ( data.items.length ) {
			rows.replaceChildren( ...data.items.map( renderRow ) );
		} else {
			rows.replaceChildren( emptyRow( state.search ? t.noResults : t.empty ) );
		}
		renderCount();
		renderPager();
	}

	async function load( page ) {
		try {
			render( await api( 'GET', '/links', null, { search: state.search, page } ) );
		} catch ( err ) {
			notify( err.message, true );
		}
	}

	let searchTimer;
	search.addEventListener( 'input', () => {
		clearTimeout( searchTimer );
		searchTimer = setTimeout( () => {
			state.search = search.value.trim();
			load( 1 );
		}, 300 );
	} );

	render( cfg.initial );
} )();
