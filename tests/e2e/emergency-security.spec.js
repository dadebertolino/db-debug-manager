// @ts-check
const { test, expect, request: playwrightRequest } = require( '@playwright/test' );
const { BASE_URL, ADMIN_STATE, TABS, EMERGENCY_URL, EMERGENCY_PASSWORD, resetState, getState, emergencyLogin } = require( './helpers' );

/**
 * Accesso d'emergenza (emergency.php, senza WordPress): bug di priorità A
 * della Fase A. Ogni test usa contesti del browser senza sessione WordPress.
 */

const DASHBOARD = /Emergency Dashboard/;

/**
 * Contesto API con una propria sessione emergency: GET della pagina di
 * login e token CSRF letto dal form.
 *
 * @param {object} [headers]
 */
async function loginSession( headers = {} ) {
	const ctx = await playwrightRequest.newContext( { baseURL: BASE_URL, extraHTTPHeaders: headers } );
	const html = await ( await ctx.get( EMERGENCY_URL ) ).text();
	const csrf = ( html.match( /name="csrf" value="([0-9a-f]+)"/ ) || [] )[ 1 ];
	return { ctx, csrf };
}

async function postPassword( session, password ) {
	const res = await session.ctx.post( EMERGENCY_URL, { form: { csrf: session.csrf, password }, maxRedirects: 0 } );
	return { status: res.status(), body: await res.text() };
}

test.describe( 'credenziali e cartella privata', () => {

	test( 'funziona con il wp-config.php di Docker/wp-env (getenv_docker, bug 10)', async ( { browser, request } ) => {
		const state = await resetState( request, { emergency: true } );
		expect( state.wp_config ).toContain( 'getenv_docker' );

		const page = await ( await browser.newContext() ).newPage();
		await emergencyLogin( page );

		await expect( page.getByRole( 'heading', { name: DASHBOARD } ) ).toBeVisible();
	} );

	test( 'cartella privata in wp-content, non nel plugin (bug 39)', async ( { request } ) => {
		const state = await resetState( request, { emergency: true } );

		expect( state.private_dir ).toMatch( /^wp-content\/dbdm-private-[0-9a-f]{16}$/ );
		expect( Object.keys( state.private ) ).toEqual( [ state.private_dir ] );
	} );

	test( 'senza cartella privata nessun ripiego su private/ (bug 15)', async ( { request } ) => {
		// Come dopo un aggiornamento via FTP: password e abilitazione nel DB,
		// cartella privata mai creata.
		await resetState( request );
		await request.post( '/?rest_route=/dbdm-e2e/v1/emergency-password', { data: { password: EMERGENCY_PASSWORD, enabled: true } } );

		const res = await request.get( EMERGENCY_URL );

		expect( await res.text() ).toContain( 'Cartella privata del plugin non trovata' );
		expect( ( await getState( request ) ).private ).toEqual( {} );
	} );
} );

test.describe( 'sessione', () => {

	test.beforeEach( async ( { request } ) => {
		await resetState( request, { emergency: true } );
	} );

	test( 'nuovo ID di sessione al login: un ID imposto non viene autenticato (bug 12)', async ( { browser } ) => {
		const planted = 'dbdmfixation0123456789abcdef';
		const victim = await browser.newContext();
		await victim.addCookies( [ { name: 'dbdm_emergency', value: planted, url: BASE_URL + EMERGENCY_URL } ] );
		const page = await victim.newPage();

		await emergencyLogin( page );
		await expect( page.getByRole( 'heading', { name: DASHBOARD } ) ).toBeVisible();
		const cookie = ( await victim.cookies() ).find( ( c ) => c.name === 'dbdm_emergency' );
		expect( cookie.value ).not.toBe( planted );

		// L'attaccante con l'ID imposto non vede la dashboard.
		const attacker = await browser.newContext();
		await attacker.addCookies( [ { name: 'dbdm_emergency', value: planted, url: BASE_URL + EMERGENCY_URL } ] );
		const attackerPage = await attacker.newPage();
		await attackerPage.goto( EMERGENCY_URL );
		await expect( attackerPage.getByRole( 'heading', { name: 'Emergency Access' } ) ).toBeVisible();
	} );

	test( 'il cambio password chiude le sessioni aperte (bug 13)', async ( { browser, request } ) => {
		const page = await ( await browser.newContext() ).newPage();
		await emergencyLogin( page );
		await expect( page.getByRole( 'heading', { name: DASHBOARD } ) ).toBeVisible();

		await request.post( '/?rest_route=/dbdm-e2e/v1/emergency-password', { data: { password: 'Nuova-Password-2026' } } );
		await page.reload();

		await expect( page.getByRole( 'heading', { name: 'Emergency Access' } ) ).toBeVisible();
	} );

	test( 'disattivare il plugin spegne l\'emergency e chiude le sessioni (bug 14)', async ( { browser, request } ) => {
		const page = await ( await browser.newContext() ).newPage();
		await emergencyLogin( page );
		await expect( page.getByRole( 'heading', { name: DASHBOARD } ) ).toBeVisible();

		const state = await request.post( '/?rest_route=/dbdm-e2e/v1/deactivate' ).then( ( r ) => r.json() );
		expect( state.plugin_active ).toBe( false );
		await page.reload();

		await expect( page.getByText( 'L\'accesso emergency è disattivato' ) ).toBeVisible();
		await resetState( request );
		expect( ( await getState( request ) ).plugin_active ).toBe( true );
	} );
} );

