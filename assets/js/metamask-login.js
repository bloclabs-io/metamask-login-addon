/**
 * MetaMask Login Add-On — browser script.
 *
 * Handles every MetaMask button rendered by the plugin through event
 * delegation on `[data-metamask-action]`:
 *   - login  : sign a one-time Sign-In with Ethereum message and log in
 *   - link   : connect the wallet to the logged-in account
 *   - unlink : disconnect the wallet
 *
 * No jQuery or build step required.
 */
( function () {
	'use strict';

	const config = window.MetaMaskLogin || {};
	const i18n = config.i18n || {};

	/* ------------------------------------------------------------------
	 * Wallet discovery (EIP-6963 with window.ethereum fallback)
	 * ---------------------------------------------------------------- */

	const announced = [];

	window.addEventListener( 'eip6963:announceProvider', ( event ) => {
		const detail = event.detail;
		if ( detail && detail.provider && ! announced.some( ( d ) => d.provider === detail.provider ) ) {
			announced.push( detail );
		}
	} );
	window.dispatchEvent( new Event( 'eip6963:requestProvider' ) );

	/**
	 * Pick MetaMask when several wallets are installed, otherwise any
	 * EIP-1193 provider.
	 *
	 * @return {Object|null} Provider.
	 */
	function getProvider() {
		const metamask = announced.find( ( d ) => d.info && /^io\.metamask/.test( d.info.rdns || '' ) );
		if ( metamask ) {
			return metamask.provider;
		}
		const injected = window.ethereum;
		if ( injected ) {
			if ( Array.isArray( injected.providers ) ) {
				return injected.providers.find( ( p ) => p.isMetaMask ) || injected.providers[ 0 ];
			}
			return injected;
		}
		return announced.length ? announced[ 0 ].provider : null;
	}

	function isMobile() {
		return /Android|iPhone|iPad|iPod/i.test( navigator.userAgent );
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------- */

	function utf8ToHex( text ) {
		const bytes = new TextEncoder().encode( text );
		let hex = '0x';
		bytes.forEach( ( b ) => {
			hex += b.toString( 16 ).padStart( 2, '0' );
		} );
		return hex;
	}

	/**
	 * POST to admin-ajax.php and unwrap wp_send_json_* responses.
	 *
	 * @param {string} action AJAX action.
	 * @param {Object} data   Fields.
	 * @return {Promise<Object>} Response data.
	 */
	async function post( action, data ) {
		const body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', config.nonce || '' );
		Object.keys( data || {} ).forEach( ( key ) => body.append( key, data[ key ] ) );

		let response;
		try {
			response = await fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body } );
		} catch ( e ) {
			throw new Error( i18n.networkError );
		}

		let json = null;
		try {
			json = await response.json();
		} catch ( e ) {
			// Fall through to the generic error.
		}

		if ( ! json || ! json.success ) {
			const error = new Error( ( json && json.data && json.data.message ) || i18n.genericError );
			error.serverCode = json && json.data && json.data.code;
			throw error;
		}
		return json.data || {};
	}

	function container( el ) {
		return el.closest( '[data-metamask-container]' ) || el.parentElement;
	}

	/**
	 * Show a status message (text only, optional link).
	 *
	 * @param {Element} el   Element inside the container.
	 * @param {string}  type info|success|error.
	 * @param {string}  text Message.
	 * @param {Object=} link { href, label }.
	 */
	function setStatus( el, type, text, link ) {
		const box = container( el ).querySelector( '.metamask-status' );
		if ( ! box ) {
			return;
		}
		box.textContent = '';
		box.className = 'metamask-status is-' + type;
		box.appendChild( document.createTextNode( text ) );
		if ( link ) {
			const a = document.createElement( 'a' );
			a.href = link.href;
			a.textContent = link.label;
			a.className = 'metamask-status__link';
			if ( link.external ) {
				a.target = '_blank';
				a.rel = 'noopener';
			}
			box.appendChild( document.createTextNode( ' ' ) );
			box.appendChild( a );
		}
	}

	function setBusy( button, busy ) {
		button.disabled = busy;
		button.classList.toggle( 'is-busy', busy );
		button.setAttribute( 'aria-busy', busy ? 'true' : 'false' );
	}

	function walletErrorMessage( error ) {
		const code = error && ( error.code || ( error.data && error.data.code ) );
		if ( 4001 === code || 'ACTION_REJECTED' === code ) {
			return i18n.rejected;
		}
		if ( -32002 === code ) {
			return i18n.pending;
		}
		return ( error && error.message ) || i18n.genericError;
	}

	function showNoWallet( button ) {
		if ( isMobile() ) {
			const target = window.location.host + window.location.pathname + window.location.search;
			setStatus( button, 'error', i18n.noWallet, {
				href: ( config.deepLinkUrl || 'https://metamask.app.link/dapp/' ) + target,
				label: i18n.openInApp,
			} );
		} else {
			setStatus( button, 'error', i18n.noWallet, {
				href: config.installUrl || 'https://metamask.io/download/',
				label: i18n.installMetaMask,
				external: true,
			} );
		}
	}

	/* ------------------------------------------------------------------
	 * Flows
	 * ---------------------------------------------------------------- */

	/**
	 * Connect, fetch a one-time challenge and sign it.
	 *
	 * @param {Object}  provider EIP-1193 provider.
	 * @param {string}  purpose  login|link.
	 * @param {Element} button   Clicked button.
	 * @return {Promise<Object>} { address, signature, challenge }.
	 */
	async function signChallenge( provider, purpose, button ) {
		setStatus( button, 'info', i18n.connecting );
		const accounts = await provider.request( { method: 'eth_requestAccounts' } );
		if ( ! accounts || ! accounts.length ) {
			throw new Error( i18n.noAccounts );
		}
		const address = accounts[ 0 ];

		let chainId = '0x1';
		try {
			chainId = await provider.request( { method: 'eth_chainId' } );
		} catch ( e ) {
			// Some wallets do not expose the chain before connecting.
		}

		setStatus( button, 'info', i18n.preparing );
		const challenge = await post( 'metamask_get_nonce', { address, chain_id: chainId, purpose } );

		setStatus( button, 'info', i18n.signing );
		const signature = await provider.request( {
			method: 'personal_sign',
			params: [ utf8ToHex( challenge.message ), address ],
		} );

		setStatus( button, 'info', i18n.verifying );
		return { address, signature, challenge: challenge.nonce };
	}

	function loginRedirectTarget( button ) {
		if ( button.dataset.redirect ) {
			return button.dataset.redirect;
		}
		const field = document.querySelector( '#loginform input[name="redirect_to"]' );
		if ( field ) {
			return field.value;
		}
		return window.location.href;
	}

	async function login( button, provider ) {
		const signed = await signChallenge( provider, 'login', button );
		const remember = document.getElementById( 'rememberme' );
		const result = await post( 'metamask_authentication', {
			address: signed.address,
			signature: signed.signature,
			challenge: signed.challenge,
			redirect_to: loginRedirectTarget( button ),
			remember: remember && ! remember.checked ? '0' : '1',
		} );
		setStatus( button, 'success', i18n.loginSuccess );
		window.location.assign( result.redirect || window.location.href );
		return true;
	}

	async function link( button, provider ) {
		const signed = await signChallenge( provider, 'link', button );
		await post( 'metamask_connect_wallet', signed );
		setStatus( button, 'success', i18n.linkSuccess );
		window.setTimeout( () => window.location.reload(), 800 );
		return true;
	}

	async function unlink( button ) {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( i18n.confirmUnlink ) ) {
			return false;
		}
		await post( 'metamask_disconnect_wallet', { user_id: button.dataset.userId || '' } );
		setStatus( button, 'success', i18n.unlinkSuccess );
		window.setTimeout( () => window.location.reload(), 600 );
		return true;
	}

	async function handleClick( button ) {
		const action = button.dataset.metamaskAction;
		if ( button.disabled || ! [ 'login', 'link', 'unlink' ].includes( action ) ) {
			return;
		}

		const provider = 'unlink' === action ? null : getProvider();
		if ( 'unlink' !== action && ! provider ) {
			showNoWallet( button );
			return;
		}

		setBusy( button, true );
		let keepBusy = false;
		try {
			if ( 'login' === action ) {
				keepBusy = await login( button, provider );
			} else if ( 'link' === action ) {
				keepBusy = await link( button, provider );
			} else {
				keepBusy = await unlink( button );
			}
		} catch ( error ) {
			setStatus( button, 'error', walletErrorMessage( error ) );
		} finally {
			// Stay disabled while the page navigates / reloads.
			if ( ! keepBusy ) {
				setBusy( button, false );
			}
		}
	}

	/* ------------------------------------------------------------------
	 * Boot
	 * ---------------------------------------------------------------- */

	function moveLoginPageButton() {
		const form = document.getElementById( 'loginform' );
		const box = document.querySelector( '.metamask-login--wp-login' );
		if ( form && box ) {
			form.appendChild( box );
			box.hidden = false;
		}
	}

	async function showBalances() {
		const nodes = document.querySelectorAll( '[data-metamask-balance]' );
		const provider = nodes.length ? getProvider() : null;
		if ( ! provider || 'undefined' === typeof BigInt ) {
			return;
		}
		nodes.forEach( async ( node ) => {
			try {
				const wei = BigInt( await provider.request( { method: 'eth_getBalance', params: [ node.dataset.metamaskBalance, 'latest' ] } ) );
				const unit = BigInt( '1000000000000000000' ); // 1e18 wei
				const whole = wei / unit;
				const fraction = ( ( wei % unit ) / BigInt( '100000000000000' ) ).toString().padStart( 4, '0' );
				node.textContent = whole.toString() + '.' + fraction;
			} catch ( e ) {
				// Leave the placeholder text.
			}
		} );
	}

	document.addEventListener( 'click', ( event ) => {
		const button = event.target.closest && event.target.closest( '[data-metamask-action]' );
		if ( button ) {
			event.preventDefault();
			handleClick( button );
		}
	} );

	function boot() {
		moveLoginPageButton();
		// Give EIP-6963 wallets a moment to announce themselves.
		window.setTimeout( showBalances, 300 );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}

	// Small public API for themes and tests.
	window.MetaMaskLoginApp = { getProvider, utf8ToHex };
} )();
