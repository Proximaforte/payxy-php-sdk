<?php

declare(strict_types=1);

namespace Payxy\Merchant\Request;

use Payxy\Request\RequestPayload;
use Payxy\Support\Amount;
use Payxy\Support\Assert;
use Payxy\Support\Payload;
use Payxy\Support\Wire;
use SensitiveParameter;

/**
 * Sends money from the live wallet to a Nigerian bank account.
 */
final readonly class CreatePayoutRequest implements RequestPayload
{
    public Amount $amount;

    /**
     * @param Amount|string|int $amount in major units, e.g. "5000.00"
     * @param string|null $transactionPin required by the current wallet debit contract only
     */
    public function __construct(
        public string $accountNumber,
        public string $accountName,
        public string $bankCode,
        Amount|string|int $amount,
        public ?string $bankName = null,
        public ?string $narration = null,
        #[SensitiveParameter]
        private ?string $transactionPin = null,
    ) {
        Assert::digits($accountNumber, 'accountNumber', 10, 10);
        Assert::notBlank($accountName, 'accountName', 100);
        Assert::digits($bankCode, 'bankCode', 3, 10);
        Assert::text($bankName, 'bankName', 100);
        $this->amount = Wire::amount($amount, 'amount');
        Assert::positiveAmount($this->amount, 'amount');
        Assert::text($narration, 'narration', 100);
        if ($transactionPin !== null) {
            Assert::notBlank($transactionPin, 'transactionPin', 32);
        }
    }

    public function toPayload(): array
    {
        return Payload::compact([
            'accountNumber' => $this->accountNumber,
            'accountName' => $this->accountName,
            'bankCode' => $this->bankCode,
            'bankName' => $this->bankName,
            'amount' => Wire::number($this->amount, 'amount'),
            'narration' => $this->narration,
            'transactionPin' => $this->transactionPin,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'accountNumber' => $this->accountNumber,
            'accountName' => $this->accountName,
            'bankCode' => $this->bankCode,
            'amount' => $this->amount->toDecimalString(),
            'transactionPin' => $this->transactionPin === null ? null : '***',
        ];
    }
}
