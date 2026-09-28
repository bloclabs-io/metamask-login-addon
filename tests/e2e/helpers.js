const { Wallet, toUtf8String, getBytes } = require( 'ethers' );

const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin-pass-123';

/**
 * Inject a fake MetaMask (EIP-1193 + EIP-6963) into the page. Requests are
 * answered in Node by a real ethers Wallet, so signatures are genuine.
 *
 * @param {import('@playwright/test').Page} page
 * @param {Wallet} wallet  Account exposed to the site.
 * @param {Object} [opts]
 * @param {Wallet} [opts.signer]        Wallet that actually signs (to simulate forgery).
 * @param {boolean} [opts.rejectSign]   Simulate the user clicking "Cancel".
 * @param {string[]} [opts.log]         Receives every signed message.
 */
async function installWallet( page, wallet, opts = {} ) {
	await page.exposeFunction( '__mmRequest', async ( method, params ) => {
		switch ( method ) {
			case 'eth_requestAccounts':
			case 'eth_accounts':
				// MetaMask returns lowercase addresses.
				return { result: [ wallet.address.toLowerCase() ] };
			case 'eth_chainId':
				return { result: '0x89' };
			case 'personal_sign': {
				if ( opts.rejectSign ) {
					return { error: { code: 4001, message: 'User rejected the request.' } };
				}
				const message = toUtf8String( getBytes( params[ 0 ] ) );
				if ( opts.log ) {
					opts.log.push( message );
				}
				return { result: await ( opts.signer || wallet ).signMessage( message ) };
			}
			case 'eth_getBalance':
				return { result: '0x1bc16d674ec80000' }; // 2 ETH
			default:
				return { error: { code: 4200, message: 'Unsupported method ' + method } };
		}
	} );

	await page.addInitScript( () => {
		const provider = {
			isMetaMask: true,
			async request( { method, params } ) {
				const res = await window.__mmRequest( method, params || [] );
				if ( res.error ) {
					const err = new Error( res.error.message );
					err.code = res.error.code;
					throw err;
				}
				return res.result;
			},
			on() {},
			removeListener() {},
		};
		window.ethereum = provider;
		const announce = () =>
			window.dispatchEvent(
				new CustomEvent( 'eip6963:announceProvider', {
					detail: Object.freeze( {
						info: { uuid: '00000000-0000-4000-8000-000000000000', name: 'MetaMask', icon: 'data:,', rdns: 'io.metamask' },
						provider,
					} ),
				} )
			);
		window.addEventListener( 'eip6963:requestProvider', announce );
		announce();
	} );
}

async function passwordLogin( page, user = ADMIN_USER, pass = ADMIN_PASS ) {
	await page.goto( '/wp-login.php' );
	// wp-login.php focuses and selects #user_login ~200ms after load; wait for
	// it so it cannot steal focus in the middle of typing the password.
	await page
		.waitForFunction( () => document.activeElement && 'user_login' === document.activeElement.id, null, { timeout: 3000 } )
		.catch( () => {} );
	await page.fill( '#user_login', user );
	await page.fill( '#user_pass', pass );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );
}

module.exports = { installWallet, passwordLogin, Wallet, ADMIN_USER, ADMIN_PASS };
