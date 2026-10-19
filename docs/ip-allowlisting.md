# IP allowlisting

[← Back to README](../README.md)

Payxy can restrict Payment API calls (Initialize, VerifyPayment) to known source addresses. It's configured in the
merchant dashboard, **separately for test and production**. The public key you present selects which list applies.

## Behaviour

| Enforcement | Entries | Result |
|---|---|---|
| Disabled | any | All sources accepted |
| Enabled | none | All sources accepted (prevents accidental lockout while configuring) |
| Enabled | one or more | Only an exact IP, or an address inside a configured CIDR range, is accepted |

Entries can be single IPv4/IPv6 addresses or CIDR ranges (up to 100 per mode). Example:

```text
Test mode:        Enabled: true   Allowed: 203.0.113.10, 198.51.100.0/24
Production mode:  Enabled: true   Allowed: 192.0.2.25
```

## What to allowlist

**Do** add your backend's **public outbound (egress) IP**: every NAT gateway or proxy your servers call out through.

**Don't** add:

- the customer's browser IP
- private addresses such as `10.x.x.x`
- a developer workstation's temporary IP
- Payxy's webhook sender addresses (webhooks are inbound to you)

Serverless or autoscaled apps may need a fixed NAT egress address. Save every egress address **before** turning on
enforcement.

## In the SDK

A rejected source gets **HTTP 401**. The SDK raises `AuthenticationException`, whose message suggests checking the
key and the allowlist. It's **never retried**: treat it as an access or configuration error, not a transient failure.

Once enforcement is on, direct browser or mobile calls to the Payment API from customer networks are blocked. Keep
Initialize and VerifyPayment on your allowlisted backend, which is how this SDK is designed to be used.
