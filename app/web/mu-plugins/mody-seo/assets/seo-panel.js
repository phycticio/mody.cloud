/**
 * Mody SEO: the "Search engines" panel of the editor's document sidebar, in
 * the post editor and the Site Editor (src/Panel.php loads it). It edits the
 * page's SEO title and SEO description; they are saved with the page.
 * Empty fields show what is used instead: the title WordPress builds from the
 * page's title, and the excerpt. Plain JS, no build step.
 */
( function ( wp, config ) {
	if ( ! wp || ! config || ! wp.plugins || ! wp.editor || ! wp.editor.PluginDocumentSettingPanel ) {
		return;
	}
	const el = wp.element.createElement;
	const { __, sprintf } = wp.i18n;
	const { TextControl, TextareaControl } = wp.components;
	const { useSelect, useDispatch } = wp.data;

	/**
	 * The `<title>` WordPress builds without an SEO title (Fields::automatic_title).
	 *
	 * @param {string} title The page's title.
	 * @param {number} id    The page's ID.
	 * @return {string} The title search results show.
	 */
	function automaticTitle( title, id ) {
		if ( id && id === config.frontPage ) {
			return config.tagline ? config.site + ' – ' + config.tagline : config.site;
		}
		return ( title || __( '(no title)', 'mody_cloud' ) ) + ' – ' + config.site;
	}

	/**
	 * The text under a field: its length, or what is used while it is empty.
	 *
	 * @param {string} value    The field.
	 * @param {string} advice   What works best.
	 * @param {string} fallback What is used while it is empty.
	 * @return {string} The help text.
	 */
	function help( value, advice, fallback ) {
		/* translators: 1: a number of characters, 2: advice on the length. */
		return value ? sprintf( __( '%1$d characters. %2$s', 'mody_cloud' ), value.length, advice ) : fallback;
	}

	function SearchPanel() {
		const page = useSelect( ( select ) => {
			const editor = select( 'core/editor' );
			return {
				type: editor.getCurrentPostType(),
				id: editor.getCurrentPostId(),
				title: editor.getEditedPostAttribute( 'title' ) || '',
				excerpt: editor.getEditedPostAttribute( 'excerpt' ) || '',
				meta: editor.getEditedPostAttribute( 'meta' ) || {},
			};
		}, [] );
		const { editPost } = useDispatch( 'core/editor' );
		if ( ! config.postTypes.includes( page.type ) ) {
			return null;
		}
		const title = page.meta[ config.titleKey ] || '';
		const description = page.meta[ config.descriptionKey ] || '';
		const automatic = automaticTitle( page.title, page.id );
		const excerpt = page.excerpt.replace( /<[^>]*>/g, '' ).replace( /\s+/g, ' ' ).trim();
		const set = ( key ) => ( value ) => editPost( { meta: { ...page.meta, [ key ]: value } } );

		return el(
			wp.editor.PluginDocumentSettingPanel,
			{ name: 'mody-seo', title: __( 'Search engines', 'mody_cloud' ), className: 'mody-seo-panel' },
			el( TextControl, {
				label: __( 'SEO title', 'mody_cloud' ),
				value: title,
				placeholder: automatic,
				maxLength: config.titleMax,
				onChange: set( config.titleKey ),
				help: help(
					title,
					__( 'Search results show about 60.', 'mody_cloud' ),
					__( 'Empty: search results show the one in grey, made from the title.', 'mody_cloud' )
				),
				__next40pxDefaultSize: true,
				__nextHasNoMarginBottom: true,
			} ),
			el( 'div', { style: { height: '16px' } } ),
			el( TextareaControl, {
				label: __( 'SEO description', 'mody_cloud' ),
				value: description,
				placeholder: excerpt,
				maxLength: config.descriptionMax,
				rows: 4,
				onChange: set( config.descriptionKey ),
				help: help(
					description,
					__( '70 to 160 work best.', 'mody_cloud' ),
					excerpt
						? __( 'Empty: search results show the excerpt, in grey.', 'mody_cloud' )
						: __( 'Empty, with no excerpt: search engines pick a snippet of the text.', 'mody_cloud' )
				),
				__nextHasNoMarginBottom: true,
			} )
		);
	}

	wp.plugins.registerPlugin( 'mody-seo', { render: SearchPanel } );
} )( window.wp, window.modySeo );
