<?php

declare(strict_types=1);

namespace Payxy\Tests\Unit\Checkout;

use Payxy\Checkout\CheckoutDisplay;
use Payxy\Checkout\CheckoutOutcome;
use Payxy\Checkout\CheckoutRequest;
use Payxy\Checkout\CheckoutSession;
use Payxy\Environment;
use Payxy\Exception\ConfigurationException;
use Payxy\Exception\InvalidArgumentException;
use Payxy\Exception\InvalidResponseException;
use Payxy\Exception\RequestFailedException;
use Payxy\Exception\WebhookVerificationException;
use Payxy\Support\Amount;
use Payxy\Tests\Support\SdkTestCase;
use Payxy\Tests\Support\ShopCheckout;
use PHPUnit\Framework\Attributes\DataProvider;

final class PayxyCheckoutTest extends SdkTestCase
{
    public function testStartSendsClassSettingsAndFallsBackToTheEnvironmentsCheckoutUrl(): void
    {
        $checkout = new ShopCheckout($this->payxy(self::ok(['accessCode' => 'AC 1'])));

        $session = $checkout->start(new CheckoutRequest('ada@example.com', Amount::of('2500'), 'ORDER-9'));

        $body = $this->http->lastJsonBody();
        self::assertSame('https://shop.example.com/payxy/callback', $body['callbackUrl']);
        self::assertSame('#0A84FF', $body['buttonColor']);
        self::assertSame('#F7F9FC', $body['bodyColor']);
        self::assertSame('2500.00', $body['amount']);
        self::assertSame('ORDER-9', $session->transactionId);
        self::assertSame('https://checkout.payxy.ng/AC%201', $session->paymentUrl);
        self::assertSame(
            ['transactionId' => 'ORDER-9', 'accessCode' => 'AC 1', 'paymentUrl' => 'https://checkout.payxy.ng/AC%201', 'amount' => '2500.00', 'currency' => 'NGN', 'environment' => 'staging'],
            $session->toArray(),
        );
    }

    public function testStartUsesThePaymentUrlFromTheGateway(): void
    {
        $checkout = new ShopCheckout($this->payxy(self::ok(['paymentUrl' => 'https://payxy.ng/pay/checkout/AC1', 'accessCode' => 'AC1'])));

        self::assertSame('https://payxy.ng/pay/checkout/AC1', $checkout->start(self::order())->paymentUrl);
    }

    public function testStartIgnoresAnInsecureCheckoutUrlFromTheGateway(): void
    {
        $checkout = new ShopCheckout($this->payxy(self::ok(['accessCode' => 'AC1', 'paymentUrl' => 'http://evil.test/AC1'])));

        self::assertSame('https://checkout.payxy.ng/AC1', $checkout->start(self::order())->paymentUrl);
    }

    public function testStartGeneratesATransactionIdWhenNoneIsGiven(): void
    {
        $checkout = new ShopCheckout($this->payxy(self::ok(['accessCode' => 'AC1'])));

        self::assertStringStartsWith('PXY-', $checkout->start(new CheckoutRequest('ada@example.com', Amount::of(10)))->transactionId);
    }

    public function testStartFailsWithoutAnAccessCode(): void
    {
        $checkout = new ShopCheckout($this->payxy(self::ok([])));

        $this->expectException(InvalidResponseException::class);
        $checkout->start(self::order());
    }

    public function testRedirectButtonIsAPlainLinkWithoutScript(): void
    {
        $checkout = new ShopCheckout($this->payxy(self::ok(['accessCode' => 'AC1'])));

        $html = $checkout->button(self::order());

        self::assertStringContainsString('href="https://checkout.payxy.ng/AC1"', $html);
        self::assertStringContainsString('data-payxy-display="redirect"', $html);
        self::assertStringContainsString('Pay ₦1,500,000.50', $html);
        self::assertStringContainsString('.payxy-checkout__button{background:#0A84FF}', $html);
        self::assertStringNotContainsString('<script', $html);
    }

