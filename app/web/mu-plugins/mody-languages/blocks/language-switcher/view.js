/**
 * The language switcher's dropdown on the front end. It is a <details>
 * element, so it opens and closes without this script; this closes it on a
 * click outside and on Escape (focus back on its button), and hangs its list
 * from the button's start when it fits on the screen (without this script,
 * from its end, which stays on screen at the end of a header). Plain script,
 * no build step (tickets/language-switcher-settings.md).
 */
( function () {
	const OPEN = '.wp-block-mody-language-switcher__dropdown[open]';

	document.addEventListener( 'click', ( event ) => {
		document.querySelectorAll( OPEN ).forEach( ( dropdown ) => {
			if ( ! dropdown.contains( event.target ) ) {
				dropdown.open = false;
			}
		} );
	} );

	// `toggle` does not bubble: listen while it goes down.
	document.addEventListener(
		'toggle',
		( event ) => {
			const dropdown = event.target;
			if ( ! ( dropdown instanceof HTMLDetailsElement ) || ! dropdown.matches( OPEN ) ) {
				return;
			}
			const list = dropdown.querySelector( 'ul' );
			dropdown.classList.remove( 'opens-start' );
			const fits = 'rtl' === getComputedStyle( dropdown ).direction
				? dropdown.getBoundingClientRect().right - list.offsetWidth >= 8
				: dropdown.getBoundingClientRect().left + list.offsetWidth <= document.documentElement.clientWidth - 8;
			dropdown.classList.toggle( 'opens-start', fits );
		},
		true
	);

	document.addEventListener( 'keydown', ( event ) => {
		if ( 'Escape' !== event.key || ! ( event.target instanceof Element ) ) {
			return;
		}
		const dropdown = event.target.closest( OPEN );
		if ( dropdown ) {
			dropdown.open = false;
			dropdown.querySelector( 'summary' ).focus();
		}
	} );
} )();
