# Symfony

[← Back to README](../README.md)

## Setup

```php
// config/bundles.php
return [
    // ...
    Payxy\Bridge\Symfony\PayxyBundle::class => ['all' => true],
];
```

```yaml
# config/packages/payxy.yaml
payxy:
  public_key: '%env(PAYXY_PUBLIC_KEY)%'
  webhook_secret: '%env(default::PAYXY_WEBHOOK_SECRET)%'
  # base_url: ~                       # only for a proxy or mock
  # timeout: 30
  # connect_timeout: 10
  # max_retries: 3
  # http_client: my.psr18.client      # optional PSR-18 service id
```

`Payxy\Payxy` (and your `PayxyCheckout` subclass) are autowirable. SDK logs go to a dedicated `payxy` Monolog channel.

## Callback and webhook

```php
use Payxy\Checkout\CheckoutOutcome;
use Payxy\Exception\WebhookVerificationException;
use Payxy\Payxy;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;

#[Route('/payments/payxy/return', name: 'payxy_return')]
public function return(Request $request, ShopCheckout $checkout): Response
{
    $result = $checkout->confirm($request->getSession()->get('payxy_transaction_id'));
    // ... render by $result->outcome
}

#[Route('/webhooks/payxy', methods: ['POST'])]
public function webhook(Request $request, Payxy $payxy): Response
{
    try {
        $event = $payxy->webhooks()->constructEvent(
            $request->getContent(),
            $request->headers->get('x-payxy-signature'),
            $request->headers->get('x-payxy-timestamp'),
            $request->headers->get('x-payxy-attempt'),
        );
    } catch (WebhookVerificationException) {
        return new Response('Invalid signature', 401);
    }
    // insert $event->idempotencyKey() under a unique constraint, then apply the state change
    return new Response('OK');
}
```
