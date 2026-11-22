<?php

declare(strict_types=1);

namespace Payxy\Merchant;

use Payxy\Http\Endpoint;
use Payxy\Http\HttpMethod;
use Payxy\Merchant\Request\CreateVirtualAccountRequest;
use Payxy\Merchant\Response\VirtualAccountResponse;
use Payxy\Service\AbstractService;

final class VirtualAccountsService extends AbstractService
{
    /**
     * Creates a static or dynamic NGN virtual account. Generate the idempotency key once, store it
     * before sending, and reuse it on a retry: the same key returns the original account with
     * isIdempotentReplay() set instead of creating a second one.
     */
    public function create(CreateVirtualAccountRequest $request, string $idempotencyKey): VirtualAccountResponse
    {
        return $this->request(
            Endpoint::machine(HttpMethod::Post, '/api/v1/virtual-accounts', acceptedCodes: ['09'], idempotencyKey: true),
            VirtualAccountResponse::class,
            $request,
            idempotencyKey: $idempotencyKey,
        );
    }
}
