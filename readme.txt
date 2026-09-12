=== Payzum for Tutor LMS ===
Contributors: payzum
Tags: crypto, stablecoin, payments, tutor lms, usdc
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Accept crypto & stablecoin payments (USDC, USDT and more, multi-chain) for Tutor LMS course orders — non-custodial, funds settle to your own wallet.

== Description ==

Adds Payzum as a native payment gateway for Tutor LMS's own ecommerce (Tutor LMS 3.0+, free version — no Tutor Pro or WooCommerce required).

* The student picks the crypto method at checkout and is redirected to a hosted checkout page, where they choose the asset and network and send the payment. No wallet data touches your server.
* Crypto confirmation is asynchronous, so the order is completed — and the student enrolled — from Payzum's signed server-to-server notification, never from the browser return.
* Every notification is verified with HMAC-SHA-512 over the raw request bytes (with a replay window) before a single field of it is read, and the invoice amount and currency are re-checked against the order before it is completed. Redelivered notifications are a no-op, so a student is never enrolled twice and a late "expired" never cancels a paid order.
* Non-custodial: funds settle directly to your own wallet. Payzum never takes custody.

Subscription plans are not supported — crypto has no card on file to pull from; the gateway registers with subscriptions disabled so Tutor never offers it there.

== Installation ==

1. Install and activate Tutor LMS 3.0 or newer, with monetization set to Tutor's native ecommerce.
2. Upload and activate this plugin.
3. Go to Tutor LMS → Settings → Payment Methods → Payzum, and paste your API key and webhook secret (from merchant.payzum.com).

There is nothing to configure in the Payzum dashboard: the notification URL is sent with every invoice.

== Changelog ==

= 1.0.0 =
* Initial release.
