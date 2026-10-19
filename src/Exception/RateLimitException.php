<?php

declare(strict_types=1);

namespace Payxy\Exception;

/**
 * HTTP 429: too many requests. Honour getRetryAfterSeconds() before trying again.
 */
final class RateLimitException extends ApiException {}
