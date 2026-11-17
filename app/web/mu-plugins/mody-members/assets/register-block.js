/**
 * The registration block in the editor (ticket M1-04).
 *
 * Plain script, no build step. The block's settings and styles come from
 * blocks/register/block.json, registered on the server. Here it gets its edit
 * view: a still preview of the chat inside the block's wrapper (useBlockProps),
 * with the same classes as the real chat, so register.css styles it and every
 * style change shows at once. The real chat runs only on the front end
 * (register.js). The block is dynamic: nothing is saved but its attributes.
 */
( function ( wp ) {
	'use strict';

	const { registerBlockType } = wp.blocks;
	const { useBlockProps, InspectorControls } = wp.blockEditor;
	const { PanelBody } = wp.components;
	const { createElement: el, Fragment } = wp.element;
	const { useSelect } = wp.data;
	const { __ } = wp.i18n;
	const settings = window.modyRegisterBlock || { strings: {} };
	const strings = settings.strings;

	/**
	 * A style value as CSS, as Registration::css_value() does on the server:
	 * `var:preset|color|accent` becomes `var(--wp--preset--color--accent)`.
	 *
	 * @param {*} value A value from the block's attributes or the site's styles.
	 * @return {string|undefined} The CSS value, or undefined.
	 */
	function cssValue( value ) {
		if ( 'string' !== typeof value || '' === value.trim() ) {
			return undefined;
		}
		const preset = value.trim().match( /^var:preset\|([a-z0-9-]+)\|([a-z0-9-]+)$/i );
		const css = preset ? 'var(--wp--preset--' + preset[ 1 ] + '--' + preset[ 2 ] + ')' : value.trim();
		return /^[\w\s#%(),.+\-\/]+$/.test( css ) && ! /url\s*\(|expression/i.test( css ) ? css : undefined;
	}

	// The site's button colors (Styles → Blocks → Button, or the theme's).
	function useSiteButtonColors() {
		const { user, theme } = useSelect( ( select ) => {
			const core = select( 'core' );
			const id = core.__experimentalGetCurrentGlobalStylesId ? core.__experimentalGetCurrentGlobalStylesId() : null;
			const record = id ? core.getEditedEntityRecord( 'root', 'globalStyles', id ) : null;
			const base = core.__experimentalGetCurrentThemeBaseGlobalStyles ? core.__experimentalGetCurrentThemeBaseGlobalStyles() : null;
			return {
				user: record && record.styles && record.styles.elements && record.styles.elements.button ? record.styles.elements.button.color : undefined,
				theme: base && base.styles && base.styles.elements && base.styles.elements.button ? base.styles.elements.button.color : undefined,
			};
		}, [] );
		return Object.assign( {}, theme || {}, user || {} );
	}

	function message( who, text ) {
		return el(
			'div',
			{ className: 'mody-register__message mody-register__message--' + who },
			el( 'span', { className: 'mody-register__who' }, 'you' === who ? strings.you : strings.mody ),
			el( 'p', { className: 'mody-register__text' }, text )
		);
	}

	function Edit( { attributes } ) {
		const style = attributes.style || {};
		const site = useSiteButtonColors();

		// What WordPress does not turn into CSS for this block (Registration::wrapper_attributes()).
		const vars = {};
		let gap = style.spacing ? style.spacing.blockGap : undefined;
		if ( gap && 'object' === typeof gap ) {
			gap = gap.top;
		}
		if ( cssValue( gap ) ) {
			vars[ '--mody-register-gap' ] = cssValue( gap );
		}
		const own = ( style.elements && style.elements.button && style.elements.button.color ) || {};
		const colors = own.background || own.gradient ? own : site;
		const background = cssValue( colors.gradient || colors.background );
		const text = cssValue( own.text || site.text );
		if ( background ) {
			vars[ '--mody-register-you-background' ] = background;
		}
		if ( text ) {
			vars[ '--mody-register-you-text' ] = text;
		}
		const blockProps = useBlockProps( { className: 'mody-register', style: vars } );

		if ( ! settings.isMainSite ) {
			return el( 'div', blockProps, el( 'p', null, strings.notMain ) );
		}
		return el(
			Fragment,
			null,
			! settings.isOpen &&
				el( InspectorControls, null, el( PanelBody, { title: __( 'Registration', 'mody_cloud' ) }, el( 'p', null, strings.closed ) ) ),
			el(
				'div',
				blockProps,
				el(
					'div',
					{ className: 'mody-register__chat', 'aria-hidden': 'true', style: { pointerEvents: 'none' } },
					el(
						'div',
						{ className: 'mody-register__log' },
						message( 'mody', strings.intro ),
						message( 'mody', strings.askName ),
						message( 'you', strings.sampleName ),
						message( 'mody', ( strings.askEmail || '' ).replace( '%s', strings.sampleName ) )
					),
					el(
						'div',
						{ className: 'mody-register__composer' },
						el(
							'span',
							{ className: 'mody-register__address' },
							el( 'input', { type: 'email', placeholder: strings.placeholder, readOnly: true, tabIndex: -1 } )
						),
						el( 'button', { type: 'button', className: 'wp-element-button', tabIndex: -1 }, strings.send )
					)
				)
			)
		);
	}

	registerBlockType( 'mody/register', {
		edit: Edit,
		save: () => null,
	} );
}( window.wp ) );
