<?php

declare(strict_types=1);

namespace Payxy\Merchant;

use Payxy\Environment;
use Payxy\Exception\ConfigurationException;
use Payxy\Http\Endpoint;
use Payxy\Http\HttpMethod;
use Payxy\Http\Transport;
use Payxy\Merchant\Request\SimulateCreditRequest;
use Payxy\Merchant\Response\SimulatedCreditResponse;
use Payxy\PayxyConfig;
use Payxy\Service\AbstractService;

final class SimulatorService extends AbstractService
{
    public function __construct(Transport $transport, private readonly PayxyConfig $config)
    {
        parent::__construct($transport);
    }

    /**
     * Sandbox only: fakes a transfer into a test virtual account and queues the webhook.
     */
    public function creditVirtualAccount(SimulateCreditRequest $request, string $idempotencyKey): SimulatedCreditResponse
    {
        if ($this->config->environment !== Environment::Sandbox) {
            throw new ConfigurationException('The simulator only works with test keys (pk_test_).');
        }

        return $this->request(
            Endpoint::machine(HttpMethod::Post, '/api/v1/simulator/virtual-account-credits', acceptedCodes: ['05'], idempotencyKey: true),
            SimulatedCreditResponse::class,
            $request,
            idempotencyKey: $idempotencyKey,
        );
    }
}
