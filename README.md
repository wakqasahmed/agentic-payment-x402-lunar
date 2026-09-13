# Agentic Pay for Lunar

[![Latest Version on Packagist](https://img.shields.io/packagist/v/aiopsome/agentic-payment-x402-lunar.svg?style=flat-square)](https://packagist.org/packages/aiopsome/agentic-payment-x402-lunar)
[![Total Downloads](https://img.shields.io/packagist/dt/aiopsome/agentic-payment-x402-lunar.svg?style=flat-square)](https://packagist.org/packages/aiopsome/agentic-payment-x402-lunar)
[![PHP Version](https://img.shields.io/packagist/dependency-v/aiopsome/agentic-payment-x402-lunar/php.svg?style=flat-square)](https://packagist.org/packages/aiopsome/agentic-payment-x402-lunar)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg?style=flat-square)](LICENSE)
[![Lunar](https://img.shields.io/badge/Lunar-v1.0%2B-5B21B6.svg?style=flat-square)](https://lunarphp.io)
[![Protocol](https://img.shields.io/badge/protocol-x402%20v2-blueviolet.svg?style=flat-square)](https://x402.org)
[![Network](https://img.shields.io/badge/network-Base%20(8453)-0052FF.svg?style=flat-square)](https://base.org)

> **Package:** `aiopsome/agentic-payment-x402-lunar` · **Ecosystem:** [AIOpsome Agentic Commerce](https://github.com/AIOpsome)

Crypto and x402 payment driver for [Lunar](https://lunarphp.io) — accept stablecoin payments from humans (wallet-signed on-chain transfer at checkout) and AI shopping agents (x402 HTTP-402 payment challenge/response). USDC on Base, via the [x402 protocol](https://github.com/coinbase/x402) (v2).

Part of the **Agentic Pay** suite by [AIOpsome](https://aiopsome.com):
- 🛒 **[Agentic Pay for WooCommerce](https://github.com/AIOpsome/agentic-payment-x402-woocommerce)** — WordPress / WooCommerce Gateway (`agentic-pay-for-woocommerce`)
- 🛍️ **[Agentic Pay for Vendure](https://github.com/AIOpsome/agentic-payment-x402-vendure)** — Vendure Plugin (`agentic-payment-x402-vendure`)
- 🌙 **[Agentic Pay for Lunar](https://github.com/AIOpsome/agentic-payment-x402-lunar)** — Lunar Driver (`aiopsome/agentic-payment-x402-lunar`)

**Status: beta.** Settlement core, payload/response cross-validation, an
audit-trail/idempotency guard, and both checkout entry points are
implemented and covered by an end-to-end integration test suite (real Lunar
Cart → Order → placed order, against a real Currency/Product/Price graph —
not just mocked HTTP). Known gaps are listed under
[Known limitations](#known-limitations) below; none of them are silent —
each fails closed with a clear error rather than doing the wrong thing
quietly.

## Requirements

- PHP ^8.2 with the `bcmath`, `intl`, and `exif` extensions (all required by
  `lunarphp/core` itself)
- Laravel 11 or 12
- `lunarphp/core` ^1.0

## Installation

```bash
composer require aiopsome/agentic-payment-x402-lunar
php artisan vendor:publish --tag=lunar-crypto.config
php artisan migrate
```

Set the required environment variables (a merchant `pay_to` wallet address
is the only one without a default — see `config/crypto.php` for the rest):

```env
LUNAR_CRYPTO_PAY_TO=0xYourWalletAddress
LUNAR_CRYPTO_X402_PAY_TO=0xYourWalletAddress
```

## Usage

### Human checkout

Register `crypto` alongside your other payment types
(`config/lunar/payments.php` or wherever your app configures Lunar's
`PaymentTypeInterface` drivers), then have your checkout frontend post the
shopper's signed x402 `PaymentPayload` (from their wallet) as cart data
under `payment_payload` before calling `authorize()` — same shape as any
other Lunar payment type, e.g. `lunarphp/stripe`.

### Agent checkout (x402)

Bind an x402-protected route to a `Cart` your app has already built/priced
via its own product/checkout API:

```php
use Illuminate\Support\Facades\Route;

Route::post('/x402/carts/{cart}/checkout', CompleteCheckoutController::class)
    ->middleware('x402');
```

An agent hitting that route with no `X-PAYMENT` header gets a 402 response
with payment requirements; retrying with a signed `X-PAYMENT` header
settles the cart into a placed order, exactly like the human flow.

## Design

Both entry points settle into the same place — a Lunar cart becoming a
placed order — via one shared pipeline:

- `PaymentTypes\CryptoPaymentType` — implements Lunar's `PaymentTypeInterface`
  for human checkout, same pattern as `lunarphp/stripe`. The frontend has the
  shopper sign an EIP-3009 transfer (e.g. USDC) and posts the resulting x402
  `PaymentPayload` as cart data; `authorize()` delegates to
  `AuthorizeCryptoPayment`.
- `X402\X402PaymentMiddleware` — wraps a cart-checkout route for agent
  payments. Not a `PaymentTypeInterface` implementation — x402 is an HTTP 402
  challenge on a route, not something Lunar's checkout invokes — but it
  resolves a `Cart` via Laravel route-model binding (`{cart}` route
  parameter) and delegates to the exact same `AuthorizeCryptoPayment`. The
  consuming app is responsible for building/pricing the cart itself (its own
  product/checkout API) before ever hitting an x402-protected route — this
  middleware only owns "is this cart paid for", the same boundary
  `CryptoPaymentType` has for human checkout.
- `Actions\AuthorizeCryptoPayment` — the one place a signed payload becomes a
  placed order: resolves/creates the order for a cart, checks the
  `CryptoSettlement` idempotency guard, settles, and finalizes. Both entry
  points call this directly rather than duplicating it, so there's exactly
  one settle-then-record-then-finalize implementation to get right.
- `Actions\SettleOnChainPayment` — the facilitator verify/settle core.
  Calls a facilitator's `/verify` then `/settle` per the
  [x402 v2 spec](https://github.com/coinbase/x402/blob/main/specs/x402-specification-v2.md),
  trying `lunar-crypto.facilitator_order` in turn until one succeeds.
- `Actions\BuildPaymentRequirements` — turns any Lunar `Price` (a cart's or
  an order's `->total`) into an x402 `PaymentRequirements` object, via...
- `Actions\ConvertToAssetUnits` — ...which rescales that total from the store
  currency's minor unit (e.g. cents) to the asset's atomic unit (USDC = 6
  decimals). Refuses (rather than silently mispricing) if the store currency
  isn't the configured `pegged_currency` — no FX conversion is implemented.
- `Actions\ValidatePaymentPayload` — checked before a payload ever reaches a
  facilitator: size cap, required fields, and (critically) the *signed*
  `authorization.value`/`.to`, not just the client-echoed `accepted.*` —
  see below. Since requirements are always rebuilt fresh from the order's
  *current* total, this also catches quote-to-settlement drift.
- `Actions\ValidateCryptoConfig` — run at service-provider boot: cross-checks
  the configured asset address against the configured network (both the
  human-checkout and x402 networks) for the networks it knows about,
  catching a testnet/mainnet mismatch at deploy time instead of at the first
  checkout.
- `Actions\GuardPayeeAddressChange` — run on every `authorize()`/x402
  attempt: compares the configured `pay_to` (checked independently for
  human checkout and x402) against the last-confirmed address recorded in
  `crypto_payee_configs`. A first-ever value is recorded and allowed
  through; an unchanged value is a no-op; a *changed* value is logged
  loudly (`Log::critical`) and fails the attempt closed with
  `PayeeAddressChangedException` — settlement stays blocked until an
  operator explicitly re-confirms the new address with
  `php artisan lunar-crypto:confirm-payee {pay_to|x402_pay_to}`. Guards
  against a compromised or mistyped `.env`/deploy pipeline silently
  redirecting future settlements to a different wallet — mirrors our
  sibling `agentic-pay-woocommerce` package's `PayeeAddressChangeGuard`,
  adapted for a package with no settings UI to gate a confirmation
  checkbox on.
- `Models\CryptoSettlement` — an audit-trail row written the instant a
  facilitator confirms settlement, before the order transaction/`placed_at`
  writes that follow it. On-chain settlement can't be undone; if those writes
  fail, this row is the proof funds already moved, and a retried attempt
  resumes from it instead of settling (and charging) again.

`SettleOnChainPayment` also doesn't trust a facilitator's `/settle` response
blindly — a reported amount/network that doesn't match what was requested,
or a missing (required, per spec) `network` field, is treated as a failed
settlement, not a successful one.

`X402PaymentMiddleware` rate-limits by requester IP
(`lunar-crypto.x402.rate_limit`, default 30/minute) before doing anything
that costs a facilitator round-trip — including the initial 402 challenge
itself, so that's not free to spam either. Caveat: `Request::ip()` is
spoofable if the consuming app trusts all proxies (`TrustProxies` set to
`*`) — that's an app-level trust-boundary decision this package can't make
for you, so configure `TrustProxies` correctly if you're behind a load
balancer.

`ValidatePaymentPayload` checks the *signed* `authorization.value`/`.to`
against requirements, not just the client-echoed `accepted.amount`/`.payTo`
— a client rewriting the unsigned `accepted` block to match requirements
would otherwise sail through a check that only compared `accepted.*`
against itself, while having actually signed for a different amount or
recipient. A cold-start review (2026-08-26) caught this in an earlier
version of this validation; the exact bypass it found is now a regression
test (`ValidatePaymentPayloadTest` and, end to end, `CryptoCheckoutTest`).

### Facilitators

Verified live against each facilitator's `/supported` endpoint:

| Facilitator | Auth | Base mainnet | Base Sepolia (testnet) |
| --- | --- | --- | --- |
| **PayAI** (default) | none | ✅ | ✅ |
| Coinbase public (`x402.org/facilitator`) | none | ❌ | ✅ |
| Coinbase CDP-hosted | CDP API key/secret | ✅ | ✅ |

PayAI is the default in `facilitator_order` because it's the only
unauthenticated option that supports Base mainnet. Coinbase's free public
facilitator is configured but excluded from the default order — it only
settles testnet, so silently including it in a mainnet fallback chain would
be misleading. Coinbase's CDP-hosted facilitator supports mainnet with
authenticated requests (CDP API key ID + secret) and can be added to
`facilitator_order` — see `config/crypto.php`.

## Known limitations

- **Refunds are not implemented.** x402/EIP-3009 is a pull-payment model —
  there's no "reverse this charge" call on the facilitator. A refund means
  the *merchant's own wallet* signing and broadcasting a fresh outbound
  transfer back to the payer, which means this package would need to
  custody a merchant signing key — a materially different (and
  security-sensitive) scope than accepting payments. `CryptoPaymentType::refund()`
  fails gracefully (`PaymentRefund(success: false, ...)`, not an exception)
  rather than pretending to support it. Refund manually via your payee
  wallet in the interim.
- x402 assumes the consuming app already has its own cart-building API —
  Lunar doesn't ship one by default (that's a storefront/headless-API
  concern), so an x402-protected route needs a `Cart` to bind to.

## Prior art / lessons folded in

[`financedistrict-platform/saleor-agentic-commerce`](https://github.com/financedistrict-platform/saleor-agentic-commerce)
ships an equivalent x402/EIP-3009 stablecoin handler for Saleor (not
portable here — different stack). Their merged
[#65](https://github.com/financedistrict-platform/saleor-agentic-commerce/pull/65)
(SAC-2) found that settling on-chain and then writing the order can silently
lose the audit trail if the order write fails after money has already moved.
`CryptoSettlement` exists specifically to avoid that class of bug here.

A cross-repo edge-case audit (2026-08-26, see
[#3](https://github.com/AIOpsome/agentic-payment-x402-lunar/issues/3)) also
caught the same major/minor-unit mismatch that financedistrict-platform hit
in production (Saleor PR #34) — this package's order total was being sent to
the facilitator without converting from the store currency's minor unit to
the asset's atomic unit. Fixed by `ConvertToAssetUnits`. The same audit
filed 4 more hardening issues, all addressed:
[#4](https://github.com/AIOpsome/agentic-payment-x402-lunar/issues/4) facilitator
response cross-validation, [#5](https://github.com/AIOpsome/agentic-payment-x402-lunar/issues/5)
payload validation, [#6](https://github.com/AIOpsome/agentic-payment-x402-lunar/issues/6)
x402 rate limiting, [#7](https://github.com/AIOpsome/agentic-payment-x402-lunar/issues/7)
network/asset config sanity check (payee-change protection from the same
issue is still open — see [Known limitations](#known-limitations)), and
[#8](https://github.com/AIOpsome/agentic-payment-x402-lunar/issues/8)
order-total drift.

## Testing

```bash
composer install
vendor/bin/pest
```

`tests/Unit` covers pure-logic Actions (no Eloquent). `tests/Feature`
boots a real Lunar application against an in-memory SQLite database — a
real `Currency`/`Channel`/`Product`/`ProductVariant`/`Price`/`Cart` graph,
real HTTP requests through `X402PaymentMiddleware`, a real
`CryptoPaymentType::authorize()` call, a real placed `Order`. Also covers:
the retry/idempotency path, the concurrent-request lock, the refund
graceful-failure path, and the exact signed-vs-echoed-amount bypass a
cold-start review caught (see above).

**What these tests do *not* prove:** cryptographic signature verification.
Every test payload's `signature` is an opaque placeholder string —
`ValidatePaymentPayload` only checks it's a non-empty string, and the
facilitator (the only party that actually verifies an EIP-712 signature
against the signer address) is mocked throughout. This package never
verifies a signature itself — that's delegated entirely to the facilitator,
by design (see `Actions\SettleOnChainPayment`). What's tested is everything
*this package* is responsible for: pricing, request/response validation,
idempotency, and finalization — not the facilitator's own cryptography.

Only the HTTP calls are mocked; everything else — including the extensions
`lunarphp/core` itself needs (`bcmath`, `intl`, `exif`) — runs for real. CI
(`.github/workflows/tests.yml`) installs them via `shivammathur/setup-php`.
If you don't have those extensions locally, a disposable container works
just as well:

```bash
docker run --rm -v "$(pwd)":/app -w /app php:8.2-cli bash -c \
  "apt-get update && apt-get install -y libicu-dev libzip-dev git unzip && \
   docker-php-ext-install bcmath intl exif && \
   curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer && \
   composer install --no-interaction && vendor/bin/pest"
```

## License

MIT
