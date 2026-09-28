// @ts-check
const { defineConfig } = require( '@playwright/test' );

/**
 * Point WP_BASE_URL at any WordPress site with the plugin active.
 * WP_ADMIN_USER / WP_ADMIN_PASS must be an administrator's credentials.
 */
module.exports = defineConfig( {
	testDir: './specs',
	fullyParallel: false,
	workers: 1,
	retries: 0,
	timeout: 60000,
	reporter: [ [ 'list' ] ],
	globalSetup: require.resolve( './global-setup.js' ),
	use: {
		baseURL: process.env.WP_BASE_URL || 'http://127.0.0.1:8899',
		headless: true,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
} );
