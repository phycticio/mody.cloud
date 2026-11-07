/**
 * The brand wizard in Network Admin (mvp-spec section 6, ticket M2-03).
 *
 * Plain script, no build step. Moves between the three steps, posts the
 * answers to mody-platform/v1/sites with the REST nonce, then follows the job
 * until the site is ready. The server validates everything; its errors are
 * shown next to their fields. All text is inserted with textContent, never
 * as HTML.
 */
( function () {
	'use strict';

	const config = window.modyWizard;
	const form = document.getElementById( 'mody-wizard-form' );
	const status = document.getElementById( 'mody-wizard-status' );
	if ( ! config || ! form || ! status ) {
		return;
	}

	const field = ( name ) => form.elements.namedItem( name );
	const steps = Array.from( form.querySelectorAll( '[data-step]' ) );
	const stepOf = { title: 1, slug: 1, brand_name: 1, mission: 1, tone: 1, preset: 2, logo: 2, prompt: 3 };
	const required = { title: 1, slug: 1, mission: 1, prompt: 3 };
	let poller = null;
	let retried = false; // A Retry generation was asked for on this page.

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

	function button( text, primary, onClick ) {
		const node = element( 'button', primary ? 'button button-primary' : 'button', text );
		node.type = 'button';
		node.addEventListener( 'click', onClick );
		return node;
	}

	function link( text, url, primary ) {
		const node = element( 'a', primary ? 'button button-primary' : 'button', text );
		if ( /^https?:\/\//.test( url ) ) {
			node.href = url;
		}
		node.target = '_blank';
		node.rel = 'noopener';
		return node;
	}

	// Steps ----------------------------------------------------------------

	// The live preview (step 2) -------------------------------------------------

	const preview = document.getElementById( 'mody-wizard-preview' );
	let previewKey = null;
	let logoUrl = null; // The chosen logo, as a local object URL.

	const presetValue = () => form.querySelector( '[name=preset]:checked' )?.value || '';

	// Loads the preview page; again only when the brand or the sentence changed.
	function loadPreview() {
		if ( ! preview || ! config.preview ) {
			return;
		}
		const brandName = field( 'brand_name' ).value.trim() || field( 'title' ).value.trim();
		const mission = field( 'mission' ).value.trim();
		const key = brandName + '\n' + mission;
		if ( key === previewKey ) {
			paint();
			return;
		}
		previewKey = key;
		const url = new URL( config.preview );
		url.searchParams.set( 'brand', brandName );
		url.searchParams.set( 'mission', mission );
		url.searchParams.set( 'preset', presetValue() );
		preview.src = url.toString();
	}

	// Sets the chosen preset's colors in the preview, without reloading it.
	function paint() {
		const colors = config.presets && config.presets[ presetValue() ];
		let root = null;
		try {
			root = preview.contentDocument && preview.contentDocument.documentElement;
		} catch ( e ) {
			root = null; // Another origin: the colors in the URL stay.
		}
		if ( ! colors || ! root ) {
			return;
		}
		Object.keys( colors ).forEach( ( slug ) => {
			root.style.setProperty( '--wp--preset--color--' + slug, colors[ slug ] );
		} );

		// The logo before the site title, as the Site Logo block shows it (48 px wide).
		const doc = preview.contentDocument;
		const shown = doc.getElementById( 'mody-preview-logo' );
		if ( shown ) {
			shown.remove();
		}
		const title = doc.querySelector( '.wp-block-site-title' );
		if ( logoUrl && title ) {
			const wrap = doc.createElement( 'div' );
			wrap.id = 'mody-preview-logo';
			wrap.className = 'wp-block-site-logo';
			const img = doc.createElement( 'img' );
			img.src = logoUrl;
			img.alt = '';
			img.style.width = '48px';
			img.style.height = 'auto';
			wrap.append( img );
			title.before( wrap );
		}
	}

	// A logo is checked here for type and size; the server checks it again.
	const logo = field( 'logo' );
	if ( logo ) {
		logo.addEventListener( 'change', () => {
			setError( 'logo', '' );
			if ( logoUrl ) {
				URL.revokeObjectURL( logoUrl );
				logoUrl = null;
			}
			const file = logo.files[ 0 ];
			if ( file && ( ! config.logoMime.includes( file.type ) || file.size > config.logoMax ) ) {
				setError( 'logo', config.strings.badLogo );
				logo.value = '';
			} else if ( file ) {
				logoUrl = URL.createObjectURL( file );
			}
			paint();
		} );
	}

	if ( preview ) {
		preview.addEventListener( 'load', paint );
		form.querySelectorAll( '[name=preset]' ).forEach( ( radio ) => radio.addEventListener( 'change', paint ) );
	}

	function go( number ) {
		if ( 2 === number ) {
			loadPreview();
		}
		steps.forEach( ( step ) => {
			step.hidden = Number( step.dataset.step ) !== number;
		} );
		form.querySelectorAll( '[data-nav]' ).forEach( ( item ) => {
			item.classList.toggle( 'is-current', Number( item.dataset.nav ) === number );
			item.classList.toggle( 'is-done', Number( item.dataset.nav ) < number );
		} );
		const first = steps[ number - 1 ].querySelector( 'input:not([type=radio]), textarea' );
		if ( first ) {
			first.focus();
		}
	}

	function setError( name, message ) {
		const slot = form.querySelector( '[data-error-for="' + name + '"]' );
		if ( slot ) {
			slot.textContent = message || '';
		}
	}

	function clearErrors() {
		form.querySelectorAll( '[data-error-for]' ).forEach( ( slot ) => {
			slot.textContent = '';
		} );
	}

	// Required fields of the steps up to `number`, checked before moving on.
	function missing( upTo ) {
		let first = 0;
		Object.keys( required ).forEach( ( name ) => {
			if ( required[ name ] <= upTo && '' === field( name ).value.trim() ) {
				setError( name, config.strings.required );
				first = first || required[ name ];
			}
		} );
		return first;
	}

	form.querySelectorAll( '[data-go]' ).forEach( ( control ) => {
		control.addEventListener( 'click', () => {
			const target = Number( control.dataset.go );
			const current = Number( control.closest( '[data-step]' ).dataset.step );
			clearErrors();
			const back = target < current ? 0 : missing( current );
			go( back || target );
		} );
	} );

	// The address and the brand name follow the site name until edited.
	const title = field( 'title' );
	const slug = field( 'slug' );
	const brand = field( 'brand_name' );
	const toSlug = ( text ) =>
		text
			.normalize( 'NFD' )
			.replace( /[̀-ͯ]/g, '' )
			.toLowerCase()
			.replace( /[^a-z0-9]/g, '' )
			.slice( 0, 63 );
	title.addEventListener( 'input', () => {
		if ( ! slug.dataset.edited ) {
			slug.value = toSlug( title.value );
		}
		if ( ! brand.dataset.edited ) {
			brand.value = title.value;
		}
	} );
	slug.addEventListener( 'input', () => {
		slug.dataset.edited = '1';
		slug.value = slug.value.toLowerCase().replace( /[^a-z0-9]/g, '' );
	} );
	brand.addEventListener( 'input', () => {
		brand.dataset.edited = '1';
	} );

	form.querySelectorAll( '[data-prompt]' ).forEach( ( chip ) => {
		chip.addEventListener( 'click', () => {
			field( 'prompt' ).value = chip.dataset.prompt;
			field( 'prompt' ).focus();
		} );
	} );

	// Requests -------------------------------------------------------------

	// A FormData body goes as multipart (the logo file with the answers); anything else as JSON.
	async function request( method, path, body ) {
		const isForm = body instanceof FormData;
		const headers = { 'X-WP-Nonce': config.nonce };
		if ( body && ! isForm ) {
			headers[ 'Content-Type' ] = 'application/json';
		}
		const response = await fetch( config.endpoint + path, {
			method,
			credentials: 'same-origin',
			headers,
			body: isForm || ! body ? body : JSON.stringify( body ),
		} );
		let data = {};
		try {
			data = await response.json();
		} catch ( e ) {
			data = {};
		}
		return { ok: response.ok, data };
	}

	form.addEventListener( 'submit', async ( event ) => {
		event.preventDefault();
		clearErrors();

		// Enter in a field of steps 1 and 2 means Next, not Create.
		const current = Number( steps.find( ( step ) => ! step.hidden ).dataset.step );
		if ( current < steps.length ) {
			go( missing( current ) || current + 1 );
			return;
		}

		const first = missing( 3 );
		if ( first ) {
			go( first );
			return;
		}

		const submit = form.querySelector( '[type=submit]' );
		submit.disabled = true;
		const answers = new FormData();
		[ 'title', 'slug', 'brand_name', 'mission', 'prompt' ].forEach( ( name ) => {
			answers.append( name, field( name ).value.trim() );
		} );
		answers.append( 'tone', form.querySelector( '[name=tone]:checked' )?.value || '' );
		answers.append( 'preset', presetValue() );
		if ( logo && logo.files[ 0 ] ) {
			answers.append( 'logo', logo.files[ 0 ] );
		}

		let result;
		try {
			result = await request( 'POST', '', answers );
		} catch ( e ) {
			submit.disabled = false;
			setError( 'form', config.strings.network );
			return;
		}
		submit.disabled = false;

		if ( result.ok ) {
			follow( result.data );
			return;
		}
		const fields = result.data.data && result.data.data.fields;
		if ( fields ) {
			let back = 3;
			Object.keys( fields ).forEach( ( name ) => {
				setError( name, fields[ name ] );
				back = Math.min( back, stepOf[ name ] || 3 );
			} );
			go( back );
			return;
		}
		if ( result.data.data && result.data.data.job ) {
			// Another site is being created: show it.
			poll( result.data.data.job );
			return;
		}
		setError( 'form', result.data.message || config.strings.unexpected );
	} );

	// Following the job --------------------------------------------------------

	function follow( job ) {
		form.hidden = true;
		status.hidden = false;
		render( job );
		if ( 'queued' === job.status || 'running' === job.status || job.retrying ) {
			window.clearTimeout( poller );
			poller = window.setTimeout( () => poll( job.job ), config.interval );
		}
	}

	async function poll( id ) {
		let result;
		try {
			result = await request( 'GET', '/jobs/' + id );
		} catch ( e ) {
			poller = window.setTimeout( () => poll( id ), config.interval * 2 );
			return;
		}
		if ( ! result.ok ) {
			status.replaceChildren( element( 'p', 'mody-wizard__error', result.data.message || config.strings.unexpected ) );
			return;
		}
		follow( result.data );
	}

	async function act( id, path ) {
		retried = retried || '/retry-generation' === path;
		const result = await request( 'POST', '/jobs/' + id + path ).catch( () => null );
		if ( ! result ) {
			status.append( element( 'p', 'mody-wizard__error', config.strings.network ) );
			return;
		}
		if ( ! result.ok ) {
			status.append( element( 'p', 'mody-wizard__error', result.data.message || config.strings.unexpected ) );
			return;
		}
		follow( result.data );
	}

	function render( job ) {
		const nodes = [];
		if ( 'done' === job.status ) {
			nodes.push( element( 'h2', '', config.strings.ready ) );
		} else if ( 'failed' === job.status ) {
			nodes.push( element( 'h2', '', config.strings.failed ) );
		} else {
			nodes.push( element( 'h2', '', config.strings.creating ) );
		}

		// The steps, ticked as they pass.
		const names = Object.keys( config.steps );
		const reached = 'done' === job.step ? names.length : names.indexOf( job.step );
		const list = element( 'ol', 'mody-wizard__progress' );
		names.forEach( ( name, index ) => {
			const item = element( 'li', '', config.steps[ name ] );
			if ( index < reached ) {
				item.className = 'is-done';
			} else if ( index === reached ) {
				item.className = 'failed' === job.status ? 'is-failed' : 'is-current';
			}
			list.append( item );
		} );
		nodes.push( list );

		if ( 'failed' === job.status ) {
			nodes.push( element( 'p', 'mody-wizard__error', job.error ) );
			const row = element( 'p', 'mody-wizard__buttons' );
			row.append(
				button( config.strings.tryAgain, true, () => act( job.job, '/run' ) ),
				button( config.strings.startOver, false, () => {
					status.hidden = true;
					form.hidden = false;
					go( 1 );
				} )
			);
			nodes.push( row );
		}

		if ( 'done' === job.status && job.site ) {
			nodes.push( element( 'p', 'mody-wizard__site', job.site.title + ' · ' + job.site.url ) );
			if ( job.retrying ) {
				nodes.push( element( 'p', 'mody-wizard__note', config.strings.retrying ) );
			} else if ( job.fallback ) {
				nodes.push( element( 'p', 'notice notice-warning inline mody-wizard__note', retried ? config.strings.retryFail : config.strings.fallback ) );
				const retry = element( 'p', 'mody-wizard__buttons' );
				retry.append( button( config.strings.retry, false, () => act( job.job, '/retry-generation' ) ) );
				nodes.push( retry );
			}
			const row = element( 'p', 'mody-wizard__buttons' );
			row.append(
				link( config.strings.visit, job.site.url, true ),
				link( config.strings.editor, job.site.editor, false ),
				link( config.strings.admin, job.site.admin, false )
			);
			nodes.push( row );
		}

		status.replaceChildren( ...nodes );
	}

	// A site creation still on the way (or stopped) when the page loaded.
	if ( config.pending ) {
		follow( config.pending );
	}
} )();
