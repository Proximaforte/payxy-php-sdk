<?php

declare(strict_types=1);

namespace Payxy\Checkout;

use Payxy\Checkout\Html\CheckoutAppearance;
use Payxy\Checkout\Html\CheckoutRenderer;
use Payxy\Exception\ConfigurationException;
use Payxy\Exception\InvalidArgumentException;
use Payxy\Exception\InvalidResponseException;
use Payxy\Exception\RequestFailedException;
use Payxy\Exception\WebhookVerificationException;
use Payxy\Payments\Model\CheckoutCustomization;
use Payxy\Payments\Request\InitializePaymentRequest;
use Payxy\Payments\Response\VerifyPaymentResponse;
use Payxy\Payxy;
use Payxy\Support\Amount;
use Payxy\Support\ArrayReader;
use Payxy\Support\Assert;
use Payxy\Support\Reference;

/**
 * Extend this once per storefront, declare your settings as properties, and the SDK
 * handles the rest: starting the payment, rendering the Pay button or page, and
 * verifying the customer's return.
 *
 *     final class ShopCheckout extends PayxyCheckout
 *     {
 *         protected string $callbackUrl = 'https://shop.example.com/payxy/callback';
 *         protected ?string $buttonColor = '#0A84FF';
 *
 *         protected function expectedAmount(string $transactionId): ?Amount { ... }
 *     }
 */
abstract class PayxyCheckout
{
    private const NO_PAYMENT_ATTEMPT_CODE = '04';

    /** Absolute URL Payxy sends the customer back to; confirm the payment there with {@see confirm()}. */
    protected string $callbackUrl = '';

    protected string $currency = 'NGN';

    protected CheckoutDisplay $display = CheckoutDisplay::Redirect;

    /** "{amount}" is replaced with the formatted amount. */
    protected string $buttonText = 'Pay {amount}';

    /** Hex colors (#RGB, #RRGGBB or #RRGGBBAA), applied to your button and Payxy's hosted page. */
    protected ?string $buttonColor = null;

    protected ?string $bodyColor = null;

    protected ?string $footerText = null;

    protected ?string $footerLink = null;

    protected ?string $footerLogo = null;

    /** Shown as the title of the page rendered by {@see page()}. */
    protected string $merchantName = 'Checkout';

    protected ?bool $applyConvenienceCharge = null;

    /** Prefix for generated transaction ids. */
    protected string $referencePrefix = 'PXY';

    /**
     * Query keys tried by {@see handleCallback()}. The hosted checkout's redirect parameters are
     * not a confirmed contract; prefer {@see confirm()} with the id from your own session.
     *
     * @var list<string>
     */
    protected array $callbackReferenceKeys = ['transactionId', 'transaction_id', 'reference', 'trxref', 'txRef'];

    public function __construct(protected readonly Payxy $payxy) {}

    /**
     * The amount you charged for this transaction, from your own records, or null if you
     * do not recognise it. Compared with what the gateway reports before anything is fulfilled.
     */
    abstract protected function expectedAmount(string $transactionId): ?Amount;

    /**
     * Server side: creates the payment and returns where to send the customer. Use this on its
     * own for single-page or mobile front ends; return {@see CheckoutSession::toArray()} as JSON.
     */
    public function start(CheckoutRequest $request): CheckoutSession
    {
        $transactionId = $request->transactionId ?? Reference::generate($this->referencePrefix);

        $response = $this->payxy->payments()->initialize(new InitializePaymentRequest(
            email: $request->email,
            amount: $request->amount,
            transactionId: $transactionId,
            callbackUrl: $this->callbackUrl(),
            currency: $this->currency,
            metadata: $request->metadata,
            productId: $request->productId,
            productDescription: $request->productDescription,
            applyConvenienceCharge: $this->applyConvenienceCharge,
            customization: $this->customization(),
        ));

        $accessCode = $response->getAccessCode();
        if ($accessCode === null || $accessCode === '') {
            throw new InvalidResponseException('Payxy did not return an access code for the checkout.', $response->getStatusCode());
        }

        return new CheckoutSession(
            $transactionId,
            $accessCode,
            $this->resolveCheckoutUrl($response->getPaymentUrl(), $accessCode),
            $request->amount,
            $this->currency,
            $request->email,
            $this->payxy->getConfig()->environment,
        );
    }

    /**
     * Starts the payment and returns an HTML Pay button. Print it unescaped
     * (`{!! !!}` in Blade, `|raw` in Twig). Pass your CSP nonce if you use one.
     */
    public function button(CheckoutRequest $request, ?string $cspNonce = null): string
    {
        return $this->renderButton($this->start($request), $cspNonce);
    }

