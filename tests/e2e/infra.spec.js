// @ts-check
const { test, expect } = require( '@playwright/test' );
const { ADMIN_STATE, TABS, EMERGENCY_URL, resetState, getState, emergencyLogin } = require( './helpers' );

/**
 * Smoke test dell'infrastruttura E2E: se questi falliscono, i fallimenti
 * degli altri spec non sono attendibili.
 */
test.describe( 'Infrastruttura E2E', () => {

	test( 'ambiente: estensioni, permessi, costanti effettive', async ( { request } ) => {
		const state = await resetState( request );

		expect( state.pdo_mysql ).toBe( true );
		expect( state.wp_config_writable ).toBe( true );
		expect( state.plugin_writable ).toBe( true );
		expect( state.content_writable ).toBe( true );
		// Baseline di .wp-env.json: debug spento, letto da una richiesta separata.
		expect( state.constants ).toMatchObject( { WP_DEBUG: false, WP_DEBUG_LOG: false, WP_DEBUG_DISPLAY: false } );
		expect( state.debug_log ).toBeNull();
		expect( state.private ).toEqual( {} );
	} );

	test( 'il reset ripristina wp-config.php dorato e prepara le varianti', async ( { request } ) => {
		const golden = ( await resetState( request ) ).wp_config;
		expect( golden ).toContain( 'getenv_docker' );

		const literal = await resetState( request, { wp_config: 'literal', emergency: true, log: 'riga di prova\n' } );
		expect( literal.wp_config ).toMatch( /define\( 'DB_NAME', '[^']+' \);/ );
		expect( literal.constants.WP_DEBUG ).toBe( false );
		expect( literal.debug_log ).toBeGreaterThan( 0 );
		expect( literal.options.dbdm_emergency_enabled ).toBe( '1' );

		const again = await resetState( request );
		expect( again.wp_config ).toBe( golden );
		expect( again.debug_log ).toBeNull();
		expect( again.options ).toEqual( {} );
	} );

	test.describe( 'sessione admin', () => {
		test.use( { storageState: ADMIN_STATE } );

		test.beforeEach( async ( { request } ) => {
			await resetState( request );
		} );

		test( 'la pagina del plugin è raggiungibile', async ( { page } ) => {
			await page.goto( TABS.config );
			await expect( page.getByRole( 'heading', { level: 1 } ) ).toContainText( 'Debug Manager' );
			await expect( page.locator( 'input[name="dbdm[WP_DEBUG]"]' ) ).toBeEnabled();
		} );
	} );

	test.describe( 'emergency.php', () => {

		test( 'con credenziali letterali il login funziona', async ( { browser, request } ) => {
			await resetState( request, { wp_config: 'literal', emergency: true } );
			const context = await browser.newContext();
			const page = await context.newPage();

			await emergencyLogin( page );

			await expect( page.getByRole( 'heading', { name: /Emergency Dashboard/ } ) ).toBeVisible();
			await context.close();
		} );

		test( 'risponde anche senza WordPress caricato', async ( { request } ) => {
			await resetState( request, { wp_config: 'literal' } );
			const res = await request.get( EMERGENCY_URL );
			expect( res.status() ).toBe( 200 );
			expect( await res.text() ).toContain( 'Accesso non disponibile' );
		} );
	} );
} );
