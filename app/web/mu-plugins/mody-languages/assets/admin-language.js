/**
 * The working language in the Site Editor and the post editor: one entry per
 * choice in the ⋮ menu. Choosing one saves it and reloads the editor where
 * it was, so its lists show that language. Plain script, no build step.
 */
( function ( wp, data ) {
	if ( ! data || ! data.choices || ! wp.plugins || ! wp.editor || ! wp.editor.PluginMoreMenuItem ) {
		return;
	}
	const { createElement: el, Fragment } = wp.element;
	const { __, sprintf } = wp.i18n;

	wp.plugins.registerPlugin( 'mody-admin-language', {
		render() {
			return el(
				Fragment,
				null,
				data.choices.map( ( choice ) =>
					el(
						wp.editor.PluginMoreMenuItem,
						{
							key: choice.url,
							icon: choice.current ? 'yes' : 'translation',
							onClick() {
								window.location.href = choice.url + '&redirect=' + encodeURIComponent( window.location.href );
							},
						},
						/* translators: %s: a language's own name, or "All languages". */
						sprintf( __( 'Content language: %s', 'mody_cloud' ), choice.label )
					)
				)
			);
		},
	} );
} )( window.wp, window.modyAdminLanguage );
