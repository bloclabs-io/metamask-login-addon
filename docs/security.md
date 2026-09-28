# Security model

## What a wallet login proves

A successful login proves that whoever clicked the button controls the private key of a wallet address, **right now, on this site**. That address is mapped to exactly one WordPress account.

## Sign-in flow

1. **Challenge.** The browser sends the wallet address. The server creates an [EIP-4361](https://eips.ethereum.org/EIPS/eip-4361) (Sign-In with Ethereum) message containing:
   - the site's domain and URI,
   - the EIP-55 checksummed address,
   - a 17-character random nonce,
   - *Issued At* and *Expiration Time* (5 minutes).

   The message is stored server-side in a transient keyed by the nonce, together with the address, the purpose (`login` or `link`), the user ID (for `link`), and a SHA-256 hash of a random secret. The secret goes to the browser as an `HttpOnly; SameSite=Strict` cookie named `wp_metamask_challenge_{nonce}`, so several tabs can have challenges open at once. If the cookie can't be sent, no challenge is issued.
2. **Signature.** MetaMask shows the message and signs it with `personal_sign` (EIP-191).
3. **Verification.** The browser sends the address, nonce and signature. The server then:
   1. loads **and deletes** the stored challenge. Only the request whose delete actually removed the row may continue, so the challenge is used once even with concurrent requests;
   2. checks that it hasn't expired and that the address, purpose, user and browser cookie all match;
   3. hashes the **stored** message (the client never supplies the message that gets verified) and recovers the signer with secp256k1;
   4. compares the recovered address with the claimed one;
   5. looks up the linked user, runs `metamask_login_authenticate`, core's `wp_authenticate_user` and the multisite spam check, then logs the user in with `wp_set_auth_cookie()` and fires the core `wp_login` action.

## Threats and mitigations

| Threat | Mitigation |
|---|---|
| Forged signature | Real secp256k1 public key recovery. r and s are range-checked, and r must be a valid curve x-coordinate. |
| Replay of a captured signature | Challenges are single-use, server-stored and expire after 5 minutes. |
| Signing a message chosen by the attacker | Only messages the server generated and stored are accepted. |
| Phishing site relaying your challenge | The SIWE domain is fixed to your site. MetaMask warns when the domain doesn't match the page the user is on. |
| Login CSRF (forcing a victim to log in as the attacker) | Every challenge is tied to a `SameSite=Strict` cookie in the browser that requested it, and a cross-site request can't send that cookie. Logged-in AJAX actions also need a WordPress nonce. |
| Plugins that ban, lock or hold accounts for approval | Wallet logins run core's `wp_authenticate_user` filter and `is_user_spammy()`, just like password logins. 2FA plugins that act on `wp_login` fail closed. Use `metamask_login_authenticate` to add explicit rules. |
| Wallet links saved by 2.x without proof | 2.x saved whatever address was posted from the profile form. On upgrade these links are moved to `metamask_wallet_address_unverified`, which is never used for login, until the owner signs a link challenge. |
| Linking someone else's wallet to your account | Linking needs a fresh `link` challenge signed by that wallet and bound to the logged-in user's ID. |
| Linking one wallet to several accounts | A wallet can belong to only one account (`409` otherwise). |
| Privilege escalation through sign-up | Sign-up is off by default. The role list excludes any role with a privileged capability (site options, users, plugins, themes, core updates, import/export, editing others' content, network or WooCommerce management; see `metamask_login_privileged_caps`), and the role is checked again when the account is created. |
| Brute force or resource exhaustion | Per-IP, fixed-window limits on challenge creation and on failed logins and links (configurable). Challenges expire automatically. |
| Spoofed `X-Forwarded-For` to dodge rate limits | Only `REMOTE_ADDR` is trusted by default. Trusted proxies can be configured with `metamask_login_client_ip`. |
| Open redirect after login | `redirect_to` goes through `wp_validate_redirect()`, so only same-site destinations are allowed. |
| XSS | All output is escaped. Status messages are inserted with `textContent`. |

## Signature verification code

`includes/crypto/` holds a small pure-PHP implementation:

- **Keccak-256** (the original Keccak padding `0x01`, *not* NIST SHA3-256, which PHP's `hash()` provides).
- **secp256k1 public key recovery.** 256-bit numbers are 10 limbs of 28 bits. Modular multiplication uses Montgomery reduction (CIOS), so no big-number division is needed. Point arithmetic uses Jacobian coordinates, and `u1·G + u2·R` is computed with Shamir's trick.

Only public data (message hash and signature) is processed, and no private keys are ever handled, so the code doesn't need to be constant-time. One recovery takes roughly 50 ms on PHP 8.

It is tested against [ethers.js](https://docs.ethers.org/) vectors (`tests/php/fixtures/vectors.json`), including multi-block Keccak inputs, Unicode messages, high-s signatures and malformed inputs. A self-test also runs every time you open the settings screen.

To use your own verifier (for example one backed by `ext-secp256k1`), return the recovered address from `metamask_login_custom_signature_verify`.

## Hardening tips

- Serve the site over HTTPS so auth cookies are `Secure`.
- If your site sits behind a CDN or reverse proxy, set `metamask_login_client_ip` so rate limiting sees real visitor IPs.
- For administrator accounts, consider requiring a second factor with `metamask_login_authenticate`.
- Leave **Create accounts for new wallets** off unless you need open registration.

## Reporting a vulnerability

Please report security issues privately to the maintainers through GitHub's **Report a vulnerability** (Security tab) rather than in a public issue.
