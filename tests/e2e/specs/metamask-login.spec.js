// @ts-check
const { test, expect, request } = require( '@playwright/test' );
const { installWallet, passwordLogin, Wallet } = require( '../helpers' );

// One wallet for the admin for the whole run; random so re-runs never collide.
const adminWallet = Wallet.createRandom();

test.describe.serial( 'MetaMask Login', () => {
	test( 'login screen shows the MetaMask button below the form', async ( { page } ) => {
		await page.goto( '/wp-login.php' );
		const button = page.locator( '#loginform .metamask-login--wp-login [data-metamask-action="login"]' );
		await expect( button ).toBeVisible();
		await expect( button ).toHaveText( 'Log in with MetaMask' );
		// It is moved after the submit button.
		const isAfterSubmit = await page.evaluate( () => {
			const submit = document.querySelector( '#loginform p.submit' );
			const box = document.querySelector( '.metamask-login--wp-login' );
			return !! ( submit.compareDocumentPosition( box ) & Node.DOCUMENT_POSITION_FOLLOWING );
		} );
		expect( isAfterSubmit ).toBe( true );
	} );

	test( 'without a wallet the user is told how to install MetaMask', async ( { page } ) => {
		await page.goto( '/wp-login.php' );
		await page.click( '[data-metamask-action="login"]' );
		const status = page.locator( '.metamask-status' );
		await expect( status ).toContainText( 'MetaMask was not detected' );
		await expect( status.locator( 'a' ) ).toHaveAttribute( 'href', 'https://metamask.io/download/' );
	} );

	test( 'an unknown wallet gets a helpful error', async ( { page } ) => {
		await installWallet( page, Wallet.createRandom() );
		await page.goto( '/wp-login.php' );
		await page.click( '[data-metamask-action="login"]' );
		await expect( page.locator( '.metamask-status' ) ).toContainText( 'not connected to an account yet' );
	} );

	test( 'cancelling the signature shows a friendly message', async ( { page } ) => {
		await installWallet( page, adminWallet, { rejectSign: true } );
		await page.goto( '/wp-login.php' );
		await page.click( '[data-metamask-action="login"]' );
		await expect( page.locator( '.metamask-status' ) ).toContainText( 'You cancelled the request' );
		await expect( page.locator( '[data-metamask-action="login"]' ) ).toBeEnabled();
	} );

	test( 'settings page shows the setup steps and passing health checks', async ( { page } ) => {
		await passwordLogin( page );
		await page.goto( '/wp-admin/options-general.php?page=metamask-login' );
		await expect( page.getByRole( 'heading', { name: 'Get started in 3 steps' } ) ).toBeVisible();
		await expect( page.locator( '.metamask-check--ok', { hasText: 'Signature verification' } ) ).toContainText( 'Self-test passed' );
		// Live preview follows the text field.
		await page.fill( '#metamask-button-text', 'Sign in with Web3' );
		await expect( page.locator( '#metamask-preview .metamask-button__label' ) ).toHaveText( 'Sign in with Web3' );
		await page.check( 'input[name="metamask_login_options[button_style]"][value="orange"]' );
		await expect( page.locator( '#metamask-preview .metamask-button' ) ).toHaveClass( /metamask-button--orange/ );
		// Dependent fields are disabled while their toggle is off.
		await expect( page.locator( '#metamask-default-role' ) ).toBeDisabled();
	} );

	test( 'admin connects a wallet from the profile screen', async ( { page } ) => {
		const log = [];
		await installWallet( page, adminWallet, { log } );
		await passwordLogin( page );
		await page.goto( '/wp-admin/profile.php' );
		await expect( page.locator( '#metamask-wallet' ) ).toBeVisible();
		await page.click( '.metamask-card [data-metamask-action="link"]' );
		await expect( page.locator( '.metamask-card .metamask-pill--ok' ) ).toHaveText( 'Connected', { timeout: 15000 } );
		await expect( page.locator( '.metamask-card__address' ) ).toHaveText( adminWallet.address );

		// The signed message is a proper Sign-In with Ethereum message.
		expect( log ).toHaveLength( 1 );
		const host = new URL( page.url() ).host;
		expect( log[ 0 ].startsWith( host + ' wants you to sign in with your Ethereum account:\n' + adminWallet.address + '\n\nConnect this wallet' ) ).toBe( true );
		expect( log[ 0 ] ).toContain( '\nChain ID: 137\n' );
		expect( log[ 0 ] ).toMatch( /\nNonce: [A-Za-z0-9]{17}\n/ );
		expect( log[ 0 ] ).toContain( adminWallet.address ); // EIP-55 checksummed.

		// Users list shows the short address.
		await page.goto( '/wp-admin/users.php' );
		await expect( page.locator( 'td.column-metamask_address code' ).first() ).toHaveText(
			adminWallet.address.slice( 0, 6 ) + '…' + adminWallet.address.slice( -4 )
		);
	} );

	test( 'admin logs in with MetaMask on the login screen', async ( { page } ) => {
		const log = [];
		await installWallet( page, adminWallet, { log } );
		await page.goto( '/wp-login.php' );
		await page.click( '[data-metamask-action="login"]' );
		await page.waitForURL( /\/wp-admin\/?$/ );
		await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
		expect( log[ 0 ] ).toMatch( /\n\nSign in to .+\. This request will not trigger a blockchain transaction/ );
		expect( log[ 0 ] ).toMatch( /\nExpiration Time: \d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/ );
	} );

	test( 'redirect_to is honoured', async ( { page } ) => {
		await installWallet( page, adminWallet );
		await page.goto( '/wp-login.php?redirect_to=' + encodeURIComponent( '/wp-admin/edit.php' ) );
		await page.click( '[data-metamask-action="login"]' );
		await page.waitForURL( /\/wp-admin\/edit\.php$/ );
	} );

	test( 'a signature from a different key is rejected', async ( { page } ) => {
		await installWallet( page, adminWallet, { signer: Wallet.createRandom() } );
		await page.goto( '/wp-login.php' );
		await page.click( '[data-metamask-action="login"]' );
		await expect( page.locator( '.metamask-status' ) ).toContainText( 'signature could not be verified' );
		expect( ( await page.context().cookies() ).some( ( c ) => c.name.startsWith( 'wordpress_logged_in' ) ) ).toBe( false );
	} );

	test( 'a captured login request cannot be replayed', async ( { page } ) => {
		await installWallet( page, adminWallet );
		await page.goto( '/wp-login.php' );

		let captured = null;
		page.on( 'request', ( req ) => {
			if ( req.url().includes( 'admin-ajax.php' ) && ( req.postData() || '' ).includes( 'metamask_authentication' ) ) {
				captured = req.postDataBuffer();
				captured = { body: captured, headers: req.headers() };
			}
		} );
		await page.click( '[data-metamask-action="login"]' );
		await page.waitForURL( /wp-admin/ );
		expect( captured ).not.toBeNull();

		// Same browser replays the exact request after logging out again, keeping
		// the challenge cookie so only the one-time challenge check can stop it.
		const keep = ( await page.context().cookies() ).filter( ( c ) => c.name.startsWith( 'wp_metamask_challenge_' ) );
		expect( keep ).toHaveLength( 1 );
		await page.context().clearCookies();
		await page.context().addCookies( keep );
		const replay = await page.request.post( '/wp-admin/admin-ajax.php', {
			headers: { 'content-type': captured.headers[ 'content-type' ] },
			data: captured.body,
		} );
		const json = await replay.json();
		expect( json.success ).toBe( false );
		expect( json.data.code ).toBe( 'metamask_challenge_expired' );
	} );

	test( 'front-end block and shortcode log in and return to the page', async ( { page, browser } ) => {
		// Create a page with the block and the shortcodes through the REST API.
		await passwordLogin( page );
		const restNonce = await page.evaluate( async () => {
			const r = await fetch( '/wp-admin/admin-ajax.php?action=rest-nonce' );
			return r.text();
		} );
		const created = await page.request.post( '/wp-json/wp/v2/pages', {
			headers: { 'X-WP-Nonce': restNonce },
			data: {
				title: 'Wallet test ' + Date.now(),
				status: 'publish',
				content:
					'<!-- wp:metamask-login/login-button {"buttonText":"Block login","buttonStyle":"orange"} /-->\n' +
					'<!-- wp:shortcode -->[metamask_profile show_balance="true"]<!-- /wp:shortcode -->',
			},
		} );
		expect( created.ok() ).toBe( true );
		const pageUrl = ( await created.json() ).link;

		// Logged-out visitor.
		const context = await browser.newContext();
		const visitor = await context.newPage();
		await installWallet( visitor, adminWallet );
		await visitor.goto( pageUrl );
		const button = visitor.locator( '.wp-block-metamask-login-login-button [data-metamask-action="login"]' );
		await expect( button ).toHaveText( 'Block login' );
		await expect( button ).toHaveClass( /metamask-button--orange/ );
		await expect( visitor.locator( '.metamask-notice' ) ).toHaveText( 'Please log in to manage your wallet.' );
		await button.click();
		await visitor.waitForURL( pageUrl );
		await expect( visitor.locator( '.metamask-login--logged-in' ) ).toContainText( 'You are logged in as' );
		await expect( visitor.locator( '.metamask-profile .metamask-pill--ok' ) ).toHaveText( 'Connected' );
		await expect( visitor.locator( '[data-metamask-balance]' ) ).toHaveText( '2.0000' );
		await context.close();
	} );

	test( 'block is available in the editor', async ( { page } ) => {
		await passwordLogin( page );
		const types = await page.evaluate( async () => {
			const nonce = await ( await fetch( '/wp-admin/admin-ajax.php?action=rest-nonce' ) ).text();
			const r = await fetch( '/wp-json/wp/v2/block-types/metamask-login/login-button', { headers: { 'X-WP-Nonce': nonce } } );
			return r.json();
		} );
		expect( types.name ).toBe( 'metamask-login/login-button' );
		expect( types.api_version ).toBe( 3 );
		expect( types.editor_script_handles.length ).toBeGreaterThan( 0 );
	} );

	test( 'new wallets can sign up when registration is enabled', async ( { page, browser } ) => {
		await passwordLogin( page );
		await page.goto( '/wp-admin/options-general.php?page=metamask-login' );
		await page.check( '#metamask-enable-registration' );
		await expect( page.locator( '#metamask-default-role' ) ).toBeEnabled();
		await page.selectOption( '#metamask-default-role', 'subscriber' );
		await page.click( '#submit' );
		await expect( page.locator( '#setting-error-settings_updated' ) ).toBeVisible();
		await expect( page.locator( '#metamask-enable-registration' ) ).toBeChecked();

		const newcomer = Wallet.createRandom();
		const context = await browser.newContext();
		const visitor = await context.newPage();
		await installWallet( visitor, newcomer );
		await visitor.goto( '/wp-login.php' );
		await visitor.click( '[data-metamask-action="login"]' );
		// Subscribers land on their profile, like core.
		await visitor.waitForURL( /profile\.php/ );
		await expect( visitor.locator( '.metamask-card__address' ) ).toHaveText( newcomer.address );
		await expect( visitor.locator( '#user_login' ) ).toHaveValue( 'wallet_' + newcomer.address.toLowerCase().slice( 2, 8 ) + newcomer.address.toLowerCase().slice( -4 ) );
		await context.close();

		// Turn registration back off.
		await page.goto( '/wp-admin/options-general.php?page=metamask-login' );
		await page.uncheck( '#metamask-enable-registration' );
		await page.click( '#submit' );
		await expect( page.locator( '#metamask-enable-registration' ) ).not.toBeChecked();
	} );

	test( 'REST API: challenge is bound to the browser that requested it', async ( { baseURL } ) => {
		const wallet = adminWallet;
		const api = await request.newContext( { baseURL } );
		const nonceRes = await api.post( '/wp-json/metamask-login/v1/nonce', { data: { address: wallet.address, chain_id: 1 } } );
		expect( nonceRes.ok() ).toBe( true );
		const challenge = await nonceRes.json();
		expect( challenge.message ).toContain( 'Nonce: ' + challenge.nonce );
		const signature = await wallet.signMessage( challenge.message );

		// A different client (no challenge cookie) cannot use it.
		const other = await request.newContext( { baseURL } );
		const stolen = await other.post( '/wp-json/metamask-login/v1/auth', { data: { address: wallet.address, nonce: challenge.nonce, signature } } );
		expect( stolen.status() ).toBe( 400 );
		await other.dispose();

		// …and it was consumed by that attempt, so a fresh challenge is needed.
		// Two challenges open at once (e.g. two tabs) must both stay usable.
		const fresh = await ( await api.post( '/wp-json/metamask-login/v1/nonce', { data: { address: wallet.address } } ) ).json();
		const second = await ( await api.post( '/wp-json/metamask-login/v1/nonce', { data: { address: wallet.address } } ) ).json();
		expect( second.nonce ).not.toBe( fresh.nonce );
		const ok = await api.post( '/wp-json/metamask-login/v1/auth', {
			data: { address: wallet.address, nonce: fresh.nonce, signature: await wallet.signMessage( fresh.message ) },
		} );
		expect( ok.status() ).toBe( 200 );
		const body = await ok.json();
		expect( body.success ).toBe( true );
		expect( body.redirect ).toContain( '/wp-admin/' );
		await api.dispose();
	} );

	test( 'logged-out AJAX works without a page nonce (cached pages)', async ( { baseURL } ) => {
		const api = await request.newContext( { baseURL } );
		const res = await api.post( '/wp-admin/admin-ajax.php', {
			form: { action: 'metamask_get_nonce', address: adminWallet.address, chain_id: '0x1' },
		} );
		const json = await res.json();
		expect( json.success ).toBe( true );
		expect( json.data.message ).toContain( 'wants you to sign in with your Ethereum account' );
		// Logged-in actions still require the nonce.
		const linkRes = await api.post( '/wp-admin/admin-ajax.php', { form: { action: 'metamask_disconnect_wallet' } } );
		expect( linkRes.ok() ).toBe( false );
		await api.dispose();
	} );

	test( 'admin can disconnect the wallet', async ( { page } ) => {
		await passwordLogin( page );
		await page.goto( '/wp-admin/profile.php' );
		page.once( 'dialog', ( dialog ) => dialog.accept() );
		await page.click( '[data-metamask-action="unlink"]' );
		await expect( page.locator( '.metamask-card .metamask-pill' ) ).toHaveText( 'Not connected', { timeout: 15000 } );
	} );

	test( 'repeated failures are rate limited', async ( { baseURL } ) => {
		const api = await request.newContext( { baseURL } );
		const wallet = Wallet.createRandom();
		const statuses = [];
		for ( let i = 0; i < 6; i++ ) {
			const c = await api.post( '/wp-json/metamask-login/v1/nonce', { data: { address: wallet.address } } );
			if ( 429 === c.status() ) {
				statuses.push( 429 );
				continue;
			}
			const { nonce, message } = await c.json();
			const res = await api.post( '/wp-json/metamask-login/v1/auth', {
				data: { address: wallet.address, nonce, signature: await wallet.signMessage( message ) },
			} );
			statuses.push( res.status() );
		}
		// 5 failures (no linked account) are allowed, the 6th is blocked.
		expect( statuses.slice( 0, 5 ) ).toEqual( [ 404, 404, 404, 404, 404 ] );
		expect( statuses[ 5 ] ).toBe( 429 );
		await api.dispose();
	} );
} );
