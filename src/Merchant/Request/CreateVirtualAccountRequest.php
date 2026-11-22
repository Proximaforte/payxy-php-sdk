<?php

declare(strict_types=1);

namespace Payxy\Merchant\Request;

use DateTimeInterface;
use Payxy\Exception\InvalidArgumentException;
use Payxy\Merchant\AccountType;
use Payxy\Merchant\Provider;
use Payxy\Request\RequestPayload;
use Payxy\Support\Amount;
use Payxy\Support\Assert;
use Payxy\Support\Payload;
use Payxy\Support\Wire;

/**
 * Opens a static or dynamic NGN virtual account for a customer.
 */
final readonly class CreateVirtualAccountRequest implements RequestPayload
{
    public string $currency;

    public ?string $expiresAt;

    public ?Amount $expectedAmount;

    /** @var array<string, string> */
    public array $metadata;

    /**
     * @param DateTimeInterface|string|null $expiresAt required for DYNAMIC accounts (ISO 8601)
     * @param Amount|string|int|null $expectedAmount required for DYNAMIC accounts, in major units such as "2500.00"
     * @param array<array-key, string> $metadata free-form key/value pairs
     */
    public function __construct(
        public Provider $provider,
        public AccountType $accountType,
        public VirtualAccountCustomer $customer,
        ?string $currency = null,
        DateTimeInterface|string|null $expiresAt = null,
        Amount|string|int|null $expectedAmount = null,
        array $metadata = [],
    ) {
        $this->currency = Wire::ngn($currency, 'currency');
        $this->expiresAt = $expiresAt === null ? null : Wire::dateTime($expiresAt, 'expiresAt');
        $this->expectedAmount = $expectedAmount === null ? null : Wire::amount($expectedAmount, 'expectedAmount');
        if ($this->expectedAmount !== null) {
            Assert::positiveAmount($this->expectedAmount, 'expectedAmount');
        }

        if ($accountType === AccountType::Dynamic) {
            if ($this->expiresAt === null) {
                throw InvalidArgumentException::forField('expiresAt', 'is required for DYNAMIC accounts');
            }
            if ($this->expectedAmount === null) {
                throw InvalidArgumentException::forField('expectedAmount', 'is required for DYNAMIC accounts');
            }
        } else {
            if ($customer->bvn === null) {
                throw InvalidArgumentException::forField('customer.bvn', 'is required for STATIC accounts');
            }
            if ($customer->dateOfBirth === null) {
                throw InvalidArgumentException::forField('customer.dateOfBirth', 'is required for STATIC accounts');
            }
        }

        $this->metadata = Wire::stringMap($metadata, 'metadata');
    }

    public function toPayload(): array
    {
        return Payload::compact([
            'provider' => $this->provider->value,
            'accountType' => $this->accountType->value,
            'currency' => $this->currency,
            'expiresAt' => $this->expiresAt,
            'expectedAmount' => $this->expectedAmount === null ? null : Wire::number($this->expectedAmount, 'expectedAmount'),
            'customer' => $this->customer->toPayload(),
            'metadata' => $this->metadata === [] ? null : (object) $this->metadata,
        ]);
    }
}
