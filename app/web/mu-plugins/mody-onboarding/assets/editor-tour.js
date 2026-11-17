/**
 * The editor tour in the Site Editor (ticket M1-03).
 *
 * Plain script, no build step, like the chat bubble. Shows the steps of
 * window.modyTour.steps one at a time: a card, and a spotlight on the
 * step's element, or the card alone in the middle when the element is not
 * there. Nothing under the tour is blocked: the spotlight lets clicks
 * through, so the member can use the chat during the "try it" steps, where
 * the tour waits for Mody's reply. All text is inserted with textContent.
 *
 * The step survives a reload (sessionStorage). Finishing or skipping sets the
 * member's onboarding stage to `done` (wp/v2/users/me).
 */
( function () {
	'use strict';

	const config = window.modyTour;
	const wp = window.wp;
	if ( ! config || ! Array.isArray( config.steps ) || ! config.steps.length || document.getElementById( 'mody-tour' ) ) {
		return;
	}
	const strings = config.strings;
	const steps = config.steps;
	const agent = window.modyAgent || null;
	const STORE = 'modyTourStep';
	const asked = 'start' === new URLSearchParams( window.location.search ).get( 'mody-tour' );

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

	function remember( value ) {
		try {
			window.sessionStorage.setItem( STORE, String( value ) );
		} catch ( e ) {}
	}

	function remembered() {
		try {
			return window.sessionStorage.getItem( STORE );
		} catch ( e ) {
			return null;
		}
	}

	// The tour's elements -------------------------------------------------------------

	const root = element( 'div', 'mody-tour' );
	root.id = 'mody-tour';
	const spot = element( 'div', 'mody-tour__spot' );
	spot.hidden = true;
	const card = element( 'section', 'mody-tour__card' );
	card.setAttribute( 'role', 'dialog' );
	card.setAttribute( 'aria-label', strings.label );
	card.setAttribute( 'aria-describedby', 'mody-tour-text' );

	const top = element( 'div', 'mody-tour__top' );
	const progress = element( 'span', 'mody-tour__progress' );
	const close = element( 'button', 'mody-tour__close', '×' );
	close.type = 'button';
	close.setAttribute( 'aria-label', strings.close );
	top.append( progress, close );

	const title = element( 'h2', 'mody-tour__title' );
	title.tabIndex = -1;
	const text = element( 'p', 'mody-tour__text' );
	text.id = 'mody-tour-text';
	const starters = element( 'div', 'mody-tour__starters' );
	const status = element( 'p', 'mody-tour__status' );
	status.setAttribute( 'aria-live', 'polite' );

	const buttons = element( 'div', 'mody-tour__buttons' );
	const skip = element( 'button', 'mody-tour__button mody-tour__button--link', strings.skip );
	skip.type = 'button';
	const back = element( 'button', 'mody-tour__button', strings.back );
	back.type = 'button';
	const next = element( 'button', 'mody-tour__button mody-tour__button--primary', strings.next );
	next.type = 'button';
	buttons.append( skip, element( 'span', 'mody-tour__spacer' ), back, next );

	card.append( top, title, text, starters, status, buttons );
	root.append( spot, card );

	// Where things are ----------------------------------------------------------------

	let index = 0;
	let target = null;
	let frame = 0;
	let placed = '';
	let observer = null;
	let answered = false;

	function visible( node ) {
		const rect = node.getBoundingClientRect();
		return rect.width > 0 && rect.height > 0;
	}

	function find( step ) {
		for ( const selector of step.target || [] ) {
			const node = document.querySelector( selector );
			if ( node && visible( node ) ) {
				return node;
			}
		}
		return null;
	}

	function onIdentity() {
		const query = new URLSearchParams( window.location.search );
		return '/identity' === query.get( 'p' ) && 'edit' !== query.get( 'canvas' );
	}

	// Back to the Identity screen, in place when the chat bubble can (M3-09).
	function toIdentity() {
		if ( onIdentity() ) {
			return;
		}
		const url = asked ? config.identity + '&mody-tour=start' : config.identity;
		if ( ! agent || 'function' !== typeof agent.navigate || ! agent.navigate( url ) ) {
			window.location.href = url;
		}
	}

	// Follows the step's element every frame: the Site Editor moves and replaces its parts.
	function place() {
		frame = window.requestAnimationFrame( place );
		const step = steps[ index ];
		if ( ! target || ! target.isConnected || ! visible( target ) ) {
			target = find( step );
		}
		const vw = window.innerWidth;
		const vh = window.innerHeight;
		const w = card.offsetWidth;
		const h = card.offsetHeight;
		if ( ! target ) {
			if ( 'center' !== placed ) {
				placed = 'center';
				spot.hidden = true;
				card.style.left = Math.max( 8, ( vw - w ) / 2 ) + 'px';
				card.style.top = Math.max( 8, ( vh - h ) / 2 ) + 'px';
			}
			return;
		}
		const r = target.getBoundingClientRect();
		const key = [ r.left, r.top, r.width, r.height, w, h, vw, vh ].map( Math.round ).join();
		if ( key === placed ) {
			return;
		}
		placed = key;

		const pad = 6;
		const left = Math.max( 2, r.left - pad );
		const topEdge = Math.max( 2, r.top - pad );
		spot.hidden = false;
		spot.style.left = left + 'px';
		spot.style.top = topEdge + 'px';
		spot.style.width = Math.min( vw - 2, r.right + pad ) - left + 'px';
		spot.style.height = Math.min( vh - 2, r.bottom + pad ) - topEdge + 'px';

		// Beside the element when there is room, else below or above, else at the bottom.
		const gap = 16;
		let x;
		let y;
		if ( r.right + gap + w <= vw - 8 ) {
			x = r.right + gap;
			y = r.top;
		} else if ( r.left - gap - w >= 8 ) {
			x = r.left - gap - w;
			y = r.top;
		} else if ( r.bottom + gap + h <= vh - 8 ) {
			x = r.left;
			y = r.bottom + gap;
		} else if ( r.top - gap - h >= 8 ) {
			x = r.left;
			y = r.top - gap - h;
		} else {
			x = ( vw - w ) / 2;
			y = vh - h - 24;
		}
		card.style.left = Math.min( Math.max( 8, x ), vw - w - 8 ) + 'px';
		card.style.top = Math.min( Math.max( 8, y ), vh - h - 8 ) + 'px';
	}

	// The "try it" steps: wait for Mody's reply to a message sent now ----------------

	function stopWaiting() {
		if ( observer ) {
			observer.disconnect();
			observer = null;
		}
	}

	function replied() {
		answered = true;
		stopWaiting();
		status.textContent = strings.answered;
		next.textContent = strings.next;
		next.classList.add( 'mody-tour__button--primary' );
	}

	function wait() {
		const log = document.getElementById( 'mody-agent-log' );
		answered = false;
		if ( ! log ) {
			replied();
			return;
		}
		let sent = false;
		status.textContent = strings.waiting;
		next.textContent = strings.skipStep;
		next.classList.remove( 'mody-tour__button--primary' );
		observer = new window.MutationObserver( ( mutations ) => {
			mutations.forEach( ( mutation ) => {
				mutation.addedNodes.forEach( ( node ) => {
					if ( ! ( node instanceof window.HTMLElement ) || answered ) {
						return;
					}
					if ( node.classList.contains( 'mody-agent__message--user' ) ) {
						sent = true;
						status.textContent = strings.thinking;
					} else if ( sent && node.classList.contains( 'mody-agent__message--assistant' ) && ! node.classList.contains( 'is-pending' ) ) {
						replied();
					}
				} );
			} );
		} );
		observer.observe( log, { childList: true } );
	}

	// Fills the chat's input with a starter, for the member to finish and send.
	function fill( starter ) {
		if ( agent && 'function' === typeof agent.openChat ) {
			agent.openChat();
		}
		const input = document.getElementById( 'mody-agent-input' );
		if ( ! input ) {
			return;
		}
		input.value = starter;
		input.focus();
		input.setSelectionRange( starter.length, starter.length );
	}

	// The steps ------------------------------------------------------------------------

	function show( i ) {
		index = i;
		remember( i );
		const step = steps[ i ];
		const last = i === steps.length - 1;

		progress.textContent = strings.progress.replace( '%1$d', String( i + 1 ) ).replace( '%2$d', String( steps.length ) );
		title.textContent = 'welcome' === step.id
			? strings.welcome.replace( '%1$s', config.name ).replace( '%2$s', config.site )
			: step.title;
		text.textContent = last && strings.trialEnd ? step.text + ' ' + strings.trialEnd : step.text;

		starters.replaceChildren();
		( step.starters || [] ).forEach( ( starter ) => {
			const button = element( 'button', 'mody-tour__starter', starter.trim() + ( /[\s:]$/.test( starter ) ? '…' : '' ) );
			button.type = 'button';
			button.addEventListener( 'click', () => fill( starter ) );
			starters.append( button );
		} );
		starters.hidden = ! starters.children.length;

		status.textContent = '';
		back.hidden = 0 === i;
		next.textContent = 0 === i ? strings.start : ( last ? strings.finish : strings.next );
		next.classList.add( 'mody-tour__button--primary' );
		skip.hidden = last;
		root.classList.toggle( 'is-light', 'reply' === step.wait );

		if ( 'chat' === step.open && agent && 'function' === typeof agent.openChat ) {
			agent.openChat();
		}
		if ( 'reply' === step.wait ) {
			wait();
		}

		target = null;
		placed = '';
		if ( 'reply' !== step.wait ) {
			title.focus( { preventScroll: true } );
		}
	}

	function go( delta ) {
		const step = steps[ index ];
		stopWaiting();
		const to = index + delta;
		if ( to >= steps.length ) {
			finish();
			return;
		}
		if ( to < 0 ) {
			return;
		}
		remember( to );
		if ( delta > 0 && 'identity' === step.leave ) {
			toIdentity();
		}
		show( to );
	}

	// Core's "Welcome to the site editor" guide would cover the tour (and loads its
	// pictures from s.w.org): the tour replaces it for this member. Saved per site.
	function quietCoreGuide() {
		try {
			wp.data.dispatch( 'core/preferences' ).set( 'core/edit-site', 'welcomeGuide', false );
		} catch ( e ) {}
	}

	function finish() {
		window.cancelAnimationFrame( frame );
		stopWaiting();
		quietCoreGuide();
		root.remove();
		remember( 'done' );
		if ( asked ) {
			// Without the parameter, a reload does not start the tour again.
			const url = new URL( window.location.href );
			url.searchParams.delete( 'mody-tour' );
			window.history.replaceState( window.history.state, '', url.pathname + url.search );
		}
		if ( config.pending && wp && wp.apiFetch ) {
			wp.apiFetch( {
				path: '/wp/v2/users/me',
				method: 'POST',
				data: { meta: { [ config.meta ]: config.done } },
			} ).catch( () => {} );
		}
	}

	next.addEventListener( 'click', () => go( 1 ) );
	back.addEventListener( 'click', () => go( -1 ) );
	skip.addEventListener( 'click', finish );
	close.addEventListener( 'click', finish );
	card.addEventListener( 'keydown', ( event ) => {
		if ( 'Escape' === event.key ) {
			event.stopPropagation();
			finish();
		}
	} );

	// Start ----------------------------------------------------------------------------

	const saved = remembered();
	if ( 'done' === saved && ! asked ) {
		return; // Finished in this tab already; the stage is being saved.
	}
	const resume = parseInt( saved, 10 );
	const first = ! asked && resume >= 0 && resume < steps.length ? resume : 0;

	if ( 0 === first ) {
		toIdentity();
	}

	// Once the Site Editor has drawn its layout (or after a few seconds).
	let tries = 0;
	( function begin() {
		if ( ! document.querySelector( '.edit-site-layout' ) && tries++ < 25 ) {
			window.setTimeout( begin, 200 );
			return;
		}
		quietCoreGuide(); // Now: the preferences store loads the saved ones first, replacing earlier changes.
		document.body.append( root );
		show( first );
		place();
	}() );
}() );
