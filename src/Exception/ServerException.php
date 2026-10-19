<?php

declare(strict_types=1);

namespace Payxy\Exception;

/**
 * HTTP 5xx: the gateway failed. For payment calls, verify the payment before retrying.
 */
final class ServerException extends ApiException {}
