// @ts-check
const { test, expect } = require( '@playwright/test' );
const { ADMIN_STATE, TABS, resetState } = require( './helpers' );

/**
 * Pannello: viewer del log (bug 43, 44, 57), download (53), avvisi decisi
 * dall'esito e mai dalla query string (48, 54).
 */

test.use( { storageState: ADMIN_STATE } );

/**
 * Accoda byte a wp-content/debug.log (quello letto dal pannello con il
 * wp-config.php di wp-env).
 *
 * @param {import('@playwright/test').APIRequestContext} request
 * @param {Buffer|string} bytes
 */
async function appendLog( request, bytes ) {
	const data = Buffer.isBuffer( bytes ) ? bytes : Buffer.from( bytes, 'utf8' );
	await request.post( '/?rest_route=/dbdm-e2e/v1/log-append', { data: { base64: data.toString( 'base64' ) } } );
}

test.describe( 'viewer del log', () => {

	test( '"Aggiorna" mostra le righe nuove, anche con un filtro (bug 43)', async ( { page, request } ) => {
		await resetState( request, { log: 'riga iniziale\n' } );
		await page.goto( TABS.log );
		const viewer = page.locator( '#dbdm-log-viewer' );
		await expect( viewer ).toContainText( 'riga iniziale' );

		await appendLog( request, 'PHP Warning: riga nuova\n' );
		await page.getByRole( 'button', { name: /Aggiorna/ } ).click();
		await expect( viewer ).toContainText( 'riga nuova' );

		await page.locator( '#dbdm-log-filter' ).fill( 'warning' );
		await expect( viewer ).toHaveText( 'PHP Warning: riga nuova' );
	} );

	test( 'auto-refresh con righe nuove (bug 43)', async ( { page, request } ) => {
		await resetState( request, { log: 'prima\n' } );
		await page.goto( TABS.log );
		await page.locator( '#dbdm-auto-refresh' ).check();

		await appendLog( request, 'arrivata dopo\n' );
		await expect( page.locator( '#dbdm-log-viewer' ) ).toContainText( 'arrivata dopo', { timeout: 12000 } );
	} );

	test( 'senza log al caricamento, il log creato dopo compare (bug 57)', async ( { page, request } ) => {
		await resetState( request );
		await page.goto( TABS.log );
		await expect( page.locator( '#dbdm-log-viewer' ) ).toHaveText( 'Il log è vuoto.' );

		await appendLog( request, 'primo errore\n' );
		await page.getByRole( 'button', { name: /Aggiorna/ } ).click();
		await expect( page.locator( '#dbdm-log-viewer' ) ).toContainText( 'primo errore' );
		await expect( page.locator( '#dbdm-log-missing' ) ).toHaveCount( 0 );
	} );

	test( 'sessione scaduta segnalata (bug 57)', async ( { page, request } ) => {
		await resetState( request, { log: 'x\n' } );
		await page.goto( TABS.log );
		await page.evaluate( () => {
			// @ts-ignore
			window.DBDM.nonce = 'scaduto';
		} );
		await page.getByRole( 'button', { name: /Aggiorna/ } ).click();
		await expect( page.locator( '#dbdm-log-status' ) ).toHaveText( 'Sessione scaduta: ricarica la pagina.' );
	} );

	test( 'UTF-8 non valido non svuota il viewer (bug 44)', async ( { page, request } ) => {
		// Latin-1: "caffè" con è = 0xE8.
		await resetState( request );
		await appendLog( request, Buffer.from( [ 0x63, 0x61, 0x66, 0x66, 0xe8, 0x20, 0x6f, 0x6b, 0x0a ] ) );

		await page.goto( TABS.log );
		await expect( page.locator( '#dbdm-log-viewer' ) ).toHaveText( 'caff� ok' );

		await page.getByRole( 'button', { name: /Aggiorna/ } ).click();
		await expect( page.locator( '#dbdm-log-status' ) ).toHaveText( '' );
		await expect( page.locator( '#dbdm-log-viewer' ) ).toHaveText( 'caff� ok' );
	} );
} );

test( 'download del log: contenuto e header (bug 53)', async ( { page, request } ) => {
	await resetState( request, { log: 'riga 1\nriga 2\n' } );
	await page.goto( TABS.log );
	const nonce = await page.locator( 'form:has(input[value="dbdm_download_log"]) input[name="_wpnonce"]' ).inputValue();

	const res = await page.request.post( '/wp-admin/admin-post.php', { form: { action: 'dbdm_download_log', _wpnonce: nonce } } );

	expect( res.status() ).toBe( 200 );
	const headers = res.headers();
	expect( headers[ 'x-content-type-options' ] ).toBe( 'nosniff' );
	expect( headers[ 'content-disposition' ] ).toMatch( /^attachment; filename="debug-\d{8}-\d{6}\.log"$/ );
	expect( headers[ 'content-length' ] ).toBe( '14' );
	expect( await res.text() ).toBe( 'riga 1\nriga 2\n' );
} );

test.describe( 'avvisi del pannello', () => {

	test( 'un messaggio nella query string non viene mostrato (bug 54)', async ( { page, request } ) => {
		await resetState( request );
		await page.goto( `${ TABS.config }&updated=0&err=Messaggio%20falso%20dal%20link&snap_err=Altro%20falso` );

		await expect( page.getByText( 'Messaggio falso dal link' ) ).toHaveCount( 0 );
		await expect( page.getByText( 'Altro falso' ) ).toHaveCount( 0 );
	} );

	test( '"eliminato" solo se lo snapshot esisteva (bug 48)', async ( { page, request } ) => {
		await resetState( request );
		await page.goto( TABS.snapshots );
		await page.getByRole( 'button', { name: 'Crea snapshot adesso' } ).click();
		await expect( page.getByText( 'Snapshot creato.' ) ).toBeVisible();

		// Un id che non esiste (eliminato nel frattempo da un altro admin).
		const form = page.locator( 'form:has(input[name="action"][value="dbdm_delete_snapshot"])' ).first();
		await form.locator( 'input[name="id"]' ).evaluate( ( el ) => {
			// @ts-ignore
			el.value = 'snap_inesistente';
		} );
		page.once( 'dialog', ( d ) => d.accept() );
		await form.locator( 'button[type="submit"]' ).click();

		await expect( page.getByText( 'Snapshot non trovato.' ) ).toBeVisible();
		await expect( page.getByText( 'Snapshot eliminato.' ) ).toHaveCount( 0 );
	} );
} );
