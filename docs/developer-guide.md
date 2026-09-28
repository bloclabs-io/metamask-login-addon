# Developer guide

## Architecture

| File | Responsibility |
|---|---|
| `metamask-login-addon.php` | Bootstrap, asset registration, shortcodes, block, templates, upgrade routine |
| `includes/class-metamask-settings.php` | Option defaults, sanitization, migration |
| `includes/class-metamask-authentication.php` | Challenges (SIWE messages), verification, login, sign-up, redirects, rate limiting, login-screen button |
| `includes/class-metamask-user-profile.php` | Wallet ↔ user linking, profile section, Users column |
| `includes/class-metamask-api.php` | REST API |
| `includes/class-metamask-admin.php` | Settings screen, status checks, setup notice |
| `includes/utils/eth-sign-verify.php` | `Eth_Sign_Verify`: EIP-191 hashing, signer recovery, EIP-55 |
| `includes/crypto/` | Pure-PHP Keccak-256 and secp256k1 public key recovery |
| `blocks/login-button/` | "MetaMask Login" block (no build step, server-rendered) |
| `templates/` | Overridable front-end markup |
| `assets/js/metamask-login.js` | All browser flows (vanilla JS, no jQuery) |

Wallet addresses are stored **lowercase** in the user meta key `metamask_wallet_address`. Addresses saved by 2.x without proof of ownership are moved to `metamask_wallet_address_unverified` on upgrade and are never used for login.

## PHP API

```php
// User linked to a wallet (WP_User|false). Case-insensitive.
MetaMask_User_Profile::get_user_by_wallet_address( $address );

// Lowercase wallet of a user, or ''.
MetaMask_User_Profile::get_wallet_address( $user_id );

// Unlink (fires metamask_wallet_disconnected).
MetaMask_User_Profile::unlink_wallet( $user_id );

// Create a one-time challenge: array( message, nonce, expires ) or WP_Error.
MetaMask_Authentication::create_challenge( $address, $chain_id = 1, $purpose = 'login', $user_id = 0 );

// Verify + log in: WP_User or WP_Error.
MetaMask_Authentication::login_with_signature( $address, $nonce, $signature, $remember = true );

// Link after verifying a 'link' challenge: true or WP_Error.
MetaMask_User_Profile::link_wallet( $user_id, $address, $nonce, $signature );

// Low-level signature helpers.
Eth_Sign_Verify::recover( $message, $signature );           // '0x…' lowercase or false
( new Eth_Sign_Verify() )->verify( $message, $signature, $address ); // bool
Eth_Sign_Verify::to_checksum_address( $address );           // EIP-55
```

## Filters

| Filter | Arguments | Purpose |
|---|---|---|
| `metamask_login_redirect` | `$url, WP_User $user` | Final redirect after a wallet login |
| `metamask_login_default_redirect` | `$url` | Fallback redirect when none is requested |
| `login_redirect` (core) | `$url, $requested, $user` | Also applied, so existing redirect plugins keep working |
| `metamask_login_authenticate` | `WP_User $user, $address` | Return a `WP_Error` to deny a login (2FA, bans, roles…) |
| `metamask_login_sign_message` | `$message, $address` | Change the signed message. Keep the `Nonce:` line. |
| `metamask_login_siwe_domain` | `$domain` | Domain in the SIWE message (defaults to the host of `home_url()`) |
| `metamask_login_new_username` | `$username, $address` | Username for automatic sign-ups |
| `metamask_login_privileged_caps` | `string[] $caps` | Capabilities that exclude a role from automatic sign-ups |
| `wp_authenticate_user` (core) | `$user, ''` | Also applied to wallet logins (password is empty) so account-status plugins can veto them |
| `metamask_login_client_ip` | `$ip` | Client IP used for rate limiting (defaults to `REMOTE_ADDR`) |
| `metamask_login_bind_challenge_to_cookie` | `bool $bind` | Turn off browser binding for headless/native clients |
| `metamask_login_show_on_login_page` | `bool $show` | Show or hide the wp-login.php button per request |
| `metamask_login_template` | `$path, $name` | Swap template files |
| `metamask_login_custom_signature_verify` | `null, $hash, $r, $s, $v` | Return a recovered address to replace the built-in recovery |

### Examples

```php
// Behind Cloudflare: use the real visitor IP for rate limiting.
add_filter( 'metamask_login_client_ip', function ( $ip ) {
	return isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) : $ip;
} );

// Only allow wallet logins for members of a certain role.
add_filter( 'metamask_login_authenticate', function ( $user ) {
	return in_array( 'member', (array) $user->roles, true )
		? $user
		: new WP_Error( 'not_member', __( 'Wallet login is for members only.' ) );
} );

// Nicer usernames for wallet sign-ups.
add_filter( 'metamask_login_new_username', function ( $username, $address ) {
	return 'member_' . substr( $address, -6 );
}, 10, 2 );
```

## Actions

