<?php

declare(strict_types=1);

namespace Payxy\Tests\Unit\Payments;

use Nyholm\Psr7\Response;
use Payxy\Exception\AuthenticationException;
use Payxy\Exception\DuplicateTransactionException;
use Payxy\Exception\RequestFailedException;
use Payxy\Exception\ValidationException;
use Payxy\Payments\Request\InitializePaymentRequest;
use Payxy\Payments\SettlementStatus;
use Payxy\ResponseCode;
use Payxy\Support\Amount;
use Payxy\Tests\Support\SdkTestCase;

/**
 * Response bodies follow the Payxy Core contract (and live sandbox captures).
 */
final class PaymentsServiceTest extends SdkTestCase
{
    public function testInitializeReturnsPaymentUrlAndAccessCode(): void
    {
        $payxy = $this->payxy(self::ok(
            ['paymentUrl' => 'https://checkout.payxy.ng/abc123accesscode', 'accessCode' => 'abc123accesscode'],
            'Authorization URL created',
        ));

        $response = $payxy->payments()->initialize(self::initialize());

        $request = $this->http->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::BASE_URL . '/api/Payments/Initialize', (string) $request->getUri());
        self::assertSame('Bearer ' . self::PUBLIC_KEY, $request->getHeaderLine('Authorization'));
        self::assertSame('application/json; charset=utf-8', $request->getHeaderLine('Content-Type'));
        self::assertStringStartsWith('payxy-php-sdk/', $request->getHeaderLine('User-Agent'));
        self::assertSame('75000.00', $this->http->lastJsonBody()['amount']);

