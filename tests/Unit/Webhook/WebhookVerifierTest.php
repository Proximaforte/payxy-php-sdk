<?php

declare(strict_types=1);

namespace Payxy\Tests\Unit\Webhook;

use Nyholm\Psr7\ServerRequest;
use Payxy\Exception\ConfigurationException;
use Payxy\Exception\WebhookVerificationException;
use Payxy\Webhook\WebhookVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebhookVerifierTest extends TestCase
{
    private const TEST_SECRET = 'sk_test_fedcba9876543210';
    private const LIVE_SECRET = 'sk_live_0123456789abcdef';
    private const NOW = 1_792_400_000;

    // Payload shapes from the Payxy Core contract (PascalCase, serialized .NET notification).
    private const SUCCESS_BODY = '{"MerchantId":351,"Event":"payment.success","WebhookUrl":"https://merchant.example.com/webhooks/payxy","Mode":"Production",'
        . '"Data":{"Amount":75000.00,"CustomerPaid":76129.00,"AmountPaidToMerchant":75000.00,"FeeBearer":"Customer","ProcessingFee":1050.00,'
        . '"PlatformVat":79.00,"MerchantVat":0.00,"TotalCharges":1129.00,"SettlementStatus":"Completed","PaymentType":"Card","Currency":"NGN",'
        . '"Status":"Successful","IsSuccessful":true,"GatewayResponse":"Approved","TransactionId":"ORD-261019-A12BC34D","PaymentReference":"PXF35081579178",'
        . '"ProviderReference":"PP75639243801918975770","MerchantId":351,"CardPan":"506099********7499","CardType":"Verve","CardExpiry":"09/30",'
        . '"OriginatorBank":"","OriginatorName":"","OriginatorAccount":"","SessionId":"PP75639243801918975770"}}';

    private const FAILED_BODY = '{"MerchantId":351,"Event":"payment.failed","Mode":"Test","Data":{"Amount":75000.00,"CustomerPaid":0.00,'
        . '"Status":"Failed","IsSuccessful":false,"GatewayResponse":"Invalid card pin","TransactionId":"ORD-261019-A12BC34D","SettlementStatus":"NotCreated"}}';

    public function testParsesTheSuccessPayload(): void
    {
        $event = $this->verifier(self::LIVE_SECRET)->constructEvent(self::SUCCESS_BODY, self::sign(self::SUCCESS_BODY, self::LIVE_SECRET), (string) self::NOW, '2');

        self::assertTrue($event->isPaymentSuccess());
        self::assertFalse($event->isPaymentFailed());
        self::assertSame('Production', $event->getMode());
        self::assertSame(351, $event->getMerchantId());
        self::assertSame('ORD-261019-A12BC34D', $event->getTransactionId());
        self::assertSame('PXF35081579178', $event->getPaymentReference());
        self::assertSame('PP75639243801918975770', $event->getProviderReference());
        self::assertSame('Successful', $event->getStatus());
        self::assertTrue($event->isSuccessful());
        self::assertSame('75000.00', (string) $event->getAmount());
        self::assertSame('76129.00', (string) $event->getCustomerPaid());
        self::assertSame('75000.00', (string) $event->getAmountPaidToMerchant());
        self::assertSame('1129.00', (string) $event->getTotalCharges());
        self::assertSame('Completed', $event->getSettlementStatus());
        self::assertSame('Approved', $event->getGatewayResponse());
        self::assertSame(2, $event->attempt);
        self::assertSame('payment.success:ORD-261019-A12BC34D', $event->idempotencyKey());
    }

    public function testParsesTheFailedPayloadWithTheTestSecret(): void
    {
        $event = $this->verifier(self::TEST_SECRET)->constructEvent(self::FAILED_BODY, self::sign(self::FAILED_BODY, self::TEST_SECRET), (string) self::NOW);

        self::assertTrue($event->isPaymentFailed());
        self::assertFalse($event->isSuccessful());
        self::assertSame('Invalid card pin', $event->getGatewayResponse());
        self::assertSame('payment.failed:ORD-261019-A12BC34D', $event->idempotencyKey());
        self::assertSame(1, $event->attempt);
    }

    public function testVerifyIsABooleanCheck(): void
    {
        $verifier = $this->verifier(self::TEST_SECRET);
        $invalidBody = '{"Event":"payment.success","Mode":"Test","Data":{}}';

        self::assertTrue($verifier->verify(self::FAILED_BODY, self::sign(self::FAILED_BODY, self::TEST_SECRET), (string) self::NOW));
        self::assertFalse($verifier->verify(self::FAILED_BODY, self::sign(self::FAILED_BODY, self::LIVE_SECRET), (string) self::NOW));
        self::assertFalse($verifier->verify(self::FAILED_BODY, null, (string) self::NOW));
        self::assertFalse($verifier->verify($invalidBody, self::sign($invalidBody, self::TEST_SECRET), (string) self::NOW));
    }

    #[DataProvider('rotationHeaders')]
    public function testRotationAcceptsAnyMatchingSignature(string $header, bool $valid): void
    {
        self::assertSame($valid, $this->verifier(self::TEST_SECRET)->verify(self::FAILED_BODY, $header, (string) self::NOW));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function rotationHeaders(): iterable
    {
        $current = self::sign(self::FAILED_BODY, self::TEST_SECRET);
        $previous = self::sign(self::FAILED_BODY, 'sk_test_previoussecret01');
        $other = self::sign(self::FAILED_BODY, 'sk_test_someoneelse0001');

        yield 'first signature matches' => [$current . ',' . $previous, true];
        yield 'second signature matches' => [$previous . ', ' . $current, true];
        yield 'uppercase hex' => [strtoupper($current), true];
        yield 'no signature matches' => [$previous . ',' . $other, false];
    }

    /**
     * @param array{string, string|null, string|null} $input
     */
    #[DataProvider('rejected')]
    public function testRejectsUnprovableWebhooks(array $input, string $reason): void
    {
        $this->expectException(WebhookVerificationException::class);
        $this->expectExceptionMessageMatches('/' . $reason . '/i');
        $this->verifier(self::TEST_SECRET)->constructEvent(...$input);
    }

    /**
     * @return iterable<string, array{array{string, string|null, string|null}, string}>
     */
    public static function rejected(): iterable
    {
        $good = self::sign(self::FAILED_BODY, self::TEST_SECRET);

        yield 'missing signature' => [[self::FAILED_BODY, null, (string) self::NOW], 'signature'];
        yield 'missing timestamp' => [[self::FAILED_BODY, $good, null], 'timestamp'];
        yield 'tampered raw body' => [[str_replace('75000.00', '750.00', self::FAILED_BODY), $good, (string) self::NOW], 'does not match'];
        yield 're-encoded body' => [[(string) json_encode(json_decode(self::FAILED_BODY)), $good, (string) self::NOW], 'does not match'];
        yield 'wrong secret' => [[self::FAILED_BODY, self::sign(self::FAILED_BODY, self::LIVE_SECRET), (string) self::NOW], 'does not match'];
        yield 'expired timestamp' => [[self::FAILED_BODY, self::sign(self::FAILED_BODY, self::TEST_SECRET, self::NOW - 301), (string) (self::NOW - 301)], 'window'];
        yield 'future timestamp' => [[self::FAILED_BODY, self::sign(self::FAILED_BODY, self::TEST_SECRET, self::NOW + 301), (string) (self::NOW + 301)], 'window'];
        yield 'timestamp swapped' => [[self::FAILED_BODY, $good, (string) (self::NOW + 1)], 'does not match'];
        yield 'not json' => [['nope', self::sign('nope', self::TEST_SECRET), (string) self::NOW], 'JSON'];
    }

    #[DataProvider('invalidPayloads')]
    public function testRejectsSignedButStructurallyInvalidWebhooks(string $body, string $reason, string $secret = self::TEST_SECRET): void
    {
        $this->expectException(WebhookVerificationException::class);
        $this->expectExceptionMessageMatches('/' . $reason . '/i');

        $this->verifier($secret)->constructEvent($body, self::sign($body, $secret), (string) self::NOW);
    }

    /**
     * @return iterable<string, array{string, string, 2?: string}>
     */
    public static function invalidPayloads(): iterable
    {
        yield 'missing event' => ['{"Mode":"Test","Data":{"TransactionId":"ORDER-1"}}', 'Event'];
        yield 'blank event' => ['{"Event":" ","Mode":"Test","Data":{"TransactionId":"ORDER-1"}}', 'Event'];
        yield 'invalid mode' => ['{"Event":"payment.success","Mode":"Sandbox","Data":{"TransactionId":"ORDER-1"}}', 'Mode'];
        yield 'mode and secret differ' => ['{"Event":"payment.success","Mode":"Production","Data":{"TransactionId":"ORDER-1"}}', 'configured webhook secret'];
        yield 'missing data' => ['{"Event":"payment.success","Mode":"Test"}', 'Data'];
        yield 'missing transaction id' => ['{"Event":"payment.success","Mode":"Test","Data":{}}', 'TransactionId'];
        yield 'blank transaction id' => ['{"Event":"payment.success","Mode":"Test","Data":{"TransactionId":" "}}', 'TransactionId'];
    }

    public function testConstructsEventsFromPsr7Requests(): void
    {
        $request = new ServerRequest('POST', '/webhooks/payxy', [
            'X-Payxy-Signature' => self::sign(self::FAILED_BODY, self::TEST_SECRET),
            'X-Payxy-Timestamp' => (string) self::NOW,
            'X-Payxy-Attempt' => '3',
        ], self::FAILED_BODY);

        self::assertSame(3, $this->verifier(self::TEST_SECRET)->constructEventFromRequest($request)->attempt);
    }

    public function testDuplicateDeliveriesShareAnIdempotencyKey(): void
    {
        $verifier = $this->verifier(self::LIVE_SECRET);
        $processed = [];
        foreach (['1', '2'] as $attempt) {
            $event = $verifier->constructEvent(self::SUCCESS_BODY, self::sign(self::SUCCESS_BODY, self::LIVE_SECRET), (string) self::NOW, $attempt);
            $processed[$event->idempotencyKey()] = ($processed[$event->idempotencyKey()] ?? 0) + 1;
        }

        self::assertSame(['payment.success:ORD-261019-A12BC34D' => 2], $processed, 'a unique constraint on this key makes the second delivery a no-op');
    }

    public function testRequiresASecretKey(): void
    {
        $this->expectException(ConfigurationException::class);
        new WebhookVerifier('pk_test_0123456789abcdef');
    }

    private function verifier(string $secret): WebhookVerifier
    {
        return new WebhookVerifier($secret, clock: static fn(): int => self::NOW);
    }

    private static function sign(string $body, string $secret, int $timestamp = self::NOW): string
    {
        return hash_hmac('sha512', $timestamp . '.' . $body, $secret);
    }
}
