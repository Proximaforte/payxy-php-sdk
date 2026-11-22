<?php

declare(strict_types=1);

namespace Payxy\Merchant;

use Payxy\Environment;
use Payxy\Exception\ConfigurationException;
use Payxy\Http\Endpoint;
use Payxy\Http\HttpMethod;
use Payxy\Http\Transport;
use Payxy\Merchant\Request\CreatePayoutRequest;
use Payxy\Merchant\Request\PayoutStatusRequest;
use Payxy\Merchant\Response\PayoutResponse;
use Payxy\PayxyConfig;
use Payxy\Service\AbstractService;

final class PayoutsService extends AbstractService
{
    public function __construct(Transport $transport, private readonly PayxyConfig $config)
    {
        parent::__construct($transport);
    }

    /**
     * Sends a payout from the live wallet. Live keys and an allowlisted server IP are required.
     * Safe to retry with the same idempotency key; after a timeout the outcome is unknown, so
     * look it up with tsq() rather than sending again with a new key.
     */
    public function create(CreatePayoutRequest $request, string $idempotencyKey): PayoutResponse
    {
        if ($this->config->environment !== Environment::Production) {
            throw new ConfigurationException('Payouts need live keys (pk_live_ and sk_live_).');
        }

        return $this->request(
            Endpoint::machine(HttpMethod::Post, '/api/v1/payouts', acceptedCodes: ['05'], idempotencyKey: true),
            PayoutResponse::class,
            $request,
            idempotencyKey: $idempotencyKey,
        );
    }

    /**
     * Transaction status query (TSQ): the current status of a payout, looked up by the idempotency key
     * you sent when you created it. Read-only and safe to repeat; poll it for payouts still processing.
     * An unknown reference, or one that belongs to another merchant, throws NotFoundException.
     */
    public function tsq(string $reference): PayoutResponse
    {
        return $this->request(
            Endpoint::machine(HttpMethod::Post, '/api/v1/payouts/tsq', acceptedCodes: ['05'], readOnly: true),
            PayoutResponse::class,
            new PayoutStatusRequest($reference),
        );
    }

    public function get(string $id): PayoutResponse
    {
        return $this->request(
            Endpoint::machine(HttpMethod::Get, '/api/v1/payouts/{id}', acceptedCodes: ['05']),
            PayoutResponse::class,
            pathParams: ['id' => $id],
        );
    }
}
