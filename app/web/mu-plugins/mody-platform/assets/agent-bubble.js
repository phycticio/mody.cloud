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

	if ( ! config.hasProfile ) {
		panel.append( element( 'p', 'mody-bubble__notice', strings.profile ) );
	}

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
		if ( Number( editor.getCurrentPostId() ) !== Number( edit.post_id ) ) {
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

	document.addEventListener( 'mody-agent:turn', ( event ) => {
		( ( event.detail && event.detail.edits ) || [] ).forEach( apply );
	} );

	// Open again after a reload if it was open, or if the agent is still working.
	let reopen = false;
	try {
		reopen = 'open' === window.sessionStorage.getItem( 'modyAgentBubble' );
	} catch ( e ) {}
	if ( reopen || ( config.pending && config.pending.job ) ) {
		panel.hidden = false;
		toggle.setAttribute( 'aria-expanded', 'true' );
		root.classList.add( 'is-open' );
	}
}() );
