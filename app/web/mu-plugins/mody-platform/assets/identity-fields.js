/**
 * Mission and tone of voice on the Site Editor's Identity screen (ticket M2-08).
 *
 * WordPress 7.1's Identity screen (site-editor.php?p=/identity) has a fixed
 * list of fields and no slot to add one. These two are real site settings
 * (Brand\BrandSettings, registered in REST), so they belong to the same
 * `root/site` entity the screen edits: this script renders them under the
 * screen's form and edits that entity, and the Site Editor's Save saves them
 * with the title and tagline. When the screen's markup is not found it does
 * nothing; Settings → General has the same two fields.
 *
 * Plain script, no build step, like the chat bubble.
 */
( function () {
	'use strict';

	const config = window.modyIdentity;
	const wp = window.wp;
	if ( ! config || ! wp || ! wp.element || ! wp.components || ! wp.data ) {
		return;
	}
	const el = wp.element.createElement;
	const { TextareaControl, RadioControl } = wp.components;
	const { useSelect, useDispatch } = wp.data;
	const ID = 'mody-identity-fields';

	function Fields() {
		const site = useSelect( ( select ) => select( 'core' ).getEditedEntityRecord( 'root', 'site' ) || {}, [] );
		const { editEntityRecord } = useDispatch( 'core' );
		const edit = ( changes ) => editEntityRecord( 'root', 'site', undefined, changes );
		return el(
			'div',
			{ className: 'mody-identity-fields' },
			el( TextareaControl, {
				label: config.strings.mission,
				help: config.strings.missionHelp,
				value: site[ config.mission ] ?? '',
				rows: 3,
				onChange: ( value ) => edit( { [ config.mission ]: value.replace( /\s+/g, ' ' ).slice( 0, config.maxMission ) } ),
				__nextHasNoMarginBottom: true,
			} ),
			el(
				'div',
				{ style: { marginTop: '24px' } }, // The same gap as between WordPress's fields.
				el( RadioControl, {
					label: config.strings.tone,
					help: config.strings.toneHelp,
					selected: site[ config.tone ] || config.defaultTone,
					options: Object.keys( config.tones ).map( ( value ) => ( { value, label: config.tones[ value ] } ) ),
					onChange: ( value ) => edit( { [ config.tone ]: value } ),
				} )
			)
		);
	}

	// The Identity screen's form: WordPress's DataForm fields, on the /identity route.
	function identityForm() {
		if ( '/identity' !== new URLSearchParams( window.location.search ).get( 'p' ) ) {
			return null;
		}
		const fields = document.querySelectorAll( '.dataforms-layouts-regular__field' );
		return fields.length ? fields[ fields.length - 1 ].parentElement : null;
	}

	let root = null;
	function mount() {
		const form = identityForm();
		const existing = document.getElementById( ID );
		if ( ! form ) {
			return;
		}
		if ( existing && form.contains( existing ) ) {
			return;
		}
		if ( existing ) {
			existing.remove(); // The screen was rendered again.
		}
		const container = document.createElement( 'div' );
		container.id = ID;
		form.append( container );
		root = wp.element.createRoot( container );
		root.render( el( Fields ) );
	}

	// The Site Editor renders and replaces its screens as the member moves between them.
	new window.MutationObserver( mount ).observe( document.body, { childList: true, subtree: true } );
	mount();
}() );