    public function testPopupButtonCarriesScriptNonceAndCompletionUrl(): void
    {
        $checkout = (new ShopCheckout($this->payxy(self::ok(['accessCode' => 'AC1']))))->useDisplay(CheckoutDisplay::Popup);

        $html = $checkout->button(self::order(), cspNonce: 'r4nd0mN0nce==');

        self::assertStringContainsString('data-payxy-display="popup"', $html);
        self::assertStringContainsString('<script nonce="r4nd0mN0nce==">', $html);
        self::assertStringContainsString('<style id="payxy-style" nonce="r4nd0mN0nce==">', $html, 'pre-empts the SDK unsigned style');
        self::assertStringContainsString('@proximafortedev/payxy 1.0.10', $html, 'official overlay is bundled');
        self::assertStringContainsString('data-payxy-access-code="AC1"', $html);
        self::assertStringContainsString('data-payxy-environment="staging"', $html);
        self::assertStringContainsString('data-payxy-complete="https://shop.example.com/payxy/callback?transactionId=ORDER-1"', $html);
        self::assertStringContainsString('href="https://checkout.payxy.ng/AC1"', $html, 'no-JS fallback is a redirect');
    }

    public function testPageEscapesEverythingItPrints(): void
    {
        $checkout = new ShopCheckout($this->payxy());
        // Built directly: request validation would already reject this email before rendering.
        $session = new CheckoutSession('ORDER-1', 'AC1', 'https://checkout.payxy.ng/AC1', Amount::of(5), 'NGN', '"><script>alert(1)</script>', Environment::Sandbox);

        $html = $checkout->renderPage($session);

        self::assertStringStartsWith('<!doctype html>', $html);
        self::assertStringContainsString('<title>Ada&apos;s Shop</title>', $html);
        self::assertStringContainsString('Thanks for shopping &lt;3', $html);
        self::assertStringContainsString('body.payxy-page{background:#F7F9FC}', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function testNonHexColorsAreRejectedToPreventCssInjection(): void
    {
        $checkout = (new ShopCheckout($this->payxy(self::ok(['accessCode' => 'AC1']))))
            ->useButtonColor('red}body{display:none');
        $session = new CheckoutSession('ORDER-1', 'AC1', 'https://checkout.payxy.ng/AC1', Amount::of(1), 'NGN', 'a@b.co', Environment::Sandbox);

        $this->expectException(ConfigurationException::class);
        $checkout->renderButton($session);
    }

    public function testMalformedNonceIsRejected(): void
    {
        $checkout = new ShopCheckout($this->payxy(self::ok(['accessCode' => 'AC1'])));

        $this->expectException(InvalidArgumentException::class);
        $checkout->button(self::order(), cspNonce: '"><script>');
    }

    /**
     * @param array<string, mixed> $verify
     */
    #[DataProvider('callbackOutcomes')]
    public function testConfirmOutcomeFollowsCoreFulfilmentRule(array $verify, ?string $expected, CheckoutOutcome $outcome): void
    {
        $checkout = new ShopCheckout($this->payxy(self::ok($verify)));
        if ($expected !== null) {
            $checkout->orders['ORDER-1'] = Amount::of($expected);
        }

        // Recommended callback path: the id comes from the merchant's own session/order record.
        $result = $checkout->confirm('ORDER-1');

        self::assertSame($outcome, $result->outcome);
        self::assertSame(['transactionId' => 'ORDER-1'], $this->http->lastJsonBody());
        self::assertSame($outcome === CheckoutOutcome::Successful, $result->isSuccessful());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string|null, CheckoutOutcome}>
     */
    public static function callbackOutcomes(): iterable
    {
        yield 'paid in full' => [['status' => 'Successful', 'isSuccessful' => true, 'amount' => 1500, 'currency' => 'NGN'], '1500.00', CheckoutOutcome::Successful];
        yield 'customer bore the fees' => [['isSuccessful' => true, 'amount' => 1500, 'customerPaid' => 1522.5, 'currency' => 'NGN'], '1500', CheckoutOutcome::Successful];
        yield 'flag false despite status' => [['status' => 'Successful', 'isSuccessful' => false, 'amount' => 1500], '1500.00', CheckoutOutcome::Failed];
        yield 'flag absent is not paid' => [['status' => 'Successful', 'amount' => 1500], '1500', CheckoutOutcome::Failed];
        yield 'pending status' => [['status' => 'Pending', 'isSuccessful' => false], '1500.00', CheckoutOutcome::Pending];
        yield 'underpaid' => [['isSuccessful' => true, 'amount' => 15, 'currency' => 'NGN'], '1500.00', CheckoutOutcome::AmountMismatch];
        yield 'wrong currency' => [['isSuccessful' => true, 'amount' => 1500, 'currency' => 'USD'], '1500.00', CheckoutOutcome::AmountMismatch];
        yield 'unknown order' => [['isSuccessful' => true, 'amount' => 1500], null, CheckoutOutcome::AmountMismatch];
        yield 'failed' => [['status' => 'Failed', 'isSuccessful' => false, 'amount' => 1500], '1500.00', CheckoutOutcome::Failed];
        yield 'missing status' => [['amount' => 1500], '1500.00', CheckoutOutcome::Failed];
    }

    public function testAbandonedCheckoutIsFailedNotAnError(): void
    {
        // What api.payxy.ng returns when verifying a transaction that was initialized but never paid.
        $checkout = new ShopCheckout($this->payxy(self::json(200, ['responseCode' => '04', 'responseMessage' => 'Invalid transaction id'])));

        $result = $checkout->handleCallback(['transactionId' => 'ORDER-1']);

        self::assertSame(CheckoutOutcome::Failed, $result->outcome);
        self::assertNull($result->verification);
    }

    public function testLiveStatusCodesMapToOutcomes(): void
    {
        // responseCode 05 / 02 as returned by api.payxy.ng for a pending and a failed transfer.
        $pending = new ShopCheckout($this->payxy(self::json(200, ['responseCode' => '05', 'responseMessage' => 'Transaction retrieved successfully', 'data' => ['status' => 'Pending', 'isSuccessful' => false, 'amount' => 1500]])));
        self::assertSame(CheckoutOutcome::Pending, $pending->confirm('ORDER-1')->outcome);

        $failed = new ShopCheckout($this->payxy(self::json(200, ['responseCode' => '02', 'responseMessage' => 'Transaction retrieved successfully', 'data' => ['status' => 'Failed', 'isSuccessful' => false, 'amount' => 1500]])));
        self::assertSame(CheckoutOutcome::Failed, $failed->confirm('ORDER-1')->outcome);
    }

    public function testOtherVerifyRejectionsStillThrow(): void
    {
        $checkout = new ShopCheckout($this->payxy(self::json(200, ['responseCode' => '03', 'responseMessage' => 'No merchant found'])));

        $this->expectException(RequestFailedException::class);
        $checkout->confirm('ORDER-1');
    }

    public function testCallbackAcceptsAlternativeReferenceKeys(): void
    {
        $checkout = new ShopCheckout($this->payxy(self::ok(['status' => 'Failed'])));

        self::assertSame('ORDER-7', $checkout->handleCallback(['reference' => 'ORDER-7'])->transactionId);
    }

    public function testSignedWebhookIsConfirmedThroughVerifyPayment(): void
    {
        $checkout = new ShopCheckout($this->payxy(self::ok(['status' => 'Successful', 'isSuccessful' => true, 'amount' => '5000', 'currency' => 'NGN'])));
        $checkout->orders['TXN-20260706-0001'] = Amount::of(5000);
        $body = '{"Event":"payment.success","Mode":"Test","Data":{"TransactionId":"TXN-20260706-0001","Status":"Successful","IsSuccessful":true}}';
        $timestamp = (string) time();

        $result = $checkout->handleWebhook($body, hash_hmac('sha512', $timestamp . '.' . $body, self::WEBHOOK_SECRET), $timestamp);

        self::assertSame(CheckoutOutcome::Successful, $result->outcome);
        self::assertSame(['transactionId' => 'TXN-20260706-0001'], $this->http->lastJsonBody());
    }

    public function testUnsignedWebhookNeverReachesTheApi(): void
    {
        $checkout = new ShopCheckout($this->payxy());

        try {
            $checkout->handleWebhook('{"Data":{"TransactionId":"TXN-1"}}', 'forged', (string) time());
            self::fail('Expected WebhookVerificationException');
        } catch (WebhookVerificationException) {
            self::assertSame([], $this->http->requests);
        }
    }

    public function testCallbackWithoutReferenceIsRejectedBeforeAnyCall(): void
    {
        $checkout = new ShopCheckout($this->payxy());

        try {
            $checkout->handleCallback(['status' => 'successful']);
            self::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException) {
            self::assertSame([], $this->http->requests);
        }
    }

    private static function order(): CheckoutRequest
    {
        return new CheckoutRequest('ada@example.com', Amount::of('1500000.50'), 'ORDER-1');
    }
}
