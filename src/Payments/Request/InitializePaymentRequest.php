<?php

declare(strict_types=1);

namespace Payxy\Payments\Request;

use Payxy\Exception\InvalidArgumentException;
use Payxy\Payments\Model\CheckoutCustomization;
use Payxy\Request\RequestPayload;
use Payxy\Support\Amount;
use Payxy\Support\Assert;
use Payxy\Support\Payload;

/**
 * Starts a hosted checkout session. The response carries the paymentUrl to redirect the
 * customer to and the accessCode for the official browser and mobile SDKs.
 */
final readonly class InitializePaymentRequest implements RequestPayload
{
    private const MAX_METADATA_ENTRIES = 50;

    /**
     * @param string $transactionId your unique reference for this payment (see {@see \Payxy\Support\Reference})
     * @param array<string, string> $metadata free-form key/value pairs echoed back on the transaction
     */
    public function __construct(
        public string $email,
        public Amount $amount,
        public string $transactionId,
        public ?string $callbackUrl = null,
        public string $currency = 'NGN',
        public array $metadata = [],
        public ?string $productId = null,
        public ?string $productDescription = null,
        public ?bool $applyConvenienceCharge = null,
        public ?CheckoutCustomization $customization = null,
    ) {
        Assert::email($email, 'email');
        Assert::positiveAmount($amount, 'amount');
        Assert::notBlank($transactionId, 'transactionId', 30);
        if (mb_strlen($transactionId) < 6) {
            throw InvalidArgumentException::forField('transactionId', 'must be 6-30 characters');
        }
        Assert::url($callbackUrl, 'callbackUrl');
        Assert::currency($currency, 'currency');
        Assert::text($productId, 'productId', 64);
        Assert::text($productDescription, 'productDescription', 500);

        if (\count($metadata) > self::MAX_METADATA_ENTRIES) {
            throw InvalidArgumentException::forField('metadata', \sprintf('must have at most %d entries', self::MAX_METADATA_ENTRIES));
        }
        foreach ($metadata as $name => $value) {
            Assert::notBlank((string) $name, 'metadata name', 100);
            Assert::text($value, 'metadata value', 500);
        }
    }

    public function toPayload(): array
    {
        $metadata = [];
        foreach ($this->metadata as $name => $value) {
            $metadata[] = ['name' => (string) $name, 'value' => $value];
        }

        return Payload::compact([
            'email' => $this->email,
            'amount' => $this->amount->toDecimalString(),
            'transactionId' => $this->transactionId,
            'currency' => $this->currency,
            'callbackUrl' => $this->callbackUrl,
            'metadata' => $metadata,
            'productId' => $this->productId,
            'productDescription' => $this->productDescription,
            // Spelling matches the gateway's field name.
            'applyConviniencyCharge' => $this->applyConvenienceCharge,
            ...($this->customization?->toPayload() ?? []),
        ]);
    }
}