    /**
     * Starts the payment and returns a complete, standalone checkout page.
     */
    public function page(CheckoutRequest $request, ?string $cspNonce = null): string
    {
        return $this->renderPage($this->start($request), $cspNonce);
    }

    public function renderButton(CheckoutSession $session, ?string $cspNonce = null): string
    {
        return $this->renderer()->button($session, $this->appearance(), $cspNonce);
    }

    public function renderPage(CheckoutSession $session, ?string $cspNonce = null): string
    {
        return $this->renderer()->page($session, $this->appearance(), $cspNonce);
    }

    /**
     * Fallback for callback routes that have no transaction id of their own: reads it from common
     * query keys (an unconfirmed contract). Prefer confirm() with the id saved in your session or
     * order before redirecting. Either way the outcome comes from Verify, never from the query.
     *
     * @param array<array-key, mixed> $query
     */
    public function handleCallback(array $query): CheckoutResult
    {
        $transactionId = (new ArrayReader($query))->string(...$this->callbackReferenceKeys);
        if ($transactionId === null) {
            throw InvalidArgumentException::forField('transactionId', 'is missing from the callback query');
        }

        return $this->confirm($transactionId);
    }

    /**
     * Call from your webhook route with the raw body and the x-payxy-* headers. The signature
     * is checked first (WebhookVerificationException → answer 401/400); then, as with the
     * callback, the outcome comes from Verify Payment, not from the webhook payload.
     */
    public function handleWebhook(string $rawBody, ?string $signature, ?string $timestamp): CheckoutResult
    {
        $event = $this->payxy->webhooks()->constructEvent($rawBody, $signature, $timestamp);
        $transactionId = $event->getTransactionId()
            ?? throw new WebhookVerificationException('Webhook payload has no Data.TransactionId.');

        return $this->confirm($transactionId);
    }

    /**
     * Server-side truth for one transaction: Verify Payment, then your expectedAmount(). Call it on
     * your callback route with the transaction id you stored before redirecting. Safe to repeat
     * (e.g. re-checking a Pending payment); make your fulfilment idempotent.
     */
    public function confirm(string $transactionId): CheckoutResult
    {
        Assert::notBlank($transactionId, 'transactionId', 128);

        try {
            $verification = $this->payxy->payments()->verify($transactionId);
        } catch (RequestFailedException $e) {
            // Payxy answers "04 Invalid transaction id" until a payment is attempted: nothing was paid.
            if ($e->getResponseCode() === self::NO_PAYMENT_ATTEMPT_CODE) {
                return new CheckoutResult(CheckoutOutcome::Failed, $transactionId, null);
            }
            throw $e;
        }
        $outcome = match (true) {
            $verification->isPaid() => $this->amountIsExpected($transactionId, $verification)
                ? CheckoutOutcome::Successful
                : CheckoutOutcome::AmountMismatch,
            $verification->isPending() => CheckoutOutcome::Pending,
            default => CheckoutOutcome::Failed,
        };

        return new CheckoutResult($outcome, $transactionId, $verification);
    }

    /**
     * Override to compute the callback URL at runtime (e.g. from your router).
     */
    protected function callbackUrl(): string
    {
        if ($this->callbackUrl === '') {
            throw new ConfigurationException(static::class . ' must set $callbackUrl or override callbackUrl().');
        }

        return $this->callbackUrl;
    }

    private function amountIsExpected(string $transactionId, VerifyPaymentResponse $verification): bool
    {
        $expected = $this->expectedAmount($transactionId);

        return $expected !== null && $verification->amountMatches($expected, $this->currency);
    }

    private function resolveCheckoutUrl(?string $fromGateway, string $accessCode): string
    {
        if ($fromGateway !== null && str_starts_with(strtolower($fromGateway), 'https://')) {
            return $fromGateway;
        }

        return $this->payxy->getConfig()->checkoutUrlFor($accessCode);
    }

    private function customization(): CheckoutCustomization
    {
        return new CheckoutCustomization(
            bodyColor: $this->bodyColor,
            buttonColor: $this->buttonColor,
            footerText: $this->footerText,
            footerLink: $this->footerLink,
            footerLogo: $this->footerLogo,
        );
    }

    private function appearance(): CheckoutAppearance
    {
        return new CheckoutAppearance(
            display: $this->display,
            callbackUrl: $this->callbackUrl(),
            buttonText: $this->buttonText,
            buttonColor: $this->buttonColor,
            bodyColor: $this->bodyColor,
            merchantName: $this->merchantName,
            footerText: $this->footerText,
            footerLink: $this->footerLink,
            footerLogo: $this->footerLogo,
        );
    }

    private function renderer(): CheckoutRenderer
    {
        return new CheckoutRenderer();
    }
}
