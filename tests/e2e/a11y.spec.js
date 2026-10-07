// @ts-check
const { test, expect } = require( '@playwright/test' );
const AxeBuilder = require( '@axe-core/playwright' ).default;
const { ADMIN_STATE, TABS, resetState, emergencyLogin, EMERGENCY_URL } = require( './helpers' );

/**
 * Accessibilità WCAG 2.1 AA con axe-core (bug 59): ogni tab del pannello
 * (solo l'area del plugin, non la bacheca di WordPress) e emergency.php.
 */

const TAGS = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa' ];

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} [include]
 */
async function violations( page, include ) {
	let builder = new AxeBuilder( { page } ).withTags( TAGS );
	if ( include ) {
		builder = builder.include( include );
	}
	const result = await builder.analyze();
	return result.violations.map( ( v ) => `${ v.id }: ${ v.help } → ${ v.nodes.map( ( n ) => n.target.join( ' ' ) ).join( ', ' ) }` );
}

test.describe( 'pannello', () => {
	test.use( { storageState: ADMIN_STATE } );

	test.beforeAll( async ( { request } ) => {
		await resetState( request, { emergency: true, log: 'PHP Notice: riga di prova\n' } );
	} );

	for ( const [ name, url ] of Object.entries( TABS ) ) {
		test( `tab ${ name }`, async ( { page } ) => {
			await page.goto( url );
			expect( await violations( page, '.dbdm-wrap' ) ).toEqual( [] );
		} );
	}
} );

test.describe( 'emergency.php', () => {

	test.beforeAll( async ( { request } ) => {
		await resetState( request, { emergency: true, log: 'PHP Notice: riga di prova\n' } );
	} );

	test( 'login', async ( { browser } ) => {
		const page = await ( await browser.newContext() ).newPage();
		await page.goto( EMERGENCY_URL );
		expect( await violations( page ) ).toEqual( [] );
	} );

	test( 'dashboard', async ( { browser } ) => {
		const page = await ( await browser.newContext() ).newPage();
		await emergencyLogin( page );
		expect( await violations( page ) ).toEqual( [] );
	} );
} );
