<?php

declare(strict_types=1);

namespace Payxy\Merchant\Request;

use Payxy\Support\Assert;
use Payxy\Support\Wire;
use SensitiveParameter;

/**
 * The customer a virtual account is opened for.
 */
final readonly class VirtualAccountCustomer
{
    /**
     * @param string $reference your own identifier for this customer
     * @param string|null $bvn required for STATIC accounts; sent to the provider for KYC, Payxy does not store it
     * @param string|null $dateOfBirth required for STATIC accounts, as YYYY-MM-DD
     */
    public function __construct(
        public string $reference,
        public string $firstName,
        public string $lastName,
        public ?string $email = null,
        public ?string $phone = null,
        #[SensitiveParameter]
        public ?string $bvn = null,
        public ?string $dateOfBirth = null,
    ) {
        Assert::notBlank($reference, 'customer.reference', 128);
        Assert::notBlank($firstName, 'customer.firstName', 100);
        Assert::notBlank($lastName, 'customer.lastName', 100);
        Assert::email($email, 'customer.email');
        Assert::text($phone, 'customer.phone', 32);
        Assert::digits($bvn, 'customer.bvn', 11, 11);
        if ($dateOfBirth !== null) {
            Wire::date($dateOfBirth, 'customer.dateOfBirth');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'reference' => $this->reference,
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'email' => $this->email,
            'phone' => $this->phone,
            'bvn' => $this->bvn,
            'dateOfBirth' => $this->dateOfBirth,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['reference' => $this->reference, 'firstName' => $this->firstName, 'lastName' => $this->lastName, 'bvn' => $this->bvn === null ? null : '***'];
    }
}
