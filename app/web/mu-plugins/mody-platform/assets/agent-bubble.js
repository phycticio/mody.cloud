/**
 * The Mody agent as a chat bubble in the editors (ticket M3-05).
 *
 * Plain script, no build step. Builds a round button at the bottom right and
 * a chat panel holding the same elements as the chat page (#mody-agent-log,
 * #mody-agent-form, #mody-agent-input, #mody-agent-reset). agent-chat.js,
 * loaded right after, runs the conversation in them. All text is inserted
 * with textContent, never as HTML.
 */
( function () {
	'use strict';

	const config = window.modyAgent;
	if ( ! config || document.getElementById( 'mody-agent-bubble' ) ) {
		return;
	}
	const strings = config.strings;

	function element( tag, className, text ) {
		const node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text ) {
			node.textContent = text;
		}
		return node;
	}

	// A speech-bubble icon, drawn with DOM calls.
	function icon() {
		const ns = 'http://www.w3.org/2000/svg';
		const svg = document.createElementNS( ns, 'svg' );
		svg.setAttribute( 'viewBox', '0 0 24 24' );
		svg.setAttribute( 'width', '26' );
		svg.setAttribute( 'height', '26' );
		svg.setAttribute( 'aria-hidden', 'true' );
		const path = document.createElementNS( ns, 'path' );
		path.setAttribute( 'd', 'M4 4h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H9l-5 4v-4H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zm3 6.5a1.5 1.5 0 1 0 0 .01zm5 0a1.5 1.5 0 1 0 0 .01zm5 0a1.5 1.5 0 1 0 0 .01z' );
		path.setAttribute( 'fill', 'currentColor' );
		svg.append( path );
		return svg;
	}

	const root = element( 'div', 'mody-bubble' );
	root.id = 'mody-agent-bubble';

	const panel = element( 'section', 'mody-bubble__panel mody-agent' );
	panel.id = 'mody-agent-panel';
	panel.hidden = true;
	panel.setAttribute( 'role', 'dialog' );
	panel.setAttribute( 'aria-label', strings.title );

	const header = element( 'header', 'mody-bubble__header' );
	const title = element( 'h2', 'mody-bubble__title', strings.title );
	const reset = element( 'button', 'button-link mody-bubble__reset', strings.reset );
	reset.type = 'button';
	reset.id = 'mody-agent-reset';
	const close = element( 'button', 'mody-bubble__close', '×' );
	close.type = 'button';
	close.setAttribute( 'aria-label', strings.close );
	header.append( title, reset, close );
	panel.append( header );


	const log = element( 'div', 'mody-agent__log' );
	log.id = 'mody-agent-log';
	log.setAttribute( 'role', 'log' );
	log.setAttribute( 'aria-live', 'polite' );
	panel.append( log );

	const chips = element( 'div', 'mody-agent__chips' );
	( config.chips || [] ).forEach( ( text ) => {
		const chip = element( 'button', 'button mody-agent__chip', text );
		chip.type = 'button';
		chip.dataset.message = text;
		chips.append( chip );
	} );
	panel.append( chips );

	const form = element( 'form', 'mody-agent__form' );
	form.id = 'mody-agent-form';
	const label = element( 'label', 'screen-reader-text', strings.message );
	label.htmlFor = 'mody-agent-input';
	const input = element( 'textarea' );
	input.id = 'mody-agent-input';
	input.rows = 2;
	input.maxLength = config.maxLength || 2000;
	input.placeholder = strings.hint;
	const buttons = element( 'div', 'mody-agent__buttons' );
	const send = element( 'button', 'button button-primary', strings.send );
	send.type = 'submit';
	buttons.append( send );
	form.append( label, input, buttons );
	panel.append( form );

	const toggle = element( 'button', 'mody-bubble__toggle' );
	toggle.type = 'button';
	toggle.setAttribute( 'aria-label', strings.open );
	toggle.setAttribute( 'aria-controls', panel.id );
	toggle.setAttribute( 'aria-expanded', 'false' );
	toggle.append( icon() );

	root.append( panel, toggle );
	document.body.append( root );

	function setOpen( open ) {
		panel.hidden = ! open;
		toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		root.classList.toggle( 'is-open', open );
		try {
			window.sessionStorage.setItem( 'modyAgentBubble', open ? 'open' : 'closed' );
		} catch ( e ) {}
		if ( open ) {
			log.scrollTop = log.scrollHeight;
			input.focus();
		} else {
			toggle.focus();
		}
	}

	toggle.addEventListener( 'click', () => setOpen( panel.hidden ) );
	close.addEventListener( 'click', () => setOpen( false ) );
	panel.addEventListener( 'keydown', ( event ) => {
		if ( 'Escape' === event.key ) {
			event.stopPropagation(); // The editor would also act on Escape.
			setOpen( false );
		}
	} );

	// Editing what is open (M3-06) ------------------------------------------------

	const wpData = () => ( window.wp && window.wp.data ) || null;

	// What is open, read from the editor's public stores when a message is sent.
	config.context = () => {
		const data = wpData();
		if ( ! data || ! window.wp.blocks ) {
			return null;
		}
		try {
			const editor = data.select( 'core/editor' );
			const blocks = data.select( 'core/block-editor' );
			const type = editor.getCurrentPostType();
			const id = editor.getCurrentPostId();
			if ( ! type || ! id ) {
				return null;
			}
			let selected = blocks.getSelectedBlock();
			// Showing a page inside its template: only blocks of the page's own content count.
			const mode = editor.getRenderingMode ? editor.getRenderingMode() : 'post-only';
			if ( selected && 'post-only' !== mode && ! blocks.getBlockParentsByBlockName( selected.clientId, 'core/post-content' ).length ) {
				selected = null;
			}
			return {
				post_type: type,
				post_id: id,
				selected: selected ? { client_id: selected.clientId, markup: window.wp.blocks.serialize( selected ) } : null,
				content: editor.getEditedPostContent(),
			};
		} catch ( e ) {
			return null;
		}
	};

	// Adds a note, or an Undo button, under the latest reply.
	function underReply( node ) {
		const replies = log.querySelectorAll( '.mody-agent__message--assistant' );
		const last = replies[ replies.length - 1 ];
		if ( last ) {
			last.append( node );
			log.scrollTop = log.scrollHeight;
		}
	}

	// Applies one edit in the editor: undoable, and saved only with Save.
	function apply( edit ) {
		const data = wpData();
		if ( ! data || ! edit || 'string' !== typeof edit.markup ) {
			return;
		}
		const editor = data.select( 'core/editor' );
		// A page's ID is a number, a template's a string (theme//header).
		if ( String( editor.getCurrentPostId() ) !== String( edit.post_id ) ) {
			underReply( element( 'p', 'mody-bubble__note', strings.otherPage ) );
			return;
		}
		const blocks = window.wp.blocks.parse( edit.markup );
		if ( ! blocks.length ) {
			return;
		}
		if ( edit.client_id ) {
			if ( ! data.select( 'core/block-editor' ).getBlock( edit.client_id ) ) {
				underReply( element( 'p', 'mody-bubble__note', strings.blockGone ) );
				return;
			}
			data.dispatch( 'core/block-editor' ).replaceBlocks( edit.client_id, blocks );
		} else {
			data.dispatch( 'core/editor' ).resetEditorBlocks( blocks );
		}
		const undo = element( 'button', 'button mody-bubble__undo', strings.undo );
		undo.type = 'button';
		undo.addEventListener( 'click', () => {
			data.dispatch( 'core/editor' ).undo();
			undo.disabled = true;
		} );
		const row = element( 'div', 'mody-agent__actions' );
		row.append( undo );
		underReply( row );
	}

	// The Site Editor: one page whose screen is in the URL (?p=…&section=…&canvas=…).
	function inSiteEditor() {
		return /\/site-editor\.php$/.test( window.location.pathname );
	}

	// Where the owner is, sent with each message (M3-09).
	config.screen = () => {
		if ( ! inSiteEditor() ) {
			return null;
		}
		const query = new URLSearchParams( window.location.search );
		return {
			p: query.get( 'p' ) || '/',
			section: query.get( 'section' ) || '',
			canvas: query.get( 'canvas' ) || '',
		};
	};

	// Switches the Site Editor to another screen in place (M3-09). Its router listens
	// to the browser's history, as Back and Forward do: push the URL, with the state
	// shape its history library keeps, then tell it with a popstate event. The chat
	// stays open, and unsaved changes stay in the editor (Save lists them all).
	function navigate( url ) {
		let target;
		try {
			target = new URL( url, window.location.href );
		} catch ( e ) {
			return false;
		}
		if ( ! inSiteEditor() || target.origin !== window.location.origin || ! /\/site-editor\.php$/.test( target.pathname ) ) {
			return false;
		}
		target.searchParams.delete( config.openParam || 'mody-chat' );
		if ( target.search === window.location.search ) {
			return true; // Already there.
		}
		const state = window.history.state || {};
		const idx = Number.isInteger( state.idx ) ? state.idx + 1 : 0;
		window.history.pushState( { usr: null, key: Math.random().toString( 36 ).slice( 2, 10 ), idx }, '', window.location.pathname + target.search );
		window.dispatchEvent( new PopStateEvent( 'popstate', { state: window.history.state } ) );
		return true;
	}

	// For the editor tour (M1-03): open the chat, and switch screens in place.
	config.openChat = () => {
		if ( panel.hidden ) {
			setOpen( true );
		}
	};
	config.navigate = navigate;

	// The turn's screen to open: in the Site Editor it opens now, and its button gives
	// way to a note with Go back. Elsewhere the button stays (decided 2026-11-08).
	function openScreen( actions ) {
		const action = actions.filter( ( item ) => item && item.navigate ).pop();
		if ( ! action || ! navigate( action.url ) ) {
			return;
		}
		const replies = log.querySelectorAll( '.mody-agent__message--assistant' );
		const last = replies[ replies.length - 1 ];
		if ( last ) {
			last.querySelectorAll( '.mody-agent__actions a' ).forEach( ( link ) => {
				if ( link.getAttribute( 'href' ) === action.url ) {
					link.remove();
				}
			} );
		}
		const row = element( 'div', 'mody-agent__actions mody-bubble__opened' );
		row.append( element( 'span', 'mody-bubble__note', strings.opened.replace( '%s', action.place || '' ) ) );
		const back = element( 'button', 'button mody-bubble__back', strings.back );
		back.type = 'button';
		back.addEventListener( 'click', () => {
			window.history.back();
			back.disabled = true;
		} );
		row.append( back );
		underReply( row );
	}

	// What the agent changed on the server, and the editor holds a copy of: the records
	// to load again (core-data's getEntityRecord arguments), by the turn's `refresh` names.
	const RECORDS = {
		styles: ( core ) => {
			const id = core.__experimentalGetCurrentGlobalStylesId ? core.__experimentalGetCurrentGlobalStylesId() : null;
			return id ? [ [ 'root', 'globalStyles', id ] ] : [];
		},
		site: () => [ [ 'root', 'site' ], [ 'root', '__unstableBase' ] ],
	};

	// Loads those records again, so the open screen and the canvas show the change now,
	// as after a page reload (the Site Editor keeps them from when it opened). Only
	// records the editor has loaded: the others are read fresh when needed. Unsaved
	// changes of the owner's to the same record stay on top, and a note says so.
	async function refresh( names ) {
		const data = wpData();
		const core = data && data.select( 'core' );
		if ( ! core || ! names.length ) {
			return;
		}
		const records = names
			.flatMap( ( name ) => ( RECORDS[ name ] ? RECORDS[ name ]( core ) : [] ) )
			.filter( ( args ) => core.hasFinishedResolution( 'getEntityRecord', args ) );
		try {
			await Promise.all( records.map( ( args ) => {
				data.dispatch( 'core' ).invalidateResolution( 'getEntityRecord', args );
				return data.resolveSelect( 'core' ).getEntityRecord( ...args );
			} ) );
		} catch ( e ) {
			return;
		}
		if ( records.some( ( args ) => core.hasEditsForEntityRecord( ...args ) ) ) {
			underReply( element( 'p', 'mody-bubble__note', strings.unsaved ) );
		}
	}

	// What the owner has unsaved changes to, sent with each message: the agent holds a
	// change to it for their decision (decided by the maintainer on 2026-11-09).
	config.unsaved = () => {
		const data = wpData();
		const core = data && data.select( 'core' );
		if ( ! core ) {
			return [];
		}
		try {
			return Object.keys( RECORDS ).filter( ( name ) => RECORDS[ name ]( core ).some( ( args ) => core.hasEditsForEntityRecord( ...args ) ) );
		} catch ( e ) {
			return [];
		}
	};

	// The owner's choice on a held change, in the editor, before the change runs: save
	// their changes (the Site Editor's own save) or discard them (each edit set back to
	// the saved value, which core-data then drops). True when none is left.
	config.settle = async ( name, choice ) => {
		const data = wpData();
		const core = data && data.select( 'core' );
		if ( ! core || ! RECORDS[ name ] ) {
			return false;
		}
		const records = RECORDS[ name ]( core ).filter( ( args ) => core.hasEditsForEntityRecord( ...args ) );
		try {
			for ( const args of records ) {
				if ( 'save' === choice ) {
					await data.dispatch( 'core' ).saveEditedEntityRecord( ...args );
				} else {
					const saved = core.getRawEntityRecord( ...args ) || {};
					const edits = core.getEntityRecordEdits( ...args ) || {};
					data.dispatch( 'core' ).editEntityRecord( args[ 0 ], args[ 1 ], args[ 2 ], Object.fromEntries( Object.keys( edits ).map( ( key ) => [ key, saved[ key ] ] ) ) );
				}
			}
		} catch ( e ) {
			return false;
		}
		return records.every( ( args ) => ! core.hasEditsForEntityRecord( ...args ) );
	};

	document.addEventListener( 'mody-agent:turn', ( event ) => {
		( ( event.detail && event.detail.edits ) || [] ).forEach( apply );
		openScreen( ( event.detail && event.detail.actions ) || [] );
		refresh( ( event.detail && event.detail.refresh ) || [] );
	} );

	// Open on load: after an agent button (?mody-chat=open), after a reload if it was
	// open, or while the agent is still working.
	let reopen = false;
	try {
		reopen = 'open' === new URLSearchParams( window.location.search ).get( config.openParam || 'mody-chat' )
			|| 'open' === window.sessionStorage.getItem( 'modyAgentBubble' );
	} catch ( e ) {}
	if ( reopen || ( config.pending && config.pending.job ) ) {
		panel.hidden = false;
		toggle.setAttribute( 'aria-expanded', 'true' );
		root.classList.add( 'is-open' );
	}
}() );
