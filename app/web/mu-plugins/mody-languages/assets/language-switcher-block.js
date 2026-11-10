/**
 * The language switcher in the editors: a server-rendered preview, and one
 * setting (show the current language). Plain script, no build step.
 */
( function ( wp ) {
	const { createElement: el } = wp.element;
	const { __ } = wp.i18n;
	const { useBlockProps, InspectorControls } = wp.blockEditor;
	const { PanelBody, ToggleControl } = wp.components;
	const ServerSideRender = wp.serverSideRender;

	wp.blocks.registerBlockType( 'mody/language-switcher', {
		edit( { attributes, setAttributes } ) {
			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Settings', 'mody_cloud' ) },
						el( ToggleControl, {
							label: __( 'Show the current language', 'mody_cloud' ),
							checked: attributes.showCurrent,
							onChange: ( value ) => setAttributes( { showCurrent: value } ),
						} )
					)
				),
				el( ServerSideRender, { block: 'mody/language-switcher', attributes, skipBlockSupportAttributes: true } )
			);
		},
		save: () => null,
	} );
} )( window.wp );
