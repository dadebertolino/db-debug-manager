// @ts-check
const { test, expect } = require( '@playwright/test' );
const { EMERGENCY_URL, resetState, emergencyLogin } = require( './helpers' );

/**
 * Scenario "sito rotto": un plugin o il tema vanno in fatal a ogni
 * richiesta. Dall'emergency (senza WordPress) si accende il log, si trova
 * il colpevole, lo si disattiva e il sito torna a rispondere.
 */

const DASHBOARD = 'Emergency Dashboard';
const BROKEN_PLUGIN = 'dbdm-e2e-rotto/dbdm-e2e-rotto.php';

/**
 * Riga di una costante nella dashboard (nome esatto: WP_DEBUG non deve
 * prendere anche WP_DEBUG_LOG).
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} name
 */
function constantRow( page, name ) {
	return page.locator( 'tr' ).filter( { has: page.locator( 'code', { hasText: new RegExp( `^${ name }$` ) } ) } );
}

async function turnOn( page, name ) {
	await constantRow( page, name ).getByRole( 'button', { name: 'On' } ).click();
	await expect( page.locator( '.notice-ok' ) ).toContainText( `${ name } impostata a true` );
}

test.afterEach( async ( { request } ) => {
	await resetState( request );
} );

test( 'plugin in fatal: trovato nel log e disattivato, il sito torna su', async ( { browser, request } ) => {
	await resetState( request, { emergency: true, broken: 'plugin' } );
	expect( ( await request.get( '/' ) ).status() ).toBe( 500 );

	const page = await ( await browser.newContext() ).newPage();
	await emergencyLogin( page );
	await expect( page.getByRole( 'heading', { name: DASHBOARD } ) ).toBeVisible();

	// Log acceso dall'emergency, poi una visita che va in fatal.
	await turnOn( page, 'WP_DEBUG' );
	await turnOn( page, 'WP_DEBUG_LOG' );
	expect( ( await request.get( '/' ) ).status() ).toBe( 500 );
	await page.goto( EMERGENCY_URL );
	await expect( page.locator( 'pre.log' ).first() ).toContainText( 'dbdm_e2e_funzione_inesistente_del_plugin' );
	await expect( page.locator( 'pre.log' ).first() ).toContainText( 'dbdm-e2e-rotto.php' );

	// Disattivato solo lui.
	page.once( 'dialog', ( dialog ) => dialog.accept() );
	await page.locator( 'tr', { hasText: BROKEN_PLUGIN } ).getByRole( 'button', { name: 'Disattiva' } ).click();
	await expect( page.locator( '.notice-ok' ) ).toContainText( `Plugin disattivato: ${ BROKEN_PLUGIN }` );
	await expect( page.locator( 'tr', { hasText: 'db-debug-manager/db-debug-manager.php' } ) ).toBeVisible();

	expect( ( await request.get( '/' ) ).status() ).toBe( 200 );
} );

test( '"Disattiva tutti i plugin" rimette in piedi il sito', async ( { browser, request } ) => {
	await resetState( request, { emergency: true, broken: 'plugin' } );
	expect( ( await request.get( '/' ) ).status() ).toBe( 500 );

	const page = await ( await browser.newContext() ).newPage();
	await emergencyLogin( page );
	page.once( 'dialog', ( dialog ) => dialog.accept() );
	await page.getByRole( 'button', { name: /Disattiva tutti i plugin/ } ).click();
	await expect( page.locator( '.notice-ok' ) ).toContainText( 'Tutti i plugin sono stati disattivati.' );
	await expect( page.getByRole( 'heading', { name: /Plugin attivi \(0\)/ } ) ).toBeVisible();

	expect( ( await request.get( '/' ) ).status() ).toBe( 200 );
} );

test( 'tema in fatal: "Cambia a tema default" sceglie un tema sano (bug 28)', async ( { browser, request } ) => {
	await resetState( request, { emergency: true, broken: 'theme' } );
	expect( ( await request.get( '/' ) ).status() ).toBe( 500 );

	const page = await ( await browser.newContext() ).newPage();
	await emergencyLogin( page );
	await expect( page.getByText( 'dbdm-e2e-tema-rotto' ).first() ).toBeVisible();

	page.once( 'dialog', ( dialog ) => dialog.accept() );
	await page.getByRole( 'button', { name: /Cambia a tema default/ } ).click();
	await expect( page.locator( '.notice-ok' ) ).toContainText( /Tema cambiato a: twenty/ );
	await expect( page.locator( '.notice-ok' ) ).not.toContainText( 'dbdm-e2e-tema-rotto' );

	expect( ( await request.get( '/' ) ).status() ).toBe( 200 );
} );
