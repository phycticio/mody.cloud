/**
 * The signup chat on /register/ (ticket M1-03).
 *
 * Plain script, no build step. Hides the block's form and asks its four
 * questions one at a time: name, email, the project's name, the site's
 * address. Each answer is checked on the server (mody-platform/v1/register/check)
 * before the next question; then a summary, and Create my site
 * (mody-platform/v1/register). All text is inserted with textContent,
 * never as HTML. Without this script the form works on its own.
 *
 * Antibot (tickets/antibot-altcha.md): creating the account sends an ALTCHA
 * solution. The form's widget solves it in the background after the first
 * answer; each solution is used once, so a new one is solved for a retry.
 */
( function () {
	'use strict';

	const config = window.modyRegister;
	const chat = document.getElementById( 'mody-register-chat' );
	const form = document.getElementById( 'mody-register-form' );
	if ( ! config || ! chat || ! form || ! window.fetch ) {
		return;
	}
	const strings = config.strings;
	const FIELDS = [ 'name', 'email', 'title', 'slug' ];
	const INPUTS = {
		name: { type: 'text', autocomplete: 'name', max: config.maxName },
		email: { type: 'email', autocomplete: 'email', max: 254 },
		title: { type: 'text', autocomplete: 'organization', max: config.maxTitle },
		slug: { type: 'text', autocomplete: 'off', max: 63 },
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

	// The chat: the log, then one input at the bottom.
	const log = element( 'div', 'mody-register__log' );
	log.setAttribute( 'role', 'log' );
	log.setAttribute( 'aria-live', 'polite' );
	const composer = element( 'form', 'mody-register__composer' );
	composer.noValidate = true;
	const label = element( 'label', 'screen-reader-text' );
	label.htmlFor = 'mody-register-input';
	const field = element( 'span', 'mody-register__address' );
	const input = element( 'input' );
	input.id = 'mody-register-input';
	const suffix = element( 'span', 'mody-register__suffix', config.suffix );
	const send = element( 'button', 'wp-element-button', strings.send );
	send.type = 'submit';
	field.append( input, suffix );
	composer.append( label, field, send );
	chat.append( log, composer );
	form.hidden = true;
	chat.hidden = false;

	// The proof of work (the form's ALTCHA widget): a promise of its payload.
	const widget = form.querySelector( 'altcha-widget' );
	let proof = null;

	function prove() {
		if ( ! proof ) {
			proof = ( async () => {
				try {
					const result = await widget.verify();
					return ( result && result.payload ) || '';
				} catch ( error ) {
					return '';
				}
			} )();
		}
		return proof;
	}

	function newProof() {
		proof = null;
		if ( widget && widget.reset ) {
			widget.reset();
		}
	}

	if ( widget ) {
		widget.addEventListener( 'expired', newProof );
	}

	const answers = { name: '', email: '', title: '', slug: '' };
	let current = null; // The question being answered.
	let suggestion = ''; // The address suggested for the project's name.
	let backToSummary = false; // Changing one answer from the summary.
	let busy = false;

	function scroll() {
		log.scrollTop = log.scrollHeight;
	}

	/**
	 * Adds a message to the log.
	 *
	 * @param {string}  text  Plain text.
	 * @param {string}  who   'mody' or 'you'.
	 * @param {boolean} error Whether it says something went wrong.
	 * @return {HTMLElement} The message.
	 */
	function say( text, who = 'mody', error = false ) {
		const item = element( 'div', 'mody-register__message mody-register__message--' + who );
		if ( error ) {
			item.classList.add( 'is-error' );
		}
		item.append(
			element( 'span', 'mody-register__who', 'you' === who ? strings.you : strings.mody ),
			element( 'p', 'mody-register__text', text )
		);
		log.append( item );
		scroll();
		return item;
	}

	// Buttons under the last message. Choosing one removes them all.
	function choices( list ) {
		const row = element( 'div', 'mody-register__choices' );
		list.forEach( ( choice ) => {
			const button = element( 'button', choice.primary ? 'wp-element-button' : 'mody-register__choice', choice.label );
			button.type = 'button';
			button.addEventListener( 'click', () => {
				if ( busy ) {
					return;
				}
				clearChoices();
				choice.onClick();
			} );
			row.append( button );
		} );
		log.append( row );
		scroll();
		return row;
	}

	function clearChoices() {
		log.querySelectorAll( '.mody-register__choices' ).forEach( ( row ) => row.remove() );
	}

	function setBusy( state ) {
		busy = state;
		input.disabled = state;
		send.disabled = state;
		log.querySelectorAll( '.mody-register__choices button' ).forEach( ( button ) => {
			button.disabled = state;
		} );
	}

	function question( name ) {
		switch ( name ) {
			case 'name':
				return strings.askName;
			case 'email':
				return strings.askEmail.replace( '%s', answers.name );
			case 'title':
				return strings.askTitle;
		}
		return suggestion
			? strings.askSlug.replace( '%1$s', suggestion + config.suffix ).replace( '%2$s', config.suffix )
			: strings.askSlugNew.replace( '%s', config.suffix );
	}

	/**
	 * Asks a question and sets the input up for its answer.
	 *
	 * @param {string}  name  One of FIELDS.
	 * @param {boolean} again Asked again after an error: no new message.
	 */
	function ask( name, again = false ) {
		current = name;
		const text = question( name );
		if ( ! again ) {
			say( text );
		}
		label.textContent = text;
		input.type = INPUTS[ name ].type;
		input.autocomplete = INPUTS[ name ].autocomplete;
		input.maxLength = INPUTS[ name ].max;
		input.placeholder = strings.placeholders[ name ];
		input.spellcheck = 'name' === name || 'title' === name;
		input.value = '';
		suffix.hidden = 'slug' !== name;
		composer.hidden = false;
		if ( 'slug' === name && suggestion && ! again ) {
			choices( [
				{
					label: strings.keep.replace( '%s', suggestion + config.suffix ),
					primary: true,
					onClick: () => answer( suggestion ),
				},
			] );
		}
		input.focus();
	}

	async function post( path, body ) {
		const response = await fetch( config.endpoint + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json' },
			// The invite code the page was opened with, if any: the permission check and the account both need it.
			body: JSON.stringify( { ...body, nonce: config.nonce, ref: config.ref || '' } ),
		} );
		const data = await response.json().catch( () => ( {} ) );
		return { ok: response.ok, data };
	}

	// What the visitor typed, checked on the server before the next question.
	async function answer( text ) {
		text = ( text || '' ).trim();
		if ( ! text || busy || ! current ) {
			return;
		}
		const name = current;
		if ( widget ) {
			prove(); // Solved while the visitor answers the rest.
		}
		clearChoices();
		say( 'slug' === name && ! text.includes( '.' ) ? text + config.suffix : text, 'you' );
		input.value = '';
		setBusy( true );
		const checking = say( strings.checking );
		checking.classList.add( 'is-pending' );
		try {
			const { ok, data } = await post( '/check', { field: name, value: text } );
			checking.remove();
			if ( ! ok ) {
				say( data.message || strings.failed, 'mody', true ); // For example: the page expired.
				return;
			}
			if ( ! data.ok ) {
				const message = say( data.message, 'mody', true );
				if ( data.exists ) {
					const link = element( 'a', 'mody-register__link', strings.logIn );
					link.href = config.login;
					message.append( link );
				}
				ask( name, true );
				return;
			}
			answers[ name ] = data.value;
			if ( 'title' === name && ! backToSummary ) {
				suggestion = data.suggestion || '';
			}
			next( name );
		} catch ( error ) {
			checking.remove();
			say( strings.network, 'mody', true );
		} finally {
			setBusy( false );
			if ( ! composer.hidden ) {
				input.focus();
			}
		}
	}

	function next( name ) {
		if ( backToSummary || 'slug' === name ) {
			summary();
			return;
		}
		ask( FIELDS[ FIELDS.indexOf( name ) + 1 ] );
	}

	function summary() {
		backToSummary = false;
		current = null;
		composer.hidden = true;
		const message = say( strings.summary );
		const list = element( 'dl', 'mody-register__summary' );
		FIELDS.forEach( ( name ) => {
			list.append(
				element( 'dt', '', strings.labels[ name ] ),
				element( 'dd', '', 'slug' === name ? answers.slug + config.suffix : answers[ name ] )
			);
		} );
		message.append( list );
		choices( [
			{ label: strings.create, primary: true, onClick: create },
			{ label: strings.change, onClick: change },
		] );
	}

	function change() {
		say( strings.change, 'you' );
		say( strings.which );
		choices(
			FIELDS.map( ( name ) => ( {
				label: strings.labels[ name ],
				onClick: () => {
					say( strings.labels[ name ], 'you' );
					backToSummary = true;
					if ( 'slug' === name ) {
						suggestion = answers.slug;
					}
					ask( name );
				},
			} ) )
		);
	}

	async function create() {
		say( strings.create, 'you' );
		setBusy( true );
		const pending = say( strings.creating );
		pending.classList.add( 'is-pending' );
		try {
			const altcha = widget ? await prove() : '';
			const { ok, data } = await post( '', { ...answers, altcha } );
			pending.remove();
			if ( ok ) {
				say( strings.done.replace( '%1$s', data.address ).replace( '%2$s', data.email ) );
				say( strings.spam ).classList.add( 'is-note' );
				return;
			}
			const fields = ( data.data && data.data.fields ) || null;
			const wrong = fields ? FIELDS.find( ( name ) => fields[ name ] ) : null;
			if ( wrong ) {
				// Taken meanwhile, for example: ask that question again, then the summary.
				say( fields[ wrong ], 'mody', true );
				backToSummary = true;
				suggestion = '';
				ask( wrong );
				return;
			}
			say( data.message || strings.failed, 'mody', true );
			choices( [ { label: strings.create, primary: true, onClick: create } ] );
		} catch ( error ) {
			pending.remove();
			say( strings.network, 'mody', true );
			choices( [ { label: strings.create, primary: true, onClick: create } ] );
		} finally {
			newProof(); // A solution is used once, whatever the answer was.
			setBusy( false );
		}
	}

	composer.addEventListener( 'submit', ( event ) => {
		event.preventDefault();
		answer( input.value );
	} );

	say( strings.intro );
	current = 'name';
	say( strings.askName );
	label.textContent = strings.askName;
	Object.assign( input, { type: 'text', autocomplete: 'name', maxLength: config.maxName, placeholder: strings.placeholders.name } );
	suffix.hidden = true;
}() );
