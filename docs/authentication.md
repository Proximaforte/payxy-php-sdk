# Authentication

[← Back to README](../README.md)

## Credentials

| Credential | Purpose | Sent to Payxy? |
|---|---|---|
| `pk_test_...` | Authenticates **test** API requests | Yes, as `Authorization: Bearer pk_test_...` |
| `pk_live_...` | Authenticates **production** API requests | Yes, as `Authorization: Bearer pk_live_...` |
| `sk_test_...` | Verifies signatures on **test** webhooks | **Never** |
| `sk_live_...` | Verifies signatures on **production** webhooks | **Never** |

Secret keys (`sk_`) are **not** accepted as API credentials. They are used only locally, to check
webhook signatures.

## Configuration

```dotenv
PAYXY_PUBLIC_KEY=pk_test_xxx
PAYXY_WEBHOOK_SECRET=sk_test_xxx
# Optional override (proxy, mock); otherwise derived from the public key
# PAYXY_BASE_URL=https://api.payxy.ng
```

```php
use Payxy\Payxy;
use Payxy\PayxyConfig;

$payxy = new Payxy(new PayxyConfig(
    publicKey: $_ENV['PAYXY_PUBLIC_KEY'],
    webhookSecret: $_ENV['PAYXY_WEBHOOK_SECRET'] ?? null,
    baseUrl: $_ENV['PAYXY_BASE_URL'] ?? null,
));
```

| Option | Default | Notes |
|---|---|---|
| `publicKey` | *required* | `pk_test_` or `pk_live_`. An `sk_` value is rejected with `ConfigurationException`. |
| `webhookSecret` | `null` | `sk_test_` / `sk_live_`. Must belong to the same mode as the public key. |
| `baseUrl` | from the key | `https` only (plain `http` allowed for `localhost`). A live key pointed at the sandbox URL (or vice versa) is rejected. |
| `timeoutSeconds` / `connectTimeoutSeconds` | `30` / `10` | |
| `retryPolicy` | Verify: 2 s, 5 s, 10 s | See [errors and retries](errors-and-retries.md). |
| `maxResponseBytes` | 2 MiB | |
| `userAgentSuffix` | `null` | |

## Mode selection

The mode comes **only** from the public-key prefix:

- `pk_test_` → Test
- `pk_live_` → Production

There is no separate SDK "mode" option. The `mode` field of the Initialize request is deprecated: Core
ignores it when it conflicts with the key, so the SDK never sends it.

`$payxy->getConfig()->environment` tells you which mode is in use (`Environment::Sandbox` or `Environment::Production`).

### Base URLs

| Mode | Default API URL |
|---|---|
| Test | `https://api.payxy.ng` |
| Production | `https://api.payxy.africa` (as published on docs.payxy.africa; still to be confirmed with Payxy, see [README § Status](../README.md#status-confirmed-vs-pending)) |

Set `baseUrl` explicitly if Payxy gives you a different URL.

## Handling keys safely

- Keep keys in environment variables or a secret store, never in source control.
- The SDK never logs either credential. Its log lines contain only method, path template, status, attempt and duration.
- Keys are masked in `var_dump`/`print_r` (`pk_test_***`) and refuse `serialize()`, so they can't leak through caches, sessions or queues.
- If you suspect a key is compromised, rotate it in the dashboard. Rotating a public key invalidates the old one immediately.

## Errors

A wrong, rotated or wrong-mode key, or a server not on the IP allowlist, gets **HTTP 401**. The body is
plain text, for example `Invalid merchant key` or `You are not authorized to view this resource`. The SDK raises
`AuthenticationException`, quotes Payxy's text, and adds a hint to check the key and the allowlist. It never retries a 401.
