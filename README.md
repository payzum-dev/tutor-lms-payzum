# Payzum for Tutor LMS — Accept Crypto & Stablecoin Payments (USDC, USDT)

Accept **cryptocurrency and stablecoin payments** (USDC, USDT and more, multi-chain) for
[Tutor LMS](https://tutorlms.com) course orders through [Payzum](https://payzum.com) —
**non-custodial**: funds settle directly to your own wallet, Payzum never takes
custody. Works with Tutor LMS's own native ecommerce (Tutor 3.0+, free) — no
Tutor Pro, no WooCommerce. No chargebacks, no card networks, no PCI surface.

- **Plugin:** `payzum-for-tutor-lms` · **Version:** 1.0.0 · **License:** MIT
- **Requires:** WordPress ≥ 6.0, Tutor LMS 3.0+ (native ecommerce), PHP ≥ 8.1

## How it works

1. The student picks **Payzum** at the Tutor checkout and is redirected to a
   hosted checkout page (QR code + deposit address, live status), where they
   choose the coin and chain and send the payment. No wallet or card data
   touches your server.
2. Crypto confirmation is **asynchronous**, so the order is marked paid — and
   the student enrolled — from Payzum's signed server-to-server IPN webhook,
   never from the browser return. A closed tab never loses a paid enrollment.
3. Every webhook is verified with **HMAC-SHA-512 over the raw request bytes**
   (constant-time compare, replay window) before a single field of it is read.
   Redelivered webhooks are deduplicated, the settled amount and currency are
   re-checked against the order, and the whole transition runs under a
   per-order lock — a student is never enrolled twice, and a partial payment
   never unlocks a course.

## Features

- **Stablecoin-first**: USDC and USDT across multiple chains (Polygon, Ethereum,
  Arbitrum, Base, Optimism, Tron, Solana and more), plus major cryptocurrencies.
- **Non-custodial** — course revenue settles to the site owner's own wallet.
- **Native Tutor LMS gateway** — built on Tutor's own payment gateway
  framework, side by side with PayPal; registers with its own icon in
  Tutor's payment settings.
- **Automatic enrollment** on settlement, driven by the signed IPN.
- **Signed IPN webhooks** (HMAC-SHA-512) with amount/currency verification,
  event deduplication and per-order locking.
- **Zero chargebacks** — crypto payments are final.

## Installation

**From the release zip (recommended).** Download
[`payzum-for-tutor-lms-1.0.0.zip`](https://github.com/payzum-dev/tutor-lms-payzum/releases/latest), then in WordPress go to
**Plugins → Add New → Upload Plugin**, pick the zip and activate it (requires Tutor LMS 3.0+). The archive unpacks to
`payzum-for-tutor-lms/`, the folder name WordPress expects.

**From a clone.** This repository *is* the plugin, so its contents must land in a folder named
`payzum-for-tutor-lms`:

```bash
git clone https://github.com/payzum-dev/tutor-lms-payzum.git payzum-for-tutor-lms
mv payzum-for-tutor-lms /path/to/wp-content/plugins/
```

Do not drop the repository in as `tutor-lms-payzum` — WordPress keys the plugin off the folder name.

The official [`payzum/payzum-php`](https://packagist.org/packages/payzum/payzum-php) SDK is
vendored, so no composer step is needed.

## Configuration

Go to **Tutor LMS → Settings → Payment Methods** and enable **Payzum**:

| Setting | Meaning |
|---|---|
| API key | From your [Payzum merchant dashboard](https://merchant.payzum.com) |
| Webhook secret | Verifies incoming payment webhooks (IPN) |
| Environment | Production or staging (staging needs its own API key) |

Paste the webhook URL
(`https://<your-site>/wp-json/tutor/v1/ecommerce-webhook/payzum`) into the
Payzum dashboard webhook settings. The signature header is fixed; nothing to
configure.

The student chooses the coin on the Payzum checkout, limited to the allowlist
you configure in the Payzum dashboard (**Merchants → Settings → Accepted
tokens**) and enforced server-side.

## Order status mapping

| Payzum payment status | Tutor LMS order | Enrollment |
|---|---|---|
| `finished` (amount and currency match the order) | Paid / completed | Student enrolled |
| `finished` (settled amount or currency does not match) | Unchanged, logged for review | Not enrolled |
| `partially_paid` | Unchanged (underpaid) | Not enrolled |
| `expired` / `failed` (on a pending order) | Failed / cancelled | Not enrolled |
| anything else | Acknowledged, no change | Unchanged |

Subscriptions are not offered (`support_subscription: false`): crypto has no
recurring pull, so the gateway covers one-time course and bundle orders.

## FAQ

**Is Payzum custodial?**
No. Funds settle directly to your own wallet — Payzum never holds your money.

**Can Tutor LMS accept USDT or USDC for courses?**
Yes — with this plugin, students pay for courses in USDC, USDT or other assets
on the chains you allow, and they are enrolled automatically when the payment
settles.

**Does it need WooCommerce or Tutor Pro?**
No. It plugs into Tutor LMS's own native ecommerce, available in the free
Tutor 3.0+.

**Do students need an account or a specific wallet?**
No. They scan a QR or copy a deposit address from the Payzum checkout and pay
from any wallet.

**Can a partial payment unlock a course?**
No. Enrollment happens only when the settled amount and currency match the
order; anything less is acknowledged without enrolling.

**What data is shared with Payzum?**
Only the order total, currency, an order reference and your site's callback
URLs — no student personal data. Endpoints: `https://merchant.payzum.com`
(production), `https://staging.payzum.com` (staging).

## Related Payzum integrations

Payzum ships official plugins for most major e-commerce, donation and billing
platforms — WooCommerce, Easy Digital Downloads, GiveWP, Paid Memberships Pro,
Fluent Forms, Magento 2, PrestaShop, Shopware 6, OpenCart, Zen Cart,
nopCommerce, Ecwid, BigCommerce, Shopify, Wix, Medusa, Vendure, Saleor, Sylius,
WHMCS, Blesta, HostBill, ClientExec, pretix, Frappe/ERPNext, Akaunting and
django-payments — plus official SDKs for PHP, Node.js/TypeScript, Python and
Rust. Browse them all at [github.com/payzum-dev](https://github.com/payzum-dev).

## About Payzum

[Payzum](https://payzum.com) is a non-custodial crypto payment gateway for
merchants: accept USDC, USDT and other digital assets with settlement straight
to your own wallet, optional auto-conversion to stablecoins, and a single REST
API. API docs: [merchant.payzum.com/api/docs](https://merchant.payzum.com/api/docs).

## License

[MIT](LICENSE). Contributed and maintained by Payzum.