test.describe( 'limite dei tentativi', () => {

	test.beforeEach( async ( { request } ) => {
		await resetState( request, { emergency: true } );
	} );

	test( '20 tentativi in parallelo: al massimo 5 arrivano alla verifica (bug 11)', async () => {
		const sessions = await Promise.all( Array.from( { length: 20 }, () => loginSession() ) );

		const results = await Promise.all( sessions.map( ( s ) => postPassword( s, 'password-sbagliata' ) ) );

		const wrong = results.filter( ( r ) => r.body.includes( 'Password errata' ) ).length;
		const blocked = results.filter( ( r ) => r.body.includes( 'Troppi tentativi' ) ).length;
		expect( wrong ).toBeLessThanOrEqual( 5 );
		expect( wrong + blocked ).toBe( 20 );
		await Promise.all( sessions.map( ( s ) => s.ctx.dispose() ) );

		// Anche la password giusta viene respinta durante il blocco.
		const late = await loginSession();
		expect( ( await postPassword( late, EMERGENCY_PASSWORD ) ).body ).toContain( 'Troppi tentativi' );
		await late.ctx.dispose();
	} );

	test( 'CF-Connecting-IP falsificato non aggira il blocco (bug 16)', async ( { request } ) => {
		await resetState( request, { emergency: { trust_proxy: true } } );

		const results = [];
		for ( let i = 0; i < 7; i++ ) {
			const s = await loginSession( { 'CF-Connecting-IP': `198.51.100.${ i + 1 }` } );
			results.push( ( await postPassword( s, 'sbagliata' ) ).body );
			await s.ctx.dispose();
		}

		expect( results.filter( ( b ) => b.includes( 'Password errata' ) ) ).toHaveLength( 5 );
		expect( results.slice( 5 ).every( ( b ) => b.includes( 'Troppi tentativi' ) ) ).toBe( true );
	} );

	test( 'input non stringa: nessun errore 500 (bug 33)', async () => {
		const s = await loginSession();
		const res = await s.ctx.post( EMERGENCY_URL, { form: { 'csrf[]': 'x', 'password[]': 'y' } } );
		expect( res.status() ).toBe( 200 );
		await s.ctx.dispose();
	} );
} );

test.describe( 'ripristino snapshot dall\'emergency (bug 17)', () => {

	test( 'percorsi non validi e tema senza padre vengono scartati', async ( { browser, request } ) => {
		await resetState( request, {
			emergency: true,
			themes: [ 'orfano' ],
			snapshots: [ {
				id: 'snap_e2e_manomesso',
				trigger: 'manual',
				timestamp: Math.floor( Date.now() / 1000 ),
				active_plugins: [ 'hello.php', '../../../wp-config.php', 'akismet', [ 'x' ] ],
				stylesheet: 'dbdm-e2e-orfano',
				template: 'dbdm-e2e-orfano',
			} ],
		} );
		const page = await ( await browser.newContext() ).newPage();
		await emergencyLogin( page );

		page.once( 'dialog', ( d ) => d.accept() );
		await page.getByRole( 'button', { name: /Ripristina/ } ).click();

		await expect( page.locator( '.notice-ok' ) ).toContainText( 'Plugin attivi ripristinati: 1 plugin.' );
		await expect( page.locator( '.notice-err' ).first() ).toContainText( '../../../wp-config.php' );
		await expect( page.locator( '.notice-err' ).last() ).toContainText( 'tema padre non installato' );
		// Il tema attivo non è cambiato e il sito risponde.
		await expect( page.getByText( 'dbdm-e2e-orfano' ).first() ).toBeVisible();
		const home = await request.get( '/' );
		expect( home.status() ).toBe( 200 );
	} );
} );

test.describe( 'debug.log dall\'emergency', () => {

	test( 'attivare WP_DEBUG_LOG lo mette nella cartella privata (bug 41)', async ( { browser, request } ) => {
		await resetState( request, { emergency: true } );
		const page = await ( await browser.newContext() ).newPage();
		await emergencyLogin( page );

		const row = page.locator( 'tr', { hasText: 'WP_DEBUG_LOG' } );
		await row.getByRole( 'button', { name: 'On' } ).click();

		await expect( page.locator( '.notice-ok' ) ).toContainText( 'WP_DEBUG_LOG impostata a true' );
		const state = await getState( request );
		expect( state.constants.WP_DEBUG_LOG ).toMatch( /\/dbdm-private-[0-9a-f]{16}\/debug\.log$/ );
		expect( state.backup_sha1 ).toBe( state.golden_sha1 );
	} );
} );

test.describe( 'pannello: cartella privata', () => {
	test.use( { storageState: ADMIN_STATE } );

	test( 'uno snapshot creato dal pannello è visibile nell\'emergency', async ( { browser, page, request } ) => {
		await resetState( request, { emergency: true } );
		await page.goto( TABS.snapshots );
		await page.locator( '#dbdm-snap-note' ).fill( 'prima di un aggiornamento' );
		await page.getByRole( 'button', { name: 'Crea snapshot adesso' } ).click();
		await expect( page.getByText( 'Snapshot creato.' ) ).toBeVisible();

		const em = await ( await browser.newContext() ).newPage();
		await emergencyLogin( em );
		await expect( em.getByRole( 'heading', { name: /Snapshot disponibili \(1\)/ } ) ).toBeVisible();
		await expect( em.getByText( 'prima di un aggiornamento' ) ).toBeVisible();
	} );
} );
