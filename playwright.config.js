// @ts-check
const { defineConfig, devices } = require( '@playwright/test' );

/**
 * Config Playwright per gli E2E di DB Debug Manager.
 *
 * baseURL punta all'ambiente "development" di wp-env (porta 8888), quello su
 * cui opera di default `wp-env run cli` (il setup prepara lì estensioni,
 * permessi e stato baseline). emergency.php si raggiunge sullo stesso host,
 * all'URL diretto del plugin, senza WordPress.
 */
module.exports = defineConfig( {
	testDir: './tests/e2e',
	fullyParallel: false, // i test condividono wp-config.php e la cartella privata: sequenziali.
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 1 : 0,
	workers: 1,
	reporter: process.env.CI ? [ [ 'list' ], [ 'html', { open: 'never' } ] ] : 'list',

	use: {
		baseURL: process.env.WP_BASE_URL || 'http://localhost:8888',
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
	},

	projects: [
		// Baseline del plugin + login admin (sessione in tests/e2e/.auth/).
		{
			name: 'setup',
			testMatch: /.*\.setup\.js/,
		},
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
			dependencies: [ 'setup' ],
		},
	],
} );
