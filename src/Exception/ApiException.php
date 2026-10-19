<?php

declare(strict_types=1);

namespace Payxy\Exception;

use Payxy\ResponseCode;
use Payxy\Support\ArrayReader;
use RuntimeException;

/**
 * The gateway rejected the request: a non-2xx status, or a 2xx carrying a non-"00" responseCode.
 * Subclasses narrow it down by status code.
 */
class ApiException extends RuntimeException implements PayxyException
{
    private const MAX_MESSAGE_LENGTH = 500;
    private const AUTH_HINT = " Check that the public key is current for this environment (pk_test_ for sandbox, pk_live_ for production) and that this server's IP is allowlisted.";

    /**
     * @param array<array-key, mixed> $responseBody
     * @param array<string, list<string>> $errors
     */
    final public function __construct(
        string $message,
        private readonly int $statusCode,
        private readonly array $responseBody = [],
        private readonly array $errors = [],
        private readonly ?string $requestId = null,
        private readonly ?int $retryAfterSeconds = null,
        private readonly ?string $responseCode = null,
    ) {
        parent::__construct($message, $statusCode);
    }

    /**
     * Builds the most specific exception for the status, reading ASP.NET-style
     * `message` / `title` / `errors` fields when the body provides them.
     *
     * @param array<array-key, mixed> $body
     */
    public static function fromResponse(
        int $statusCode,
        array $body,
        ?string $requestId,
        ?int $retryAfterSeconds,
        ?string $plainText = null,
    ): self {
        $reader = new ArrayReader($body);
        $responseCode = $reader->string('responseCode');
        $detail = $reader->string('responseMessage', 'message', 'title', 'detail', 'error') ?? $plainText;

        $class = match (true) {
            $responseCode === ResponseCode::BadRequest->value && $detail !== null && stripos($detail, 'already exists') !== false
                => DuplicateTransactionException::class,
            $statusCode >= 200 && $statusCode < 300 => RequestFailedException::class,
            $statusCode === 400, $statusCode === 422 => ValidationException::class,
            $statusCode === 401 => AuthenticationException::class,
            $statusCode === 403 => PermissionDeniedException::class,
            $statusCode === 404 => NotFoundException::class,
            $statusCode === 409 => ConflictException::class,
            $statusCode === 429 => RateLimitException::class,
            $statusCode >= 500 => ServerException::class,
            default => self::class,
        };

        $message = \sprintf('Payxy API returned HTTP %d', $statusCode)
            . ($responseCode !== null ? \sprintf(' (responseCode %s)', $responseCode) : '')
            . ($detail !== null ? ': ' . rtrim(mb_substr($detail, 0, self::MAX_MESSAGE_LENGTH), '. ') . '.' : '.')
            . ($statusCode === 401 ? self::AUTH_HINT : '');

        return new $class(
            $message,
            $statusCode,
            $body,
            self::extractErrors($reader->get('errors')),
            $requestId ?? $reader->string('traceId', 'requestId'),
            $retryAfterSeconds,
            $responseCode,
        );
    }

    /**
     * Payxy's own result code ("00" = success), when the body carried one.
     */
    public function getResponseCode(): ?string
    {
        return $this->responseCode;
    }

    /**
     * Typed view of getResponseCode(); null for absent or not-yet-known codes.
     */
    public function responseCode(): ?ResponseCode
    {
        return $this->responseCode === null ? null : ResponseCode::tryFrom($this->responseCode);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Decoded JSON body as returned by the gateway (empty when the body was not JSON).
     *
     * @return array<array-key, mixed>
     */
    public function getResponseBody(): array
    {
        return $this->responseBody;
    }

    /**
     * Field-level validation messages, keyed by field name.
     *
     * @return array<string, list<string>>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Correlation id to quote when contacting Payxy support.
     */
    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    public function getRetryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function extractErrors(mixed $errors): array
    {
        if (!\is_array($errors)) {
            return [];
        }

        $result = [];
        foreach ($errors as $field => $messages) {
            $list = \is_array($messages) ? $messages : [$messages];
            $result[(string) $field] = array_values(array_map(
                static fn(mixed $m): string => \is_scalar($m) ? (string) $m : '',
                array_filter($list, is_scalar(...)),
            ));
        }

        return $result;
    }
}
