<?php

declare(strict_types=1);

namespace Payxy\Service;

use Payxy\Http\Endpoint;
use Payxy\Http\Transport;
use Payxy\Request\RequestPayload;
use Payxy\Response\PayxyResponse;

/**
 * Generic plumbing shared by every resource service: one call turns an endpoint,
 * a payload and a response class into a typed result.
 */
abstract class AbstractService
{
    public function __construct(private readonly Transport $transport) {}

    /**
     * @template T of PayxyResponse
     *
     * @param class-string<T> $responseClass
     * @param array<string, string> $pathParams
     * @param array<string, string> $query
     *
     * @return T
     */
    protected function request(
        Endpoint $endpoint,
        string $responseClass,
        ?RequestPayload $payload = null,
        array $pathParams = [],
        array $query = [],
    ): PayxyResponse {
        $result = $this->transport->send($endpoint, $pathParams, $query, $payload?->toPayload());

        return $responseClass::fromResult($result);
    }
}
