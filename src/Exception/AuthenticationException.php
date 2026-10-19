<?php

declare(strict_types=1);

namespace Payxy\Exception;

/**
 * HTTP 401: the access token is missing, invalid or expired.
 */
final class AuthenticationException extends ApiException {}
