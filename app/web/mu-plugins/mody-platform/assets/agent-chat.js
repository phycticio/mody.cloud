/**
 * Mody Agent chat in wp-admin (mvp-spec section 8).
 *
 * Plain script, no build step: posts each message to mody-platform/v1/chat
 * with the REST nonce and shows the agent's turn. All text is inserted with
 * textContent, never as HTML.
 */
( function () {
	'use strict';

	const config = window.modyAgent;
	const log = document.getElementById( 'mody-agent-log' );
	const form = document.getElementById( 'mody-agent-form' );
	const input = document.getElementById( 'mody-agent-input' );
	const reset = document.getElementById( 'mody-agent-reset' );
	if ( ! config || ! log || ! form || ! input ) {
		return;
	}

	let busy = false;

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

	/**
	 * Adds a message to the log.
	 *
	 * @param {string} role  'user' or 'assistant'.
	 * @param {string} text  Plain text.
	 * @param {Object} extra actions, choices, error, refused, interactive.
	 * @return {HTMLElement} The message element.
	 */
	function show( role, text, extra = {} ) {
		const item = element( 'div', 'mody-agent__message mody-agent__message--' + role );
		if ( extra.error ) {
			item.classList.add( 'is-error' );
		}
		if ( extra.refused ) {
			item.classList.add( 'is-refused' );
		}
		item.append(
			element( 'span', 'mody-agent__who', 'user' === role ? config.strings.you : config.strings.agent ),
			element( 'p', 'mody-agent__text', text )
		);

		if ( extra.actions && extra.actions.length ) {
			const row = element( 'div', 'mody-agent__actions' );
			extra.actions.forEach( ( action ) => {
				if ( ! /^https?:\/\//.test( action.url ) ) {
					return;
				}
				const link = element( 'a', 'button button-primary', action.label );
				link.href = action.url;
				link.target = '_blank';
				link.rel = 'noopener';
				row.append( link );
			} );
			item.append( row );
		}

		if ( extra.interactive && extra.choices && extra.choices.length ) {
			const row = element( 'div', 'mody-agent__choices' );
			extra.choices.forEach( ( choice ) => {
				const button = element( 'button', 'button', choice );
				button.type = 'button';
				button.addEventListener( 'click', () => send( choice ) );
				row.append( button );
			} );
			item.append( row );
		}

		log.append( item );
		log.scrollTop = log.scrollHeight;
		return item;
	}

	function setBusy( state ) {
		busy = state;
		form.querySelectorAll( 'button' ).forEach( ( button ) => {
			button.disabled = state;
		} );
		document.querySelectorAll( '.mody-agent__chip' ).forEach( ( button ) => {
			button.disabled = state;
		} );
	}

	async function request( method, body ) {
		const response = await fetch( config.endpoint, {
			method,
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce,
			},
			body: body ? JSON.stringify( body ) : undefined,
		} );
		const data = await response.json().catch( () => ( {} ) );
		return { ok: response.ok, data };
	}

	async function send( text ) {
		text = ( text || '' ).trim();
		if ( ! text || busy ) {
			return;
		}
		setBusy( true );
		log.querySelectorAll( '.mody-agent__choices' ).forEach( ( row ) => row.remove() );
		show( 'user', text );
		input.value = '';
		const pending = show( 'assistant', config.strings.working );
		pending.classList.add( 'is-pending' );

		try {
			const { ok, data } = await request( 'POST', { message: text } );
			pending.remove();
			if ( ok ) {
				show( 'assistant', data.reply, { ...data, interactive: true } );
			} else {
				show( 'assistant', data.message || config.strings.failed, { error: true } );
			}
		} catch ( error ) {
			pending.remove();
			show( 'assistant', config.strings.failed, { error: true } );
		} finally {
			setBusy( false );
			input.focus();
		}
	}

	form.addEventListener( 'submit', ( event ) => {
		event.preventDefault();
		send( input.value );
	} );

	input.addEventListener( 'keydown', ( event ) => {
		if ( 'Enter' === event.key && ! event.shiftKey ) {
			event.preventDefault();
			send( input.value );
		}
	} );

	document.querySelectorAll( '.mody-agent__chip' ).forEach( ( chip ) => {
		chip.addEventListener( 'click', () => send( chip.dataset.message ) );
	} );

	if ( reset ) {
		reset.addEventListener( 'click', async () => {
			if ( busy ) {
				return;
			}
			setBusy( true );
			try {
				await request( 'DELETE' );
				log.replaceChildren();
				show( 'assistant', config.strings.greeting );
			} finally {
				setBusy( false );
			}
		} );
	}

	// The conversation so far, kept on the server.
	const history = Array.isArray( config.history ) ? config.history : [];
	if ( history.length ) {
		history.forEach( ( entry, index ) =>
			show( entry.role, entry.text, { ...entry, interactive: index === history.length - 1 } )
		);
	} else {
		show( 'assistant', config.strings.greeting );
	}
	input.focus();
}() );
