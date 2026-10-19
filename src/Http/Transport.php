<?php

declare(strict_types=1);

namespace Payxy\Http;

use JsonException;
use Payxy\Exception\ApiException;
use Payxy\Exception\InvalidResponseException;
use Payxy\Exception\TransportException;
use Payxy\Payxy;
use Payxy\PayxyConfig;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use stdClass;

/**
 * The single request pipeline: auth, encoding, retries, decoding and error mapping.
 *
 * Logs carry only method, path template, status, attempt and timing; bodies, headers
 * and resolved path values are never logged because they may contain card or token data.
 *
 * @internal
 */
final class Transport
{
    private const JSON_DEPTH = 64;
    private const SUCCESS_CODE = '00';
    private const ERROR_EXCERPT_BYTES = 1024;
    private const REQUEST_ID_HEADERS = ['X-Request-Id', 'Request-Id', 'X-Correlation-Id'];

    private readonly string $userAgent;

    public function __construct(
        private readonly PayxyConfig $config,
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly LoggerInterface $logger,
        private readonly Sleeper $sleeper,
    ) {
        $this->userAgent = \sprintf('payxy-php-sdk/%s PHP/%s', Payxy::VERSION, \PHP_VERSION)
            . ($config->userAgentSuffix !== null ? ' ' . $config->userAgentSuffix : '');
    }

    /**
     * @param array<string, string> $pathParams
     * @param array<string, string> $query
     * @param array<array-key, mixed>|null $body
     */
    public function send(
        Endpoint $endpoint,
        array $pathParams = [],
        array $query = [],
        #[SensitiveParameter]
        ?array $body = null,
    ): ApiResult {
        $url = $this->config->baseUrl . $endpoint->resolvePath($pathParams)
            . ($query === [] ? '' : '?' . http_build_query($query, '', '&', \PHP_QUERY_RFC3986));
        $encodedBody = $endpoint->method->hasBody() ? $this->encode($body ?? []) : null;

        $retries = 0;

        while (true) {
            $request = $this->buildRequest($endpoint, $url, $encodedBody);
            $startedAt = hrtime(true);

            try {
                $response = $this->client->sendRequest($request);
            } catch (ClientExceptionInterface $e) {
                $this->log('warning', 'Payxy request failed at network level', $endpoint, null, $retries, $startedAt);
                if ($this->config->retryPolicy->shouldRetryAfterNetworkError($endpoint, $retries)) {
                    $this->sleeper->sleep($this->config->retryPolicy->delayMs($retries++));
                    continue;
                }

                throw new TransportException(
                    \sprintf('Could not reach Payxy for %s %s.', $endpoint->method->value, $endpoint->pathTemplate)
                        . ($endpoint->idempotent ? '' : ' The request may have been processed; verify the payment before retrying.'),
                    !$endpoint->idempotent,
                    $e,
                );
            }

            $status = $response->getStatusCode();
            $this->log($status < 400 ? 'info' : 'warning', 'Payxy request completed', $endpoint, $status, $retries, $startedAt);

            if ($status >= 200 && $status < 300) {
                return $this->success($endpoint, $response);
            }

            $retryAfter = $this->retryAfterSeconds($response);
            if ($this->config->retryPolicy->shouldRetryStatus($endpoint, $status, $retries)) {
                $this->sleeper->sleep($this->config->retryPolicy->delayMs($retries++, $retryAfter));
                continue;
            }

            [$body, $text] = $this->decodeError($response);

            throw ApiException::fromResponse($status, $body, $this->requestId($response), $retryAfter, $text);
        }
    }

    private function buildRequest(Endpoint $endpoint, string $url, #[SensitiveParameter] ?string $body): RequestInterface
    {
        $request = $this->requestFactory->createRequest($endpoint->method->value, $url)
            ->withHeader('Authorization', 'Bearer ' . $this->config->publicKey->value())
            ->withHeader('Accept', 'application/json')
            ->withHeader('User-Agent', $this->userAgent);

        if ($body === null) {
            return $request;
        }

        return $request
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withBody($this->streamFactory->createStream($body));
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private function encode(#[SensitiveParameter] array $body): string
    {
        // An empty PHP array must go out as a JSON object, not [].
        return json_encode(
            $body === [] ? new stdClass() : $body,
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decodeSuccess(ResponseInterface $response): array
    {
        $raw = $this->readBody($response);
        if (trim($raw) === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, self::JSON_DEPTH, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw new InvalidResponseException('Payxy returned a response that is not valid JSON.', $response->getStatusCode());
        }

        if (!\is_array($decoded)) {
            throw new InvalidResponseException('Payxy returned a JSON scalar where an object was expected.', $response->getStatusCode());
        }

        return $decoded;
    }

    /**
     * Payxy signals business failures with a non-"00" responseCode, sometimes on HTTP 200.
     */
    private function success(Endpoint $endpoint, ResponseInterface $response): ApiResult
    {
        $status = $response->getStatusCode();
        $body = $this->decodeSuccess($response);
        $code = $body['responseCode'] ?? null;
        $carriesRecord = $endpoint->codeIsStatus && \is_array($body['data'] ?? null) && $body['data'] !== [];

        if (\is_string($code) && $code !== self::SUCCESS_CODE && !$carriesRecord) {
            throw ApiException::fromResponse($status, $body, $this->requestId($response), null);
        }

        return new ApiResult($status, $body, $this->requestId($response));
    }

    /**
     * Error bodies are best-effort: plain-text or HTML bodies still surface as an ApiException,
     * with a short sanitised excerpt so messages such as "Invalid merchant key" are not lost.
     *
     * @return array{array<array-key, mixed>, string|null}
     */
    private function decodeError(ResponseInterface $response): array
    {
        try {
            return [$this->decodeSuccess($response), null];
        } catch (InvalidResponseException) {
            $stream = $response->getBody();
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            // Payxy sometimes sends the reason as a bare JSON string, e.g. "Invalid merchant key".
            $text = trim(trim((string) preg_replace('/\s+/', ' ', strip_tags($stream->read(self::ERROR_EXCERPT_BYTES)))), '"');

            return [[], $text === '' ? null : mb_substr($text, 0, 200)];
        }
    }

    private function readBody(ResponseInterface $response): string
    {
        $stream = $response->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $buffer = '';
        while (!$stream->eof()) {
            $chunk = $stream->read(8192);
            if ($chunk === '') {
                break;
            }
            $buffer .= $chunk;
            if (\strlen($buffer) > $this->config->maxResponseBytes) {
                throw new InvalidResponseException('Payxy response exceeded the configured size limit.', $response->getStatusCode());
            }
        }

        return $buffer;
    }

    private function requestId(ResponseInterface $response): ?string
    {
        foreach (self::REQUEST_ID_HEADERS as $header) {
            $value = $response->getHeaderLine($header);
            if ($value !== '') {
                return mb_substr($value, 0, 128);
            }
        }

        return null;
    }

    private function retryAfterSeconds(ResponseInterface $response): ?int
    {
        $value = trim($response->getHeaderLine('Retry-After'));
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return (int) $value;
        }
        $date = strtotime($value);

        return $date === false ? null : max(0, $date - time());
    }

    private function log(string $level, string $message, Endpoint $endpoint, ?int $status, int $retries, int|float $startedAt): void
    {
        $this->logger->log($level, $message, [
            'method' => $endpoint->method->value,
            'path' => $endpoint->pathTemplate,
            'status' => $status,
            'attempt' => $retries + 1,
            'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1e6),
        ]);
    }
}
