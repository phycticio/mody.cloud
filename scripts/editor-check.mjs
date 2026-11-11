#!/usr/bin/env node
/**
 * Opens block markup in the real block editor and reports what the editor
 * thinks of it (tickets/agent-knows-gutenberg-spectra-settings.md, part 4).
 *
 *   PATH="$HOME/.nvm/versions/node/v22.22.3/bin:$PATH" \
 *     node scripts/editor-check.mjs <markup-file> [--keep]
 *
 * Saves the markup as a draft page on the main site (WP-CLI in the app
 * container), opens it in headless Chromium (Playwright's cached
 * chrome-headless-shell, driven over CDP) as the first super admin, waits
 * for the editor to load, and prints JSON:
 *
 *   { ok, blocks, invalid: [{ name, issues }], changed, missing: [names],
 *     errors: [console errors] }
 *
 * - `invalid`: blocks the editor marks invalid ("unexpected or invalid
 *   content"), with its validation messages.
 * - `changed`: the editor's own serialization differs from what was saved
 *   (it would mark the page as changed on load). Not an error by itself.
 * - `missing`: block names the editor does not know.
 *
 * The draft and the login session are removed at the end (`--keep` keeps
 * the draft). Local only: it refuses to run without the mody_cloud_app
 * container.
 */

import { execFileSync, spawn } from 'node:child_process';
import { readFileSync, mkdtempSync, rmSync, existsSync } from 'node:fs';
import { tmpdir, homedir } from 'node:os';
import { join } from 'node:path';

const file = process.argv[ 2 ];
const keep = process.argv.includes( '--keep' );
if ( ! file || ! existsSync( file ) ) {
	console.error( 'Usage: node scripts/editor-check.mjs <markup-file> [--keep]' );
	process.exit( 2 );
}
const markup = readFileSync( file, 'utf8' );

const wp = ( php ) => execFileSync( 'docker', [ 'exec', '-i', '-u', 'www-data', 'mody_cloud_app', 'wp', '--path=/var/www/html/wp', '--url=https://mody.local', 'eval-file', '-' ], { input: '<?php ' + php, encoding: 'utf8', stdio: [ 'pipe', 'pipe', 'ignore' ] } ).trim();

// The draft, and a login for the first super admin.
const setup = JSON.parse( wp( `
	$admin = get_user_by( 'login', get_super_admins()[0] );
	wp_set_current_user( $admin->ID );
	$id = wp_insert_post( wp_slash( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Editor check ' . time(), 'post_content' => base64_decode( '${ Buffer.from( markup, 'utf8' ).toString( 'base64' ) }' ) ) ) );
	$expiration = time() + 600;
	$token = WP_Session_Tokens::get_instance( $admin->ID )->create( $expiration );
	echo wp_json_encode( array(
		'id' => $id,
		'user' => $admin->ID,
		'token' => $token,
		'saved' => get_post( $id )->post_content,
		'cookies' => array(
			array( 'name' => SECURE_AUTH_COOKIE, 'value' => wp_generate_auth_cookie( $admin->ID, $expiration, 'secure_auth', $token ) ),
			array( 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( $admin->ID, $expiration, 'logged_in', $token ) ),
		),
	) );
` ) );

const cleanup = () => {
	wp( `
		WP_Session_Tokens::get_instance( ${ setup.user } )->destroy( ${ JSON.stringify( setup.token ) } );
		${ keep ? '' : `wp_delete_post( ${ setup.id }, true );` }
	` );
};

const chrome = join( homedir(), 'Library/Caches/ms-playwright/chromium_headless_shell-1223/chrome-headless-shell-mac-arm64/chrome-headless-shell' );
const profile = mkdtempSync( join( tmpdir(), 'mody-editor-check-' ) );
const port = 9300 + Math.floor( Math.random() * 500 );
const browser = spawn( chrome, [
	`--remote-debugging-port=${ port }`,
	'--host-resolver-rules=MAP *.mody.local 127.0.0.1, MAP mody.local 127.0.0.1',
	'--ignore-certificate-errors',
	`--user-data-dir=${ profile }`,
	'--window-size=1440,1000',
	'about:blank',
], { stdio: 'ignore' } );

