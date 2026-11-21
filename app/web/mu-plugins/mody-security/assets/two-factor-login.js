/**
 * Two-step login's second screen: "Use your security key" asks the browser
 * for the key's signed answer and sends it with the form. Plain script, no
 * build step (tickets/security-plugin-replaces-wordfence.md).
 */
( function () {
	const button = document.getElementById( 'mody-two-factor-key' );
	const field = document.getElementById( 'mody-webauthn' );
	const status = document.getElementById( 'mody-two-factor-status' );
	if ( ! button || ! field ) {
		return;
	}
	const toBuffer = ( text ) => {
		const base64 = text.replace( /-/g, '+' ).replace( /_/g, '/' );
		return Uint8Array.from( atob( base64 + '==='.slice( ( base64.length + 3 ) % 4 ) ), ( c ) => c.charCodeAt( 0 ) ).buffer;
	};
	const toText = ( buffer ) => btoa( String.fromCharCode( ...new Uint8Array( buffer ) ) ).replace( /\+/g, '-' ).replace( /\//g, '_' ).replace( /=+$/, '' );

	if ( ! window.PublicKeyCredential || ! navigator.credentials ) {
		button.disabled = true;
		status.textContent = button.dataset.unsupported || 'This browser cannot use security keys.';
		return;
	}

	button.addEventListener( 'click', async () => {
		const options = JSON.parse( button.dataset.options );
		options.challenge = toBuffer( options.challenge );
		options.allowCredentials = options.allowCredentials.map( ( c ) => ( { ...c, id: toBuffer( c.id ) } ) );
		button.disabled = true;
		status.textContent = button.dataset.touch || '…';
		try {
			const credential = await navigator.credentials.get( { publicKey: options } );
			field.value = JSON.stringify( {
				id: credential.id,
				clientDataJSON: toText( credential.response.clientDataJSON ),
				authenticatorData: toText( credential.response.authenticatorData ),
				signature: toText( credential.response.signature ),
			} );
			button.form.submit();
		} catch ( error ) {
			button.disabled = false;
			status.textContent = button.dataset.cancelled || String( error.message || error );
		}
	} );
} )();
