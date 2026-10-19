<?php

declare(strict_types=1);

namespace Payxy\Exception;

/**
 * HTTP 2xx, but Payxy's responseCode was not "00": the request was understood and refused.
 * See getResponseCode() and the message for Payxy's reason.
 */
final class RequestFailedException extends ApiException {}
