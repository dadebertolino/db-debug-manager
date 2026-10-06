// @ts-check
/**
 * Helper condivisi per gli E2E di DB Debug Manager.
 */
const path = require( 'path' );

/**
 * Sessione admin salvata da auth.setup.js. Da usare negli spec admin con
 * test.use( { storageState: ADMIN_STATE } ).
 */
const ADMIN_STATE = path.join( __dirname, '.auth', 'admin.json' );

const BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8888';

/**
 * Pagina del plugin e sue tab.
 */
const ADMIN_PAGE = '/wp-admin/tools.php?page=db-debug-manager';
const TABS = {
	config: `${ ADMIN_PAGE }&tab=config`,
	log: `${ ADMIN_PAGE }&tab=log`,
	queries: `${ ADMIN_PAGE }&tab=queries`,
	snapshots: `${ ADMIN_PAGE }&tab=snapshots`,
	emergency: `${ ADMIN_PAGE }&tab=emergency`,
};

/**
 * Accesso d'emergenza: URL diretto del plugin (niente WordPress) e
 * password impostata dal reset con `emergency: true`.
 */
const EMERGENCY_URL = '/wp-content/plugins/db-debug-manager/emergency.php';
const EMERGENCY_PASSWORD = 'Emergenza-E2E-2026';

/**
 * Riporta il sito allo stato baseline via endpoint REST della fixture.
 * Opzioni: wp_config, emergency, log (vedi dbdm_e2e_reset()).
 *
 * @param {import('@playwright/test').APIRequestContext} request
 * @param {object} [opts]
 */
async function resetState( request, opts = {} ) {
	const res = await request.post( '/?rest_route=/dbdm-e2e/v1/reset', { data: opts } );
	if ( ! res.ok() ) {
		throw new Error( `Reset E2E fallito (HTTP ${ res.status() }): ${ await res.text() }` );
	}
	return res.json();
}

/**
 * Stato lato server: wp-config.php, costanti effettive, permessi,
 * estensioni, cartella privata, opzioni.
 *
 * @param {import('@playwright/test').APIRequestContext} request
 */
async function getState( request ) {
	const res = await request.get( '/?rest_route=/dbdm-e2e/v1/state' );
	if ( ! res.ok() ) {
		throw new Error( `Lettura stato E2E fallita (HTTP ${ res.status() }): ${ await res.text() }` );
	}
	return res.json();
}

/**
 * Login su emergency.php in un contesto senza sessione WordPress.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} [password]
 */
async function emergencyLogin( page, password = EMERGENCY_PASSWORD ) {
	await page.goto( EMERGENCY_URL );
	await page.locator( 'input[name="password"]' ).fill( password );
	await Promise.all( [ page.waitForLoadState( 'load' ), page.getByRole( 'button', { name: 'Accedi' } ).click() ] );
}

module.exports = {
	BASE_URL,
	ADMIN_STATE,
	ADMIN_PAGE,
	TABS,
	EMERGENCY_URL,
	EMERGENCY_PASSWORD,
	resetState,
	getState,
	emergencyLogin,
};
