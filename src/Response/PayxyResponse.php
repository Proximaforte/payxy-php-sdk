<?php

declare(strict_types=1);

namespace Payxy\Response;

use Payxy\Http\ApiResult;
use Payxy\ResponseCode;
use Payxy\Support\ArrayReader;

/**
 * Base for every typed response. Typed accessors cover the documented fields; anything
 * else stays reachable through {@see get()} and {@see toArray()}.
 */
abstract class PayxyResponse
{
    protected readonly ArrayReader $data;

    final public function __construct(private readonly ApiResult $result)
    {
        $this->data = new ArrayReader($result->body);
    }

    /**
     * @internal
     */
    public static function fromResult(ApiResult $result): static
    {
        return new static($result);
    }

    public function getStatusCode(): int
    {
        return $this->result->statusCode;
    }

    public function getRequestId(): ?string
    {
        return $this->result->requestId;
    }

    /**
     * Payxy's responseMessage.
     */
    public function getMessage(): ?string
    {
        return $this->data->string('responseMessage', 'message');
    }

    /**
     * Raw responseCode ("00" = success); kept as a string so unknown future codes survive.
     */
    public function getResponseCode(): ?string
    {
        return $this->data->string('responseCode');
    }

    public function responseCode(): ?ResponseCode
    {
        $code = $this->getResponseCode();

        return $code === null ? null : ResponseCode::tryFrom($code);
    }

    /**
     * Raw value by key (case-insensitive, `data` envelope aware) or dot path.
     */
    public function get(string $key): mixed
    {
        return $this->data->get($key);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        return $this->data->all();
    }
}
