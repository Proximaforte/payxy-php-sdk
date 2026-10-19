<?php

declare(strict_types=1);

namespace Payxy\Http;

use LogicException;
use Payxy\Exception\InvalidArgumentException;

/**
 * Static description of one API operation. `idempotent` marks calls that are safe to
 * replay automatically; anything that can move money must leave it false.
 * `codeIsStatus` marks operations whose responseCode reports a record's state (Verify:
 * 02 failed, 05 pending) rather than request success; any response carrying data is kept.
 *
 * @internal
 */
final readonly class Endpoint
{
    public function __construct(
        public HttpMethod $method,
        public string $pathTemplate,
        public bool $idempotent,
        public bool $codeIsStatus = false,
    ) {}

    public static function get(string $pathTemplate): self
    {
        return new self(HttpMethod::Get, $pathTemplate, true);
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