| Action | Arguments | When |
|---|---|---|
| `metamask_login_successful` | `$user_id, $address` | After a wallet login (after core `wp_login`) |
| `metamask_login_failed` | `$address, WP_Error $error` | A wallet login failed |
| `metamask_user_registered` | `$user_id, $address` | An account was created for a new wallet |
| `metamask_wallet_connected` | `$user_id, $address, $old_address` | A wallet was linked |
| `metamask_wallet_updated` | `$user_id, $address, $old_address` | A user switched to a different wallet |
| `metamask_wallet_disconnected` | `$user_id, $old_address` | A wallet was unlinked |

## REST API

Namespace: `/wp-json/metamask-login/v1`. Errors use the standard WordPress shape `{ "code", "message", "data": { "status" } }`.

| Method & route | Auth | Body | Response |
|---|---|---|---|
| `POST /nonce` | none (`purpose=link` needs a logged-in user) | `address`, `chain_id` (optional), `purpose` (`login`\|`link`) | `{ success, message, nonce, expires }` |
| `POST /auth` | none | `address`, `nonce`, `signature`, `remember` (optional), `redirect_to` (optional) | `{ success, message, user_id, redirect }` + auth cookie |
| `POST /link` | logged in (cookie + `X-WP-Nonce`) | `address`, `nonce`, `signature` | `{ success, message, address }` |
| `POST /unlink` | logged in | — | `{ success, message }` |
| `GET /me` | logged in | — | `{ user_id, connected, address }` |

Status codes: `400` invalid/expired/forged, `401` not logged in, `404` wallet not linked (sign-up off), `409` wallet linked to another account, `429` rate limited.

### Example client

```js
const [ address ] = await ethereum.request( { method: 'eth_requestAccounts' } );
const chainId = await ethereum.request( { method: 'eth_chainId' } );

const challenge = await fetch( '/wp-json/metamask-login/v1/nonce', {
	method: 'POST',
	credentials: 'same-origin', // keeps the challenge cookie
	headers: { 'Content-Type': 'application/json' },
	body: JSON.stringify( { address, chain_id: chainId } ),
} ).then( ( r ) => r.json() );

const signature = await ethereum.request( { method: 'personal_sign', params: [ challenge.message, address ] } );

const result = await fetch( '/wp-json/metamask-login/v1/auth', {
	method: 'POST',
	credentials: 'same-origin',
	headers: { 'Content-Type': 'application/json' },
	body: JSON.stringify( { address, nonce: challenge.nonce, signature } ),
} ).then( ( r ) => r.json() );

window.location.assign( result.redirect );
```

**Headless or native apps** that don't keep cookies must turn off browser binding:

```php
add_filter( 'metamask_login_bind_challenge_to_cookie', '__return_false' );
```

When binding is off, protect `/auth` against login CSRF some other way, for example a custom header that only your app sends.

## AJAX actions

The bundled script uses `admin-ajax.php`. Requests from logged-in users need `nonce` = `wp_create_nonce( 'metamask_login_nonce' )`, which is exposed as `MetaMaskLogin.nonce`. Logged-out login requests don't need it (so cached pages work), because the challenge cookie already protects against CSRF. If you turn off cookie binding, the nonce becomes required again.

| Action | Fields |
|---|---|
| `metamask_get_nonce` | `address`, `chain_id`, `purpose` |
| `metamask_authentication` | `address`, `challenge`, `signature`, `redirect_to`, `remember` |
| `metamask_connect_wallet` | `address`, `challenge`, `signature` (logged in) |
| `metamask_disconnect_wallet` | `user_id` (optional; needs `edit_user`) |

## Front-end markup and JavaScript

Any element with `data-metamask-action="login|link|unlink"` inside a `[data-metamask-container]` works. Status messages go into the container's `.metamask-status` element:

```html
<div data-metamask-container>
	<button type="button" data-metamask-action="login" data-redirect="/members/">Sign in</button>
	<div class="metamask-status" role="status" aria-live="polite"></div>
</div>
```

To render the standard button from PHP, call `MetaMask_Login_Addon::button_html( array( 'action' => 'login', 'text' => 'Sign in', 'style' => 'orange' ) )`, then `MetaMask_Login_Addon::enqueue_assets()`.

Styling: the button uses `.metamask-button` with `--dark`, `--orange` or `--light`. You can override the CSS custom properties `--metamask-orange`, `--metamask-ink` and `--metamask-radius`.

`window.MetaMaskLoginApp.getProvider()` returns the selected EIP-1193 provider (MetaMask is preferred through EIP-6963).

## Templates

Copy a file from `templates/` to `your-theme/metamask-login/` to override it:

- `login-button.php`: receives `$atts` (`redirect`, `button_text`, `button_class`, `style`) and `$preview`.
- `user-profile.php`: receives `$atts` (`show_address`, `show_balance`).

## Block

`metamask-login/login-button` (block API v3). Attributes: `buttonText`, `buttonStyle` (`''`, `dark`, `orange`, `light`) and `redirect`. It supports alignment and spacing. The block is rendered on the server by the same template as the shortcode. The editor script is plain JavaScript and needs no build step.
