# Testing

The plugin has two test suites:

| Suite | Tool | Location | Needs WordPress? |
|---|---|---|---|
| Unit | PHPUnit 9.6 | `tests/php/` | No |
| End-to-end | Playwright | `tests/e2e/` | Yes, a running site |

## Unit tests

```bash
composer install
composer test        # runs vendor/bin/phpunit
composer lint        # php -l on every file
```

These cover Keccak-256 (including block-boundary inputs), EIP-191 message hashing, secp256k1 signer recovery for 20 ethers.js signatures, raw and legacy `v` values, high-s signatures, malformed or out-of-range signatures, EIP-55 checksums and address validation.

To regenerate the fixtures, sign messages with ethers.js v6 (`wallet.signMessage`) and add them to `tests/php/fixtures/vectors.json`.

## End-to-end tests

The e2e suite drives a real browser against a real WordPress site. A mock MetaMask is injected into each page. It implements EIP-1193 `request()` and announces itself through EIP-6963, and it answers `personal_sign` with a real ethers.js wallet, so every signature the server checks is genuine.

### What is covered

- The login screen button (placement, text) and the "not installed" fallback
- Unknown wallet error and cancelled signature
- Settings screen: setup steps, passing self-test, live preview, dependent fields
- Connecting a wallet from the profile (and the format of the SIWE message)
- Logging in with MetaMask, including `redirect_to`
- Rejecting a signature made by a different key
- Rejecting a replayed login request
- Block and shortcodes on a front-end page (login returns to the page, profile card, balance)
- Block registration (block API v3)
- Automatic sign-up (role, username, redirect to profile)
- REST API: challenge bound to the requesting client, two open challenges at once, then a successful login
- Logged-out AJAX without the page nonce (cached pages), while logged-in actions still require it
- Disconnecting a wallet
- Rate limiting (the 6th failure returns 429)

### Running

1. Start a WordPress site with this plugin active. Any of these works:
   - `npx @wordpress/env start` (Docker) in the plugin folder: the site is at `http://localhost:8888`, admin `admin` / `password`.
   - Local, DevKinsta, MAMP and similar tools.
   - WordPress with the [SQLite Database Integration](https://github.com/WordPress/sqlite-database-integration) plugin and `php -S`. This is what the 3.0.0 release was verified with.
2. Run the suite:

```bash
cd tests/e2e
npm install
# Chromium must be available: npx playwright install chromium
WP_BASE_URL=http://localhost:8888 \
WP_ADMIN_USER=admin \
WP_ADMIN_PASS=password \
WP_CLI="npx @wordpress/env run cli wp" \
npx playwright test
```

`WP_CLI` is optional. When it's set, the global setup resets the plugin's options and transients first, so the suite can be re-run on the same site. Without it, run the suite on a fresh site: the rate-limit test assumes no recent failures from your IP.

The suite runs serially (it changes site settings) and takes about 40 seconds.

## Release checklist

1. `composer lint && composer test`
2. Run the e2e suite on the lowest supported (6.5) and the latest WordPress version (3.0.0: 6.5.12 and 7.1.2).
3. Check `wp-content/debug.log` for notices from the plugin (`WP_DEBUG_LOG`).
4. Update the version in `metamask-login-addon.php` (header and `METAMASK_LOGIN_VERSION`), `blocks/login-button/block.json`, `blocks/login-button/index.asset.php`, `readme.txt` (Stable tag and changelog) and `CHANGELOG.md`.
5. Regenerate `languages/metamask-login-addon.pot` with `wp i18n make-pot . languages/metamask-login-addon.pot --exclude=vendor,tests,node_modules`.
