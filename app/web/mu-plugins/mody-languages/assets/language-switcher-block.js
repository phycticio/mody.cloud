/**
 * The language switcher in the editors: a server-rendered preview, and its
 * settings: the layout (links or a dropdown), what each language shows (its
 * name, its flag or both) and whether the current language is listed.
 * Plain script, no build step.
 */
( function ( wp ) {
	const { createElement: el } = wp.element;
	const { __ } = wp.i18n;
	const { useBlockProps, InspectorControls } = wp.blockEditor;
	const { PanelBody, SelectControl, ToggleControl } = wp.components;
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
						el( SelectControl, {
							label: __( 'Layout', 'mody_cloud' ),
							value: attributes.layout,
							options: [
								{ value: 'list', label: __( 'Links in a row', 'mody_cloud' ) },
								{ value: 'dropdown', label: __( 'Dropdown', 'mody_cloud' ) },
							],
							onChange: ( value ) => setAttributes( { layout: value } ),
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
						} ),
						el( 'div', { style: { height: '16px' } } ),
						el( SelectControl, {
							label: __( 'Each language shows', 'mody_cloud' ),
							value: attributes.display,
							options: [
								{ value: 'text', label: __( 'Its name', 'mody_cloud' ) },
								{ value: 'flag', label: __( 'Its flag', 'mody_cloud' ) },
								{ value: 'both', label: __( 'Its flag and name', 'mody_cloud' ) },
							],
							help:
								'flag' === attributes.display
									? __( 'Screen readers still read each language’s name, and it shows when the pointer rests on a flag.', 'mody_cloud' )
									: undefined,
							onChange: ( value ) => setAttributes( { display: value } ),
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
						} ),
						el( 'div', { style: { height: '16px' } } ),
						el( ToggleControl, {
							label:
								'dropdown' === attributes.layout
									? __( 'List the current language too', 'mody_cloud' )
									: __( 'Show the current language', 'mody_cloud' ),
							checked: attributes.showCurrent,
							onChange: ( value ) => setAttributes( { showCurrent: value } ),
							__nextHasNoMarginBottom: true,
						} )
					)
				),
				el( ServerSideRender, { block: 'mody/language-switcher', attributes, skipBlockSupportAttributes: true } )
			);
		},
		save: () => null,
	} );
} )( window.wp );
