<?php

declare(strict_types=1);

namespace Payxy\Exception;

/**
 * HTTP 409: the request conflicts with the current state, e.g. a reused transaction id.
 */
final class ConflictException extends ApiException {}
