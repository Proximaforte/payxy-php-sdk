<?php

declare(strict_types=1);

namespace Payxy\Merchant\Request;

use Payxy\Request\RequestPayload;
use Payxy\Support\Amount;
use Payxy\Support\Assert;
use Payxy\Support\Payload;
use Payxy\Support\Wire;

/**
 * Sandbox only: a fake transfer into a test virtual account.
 */
final readonly class SimulateCreditRequest implements RequestPayload
{
    public Amount $amount;

    public string $currency;

    /**
     * @param string $accountNumber a test virtual account created for this merchant
     * @param Amount|string|int $amount in major units, e.g. "2500.00"
     */
    public function __construct(
        public string $accountNumber,
        Amount|string|int $amount,
        ?string $currency = null,
        public ?string $senderAccountNumber = null,
        public ?string $senderName = null,
        public ?string $senderBankName = null,
    ) {
        Assert::digits($accountNumber, 'accountNumber', 10, 10);
        $this->amount = Wire::amount($amount, 'amount');
        Assert::positiveAmount($this->amount, 'amount');
        $this->currency = Wire::ngn($currency, 'currency');
        Assert::digits($senderAccountNumber, 'senderAccountNumber', 10, 10);
        Assert::text($senderName, 'senderName', 100);
        Assert::text($senderBankName, 'senderBankName', 100);
    }

    public function toPayload(): array
    {
        return Payload::compact([
            'accountNumber' => $this->accountNumber,
            'amount' => Wire::number($this->amount, 'amount'),
            'currency' => $this->currency,
            'senderAccountNumber' => $this->senderAccountNumber,
            'senderName' => $this->senderName,
            'senderBankName' => $this->senderBankName,
        ]);
    }
}
