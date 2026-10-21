<?php

declare(strict_types=1);

namespace Payxy\Payments;

use Payxy\Http\Endpoint;
use Payxy\Http\Transport;
use Payxy\Payments\Request\InitializePaymentRequest;
use Payxy\Payments\Request\VerifyPaymentRequest;
use Payxy\Payments\Response\InitializePaymentResponse;
use Payxy\Payments\Response\PaymentInfoResponse;
use Payxy\Payments\Response\VerifyPaymentResponse;
use Payxy\PayxyConfig;
use Payxy\Service\AbstractService;

/**
 * Hosted Checkout operations of the Payxy Payment API. Card, PIN and OTP handling stay on
 * Payxy's hosted checkout; direct charging is not part of this SDK.
 */
final class PaymentsService extends AbstractService
{
    private const INITIALIZE = '/api/Payments/Initialize';
    private const GET_PAYMENT_INFO = '/api/Payments/GetPaymentInfo/{accessCode}';
    private const VERIFY_PAYMENT = '/api/Payments/VerifyPayment';

    /**
     * The config supplies the environment's checkout URL used as the paymentUrl fallback.
     */
    public function __construct(Transport $transport, private readonly PayxyConfig $config)
    {
        parent::__construct($transport);
    }

    /**
     * Never retried automatically. After an ambiguous failure (TransportException, 5xx) retry
     * with the SAME transactionId; DuplicateTransactionException then means the first call landed.
     * getPaymentUrl() on the result is always absolute (see InitializePaymentResponse).
     */
    public function initialize(InitializePaymentRequest $request): InitializePaymentResponse
    {
        $response = $this->request(Endpoint::post(self::INITIALIZE), InitializePaymentResponse::class, $request);
        $accessCode = $response->getAccessCode();

        return $accessCode === null || $accessCode === ''
            ? $response
            : $response->withFallbackPaymentUrl($this->config->checkoutUrlFor($accessCode));
    }

    /**
     * Checkout-oriented lookup used by Payxy's hosted checkout. The response carries
     * short-lived checkout and realtime tokens: never log them or use them as API credentials.
     */
    public function getPaymentInfo(string $accessCode): PaymentInfoResponse
    {
        return $this->request(
            Endpoint::get(self::GET_PAYMENT_INFO, requiresAuthentication: false),
            PaymentInfoResponse::class,
            pathParams: ['accessCode' => $accessCode],
        );
    }

    /**
     * The source of truth for a payment's outcome; fulfil only when isPaid() is true.
     * Returns pending (05) and failed (02) records as responses; throws only when Payxy has
     * no record (04). Read-only, so it is retried on transient failures with bounded backoff.
     */
    public function verify(string $transactionId): VerifyPaymentResponse
    {
        return $this->request(
            Endpoint::post(self::VERIFY_PAYMENT, idempotent: true, codeIsStatus: true),
            VerifyPaymentResponse::class,
            new VerifyPaymentRequest($transactionId),
        );
    }
}
