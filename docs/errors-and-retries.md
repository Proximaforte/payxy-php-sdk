# Errors and retries

[← Back to README](../README.md)

The SDK never treats HTTP 2xx as business success. It inspects Payxy's `responseCode` on every response.

## Exception hierarchy

```text
PayxyException (interface)
├── InvalidArgumentException         input failed local validation; no request sent
│   └── ConfigurationException       unusable configuration (e.g. sk_ key as public key)
├── TransportException               connection, DNS, TLS or timeout: no HTTP response
├── InvalidResponseException         2xx body that isn't valid JSON or is too large
├── WebhookVerificationException     webhook signature, timestamp or body rejected
└── ApiException                     Payxy rejected the request: getStatusCode(), getResponseCode(), responseCode()
    ├── AuthenticationException      401 (plain-text or JSON); never retried
    ├── PermissionDeniedException    403
    ├── ValidationException          400 / 422 → getErrors()
    ├── DuplicateTransactionException  Initialize: "Transaction id already exists" (code 04)
    ├── NotFoundException            404
    ├── ConflictException            409
    ├── RateLimitException           429 → getRetryAfterSeconds()
    ├── ServerException              5xx
    └── RequestFailedException       HTTP 200 with a non-"00" code (Verify: only when there is no record, i.e. 04)
```

Payment **states** (pending, failed) are typed results on `VerifyPaymentResponse`, not exceptions.

## Response codes

`Payxy\ResponseCode` names Core's general meanings. They are **endpoint-specific**:

| Code | Meaning | | Code | Meaning |
|---|---|---|---|---|
| `00` | Successful | | `08` | Insufficient funds |
| `01` | Unauthorized / provider rejection | | `09` | Virtual account created |
| `02` | Failed | | `10` | Declined |
| `03` | Not found | | `12` | Pending 3DS |
| `04` | Bad request | | `13` | Password change required |
| `05` | Processing / unknown outcome | | `14` | Chargeback |
| `06` | Waiting for OTP | | `15` | Timeout / abandoned |
| `07` | Pending validation / 3DS | | `16` | Account locked |

- Initialize succeeds on `00`.
- VerifyPayment is final-success only on `00` **plus** `data.isSuccessful === true` (`isPaid()`).
- `ResponseCode::isInProgress()` is true for `05`, `06`, `07`, `09` and `12`.
- Unknown future codes stay available as raw strings (`getResponseCode()`). `responseCode()` returns `null` for them.

## Retry policy

A timeout, a `429` or a lost response doesn't prove that nothing happened on Payxy's side, so retries are
limited to reads.

| Operation | Automatic retries |
|---|---|
| `verify()`, `getPaymentInfo()` | On network errors, 429, 502, 503, 504: attempt 1 **immediately**, then after **2 s**, **5 s** and **10 s**. Then the error is raised; no endless polling. `Retry-After` can lengthen a wait (up to 30 s) but never shorten it. |
| `initialize()` | **None.** On ambiguity, retry yourself with the **same** `transactionId` (see [initialization](payment-initialization.md#ambiguous-failures-never-invent-a-new-id)). |
| 401 / 403 / IP allowlist rejection | **Never** retried. These are configuration errors. |

```php
use Payxy\Http\RetryPolicy;

new PayxyConfig($key, retryPolicy: new RetryPolicy([2000, 5000, 10000]));   // default
new PayxyConfig($key, retryPolicy: RetryPolicy::withRetries(1));            // just the first (2 s) retry
new PayxyConfig($key, retryPolicy: RetryPolicy::none());
```

Framework config: `max_retries` (0–5) takes that many entries from the default schedule, repeating the last one if needed.

## Unknown outcomes

`TransportException::isOutcomeUnknown()` is `true` for Initialize: the session may exist. Never "fix" this by
generating a new transaction ID. Retry with the same ID and reconcile with `verify()`.

## Example

```php
use Payxy\Exception\{AuthenticationException, DuplicateTransactionException, PayxyException, RateLimitException, ServerException, TransportException};

try {
    $response = $payxy->payments()->initialize($request);
} catch (AuthenticationException $e) {
    // key, mode or IP allowlist; fix the configuration, don't retry
} catch (DuplicateTransactionException $e) {
    // an earlier attempt with this id landed; reconcile with verify()
} catch (TransportException | ServerException | RateLimitException $e) {
    // ambiguous: retry later with the SAME transactionId
} catch (PayxyException $e) {
    report($e);   // $e->getMessage() includes Payxy's responseMessage
}
```
