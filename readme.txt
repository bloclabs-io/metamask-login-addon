=== MetaMask Login Add-On ===
Contributors: stcchain
Tags: metamask, web3, ethereum, login, sign-in with ethereum
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 3.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Passwordless, phishing-resistant WordPress login with MetaMask, using Sign-In with Ethereum (EIP-4361) and real on-server signature verification.

== Description ==

MetaMask Login Add-On adds a **Log in with MetaMask** button to your site. Visitors approve a free signature in their wallet and they are logged in. No passwords are needed and nothing is sent to the blockchain.

= Easy to set up =

* A **3-step setup checklist** on Settings → MetaMask Login: connect your wallet, choose where the button appears, try it.
* **Status checks** confirm that signature verification works on your server, and flag HTTPS and other setup issues in plain language.
* A **live preview** of the button text and style (dark, MetaMask orange, light).
* Works on the WordPress login screen straight away. Add it anywhere else with the **MetaMask Login block** or the `[metamask_login]` shortcode.

= Friendly for visitors =

* Clear step-by-step messages while connecting and signing.
* People without MetaMask get an **Install MetaMask** link on desktop and **Open in the MetaMask app** on phones.
* Picks MetaMask even when several wallets are installed (EIP-6963).
* Users connect, switch or disconnect their wallet from their profile, or on the front end with `[metamask_profile]`.
* Optional automatic sign-up for new wallets, with a role you choose. Privileged roles (admins, editors, user, plugin or theme managers) are never offered.

= Secure by design =

* **Real signature verification** (Keccak-256 + secp256k1) built in, in pure PHP. No extra PHP extensions, libraries or outside services.
* **Sign-In with Ethereum (EIP-4361)** messages bound to your domain, so MetaMask warns users about look-alike sites.
* **One-time challenges** that expire after 5 minutes and are tied to the browser that asked for them, which blocks replay and login CSRF.
* Rate limiting of failed attempts, same-site-only redirects, nonces on every request and escaped output.

= For developers =

* A REST API (`/wp-json/metamask-login/v1`: nonce, auth, link, unlink, me).
* Filters and actions for redirects, access control (2FA, roles), usernames, the SIWE domain, client IP detection and more.
* Theme-overridable templates.
* Documentation: https://github.com/bloclabs-io/metamask-login-addon

MetaMask is a trademark of Consensys Software Inc. This plugin is not affiliated with or endorsed by Consensys.

== Installation ==

1. Go to **Plugins → Add New Plugin → Upload Plugin**, upload the ZIP, then click **Install Now** and **Activate**.
2. Click **Finish setup** in the notice, or open **Settings → MetaMask Login**.
3. In step 1 click **Connect MetaMask** and approve the signature. Your account can now log in with MetaMask.
4. Open the login screen in a private window and try **Log in with MetaMask**.

Each user connects their own wallet once from **Users → Profile → MetaMask Wallet**.

== Frequently Asked Questions ==

= Does logging in cost gas or send a transaction? =

No. Users only sign a text message. Nothing is sent to any blockchain.

= Do I need GMP, BCMath or Composer? =

No. Signature verification is built into the plugin in plain PHP. You only need a 64-bit PHP build, which every modern host provides. The settings screen runs a self-test to confirm it.

= Do users still have passwords? =

Yes. Normal username and password login keeps working. MetaMask is an extra way in.

= A user sees "This wallet is not connected to an account yet". =

They need to log in with their password once and click **Connect MetaMask** on their profile. You can also turn on **Create accounts for new wallets** in the settings.

= Which networks are supported? =

All of them. Login doesn't depend on the selected network.

= Can one wallet be used for several accounts? =

No. Each wallet belongs to exactly one account.

= Does it work with page caching? =

Yes, but exclude pages that show the button from full-page caching for more than 24 hours, or visitors may see "Your session has expired".

= My site is behind Cloudflare or a proxy. =

Rate limiting uses the connection IP by default. Use the `metamask_login_client_ip` filter to pass the real visitor IP from a header you trust.

