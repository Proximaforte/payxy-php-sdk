<?php

declare(strict_types=1);

namespace Payxy\Tests\Unit\Payments;

use Payxy\Exception\InvalidArgumentException;
use Payxy\Payments\Model\CheckoutCustomization;
use Payxy\Payments\Request\InitializePaymentRequest;
use Payxy\Support\Amount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InitializePaymentRequestTest extends TestCase
{
    public function testSerialisesTheCoreContractExactly(): void
    {
        $request = new InitializePaymentRequest(
            email: 'customer@example.com',
            amount: Amount::fromString('75000.00'),
            transactionId: 'ORD-261019-A12BC34D',
            callbackUrl: 'https://merchant.example.com/payments/payxy/return',
            metadata: ['orderId' => 'ORDER-10492', 'customerId' => 'CUSTOMER-77'],
            productId: 'SKU-1001',
            productDescription: 'Premium subscription',
            applyConvenienceCharge: true,
            customization: new CheckoutCustomization('#FFFFFF', '#0066FF', 'Secured by Payxy', 'https://merchant.example.com', 'https://merchant.example.com/logo.png'),
        );

        self::assertSame([
            'email' => 'customer@example.com',
            'amount' => '75000.00',
            'transactionId' => 'ORD-261019-A12BC34D',
            'currency' => 'NGN',
            'callbackUrl' => 'https://merchant.example.com/payments/payxy/return',
            'metadata' => [['name' => 'orderId', 'value' => 'ORDER-10492'], ['name' => 'customerId', 'value' => 'CUSTOMER-77']],
            'productId' => 'SKU-1001',
            'productDescription' => 'Premium subscription',
            'applyConviniencyCharge' => true,
            'bodyColor' => '#FFFFFF',
            'buttonColor' => '#0066FF',
            'footerText' => 'Secured by Payxy',
            'footerLink' => 'https://merchant.example.com',
            'footerLogo' => 'https://merchant.example.com/logo.png',
        ], $request->toPayload());
    }

    public function testModeIsNeverSentAndCallbackIsOptional(): void
    {
        $payload = (new InitializePaymentRequest('customer@example.com', Amount::of('5000'), 'TXN-000001'))->toPayload();

        self::assertArrayNotHasKey('mode', $payload);
        self::assertArrayNotHasKey('callbackUrl', $payload);
        self::assertSame('5000.00', $payload['amount'], 'exact decimal string, no float arithmetic');
    }

    /**
     * @param callable(): mixed $factory
     */
    #[DataProvider('invalid')]
    public function testRejectsInvalidInputLocally(callable $factory, string $field): void
    {
        try {
            $factory();
            self::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString($field, $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{callable(): mixed, string}>
     */
    public static function invalid(): iterable
    {
        $make = static fn(string $email = 'a@b.co', string $amount = '10', string $id = 'TXN-000001', ?string $callback = null, string $currency = 'NGN'): InitializePaymentRequest
            => new InitializePaymentRequest($email, Amount::of($amount), $id, $callback, $currency);

        yield 'id shorter than 6' => [static fn() => $make(id: 'TXN-1'), 'transactionId'];
        yield 'id longer than 30' => [static fn() => $make(id: str_repeat('A', 31)), 'transactionId'];
        yield 'id with line break' => [static fn() => $make(id: "TXN-00\n0001"), 'transactionId'];
        yield 'zero amount' => [static fn() => $make(amount: '0'), 'amount'];
        yield 'three decimals' => [static fn() => $make(amount: '1.234'), 'amount'];
        yield 'bad email' => [static fn() => $make(email: 'not-an-email'), 'email'];
        yield 'javascript callback' => [static fn() => $make(callback: 'javascript:alert(1)'), 'callbackUrl'];
        yield 'currency' => [static fn() => $make(currency: 'naira'), 'currency'];
    }

    public function testBoundaryLengthsAreAccepted(): void
    {
        self::assertSame('ABCDEF', (new InitializePaymentRequest('a@b.co', Amount::of(1), 'ABCDEF'))->transactionId);
        self::assertSame(30, \strlen((new InitializePaymentRequest('a@b.co', Amount::of(1), str_repeat('A', 30)))->transactionId));
    }
}
