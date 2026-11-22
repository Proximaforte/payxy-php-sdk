# Changelog

## [1.2.0](https://github.com/Proximaforte/payxy-php-sdk/compare/v1.1.0...v1.2.0) (2026-11-22)


### Features

* add money movement, machine auth, typed webhook events and payout status query ([9d6ddfd](https://github.com/Proximaforte/payxy-php-sdk/commit/9d6ddfd9bdd7a83caf2b9b1accf06078ff856b7c))
* add money movement, typed webhook events and payout status query ([498e2a8](https://github.com/Proximaforte/payxy-php-sdk/commit/498e2a8a4a32bb7e2b373b64ab74e3a497994cc8))


### Bug Fixes

* derive checkout URL from public key environment ([140ae7e](https://github.com/Proximaforte/payxy-php-sdk/commit/140ae7e0270a9112914358bf8ca996cfe615c236))
* derive checkout URL from public key environment ([ce3642a](https://github.com/Proximaforte/payxy-php-sdk/commit/ce3642ac344ab9cf08506b3954a8b61d603d3c31))
* harden SDK for public release ([7a894a5](https://github.com/Proximaforte/payxy-php-sdk/commit/7a894a54948bc207906063a18f81b8a95a601746))
* read the webhook history as the API returns it, accept lowercase webhook keys and add hasIdentity() ([75e86b7](https://github.com/Proximaforte/payxy-php-sdk/commit/75e86b777fb276674cc9fb62a4c1e6c454d58a45))


### Miscellaneous Chores

* configure SDK release automation ([f94c459](https://github.com/Proximaforte/payxy-php-sdk/commit/f94c459ca2a394f08d843c1cd794076f8c245565))
* configure SDK release automation ([3491388](https://github.com/Proximaforte/payxy-php-sdk/commit/3491388f610dd0bbf8bdd7c05c0fc6e2426edbd0))
* **deps:** bump actions/checkout from 4.4.0 to 7.0.1 ([76a4cec](https://github.com/Proximaforte/payxy-php-sdk/commit/76a4cec0bf0e5f4fa8d3276c780f345f8e428e4e))
* **deps:** bump actions/checkout from 4.4.0 to 7.0.1 ([d421ea0](https://github.com/Proximaforte/payxy-php-sdk/commit/d421ea07adf0618a1b316f544dffe7eaccc2164a))
* **deps:** bump googleapis/release-please-action from 4.4.1 to 5.0.0 ([f1576a2](https://github.com/Proximaforte/payxy-php-sdk/commit/f1576a21636dae49fbcda5f465649230094deb91))
* **deps:** bump googleapis/release-please-action from 4.4.1 to 5.0.0 ([3e3c597](https://github.com/Proximaforte/payxy-php-sdk/commit/3e3c59738a049d81b0702e24c119492a3badac42))
* **master:** release 1.0.1 ([fe93026](https://github.com/Proximaforte/payxy-php-sdk/commit/fe930269bb39512cd5347ec74dd4a260161fea95))
* **master:** release 1.0.1 ([6227393](https://github.com/Proximaforte/payxy-php-sdk/commit/62273934083537ac616a760687154ca136aa0e73))
* **master:** release 1.0.2 ([7d1365c](https://github.com/Proximaforte/payxy-php-sdk/commit/7d1365cab1bbfdde4967ba08cdd90a63c741ed1b))
* **master:** release 1.0.2 ([ccf2dd1](https://github.com/Proximaforte/payxy-php-sdk/commit/ccf2dd1f2a436c9f0e82c9eed9724d0d6e9689b2))
* **master:** release 1.0.3 ([578a0c7](https://github.com/Proximaforte/payxy-php-sdk/commit/578a0c7447700aef689065e6656eeed26272bbc5))
* **master:** release 1.0.3 ([a2b741c](https://github.com/Proximaforte/payxy-php-sdk/commit/a2b741c450eb0d613698099d0c5a4b65f6fbdd70))
* **master:** release payxy/payxy-php-sdk 1.0.0 ([bf0c87f](https://github.com/Proximaforte/payxy-php-sdk/commit/bf0c87f3a725d37999919ea616d1896b922d73b2))
* **master:** release payxy/payxy-php-sdk 1.0.0 ([6196429](https://github.com/Proximaforte/payxy-php-sdk/commit/6196429392cdddcfd45d88936f58c6f85e6fb9d1))
* release 1.1.0 ([2e5a12e](https://github.com/Proximaforte/payxy-php-sdk/commit/2e5a12e9fab3a204ef3e9a2811be3665c420fb00))

## [1.1.0](https://github.com/Proximaforte/payxy-php-sdk/compare/v1.0.3...v1.1.0) (2026-11-22)


### Features

* money movement: virtual accounts, wallet balance, payouts, sandbox simulator, providers, webhook history and resend
* payout transaction status query (TSQ) with `payouts()->tsq()`
* machine authentication: the public and secret key are exchanged for a cached, auto-refreshing access token
* typed webhook events for `payment.received`, `settlement.completed`, `settlement.failed`, `virtual_account.created` and `virtual_account.failed`, plus event id, delivery id, version and occurred-at
* checkout `start()` appends the transaction id to the callback URL
* `WebhookEvent::hasIdentity()` is false for the empty older-format copy Payxy sends beside some events, such as `virtual_account.created`

### Bug Fixes

* webhook history records are read as the API returns them: `getEventType()` reads `event`, and `getDeliveryStatus()`, `getAttemptCount()`, `getNextAttemptAt()`, `getResourceReference()` and `lastAttempt()` are new, so `wasDelivered()` is correct
* webhook payload keys are matched without regard to case, so Payxy's endpoint verification ping is accepted instead of being rejected with 401

### Changed

* `PayxyCheckout::handleWebhook()` returns `null` for events other than `payment.success` and `payment.failed`
* webhook verification accepts a payload with no `Mode`, and requires `Data.TransactionId` only on payment events

## [1.0.3](https://github.com/Proximaforte/payxy-php-sdk/compare/v1.0.2...v1.0.3) (2026-10-21)


### Bug Fixes

* derive checkout URL from public key environment ([140ae7e](https://github.com/Proximaforte/payxy-php-sdk/commit/140ae7e0270a9112914358bf8ca996cfe615c236))
* derive checkout URL from public key environment ([ce3642a](https://github.com/Proximaforte/payxy-php-sdk/commit/ce3642ac344ab9cf08506b3954a8b61d603d3c31))

## [1.0.2](https://github.com/Proximaforte/payxy-php-sdk/compare/v1.0.1...v1.0.2) (2026-10-19)


### Miscellaneous Chores

* **deps:** bump actions/checkout from 4.4.0 to 7.0.1 ([76a4cec](https://github.com/Proximaforte/payxy-php-sdk/commit/76a4cec0bf0e5f4fa8d3276c780f345f8e428e4e))
* **deps:** bump googleapis/release-please-action from 4.4.1 to 5.0.0 ([f1576a2](https://github.com/Proximaforte/payxy-php-sdk/commit/f1576a21636dae49fbcda5f465649230094deb91))

## [1.0.1](https://github.com/Proximaforte/payxy-php-sdk/compare/v1.0.0...v1.0.1) (2026-10-19)


### Bug Fixes

* harden SDK for public release ([7a894a5](https://github.com/Proximaforte/payxy-php-sdk/commit/7a894a54948bc207906063a18f81b8a95a601746))


### Miscellaneous Chores

* configure SDK release automation ([f94c459](https://github.com/Proximaforte/payxy-php-sdk/commit/f94c459ca2a394f08d843c1cd794076f8c245565))
* configure SDK release automation ([3491388](https://github.com/Proximaforte/payxy-php-sdk/commit/3491388f610dd0bbf8bdd7c05c0fc6e2426edbd0))
* **master:** release payxy/payxy-php-sdk 1.0.0 ([bf0c87f](https://github.com/Proximaforte/payxy-php-sdk/commit/bf0c87f3a725d37999919ea616d1896b922d73b2))
* **master:** release payxy/payxy-php-sdk 1.0.0 ([6196429](https://github.com/Proximaforte/payxy-php-sdk/commit/6196429392cdddcfd45d88936f58c6f85e6fb9d1))

## 1.0.0 (2026-10-19)


### Miscellaneous Chores

* configure SDK release automation ([f94c459](https://github.com/Proximaforte/payxy-php-sdk/commit/f94c459ca2a394f08d843c1cd794076f8c245565))
* configure SDK release automation ([3491388](https://github.com/Proximaforte/payxy-php-sdk/commit/3491388f610dd0bbf8bdd7c05c0fc6e2426edbd0))
