# Security and PCI

[← Back to README](../README.md)

> **Card details are collected by Payxy Hosted Checkout. They should not pass through the merchant's PHP application.**

This SDK has no API for submitting card numbers, CVVs, PINs or OTPs. Direct payment isn't part of the public SDK;
see [direct API](direct-api.md).

## What the SDK protects

| Risk | Protection |
|---|---|
| Wrong-mode payments | The mode comes from the key prefix. Mixed test and live keys, or a live key on the sandbox URL, are rejected at startup. |
| Secret key misuse | `sk_` values are rejected as API keys and never sent to Payxy. They're used only for local HMAC checks. |
| Credentials in logs and dumps | Keys are masked in `var_dump`/`print_r` and refuse `serialize()`. |
| Forged or replayed webhooks | HMAC-SHA512 over `timestamp.rawBody`, constant-time comparison, 300 s freshness window. |
| Fake "paid" redirects | Outcomes come only from VerifyPayment (`00` + `isSuccessful`) or a verified webhook. Callback query parameters are never trusted. |
| Underpayment or wrong currency | `data.amount` (never `customerPaid`) is compared with your order; a mismatch gives `AmountMismatch`. |
| Double charging | Initialize is never retried automatically. Ambiguous outcomes are reported as unknown. |
| Interception | HTTPS only, TLS verification on, redirects disabled in the default client. |
| Header and path injection | Keys are restricted to `[A-Za-z0-9_]`; path values are URL-encoded. |
| XSS in rendered checkout HTML | All values escaped, hex-only colors, `http(s)`-only URLs, CSP nonce support. |
| Oversized or malformed responses | 2 MiB cap, JSON depth limit, strict decoding. |

## Logging and redaction

The SDK's PSR-3 log lines contain only `method`, `path` (as a template, e.g. `/api/Payments/GetPaymentInfo/{accessCode}`),
`status`, `attempt` and `duration_ms`. **It never logs headers or bodies**, so none of the following can appear:

- public keys and secret keys
- `Authorization` headers
- checkout tokens and realtime tokens (from GetPaymentInfo)
- card numbers, CVV, PIN, OTP
- raw encrypted payment payloads

A test asserts that neither the key nor the `Authorization` header reaches the logger. In your own code, don't log
`$response->toArray()` from GetPaymentInfo, because it contains the checkout and realtime tokens.

## SignalR stays out of the server SDK

Payxy's realtime hub (`https://api.payxy.ng/paymentHub`, authorized by the short-lived `paymentRealtimeToken`)
mainly serves the browser checkout. Server-side code should rely on **webhooks** for push updates and
**VerifyPayment** for reconciliation. The PHP SDK deliberately has no SignalR client.

## Your responsibilities

- Keep `PAYXY_PUBLIC_KEY` and `PAYXY_WEBHOOK_SECRET` in environment config or a secret store.
- Serve your site over HTTPS.
- Save the transaction ID before redirecting, and confirm with that ID, not the browser's.
- Make callback and webhook handling idempotent.
- Implement `expectedAmount()` (or your own amount check) from your own records.
