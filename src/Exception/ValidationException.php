<?php

declare(strict_types=1);

namespace Payxy\Exception;

/**
 * HTTP 400/422: the gateway rejected the request payload. See getErrors() for field details.
 */
final class ValidationException extends ApiException {}