= Can I require a second factor for administrators? =

Yes. Use the `metamask_login_authenticate` filter to return a `WP_Error` for users who must log in another way.

== Screenshots ==

1. The "Log in with MetaMask" button on the WordPress login screen.
2. Settings screen with the 3-step setup checklist, live preview and status checks.
3. The MetaMask Wallet section on the user profile screen.
4. The MetaMask Login block in the block editor.

== Changelog ==

= 3.0.0 - 2026-09-28 =
* New: WordPress 7.1 compatibility (tested on 7.1.2). The minimum is now WordPress 6.5.
* New: real signature verification. Pure-PHP Keccak-256 and secp256k1 recovery with no extensions needed. In 2.x signatures were never actually verified, so wallet login could not succeed.
* New: Sign-In with Ethereum (EIP-4361) messages with domain binding, a nonce, and issued/expiry times.
* New: one-time, server-stored challenges bound to the requesting browser (blocks replay and login CSRF).
* New: redesigned settings screen with a setup checklist, status checks, live button preview and plain-language options.
* New: "MetaMask Login" block (block API v3).
* New: optional automatic sign-up for new wallets with a safe role picker.
* New: button styles, custom button text, a custom post-login destination and a custom sign-in sentence.
* New: EIP-6963 wallet discovery, mobile "Open in the MetaMask app" link and an install link when no wallet is found.
* New: redesigned profile wallet card with Connect, Switch and Disconnect. Administrators can disconnect a user's wallet.
* New: REST route `GET /me`, and `nonce` parameters for `/auth` and `/link`.
* New: filters `metamask_login_authenticate`, `metamask_login_siwe_domain`, `metamask_login_client_ip`, `metamask_login_new_username`, `metamask_login_bind_challenge_to_cookie`, `metamask_login_show_on_login_page`, `metamask_login_template`, and actions `metamask_login_failed`, `metamask_user_registered`.
* New: theme template overrides (`your-theme/metamask-login/`).
* New: translation template (`languages/metamask-login-addon.pot`).
* Improved: the browser script is rewritten in vanilla JavaScript (no jQuery) with accessible status messages.
* Improved: settings for the login button and rate limiting now take effect (they were ignored in 2.x).
* Improved: redirects follow `redirect_to` and the core `login_redirect` filter, allowing same-site destinations only.
* Improved: rate limiting uses `REMOTE_ADDR` by default, so it can't be bypassed with spoofed headers.
* Changed: the text domain is now `metamask-login-addon`.
* Changed: uninstalling keeps wallet links unless "Delete wallet links when the plugin is deleted" is on.
* Removed: the insecure development-mode signature bypass, the unused `/verify` REST route and the `authenticate` filter integration.
* Fixed: login messages lost their line breaks during sanitization, so signatures could never match.
* Fixed: a JavaScript error when the MetaMask account changed.
* New: wallet logins run through the core `wp_authenticate_user` filter and the multisite spam check, so ban, approval and lockout plugins still apply.
* Changed: wallet links saved by 2.x (which were never verified) are set aside, and users confirm them with one click.
* Tests: 19 PHPUnit tests (crypto) and 17 Playwright end-to-end tests, run on WordPress 7.1.2 and 6.5.12.

= 2.0.0 - 2025-11-12 =
* Settings page, rate limiting, activation hooks, developer hooks, REST API.

= 1.0.0 - 2024-01-01 =
* Initial release.

== Upgrade Notice ==

= 3.0.0 =
Major security and usability update. Wallet login now really verifies signatures. Settings migrate automatically. Wallets linked with 2.x must be confirmed once from the user's profile.

== Privacy ==

The plugin stores each user's wallet address in the `wp_usermeta` table. Short-lived data (sign-in challenges for 5 minutes and rate-limit counters) is kept in transients. A strictly necessary cookie, `wp_metamask_challenge`, lasts up to 5 minutes during sign-in. No data is sent to outside services. The optional balance display in `[metamask_profile]` reads the balance through the visitor's own wallet.
