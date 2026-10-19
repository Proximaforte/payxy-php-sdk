<?php

declare(strict_types=1);

namespace Payxy\Http;

use Payxy\Exception\ConfigurationException;

/**
 * Bounded, scheduled retries for read-only operations (VerifyPayment, GetPaymentInfo):
 * attempt 1 immediately, then after 2 s, 5 s and 10 s by default. Never polls indefinitely.
 *
 * Operations that can start a payment (Initialize) are never retried automatically: a timeout,
 * 429 or lost response does not prove that nothing happened on Payxy's side.
 */
final readonly class RetryPolicy
{
    public const DEFAULT_SCHEDULE_MS = [2000, 5000, 10000];

    private const TRANSIENT_STATUSES = [429, 502, 503, 504];
    private const MAX_RETRIES = 5;
    private const MAX_DELAY_MS = 60_000;
    private const MAX_RETRY_AFTER_SECONDS = 30;

    /** @var list<int> */
    public array $delaysMs;

    public int $maxRetries;

    /**
     * @param list<int> $delaysMs wait before each retry, in milliseconds
     */
    public function __construct(array $delaysMs = self::DEFAULT_SCHEDULE_MS)
    {
        if (\count($delaysMs) > self::MAX_RETRIES) {
            throw new ConfigurationException(\sprintf('At most %d retries are allowed.', self::MAX_RETRIES));
        }
        foreach ($delaysMs as $delay) {
            if ($delay < 0 || $delay > self::MAX_DELAY_MS) {
                throw new ConfigurationException(\sprintf('Retry delays must be between 0 and %d ms.', self::MAX_DELAY_MS));
            }
        }
        $this->delaysMs = array_values($delaysMs);
        $this->maxRetries = \count($this->delaysMs);
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * The default schedule cut or extended (repeating its last delay) to $retries entries.
     */
    public static function withRetries(int $retries): self
    {
        if ($retries < 0 || $retries > self::MAX_RETRIES) {
            throw new ConfigurationException(\sprintf('max_retries must be between 0 and %d.', self::MAX_RETRIES));
        }
        $schedule = self::DEFAULT_SCHEDULE_MS;
        while (\count($schedule) < $retries) {
            $schedule[] = $schedule[\count($schedule) - 1];
        }

        return new self(\array_slice($schedule, 0, $retries));
    }

    /**
     * @param int $retriesSoFar number of retries already performed for this call
     */
    public function shouldRetryAfterNetworkError(Endpoint $endpoint, int $retriesSoFar): bool
    {
        return $endpoint->idempotent && $retriesSoFar < $this->maxRetries;
    }

    public function shouldRetryStatus(Endpoint $endpoint, int $status, int $retriesSoFar): bool
    {
        return $endpoint->idempotent
            && $retriesSoFar < $this->maxRetries
            && \in_array($status, self::TRANSIENT_STATUSES, true);
    }

    /**
     * Scheduled delay, lengthened (never shortened) to honour Retry-After up to 30 s.
     */
    public function delayMs(int $retriesSoFar, ?int $retryAfterSeconds = null): int
    {
        $scheduled = $this->delaysMs[$retriesSoFar] ?? 0;

        return $retryAfterSeconds === null
            ? $scheduled
            : max($scheduled, min($retryAfterSeconds, self::MAX_RETRY_AFTER_SECONDS) * 1000);
    }
}
