# Getting started

This guide is for site owners. You don't need to know anything about code or blockchains.

## 1. Install and activate

1. Go to **Plugins → Add New Plugin → Upload Plugin** and upload the plugin ZIP.
2. Click **Activate**.
3. A blue notice says *"MetaMask Login is active."* Click **Finish setup**. You can also open **Settings → MetaMask Login** at any time.

## 2. Connect your own wallet

You need the [MetaMask browser extension](https://metamask.io/download/) for this step.

1. On **Settings → MetaMask Login**, find step 1 of **Get started in 3 steps** and click **Connect MetaMask**.
2. MetaMask opens and asks which account to share. Pick one and click **Connect**.
3. MetaMask shows a **sign-in request** with your site's address and a short message. Click **Confirm** (or **Sign**).
   - Signing is free. It is **not** a transaction and costs no gas.
4. The card changes to **Connected** and shows your address.

You can do the same from **Users → Profile → MetaMask Wallet**.

## 3. Check the Status panel

The **Status** panel on the right of the settings screen should show green checks for:

- **Signature verification**: the server can check MetaMask signatures (self-test).
- **HTTPS**: needed on live sites, not on local test sites.
- **Login page button**: the button is visible on the login screen.
- **Your wallet**: your account has a connected wallet.

A yellow or red item comes with a sentence telling you what to do.

## 4. Try it

1. Click **Open login screen** (step 3). It's best to do this in a private or incognito window where MetaMask is also enabled.
2. Click **Log in with MetaMask** under the normal login form.
3. Approve the request in MetaMask. You land on the Dashboard.

## 5. Let your users connect their wallets

Every user connects their own wallet once, from **Users → Profile** (or from a page where you placed the `[metamask_profile]` shortcode). After that they can log in with MetaMask.

Want new visitors to be able to sign up with only a wallet? Turn on **Create accounts for new wallets** under **New users**, and pick the role those accounts should get (Subscriber is a safe choice).

## 6. Put the button where you want it

- **Login screen:** on by default. Switch **Show on the login screen** off to hide it there.
- **Any page or post:** add the **MetaMask Login** block, or type `[metamask_login]` in a Shortcode block.
- **A "my wallet" page:** add `[metamask_profile]` so logged-in users can connect or disconnect their wallet without visiting wp-admin.

Change the button's text and colour under **Login button**. The **Preview** updates as you type.

## Frequently asked questions

**Do people still need a password?**
Their normal password keeps working. MetaMask is an extra, faster way in.

**Does this cost anything or touch the blockchain?**
No. Users only sign a text message. Nothing is sent to any blockchain.

**Which networks are supported?**
All of them. Login doesn't depend on the network selected in MetaMask. The chain ID is only included in the signed message for information.

**What if someone loses access to their wallet?**
They log in with their password (or reset it) and connect a new wallet from their profile. An administrator can also disconnect a user's wallet from **Users → Edit user**.

**Can two accounts use the same wallet?**
No. A wallet can be connected to only one account, so it always identifies exactly one user.

**Does it work on phones?**
Yes. Open your site inside the MetaMask mobile app's browser. When someone taps the button in a normal mobile browser, the plugin offers an **Open in the MetaMask app** link.
