<?php

declare(strict_types=1);

namespace Payxy\Merchant;

use Payxy\Http\Endpoint;
use Payxy\Http\HttpMethod;
use Payxy\Merchant\Response\ProvidersResponse;
use Payxy\Service\AbstractService;

/**
 * Needs the public and secret key (see {@see \Payxy\PayxyConfig}); the access token is handled for you.
 */
final class ProvidersService extends AbstractService
{
    /**
     * The providers and account types currently available.
     */
    public function list(): ProvidersResponse
    {
        return $this->request(Endpoint::machine(HttpMethod::Get, '/api/v1/providers'), ProvidersResponse::class);
    }
}
