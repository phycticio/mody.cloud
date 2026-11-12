/**
 * Dashboard → Download your site (Dashboard\ExportPanel): while a download is
 * queued or running, asks the server for its state every few seconds and
 * updates the steps, the bar and the sentence in place. When the job is done
 * (or has failed) the page loads again once, and the server renders the
 * downloads (or the way to try again).
 *
 * Each poll also helps the build along (the server starts a waiting job and
 * restarts a builder that went quiet), so it keeps going while the tab is
 * open; the build does not need the page, and goes on without it. All text
 * comes from the server, already translated; this script has none of its own.
 *
 * Plain script, no build step.
 */
( function () {
	'use strict';

	const root = document.querySelector( '.mody-export[data-ajax-url]' );
	if ( ! root || ! window.fetch ) {
		return;
	}

	const steps = root.querySelector( '[data-role="steps"]' );
	const message = root.querySelector( '[data-role="message"]' );
	const bar = root.querySelector( '[data-role="bar"]' );
	const percent = root.querySelector( '[data-role="percent"]' );
	const interval = parseInt( root.dataset.interval, 10 ) || 3000;
	let stopped = false;
	let failures = 0;

	const apply = ( state ) => {
		if ( steps && Array.isArray( state.steps ) ) {
			state.steps.forEach( ( step ) => {
				const item = steps.querySelector( '[data-step="' + step.key + '"]' );
				if ( ! item ) {
					return;
				}
				item.className = 'is-' + step.state;
				if ( 'current' === step.state ) {
					item.setAttribute( 'aria-current', 'step' );
				} else {
					item.removeAttribute( 'aria-current' );
				}
			} );
		}
		if ( message && state.message && message.textContent !== state.message ) {
			message.textContent = state.message;
		}
		if ( bar ) {
			const known = null !== state.percent && undefined !== state.percent;
			bar.classList.toggle( 'is-indeterminate', ! known );
			if ( known ) {
				bar.setAttribute( 'aria-valuenow', String( state.percent ) );
			} else {
				bar.removeAttribute( 'aria-valuenow' );
			}
			const fill = bar.firstElementChild;
			if ( fill ) {
				fill.style.width = known ? state.percent + '%' : '100%';
			}
		}
		if ( percent ) {
			percent.textContent = state.percent_label || '';
		}
	};

	const poll = () => {
		if ( stopped ) {
			return;
		}
		const body = new URLSearchParams( {
			action: root.dataset.action,
			nonce: root.dataset.nonce,
		} );
		fetch( root.dataset.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body,
		} )
			.then( ( response ) => {
				if ( 403 === response.status || 400 === response.status ) {
					// The page is too old (its nonce) or the session ended: stop asking.
					stopped = true;
					return null;
				}
				return response.json();
			} )
			.then( ( payload ) => {
				if ( ! payload || ! payload.success || ! payload.data ) {
					return;
				}
				failures = 0;
				const state = payload.data;
				if ( 'queued' === state.status || 'running' === state.status ) {
					apply( state );
					return;
				}
				// Done, failed, or gone: the server renders what comes next.
				// Without the "started" notice of the request that began it, nor the expired-link one.
				stopped = true;
				const next = new URL( window.location.href );
				next.searchParams.delete( 'export-result' );
				next.searchParams.delete( 'export_link' );
				window.location.assign( next.toString() );
			} )
			.catch( () => {
				failures += 1;
			} )
			.finally( () => {
				if ( ! stopped ) {
					// A longer wait after repeated failures, never giving up: the build goes on without the page.
					window.setTimeout( poll, interval * ( failures > 3 ? 4 : 1 ) );
				}
			} );
	};

	window.setTimeout( poll, interval );
}() );
