# Security Policy

Security is especially important for a payment SDK. We appreciate responsible reports that help protect Payxy merchants and their customers.

## Supported versions

Security updates are provided for the latest stable release of `payxy/payxy-php-sdk`. If a vulnerability also affects an older release, we may provide a backport when the severity and adoption of that release justify it.

| Version | Supported |
| --- | --- |
| Latest stable release | Yes |
| Older releases | Case by case |
| Development branches and unreleased builds | No guarantee |

Merchants should use the latest stable package version and keep its Composer dependencies up to date.

## Reporting a vulnerability

Do not disclose a suspected vulnerability in a public GitHub issue, pull request, discussion, or social-media post.

Report it privately using GitHub's **Report a vulnerability** feature on this repository's **Security** tab. If private vulnerability reporting is unavailable, contact the Payxy security team through the security contact published on the official Payxy website.

Please include as much of the following as possible:

- The affected SDK version, PHP version, framework, and HTTP client.
- A clear description of the issue and its potential impact.
- Reproduction steps or a minimal proof of concept.
- The affected files, methods, endpoints, or webhook fields.
- Whether the issue affects Test mode, Production mode, or both.
- Relevant sanitized logs or HTTP messages.
- Any suggested mitigation or remediation.

Never include real cardholder data, PINs, CVVs, OTPs, access tokens, public or secret API keys, webhook secrets, customer personal data, or production payloads in a report. Replace sensitive values with clearly synthetic examples.

## Issues that should be reported

Examples include:

- Authentication or authorization bypasses.
- Exposure or unintended transmission of API keys, webhook secrets, tokens, or payment data.
- Webhook signature-verification or replay-protection bypasses.
- Payment verification, amount, currency, environment, or transaction-status validation flaws that could lead to incorrect fulfilment.
- Unsafe retry or idempotency behaviour that could duplicate a payment or merchant action.
- Request forgery, injection, redirect, TLS, or HTTP client vulnerabilities.
- Dependency or supply-chain vulnerabilities that materially affect SDK users.

General support questions, integration problems without a security impact, and feature requests should use the normal project support channels or GitHub issues.

## Coordinated disclosure

After receiving a report, the Payxy team will assess it, reproduce it where possible, determine the affected versions, and coordinate a fix and release. We ask reporters to keep the details private until a remediation is available and an agreed disclosure date has been reached.

We will not knowingly pursue legal action against researchers who act in good faith, avoid privacy violations and service disruption, do not access or modify data beyond what is necessary to demonstrate the issue, and follow this policy.

## Security guidance for SDK users

- Store Payxy credentials in a secret manager or environment configuration; never commit them to source control.
- Use `pk_test_` and `sk_test_` credentials only in Test mode, and `pk_live_` and `sk_live_` credentials only in Production mode.
- Never expose webhook secrets or server-side credentials to browsers or mobile applications.
- Verify webhook signatures using the exact raw request body and supplied timestamp before parsing or processing the event.
- Make webhook processing and order fulfilment idempotent.
- Verify a payment through Payxy and compare its expected transaction ID, amount, and currency before providing value.
- Do not treat a browser redirect, SignalR notification, or webhook delivery by itself as final proof of payment.
- Never collect card PAN, CVV, PIN, or OTP through this SDK; use Payxy Hosted Checkout.

## Scope

This policy covers the source code and release artifacts of the Payxy PHP SDK. Vulnerabilities in Payxy's hosted services, dashboard, checkout application, or other SDKs should be reported through the same private security channel, with the affected product identified clearly.
