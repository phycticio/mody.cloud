/**
 * Two-step login on the profile: turn on the authenticator app, add and
 * remove security keys, make recovery codes, and a super admin's Turn off.
 * Talks to the mody-security/v1/two-factor routes. Plain script, no build
 * step (tickets/security-plugin-replaces-wordfence.md).
 */
( function () {
	const box = document.getElementById( 'mody-two-factor' );
	const config = window.modyTwoFactor;
	if ( ! box || ! config ) {
		return;
	}
	const t = config.i18n;
	const message = box.querySelector( '.mody-two-factor__message' );

	const say = ( text, isError ) => {
		message.textContent = text;
		message.classList.toggle( 'is-error', !! isError );
		message.hidden = ! text;
	};
	const call = async ( path, method, body ) => {
		const response = await fetch( config.root + path, {
			method,
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
			body: body ? JSON.stringify( body ) : undefined,
		} );
		const data = await response.json().catch( () => ( {} ) );
		if ( ! response.ok ) {
			throw new Error( data.message || t.failed );
		}
		return data;
	};
	const toBuffer = ( text ) => {
		const base64 = text.replace( /-/g, '+' ).replace( /_/g, '/' );
		return Uint8Array.from( atob( base64 + '==='.slice( ( base64.length + 3 ) % 4 ) ), ( c ) => c.charCodeAt( 0 ) ).buffer;
	};
	const toText = ( buffer ) => btoa( String.fromCharCode( ...new Uint8Array( buffer ) ) ).replace( /\+/g, '-' ).replace( /\//g, '_' ).replace( /=+$/, '' );

	// Recovery codes, shown once; "I saved them" reloads the profile.
	const showCodes = ( codes ) => {
		const panel = document.createElement( 'div' );
		panel.className = 'mody-two-factor__codes';
		panel.setAttribute( 'role', 'region' );
		panel.setAttribute( 'aria-label', t.codesTitle );
		const title = document.createElement( 'h3' );
		title.textContent = t.codesTitle;
		const help = document.createElement( 'p' );
		help.textContent = t.codesHelp;
		const list = document.createElement( 'ol' );
		codes.forEach( ( code ) => {
			const item = document.createElement( 'li' );
			item.textContent = code;
			list.append( item );
		} );
		const download = document.createElement( 'a' );
		download.className = 'button';
		download.textContent = t.download;
		download.download = 'recovery-codes.txt';
		download.href = 'data:text/plain;charset=utf-8,' + encodeURIComponent( codes.join( '\n' ) + '\n' );
		const done = document.createElement( 'button' );
		done.type = 'button';
		done.className = 'button button-primary';
		done.textContent = t.codesDone;
		done.style.marginLeft = '8px';
		done.addEventListener( 'click', () => window.location.reload() );
		panel.append( title, help, list, download, done );
		box.querySelectorAll( ':scope > :not(h2)' ).forEach( ( el ) => ( el.hidden = true ) );
		box.append( panel );
		done.focus();
	};
	const after = ( data ) => {
		if ( data.codes && data.codes.length ) {
			showCodes( data.codes );
		} else {
			window.location.reload();
		}
	};

	const actions = {
		'confirm-app': async () => {
			const input = document.getElementById( 'mody-2fa-confirm' );
			after( await call( '/app', 'POST', { code: input.value } ) );
		},
		'remove-app': async () => {
			if ( window.confirm( t.confirmRemoveApp ) ) {
				after( await call( '/app', 'DELETE' ) );
			}
		},
		'add-key': async () => {
			if ( ! window.PublicKeyCredential || ! navigator.credentials ) {
				throw new Error( t.noWebAuthn );
			}
			const options = await call( '/keys/options', 'POST' );
			options.challenge = toBuffer( options.challenge );
			options.user.id = toBuffer( options.user.id );
			options.excludeCredentials = options.excludeCredentials.map( ( c ) => ( { ...c, id: toBuffer( c.id ) } ) );
			say( t.touchKey );
			let credential;
			try {
				credential = await navigator.credentials.create( { publicKey: options } );
			} catch ( error ) {
				throw new Error( t.cancelled );
			}
			after( await call( '/keys', 'POST', {
				name: document.getElementById( 'mody-2fa-key-name' ).value,
				clientDataJSON: toText( credential.response.clientDataJSON ),
				attestationObject: toText( credential.response.attestationObject ),
			} ) );
		},
		'remove-key': async ( button ) => {
			if ( window.confirm( t.confirmRemoveKey ) ) {
				after( await call( '/keys/' + encodeURIComponent( button.dataset.id ), 'DELETE' ) );
			}
		},
		'new-codes': async () => {
			if ( window.confirm( t.confirmCodes ) ) {
				after( await call( '/codes', 'POST' ) );
			}
		},
		reset: async ( button ) => {
			if ( window.confirm( t.confirmReset ) ) {
				after( await call( '/users/' + encodeURIComponent( button.dataset.user ), 'DELETE' ) );
			}
		},
	};

	box.addEventListener( 'click', async ( event ) => {
		const button = event.target.closest( '[data-mody-2fa]' );
		const action = button && actions[ button.getAttribute( 'data-mody-2fa' ) ]; // Not dataset: "-2" is not camel-cased.
		if ( ! action ) {
			return;
		}
		event.preventDefault();
		button.disabled = true;
		say( '' );
		try {
			await action( button );
		} catch ( error ) {
			say( error.message || t.failed, true );
		} finally {
			button.disabled = false;
		}
	} );

	// Enter in the app's code field turns it on, instead of submitting the profile form.
	const code = document.getElementById( 'mody-2fa-confirm' );
	if ( code ) {
		code.addEventListener( 'keydown', ( event ) => {
			if ( 'Enter' === event.key ) {
				event.preventDefault();
				box.querySelector( '[data-mody-2fa="confirm-app"]' ).click();
			}
		} );
	}
} )();