        self::assertSame('https://checkout.payxy.ng/abc123accesscode', $response->getPaymentUrl());
        self::assertSame('abc123accesscode', $response->getAccessCode());
        self::assertSame(ResponseCode::Successful, $response->responseCode());
        self::assertSame('Authorization URL created', $response->getMessage());
    }

    public function testDuplicateTransactionIdIsTyped(): void
    {
        $payxy = $this->payxy(self::json(400, ['responseCode' => '04', 'responseMessage' => 'Transaction id already exists', 'data' => null]));

        try {
            $payxy->payments()->initialize(self::initialize());
            self::fail('Expected DuplicateTransactionException');
        } catch (DuplicateTransactionException $e) {
            self::assertSame(ResponseCode::BadRequest, $e->responseCode());
            self::assertSame(400, $e->getStatusCode());
        }
        self::assertCount(1, $this->http->requests, 'Initialize is never retried automatically');
    }

    public function testInvalidAmountIsAValidationError(): void
    {
        $payxy = $this->payxy(self::json(400, ['responseCode' => '04', 'responseMessage' => 'Amount must be greater than zero', 'data' => null]));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Amount must be greater than zero');
        $payxy->payments()->initialize(self::initialize());
    }

    public function testPlainTextMissingAuthenticationIsHandled(): void
    {
        $payxy = $this->payxy(new Response(401, ['Content-Type' => 'text/plain'], 'You are not authorized to view this resource'));

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('You are not authorized to view this resource.');
        $payxy->payments()->initialize(self::initialize());
    }

    public function testGetPaymentInfoIsCheckoutOrientedAndEncodesTheAccessCode(): void
    {
        $payxy = $this->payxy(self::ok([
            'link' => 'https://checkout.payxy.ng/abc123accesscode',
            'amount' => '75000.00',
            'businessName' => 'Example Merchant',
            'token' => '<checkout-payment-token>',
            'paymentRealtimeToken' => '<short-lived-signalr-token>',
            'paymentRealtimeTokenExpiresAtUtc' => '2026-10-19T12:30:00Z',
        ], 'Payment information fetched successfully'));

        $info = $payxy->payments()->getPaymentInfo('a/b?c');

        $request = $this->http->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame('/api/Payments/GetPaymentInfo/a%2Fb%3Fc', $request->getUri()->getPath());
        self::assertFalse($request->hasHeader('Authorization'));
        self::assertSame('https://checkout.payxy.ng/abc123accesscode', $info->getPaymentUrl());
        self::assertSame('75000.00', (string) $info->getAmount());
        self::assertSame('Example Merchant', $info->getBusinessName());
        self::assertSame('<short-lived-signalr-token>', $info->getRealtimeToken());
    }

    public function testCompletedPaymentLinkIsRejected(): void
    {
        $payxy = $this->payxy(self::json(400, ['responseCode' => '02', 'responseMessage' => "It's either because the link is incorrect or the transaction is already completed", 'data' => null]));

        $this->expectException(ValidationException::class);
        $payxy->payments()->getPaymentInfo('abc123accesscode');
    }

    public function testSuccessfulVerificationCarriesTheFinancialBreakdown(): void
    {
        $payxy = $this->payxy(self::ok([
            'amount' => 75000.00,
            'customerPaid' => 76129.00,
            'amountPaidToMerchant' => 75000.00,
            'feeBearer' => 'Customer',
            'processingFee' => 1050.00,
            'platformVat' => 79.00,
            'merchantVat' => 0.00,
            'totalCharges' => 1129.00,
            'settlementStatus' => 'Completed',
            'paymentType' => 'Card',
            'currency' => 'NGN',
            'status' => 'Successful',
            'isSuccessful' => true,
            'gatewayResponse' => 'Approved',
            'transactionId' => 'ORD-261019-A12BC34D',
            'paymentReference' => 'PXF35081579178',
            'providerReference' => 'PP75639243801918975770',
            'merchantId' => 351,
            'cardPan' => '506099********7499',
            'cardType' => 'Verve',
            'cardExpiry' => '09/30',
            'originatorBank' => '',
            'sessionId' => 'PP75639243801918975770',
        ], 'Transaction retrieved successfully'));

        $verify = $payxy->payments()->verify('ORD-261019-A12BC34D');

        self::assertSame(['transactionId' => 'ORD-261019-A12BC34D'], $this->http->lastJsonBody());
        self::assertSame('', $this->http->lastRequest()->getUri()->getQuery(), 'no caller parameter');
        self::assertTrue($verify->isPaid());
        self::assertFalse($verify->isPending());
        self::assertSame('75000.00', (string) $verify->getAmount());
        self::assertSame('76129.00', (string) $verify->getCustomerPaid());
        self::assertSame('75000.00', (string) $verify->getAmountPaidToMerchant());
        self::assertSame('1050.00', (string) $verify->getProcessingFee());
        self::assertSame('79.00', (string) $verify->getPlatformVat());
        self::assertSame('0.00', (string) $verify->getMerchantVat());
        self::assertSame('1129.00', (string) $verify->getTotalCharges());
        self::assertSame('Customer', $verify->getFeeBearer());
        self::assertSame(SettlementStatus::Completed, $verify->settlementStatus());
        self::assertSame('PXF35081579178', $verify->getPaymentReference());
        self::assertSame('PP75639243801918975770', $verify->getProviderReference());
        self::assertSame('506099********7499', $verify->getMaskedCardPan());
        self::assertSame(351, $verify->getMerchantId());

        // The order amount is compared with data.amount, never customerPaid (which includes fees).
        self::assertTrue($verify->amountMatches(Amount::of('75000'), 'NGN'));
        self::assertFalse($verify->amountMatches(Amount::of('76129'), 'NGN'));
        self::assertFalse($verify->amountMatches(Amount::of('75000'), 'USD'));
    }

    public function testAmountComparisonFailsWhenAnExpectedCurrencyIsNotReported(): void
    {
        $verify = $this->payxy(self::ok([
            'amount' => 75000.00,
            'status' => 'Successful',
            'isSuccessful' => true,
            'transactionId' => 'ORD-261019-A12BC34D',
        ]))->payments()->verify('ORD-261019-A12BC34D');

        self::assertTrue($verify->amountMatches(Amount::of('75000')));
        self::assertFalse($verify->amountMatches(Amount::of('75000'), 'NGN'));
    }

    /**
     * Pending (05) and failed (02) are results, not exceptions. 05/02 bodies captured live from api.payxy.ng.
     */
    public function testPendingAndFailedRecordsAreReturnedNotThrown(): void
    {
        $pending = $this->payxy(self::json(200, ['responseCode' => '05', 'responseMessage' => 'Transaction retrieved successfully', 'data' => [
            'amount' => 50000, 'customerPaid' => 0, 'status' => 'Pending', 'isSuccessful' => false, 'settlementStatus' => 'NotCreated',
            'gatewayResponse' => 'Transaction Pending. Virtual account created successfully', 'transactionId' => 'DEMO-D33B80A67574E7E4',
        ]]))->payments()->verify('DEMO-D33B80A67574E7E4');

        self::assertFalse($pending->isPaid());
        self::assertTrue($pending->isPending());
        self::assertSame(ResponseCode::Processing, $pending->responseCode());
        self::assertSame('50000.00', (string) $pending->getAmount(), 'amount arrives as a JSON number');

        $failed = $this->payxy(self::json(200, ['responseCode' => '02', 'responseMessage' => 'Transaction retrieved successfully', 'data' => [
            'amount' => 100, 'status' => 'Failed', 'isSuccessful' => false, 'gatewayResponse' => 'Invalid card pin', 'transactionId' => 'DEMO-378332340FB87116',
        ]]))->payments()->verify('DEMO-378332340FB87116');

        self::assertFalse($failed->isPaid());
        self::assertFalse($failed->isPending());
        self::assertSame('Invalid card pin', $failed->getGatewayResponse());
    }

    public function testSuccessFlagWithoutCode00IsNotPaid(): void
    {
        $verify = $this->payxy(self::json(200, ['responseCode' => '05', 'responseMessage' => 'x', 'data' => ['status' => 'Successful', 'isSuccessful' => true]]))
            ->payments()->verify('ORD-1');

        self::assertFalse($verify->isPaid());
    }

    public function testUnknownSettlementStatusIsPreserved(): void
    {
        $verify = $this->payxy(self::ok(['settlementStatus' => 'Reversed', 'isSuccessful' => true]))->payments()->verify('ORD-1');

        self::assertSame('Reversed', $verify->getSettlementStatus());
        self::assertNull($verify->settlementStatus());
    }

    public function testInvalidTransactionIdNeverLooksSuccessful(): void
    {
        $payxy = $this->payxy(self::json(200, ['responseCode' => '04', 'responseMessage' => 'Invalid transaction id.', 'data' => null]));

        try {
            $payxy->payments()->verify('ORD-UNKNOWN');
            self::fail('Expected RequestFailedException');
        } catch (RequestFailedException $e) {
            self::assertSame(ResponseCode::BadRequest, $e->responseCode());
        }
    }

    public function testStatusCodesOnlyCarryRecordsForVerify(): void
    {
        $payxy = $this->payxy(self::json(200, ['responseCode' => '05', 'responseMessage' => 'Processing', 'data' => ['accessCode' => 'AC1']]));

        $this->expectException(RequestFailedException::class);
        $payxy->payments()->initialize(self::initialize());
    }

    private static function initialize(): InitializePaymentRequest
    {
        return new InitializePaymentRequest('customer@example.com', Amount::fromString('75000.00'), 'ORD-261019-A12BC34D');
    }
}
