# Direct payment API: not part of the public SDK

[← Back to README](../README.md)

**Direct payment (`MakePayment`, `ValidatePayment`, card / account / USSD charging, PIN and OTP handling) isn't part
of this SDK's public API.** Use [Hosted Checkout](hosted-checkout.md).

## Why

Core doesn't accept a plain payment object from merchants. Its wire contract is:

```http
POST /api/Payments/MakePayment
Authorization: Bearer <checkout-payment-token>      ← from GetPaymentInfo, not your pk_ key
Content-Type: application/json

{ "payload": "<encrypted-payment-request>" }
```

Core decrypts `payload` with the checkout payment token before reading it. Rebuilding that encryption without an
approved public specification would be fragile and unsafe. It would also put every merchant using it in full PCI DSS scope,
because their servers would handle PAN, CVV and PIN.

Responses also include next-action and retry semantics that a direct integration must honour exactly. For example:
`09` means a virtual account was created; `06`/`07` mean waiting for OTP / 3DS; `01` with `isRetryable: true`
means the PIN was invalid and may be retried; `05` with `isRetryable: false` means "outcome unknown: verify, don't charge
again".

## What has to be published first

A direct-payment module may be released **separately** once Payxy Core publishes:

- the encryption and key-derivation specification
- the token / bootstrap lifecycle
- PCI eligibility requirements
- retry and idempotency guarantees
- stable schemas for every payment method

## Reference: payment type values (Core)

| Integer | Value |
|---:|---|
| 0 | Card |
| 1 | Account |
| 2 | QR |
| 3 | USSD |
| 4 | Wallet |
| 5 | Transfer |
| 6 | ENaira |

`ValidatePayment` (`{transactionId, providerReference, otp, entryType}`, where `entryType` is 0 Frontend or 1 Merchant)
also uses the checkout token. A successful OTP response alone **doesn't** establish payment success: wait for the
webhook, or use VerifyPayment.
