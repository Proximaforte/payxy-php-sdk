<?php

declare(strict_types=1);

namespace Payxy\Merchant;

use Payxy\Http\Endpoint;
use Payxy\Http\HttpMethod;
use Payxy\Merchant\Response\WalletBalanceResponse;
use Payxy\Service\AbstractService;

final class WalletService extends AbstractService
{
    public function balance(): WalletBalanceResponse
    {
        return $this->request(Endpoint::machine(HttpMethod::Get, '/api/v1/wallet/balance'), WalletBalanceResponse::class);
    }
}
