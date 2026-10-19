<?php

declare(strict_types=1);

namespace Payxy\Exception;

use RuntimeException;

/**
 * The webhook is not provably from Payxy (bad or missing signature, stale timestamp, malformed body).
 * Respond with HTTP 400 and do not process it.
 */
final class WebhookVerificationException extends RuntimeException implements PayxyException {}
