<?php

declare(strict_types=1);

namespace Payxy\Exception;

/**
 * HTTP 403: the credentials are valid but not allowed to perform this operation.
 */
final class PermissionDeniedException extends ApiException {}
