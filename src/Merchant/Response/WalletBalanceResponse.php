<?php

declare(strict_types=1);

namespace Payxy\Merchant\Response;

use Payxy\Response\PayxyResponse;
use Payxy\Support\Amount;
use Payxy\Support\ArrayReader;

final class WalletBalanceResponse extends PayxyResponse
{
    /**
     * What can be spent now.
     */
    public function getBalance(): ?Amount
    {
        return $this->getAvailableBalance() ?? Amount::tryFromApi($this->data->get('balance'));
    }

    public function getAvailableBalance(): ?Amount
    {
        return Amount::tryFromApi($this->wallet()->get('availableBalance', 'available_balance'));
    }

    public function getLedgerBalance(): ?Amount
    {
        return Amount::tryFromApi($this->wallet()->get('ledgerBalance', 'ledger_balance'));
    }

    public function getWalletId(): ?string
    {
        return $this->wallet()->string('walletId', 'wallet_id');
    }

    public function getCurrency(): ?string
    {
        return $this->data->string('currency');
    }

    /**
     * The wallet record nested under `data.balance`; empty when the balance is a flat number.
     */
    private function wallet(): ArrayReader
    {
        return new ArrayReader($this->data->record('balance') ?? []);
    }
}