const sleep = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) );
let result = { ok: false, error: 'not run' };
try {
	let target;
	for ( let i = 0; i < 50 && ! target; i++ ) {
		await sleep( 200 );
		try {
			target = ( await ( await fetch( `http://127.0.0.1:${ port }/json/list` ) ).json() ).find( ( t ) => 'page' === t.type );
		} catch ( e ) {}
	}
	const socket = new WebSocket( target.webSocketDebuggerUrl );
	await new Promise( ( resolve ) => socket.addEventListener( 'open', resolve ) );
	let next = 0;
	const pending = new Map();
	const errors = [];
	socket.addEventListener( 'message', ( event ) => {
		const message = JSON.parse( event.data );
		if ( message.id && pending.has( message.id ) ) {
			pending.get( message.id )( message );
			pending.delete( message.id );
		} else if ( 'Runtime.exceptionThrown' === message.method ) {
			errors.push( message.params.exceptionDetails.text + ' ' + ( message.params.exceptionDetails.exception?.description || '' ).slice( 0, 200 ) );
		} else if ( 'Runtime.consoleAPICalled' === message.method && 'error' === message.params.type ) {
			errors.push( message.params.args.map( ( a ) => a.value ?? a.description ?? '' ).join( ' ' ).slice( 0, 300 ) );
		}
	} );
	const send = ( method, params = {} ) => new Promise( ( resolve ) => {
		const id = ++next;
		pending.set( id, resolve );
		socket.send( JSON.stringify( { id, method, params } ) );
	} );
	const evaluate = async ( expression ) => ( await send( 'Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true } ) ).result?.result?.value;

	await send( 'Runtime.enable' );
	await send( 'Network.enable' );
	for ( const cookie of setup.cookies ) {
		await send( 'Network.setCookie', { ...cookie, domain: 'mody.local', path: '/', secure: true, httpOnly: true } );
	}
	await send( 'Page.navigate', { url: `https://mody.local/wp-admin/post.php?post=${ setup.id }&action=edit` } );

	// Wait for the editor to hold the page's blocks.
	let ready = false;
	for ( let i = 0; i < 120 && ! ready; i++ ) {
		await sleep( 500 );
		ready = await evaluate( `!! ( window.wp && wp.data && wp.data.select( 'core/editor' ) && wp.data.select( 'core/editor' ).getCurrentPostId() === ${ setup.id } && wp.data.select( 'core/block-editor' ).getBlocks().length )` );
	}
	await sleep( 1500 ); // Late block registrations and fixes.
	const report = await evaluate( `( () => {
		const all = [];
		const walk = ( blocks ) => blocks.forEach( ( b ) => { all.push( b ); walk( b.innerBlocks ); } );
		walk( wp.data.select( 'core/block-editor' ).getBlocks() );
		const invalid = all.filter( ( b ) => ! b.isValid ).map( ( b ) => ( {
			name: b.name,
			issues: ( b.validationIssues || [] ).map( ( i ) => [ i.log ? i.log.join ? i.log.join( ' ' ) : String( i.log ) : '', ...( i.args || [] ).map( String ) ].join( ' ' ).slice( 0, 400 ) ),
		} ) );
		const missing = all.filter( ( b ) => 'core/missing' === b.name ).map( ( b ) => b.attributes.originalName );
		const serialized = wp.blocks.serialize( wp.data.select( 'core/block-editor' ).getBlocks() );
		return { blocks: all.length, invalid, missing, serialized };
	} )()` );
	if ( ! ready || ! report ) {
		result = { ok: false, error: ready ? 'no report' : 'the editor did not load the page', errors };
	} else {
		const normalize = ( s ) => s.replace( /\s+/g, ' ' ).trim();
		result = {
			ok: 0 === report.invalid.length && 0 === report.missing.length,
			blocks: report.blocks,
			invalid: report.invalid,
			missing: report.missing,
			changed: normalize( report.serialized ) !== normalize( setup.saved ),
			editor: report.serialized !== setup.saved ? report.serialized.slice( 0, 4000 ) : undefined,
			errors,
		};
	}
	socket.close();
} catch ( error ) {
	result = { ok: false, error: String( error ) };
} finally {
	browser.kill();
	cleanup(); // First: the draft and the session must go even if the rest fails.
	// Chromium may still be writing its profile as it exits (ENOTEMPTY on a busy machine).
	for ( let i = 0; i < 5; i++ ) {
		try {
			rmSync( profile, { recursive: true, force: true } );
			break;
		} catch ( e ) {
			await sleep( 500 );
		}
	}
}
console.log( JSON.stringify( result, null, 2 ) );
process.exit( result.ok ? 0 : 1 );
