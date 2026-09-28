# Changelog

All notable changes to this project are documented here. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project follows [Semantic Versioning](https://semver.org/).

## [3.0.0] - 2026-09-28

Verified on WordPress **7.1.2** and **6.5.12** (PHP 8.4): 19 PHPUnit tests and 17 Playwright end-to-end tests pass on both.

### Security
- **Real signature verification.** 2.x never recovered the signer (it used SHA3-256 instead of Keccak-256 and had no secp256k1 code), so wallet login could not succeed. 3.0.0 includes a tested pure-PHP Keccak-256 and secp256k1 public key recovery that needs no GMP, BCMath or Composer packages.
- **One-time challenges.** Sign-in messages are created and stored on the server, expire after 5 minutes and are deleted on first use. Clients can no longer submit their own messages, which prevents replaying old signatures.
- **Sign-In with Ethereum (EIP-4361).** Messages include the site domain, URI, chain ID, nonce and issued/expiry times. MetaMask warns about domain mismatches (phishing).
- **Login CSRF protection.** Challenges are tied to the requesting browser with a `SameSite=Strict`, `HttpOnly` cookie (can be turned off with `metamask_login_bind_challenge_to_cookie` for headless clients).
- Rate limiting now uses the plugin settings, applies to the REST API too, and trusts only `REMOTE_ADDR` by default (`metamask_login_client_ip` filter for proxies).
- Logged-out AJAX requests no longer need the page's WordPress nonce (the challenge cookie provides the CSRF protection), so the button keeps working on fully cached pages. Logged-in actions still require the nonce.
- Linking a wallet requires a `link` challenge bound to the logged-in user. Failed link attempts count toward the rate limit.
- Challenges are consumed atomically (only the request that deletes the challenge may use it), and each has its own browser cookie so several tabs can sign in at once. If the cookie can't be set, no challenge is issued (fail closed).
- Wallet logins run core's `wp_authenticate_user` filter and the multisite spam check, so account-status and lockout plugins apply.
- Wallet links saved by 2.x (typed into the profile without any proof of ownership) are moved to `metamask_wallet_address_unverified` and can't be used to log in until the user confirms them.
- Auto sign-up refuses any role with privileged capabilities (site, user, plugin or theme management, editing others' content, WooCommerce management). The list is filterable with `metamask_login_privileged_caps`.
- Rate-limit windows are fixed (repeated hits no longer extend them).
- Removed the development-mode signature bypass.
- Redirects are same-site only (`wp_validate_redirect`) and also go through the core `login_redirect` filter.

### Added
- Settings screen redesign: a 3-step setup checklist, status checks (signature self-test, 64-bit PHP, HTTPS, login button, your wallet), live button preview, toggle switches and dependent fields.
- One-time "Finish setup" notice after activation.
- "MetaMask Login" block (`metamask-login/login-button`, block API v3, server-rendered, no build step).
- Button text and style (dark / MetaMask orange / light) settings; `style` shortcode attribute.
- "After login, go to" setting.
- Optional automatic sign-up for new wallets with a restricted role picker.
- Custom sign-in sentence (the SIWE statement).
- "Delete wallet links when the plugin is deleted" setting (off by default).
- EIP-6963 multi-wallet discovery (MetaMask preferred), "Install MetaMask" and "Open in the MetaMask app" fallbacks.
- Wallet card on the profile screen with Connect / Switch / Disconnect; administrators can disconnect other users' wallets.
- REST: `GET /me`; `nonce` parameter on `/auth` and `/link`; argument schemas.
- Filters: `metamask_login_authenticate`, `metamask_login_privileged_caps`, `metamask_login_siwe_domain`, `metamask_login_client_ip`, `metamask_login_new_username`, `metamask_login_bind_challenge_to_cookie`, `metamask_login_show_on_login_page`, `metamask_login_template`.
- Actions: `metamask_login_failed`, `metamask_user_registered`.
- Theme template overrides in `your-theme/metamask-login/`.
- Translation template `languages/metamask-login-addon.pot`.
- PHPUnit crypto tests, a Playwright e2e suite with a mock MetaMask, GitHub Actions CI (PHP 7.4–8.4), and docs in `docs/`.

### Changed
- Minimum WordPress version is 6.5; tested up to 7.1.
- Front-end script rewritten in vanilla JavaScript (no jQuery), with accessible `aria-live` status messages and clearer wording. The separate admin profile script was merged into it; `metamask-login-admin.js` now only powers the settings screen.
- Text domain changed from `metamask-login` to `metamask-login-addon` (matches the plugin slug).
- Wallet addresses are stored lowercase.
- REST errors use the standard WordPress error format (`code`, `message`, `data.status`).
- Settings saved by 2.x are migrated (`custom_sign_message` becomes `sign_statement`; `dev_mode` and `require_verification` are removed).
- Uninstall keeps wallet links unless the new setting is on.

### Removed
- `POST /verify` REST route and the `token` field of `/auth` responses (neither was used).
- The `authenticate` filter integration that expected wallet data in the core login form.
- `wp_ajax_metamask_verify_wallet` (linking verifies ownership itself).

### Fixed
- Signed messages were run through `sanitize_text_field()`, which stripped their line breaks, so signatures could never match.
- The "Enable on Login Page" and rate-limit settings were ignored.
- JavaScript `ReferenceError` in the MetaMask `accountsChanged` handler.
- Translation strings echoed without escaping on the activation notice and in templates.
- `_load_textdomain_just_in_time` notices on WordPress 6.7+ (translations now load on `init`).

## [2.0.0] - 2025-11-12
- Settings page, rate limiting, activation/deactivation/uninstall hooks, developer hooks, REST API.

## [1.0.0] - 2024-01-01
- Initial release.
