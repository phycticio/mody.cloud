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

	// In the editor's chat bubble the input may be hidden: never take the focus from the editor.
	const focusInput = () => {
		if ( ! input.closest( '[hidden]' ) ) {
			input.focus();
		}
	};

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

	async function request( method, body, url = config.endpoint ) {
		const response = await fetch( url, {
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

	const sleep = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) );

	/**
	 * Polls the checker until the job is no longer running.
	 *
	 * @param {string}      id      Job ID.
	 * @param {HTMLElement} pending The "working" message, updated when it takes long.
	 * @return {Object} The finished job: status and turn.
	 */
	async function poll( id, pending ) {
		const started = Date.now();
		for ( ;; ) {
			await sleep( config.interval );
			if ( Date.now() - started > 8000 ) {
				pending.querySelector( '.mody-agent__text' ).textContent = config.strings.slow;
			}
			const { ok, data } = await request( 'GET', null, config.endpoint + '/jobs/' + encodeURIComponent( id ) );
			if ( ! ok ) {
				return { turn: { reply: data.message || config.strings.failed, error: true } };
			}
			if ( 'running' !== data.status ) {
				return data;
			}
		}
	}

	/**
	 * Shows the user's message, then waits for the agent's turn.
	 *
	 * @param {string}   text  The user's message.
	 * @param {Function} start Returns the job: a POST, or an already running job.
	 */
	async function converse( text, start ) {
		setBusy( true );
		log.querySelectorAll( '.mody-agent__choices' ).forEach( ( row ) => row.remove() );
		show( 'user', text );
		const pending = show( 'assistant', config.strings.working );
		pending.classList.add( 'is-pending' );

		try {
			const { ok, data: started } = await start();
			let data = started;
			if ( ok && 'running' === data.status ) {
				data = await poll( data.job, pending );
			}
			// Not ok: data.message explains it (for example 409, another message still running).
			pending.remove();
			if ( ok && data.turn ) {
				show( 'assistant', data.turn.reply, { ...data.turn, interactive: true } );
				// The editor's bubble applies the turn's edits (M3-06).
				document.dispatchEvent( new CustomEvent( 'mody-agent:turn', { detail: data.turn } ) );
			} else {
				show( 'assistant', ( data.turn && data.turn.reply ) || data.message || config.strings.failed, { error: true } );
			}
		} catch ( error ) {
			pending.remove();
			show( 'assistant', config.strings.failed, { error: true } );
		} finally {
			setBusy( false );
			focusInput();
		}
	}

	function send( text ) {
		text = ( text || '' ).trim();
		if ( ! text || busy ) {
			return;
		}
		input.value = '';
		const body = { message: text };
		// In the editor's bubble: what is open, read when the message is sent (M3-06).
		const context = 'function' === typeof config.context ? config.context() : null;
		if ( context ) {
			body.context = context;
		}
		converse( text, () => request( 'POST', body ) );
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

	// A job that was still running when the page loaded: keep waiting for it.
	if ( config.pending && config.pending.job ) {
		converse( config.pending.message, () =>
			Promise.resolve( { ok: true, data: { job: config.pending.job, status: 'running' } } )
		);
	} else {
		focusInput();
	}
}() );
