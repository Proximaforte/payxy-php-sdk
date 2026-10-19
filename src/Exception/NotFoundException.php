<?php

declare(strict_types=1);

namespace Payxy\Exception;

/**
 * HTTP 404: the transaction or access code does not exist.
 */
final class NotFoundException extends ApiException {}
