// @ts-check
const { test, expect } = require( '@playwright/test' );
const { ADMIN_STATE, TABS, resetState, getState } = require( './helpers' );

/**
 * Costanti e debug.log dal pannello: scrittura di wp-config.php (bug 1–4, 8),
 * backup (bug 3), log nella cartella privata e non pubblico (bug 41).
 */

test.use( { storageState: ADMIN_STATE } );

async function saveConstants( page, constants ) {
	await page.goto( TABS.config );
	for ( const name of [ 'WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY', 'SCRIPT_DEBUG', 'SAVEQUERIES' ] ) {
		await page.locator( `input[name="dbdm[${ name }]"]` ).setChecked( constants.includes( name ) );
	}
	await Promise.all( [ page.waitForURL( /updated=/ ), page.getByRole( 'button', { name: 'Salva modifiche' } ).click() ] );
}

test( 'salvataggio: valori effettivi, backup dello stato precedente', async ( { page, request } ) => {
	await resetState( request );

	await saveConstants( page, [ 'WP_DEBUG', 'SAVEQUERIES' ] );

	await expect( page.getByText( 'Impostazioni salvate.' ) ).toBeVisible();
	const state = await getState( request );
	expect( state.constants ).toMatchObject( { WP_DEBUG: true, SAVEQUERIES: true, WP_DEBUG_LOG: false, WP_DEBUG_DISPLAY: false } );
	// Un solo backup, identico al file prima del salvataggio.
	expect( state.backup_sha1 ).toBe( state.golden_sha1 );
} );

test( 'wp-config.php da hosting: commenti e condizioni rispettati (bug 1, 4)', async ( { page, request } ) => {
	const before = await resetState( request, { wp_config: 'hosting' } );
	expect( before.wp_config ).toContain( "// impostato dall'hosting" );

	await saveConstants( page, [ 'WP_DEBUG', 'SCRIPT_DEBUG' ] );

	const state = await getState( request );
	// SAVEQUERIES non è nel wp-config.php di wp-env: resta non definita.
	expect( state.constants ).toMatchObject( { WP_DEBUG: true, SCRIPT_DEBUG: true, SAVEQUERIES: null } );
	expect( state.wp_config ).toContain( "define( 'WP_DEBUG', true ); // impostato dall'hosting" );
	expect( state.wp_config ).toContain( "defined( 'SCRIPT_DEBUG' ) || define( 'SCRIPT_DEBUG', true );" );
	// Il blocco commentato non è stato toccato né duplicato.
	expect( state.wp_config ).toContain( "/* define( 'WP_DEBUG', true );\n   define( 'SAVEQUERIES', true ); */" );
	expect( state.wp_config.match( /^\s*define\(\s*'WP_DEBUG',/gm ) ).toHaveLength( 1 );
} );

test( 'su un sito pulito si scrive solo ciò che cambia (bug 8)', async ( { page, request } ) => {
	const before = await resetState( request );

	await saveConstants( page, [] );

	expect( ( await getState( request ) ).wp_config ).toBe( before.wp_config );
} );

test.describe( 'debug.log (bug 41)', () => {

	test( 'attivato dal pannello va nella cartella privata e non è pubblico', async ( { page, request } ) => {
		await resetState( request );

		await saveConstants( page, [ 'WP_DEBUG', 'WP_DEBUG_LOG' ] );
		const state = await getState( request );
		expect( state.constants.WP_DEBUG_LOG ).toMatch( /\/dbdm-private-[0-9a-f]{16}\/debug\.log$/ );

		// Un avviso PHP finisce nel log privato, visibile nella tab Log.
		await request.get( '/?dbdm_e2e_notice=1' );
		await page.goto( TABS.log );
		await expect( page.locator( '#dbdm-log-viewer' ) ).toContainText( 'DBDM E2E avviso di prova' );

		// Da fuori: niente wp-content/debug.log, cartella privata negata.
		expect( ( await request.get( '/wp-content/debug.log' ) ).status() ).toBe( 404 );
		const privateUrl = '/' + state.private_dir + '/debug.log';
		expect( ( await request.get( privateUrl ) ).status() ).toBe( 403 );
	} );

	test( 'un log pubblico già attivo viene spostato al salvataggio', async ( { page, request } ) => {
		const before = await resetState( request, { wp_config: 'public_log' } );
		expect( before.constants.WP_DEBUG_LOG ).toBe( true );

		await saveConstants( page, [ 'WP_DEBUG', 'WP_DEBUG_LOG' ] );

		expect( ( await getState( request ) ).constants.WP_DEBUG_LOG ).toMatch( /\/dbdm-private-[0-9a-f]{16}\/debug\.log$/ );
	} );
} );
