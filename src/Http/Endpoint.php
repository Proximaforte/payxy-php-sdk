<?php

declare(strict_types=1);

namespace Payxy\Http;

use LogicException;
use Payxy\Exception\InvalidArgumentException;

/**
 * Static description of one API operation. `idempotent` marks calls that are safe to
 * replay automatically; anything that can move money must leave it false unless it carries
 * an idempotency key. `codeIsStatus` marks operations whose responseCode reports a record's
 * state (Verify: 02 failed, 05 pending) rather than request success; any response carrying
 * data is kept. `tokenAuth` sends an OAuth access token instead of the public key, and
 * `acceptedCodes` lists responseCode values, besides "00", that count as success.
 *
 * @internal
 */
final readonly class Endpoint
{
    /**
     * @param list<string> $acceptedCodes
     */
    public function __construct(
        public HttpMethod $method,
        public string $pathTemplate,
        public bool $idempotent,
        public bool $codeIsStatus = false,
        public bool $requiresAuthentication = true,
        public bool $tokenAuth = false,
        public array $acceptedCodes = [],
        public bool $requiresIdempotencyKey = false,
    ) {}

    /**
     * An endpoint of the money-movement API, authenticated with an access token.
     *
     * @param list<string> $acceptedCodes extra success codes, e.g. "05" for accepted and processing
     * @param bool $idempotencyKey needs a caller-supplied Idempotency-Key; the request is then safe to retry
     * @param bool $readOnly a read-only call sent as POST (such as a status query): safe to retry
     */
    public static function machine(
        HttpMethod $method,
        string $pathTemplate,
        array $acceptedCodes = [],
        bool $idempotencyKey = false,
        bool $readOnly = false,
    ): self {
        return new self(
            $method,
            $pathTemplate,
            $method === HttpMethod::Get || $idempotencyKey || $readOnly,
            false,
            true,
            true,
            $acceptedCodes,
            $idempotencyKey,
        );
    }

    /**
     * The token exchange: unauthenticated and never retried automatically, since refresh tokens rotate.
     */
    public static function oauth(string $pathTemplate): self
    {
        return new self(HttpMethod::Post, $pathTemplate, false, requiresAuthentication: false);
    }

    public static function get(string $pathTemplate, bool $requiresAuthentication = true): self
    {
        return new self(HttpMethod::Get, $pathTemplate, true, requiresAuthentication: $requiresAuthentication);
    }

    public static function post(string $pathTemplate, bool $idempotent = false, bool $codeIsStatus = false): self
    {
        return new self(HttpMethod::Post, $pathTemplate, $idempotent, $codeIsStatus);
    }

    /**
     * Substitutes `{name}` placeholders with URL-encoded values, so a value can never
     * inject extra path segments, a query string or a fragment.
     *
     * @param array<string, string> $params
     */
    public function resolvePath(array $params): string
    {
        $path = preg_replace_callback(
            '/\{([A-Za-z0-9_]+)\}/',
            static function (array $m) use ($params): string {
                $value = $params[$m[1]] ?? '';
                if (trim($value) === '') {
                    throw InvalidArgumentException::forField($m[1], 'must not be blank');
                }

                return rawurlencode($value);
            },
            $this->pathTemplate,
        );

        return $path ?? throw new LogicException('Invalid path template.');
    }
}
