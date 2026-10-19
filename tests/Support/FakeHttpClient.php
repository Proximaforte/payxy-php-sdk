<?php

declare(strict_types=1);

namespace Payxy\Tests\Support;

use LogicException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Replays queued responses (or throws queued transport errors) and records every request.
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface|ClientExceptionInterface> */
    private array $queue;

    public function __construct(ResponseInterface|ClientExceptionInterface ...$queue)
    {
        $this->queue = array_values($queue);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue) ?? throw new LogicException('No queued response left for ' . $request->getUri());

        if ($next instanceof ClientExceptionInterface) {
            throw $next;
        }

        return $next;
    }

    public function lastRequest(): RequestInterface
    {
        return $this->requests[array_key_last($this->requests) ?? throw new LogicException('No request was sent.')];
    }

    /**
     * @return array<array-key, mixed>
     */
    public function lastJsonBody(): array
    {
        $decoded = json_decode((string) $this->lastRequest()->getBody(), true, 64, \JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? $decoded : throw new LogicException('Body is not a JSON object.');
    }
}
