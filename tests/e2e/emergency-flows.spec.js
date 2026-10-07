// @ts-check
const { test, expect } = require( '@playwright/test' );
const { EMERGENCY_URL, resetState, emergencyLogin } = require( './helpers' );

/**
 * Flussi dell'emergency nel browser: slug con apostrofo (bug 18), header
 * di sicurezza (35), logout solo con il modulo (38).
 */

const DASHBOARD = 'Emergency Dashboard';
const QUOTE_PLUGIN = "dbdm-e2e-l'apostrofo/dbdm-e2e-apostrofo.php";

test.afterEach( async ( { request } ) => {
	await resetState( request );
} );

test( 'un plugin con l\'apostrofo nello slug si disattiva (bug 18)', async ( { browser, request } ) => {
	await resetState( request, { emergency: true, quote_plugin: true } );
	const page = await ( await browser.newContext() ).newPage();
	await emergencyLogin( page );

	let message = '';
	page.once( 'dialog', ( dialog ) => {
		message = dialog.message();
		dialog.accept();
	} );
	await page.locator( 'tr', { hasText: QUOTE_PLUGIN } ).getByRole( 'button', { name: 'Disattiva' } ).click();

	expect( message ).toBe( `Disattivare ${ QUOTE_PLUGIN }?` );
	await expect( page.locator( '.notice-ok' ) ).toContainText( `Plugin disattivato: ${ QUOTE_PLUGIN }` );
	await expect( page.locator( 'tr', { hasText: QUOTE_PLUGIN } ) ).toHaveCount( 0 );
} );

test( 'header di sicurezza su ogni risposta (bug 35)', async ( { request } ) => {
	await resetState( request, { emergency: true } );
	const res = await request.get( EMERGENCY_URL );
	const headers = res.headers();

	expect( headers[ 'x-frame-options' ] ).toBe( 'DENY' );
	expect( headers[ 'content-security-policy' ] ).toContain( "frame-ancestors 'none'" );
	expect( headers[ 'x-robots-tag' ] ).toBe( 'noindex, nofollow' );
	expect( headers[ 'cache-control' ] ).toContain( 'no-store' );
	expect( headers[ 'referrer-policy' ] ).toBe( 'no-referrer' );
	expect( headers[ 'x-content-type-options' ] ).toBe( 'nosniff' );
	expect( headers[ 'set-cookie' ] ).toMatch( /dbdm_emergency=[^;]+;.*HttpOnly/i );
} );

test( 'un link ?a=logout non chiude la sessione, il pulsante sì (bug 38)', async ( { browser, request } ) => {
	await resetState( request, { emergency: true } );
	const page = await ( await browser.newContext() ).newPage();
	await emergencyLogin( page );

	await page.goto( `${ EMERGENCY_URL }?a=logout` );
	await expect( page.getByRole( 'heading', { name: DASHBOARD } ) ).toBeVisible();

	await page.getByRole( 'button', { name: 'Logout' } ).click();
	await expect( page.getByRole( 'heading', { name: 'Emergency Access' } ) ).toBeVisible();
	await page.goto( EMERGENCY_URL );
	await expect( page.getByRole( 'heading', { name: 'Emergency Access' } ) ).toBeVisible();
} );
