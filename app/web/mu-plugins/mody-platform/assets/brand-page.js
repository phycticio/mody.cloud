/**
 * Settings → Brand (Brand\BrandPage): Add and Remove for the lists of items
 * (products and services, values). Without this script the page shows the
 * saved items and one empty row, and saving adds another empty row.
 *
 * Plain script, no build step.
 */
( function () {
	'use strict';

	document.querySelectorAll( '.mody-brand__list' ).forEach( ( list ) => {
		const rows = list.querySelector( '.mody-brand__items-edit' );
		const template = list.querySelector( 'template' );
		const add = list.querySelector( '.mody-brand__add' );
		const max = parseInt( list.dataset.max, 10 ) || 1;
		const name = list.dataset.name;
		if ( ! rows || ! template || ! add || ! name ) {
			return;
		}
		let next = rows.children.length;

		const refresh = () => {
			add.hidden = rows.children.length >= max;
			rows.querySelectorAll( '.mody-brand__remove' ).forEach( ( button ) => {
				button.hidden = false;
			} );
		};

		add.addEventListener( 'click', () => {
			if ( rows.children.length >= max ) {
				return;
			}
			const row = template.content.firstElementChild.cloneNode( true );
			const index = next++;
			row.querySelectorAll( 'input' ).forEach( ( input ) => {
				input.name = input.name.replace( name + '[999]', name + '[' + index + ']' );
				input.value = '';
			} );
			rows.append( row );
			refresh();
			row.querySelector( 'input' ).focus();
		} );

		rows.addEventListener( 'click', ( event ) => {
			const button = event.target.closest( '.mody-brand__remove' );
			if ( ! button ) {
				return;
			}
			const row = button.closest( '.mody-brand__item' );
			const sibling = row.nextElementSibling || row.previousElementSibling;
			row.remove();
			refresh();
			( sibling ? sibling.querySelector( 'input' ) : add ).focus();
		} );

		refresh();
	} );
}() );
