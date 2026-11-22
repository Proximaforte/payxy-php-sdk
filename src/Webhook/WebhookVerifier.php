<?php

declare(strict_types=1);

namespace Payxy\Webhook;

use Closure;
use JsonException;
use Payxy\Auth\WebhookSecret;
use Payxy\Environment;
use Payxy\Exception\ConfigurationException;
use Payxy\Exception\WebhookVerificationException;
use Psr\Http\Message\ServerRequestInterface;
use SensitiveParameter;

/**
 * Authenticates Payxy webhooks: HMAC-SHA512(sk_ secret, timestamp + "." + rawBody), lowercase hex,
 * compared in constant time against every comma-separated signature (secret-rotation grace period),
 * with a freshness window that stops captured webhooks from being replayed later.
 */
final class WebhookVerifier
{
    public const SIGNATURE_HEADER = 'x-payxy-signature';
    public const TIMESTAMP_HEADER = 'x-payxy-timestamp';
    public const ATTEMPT_HEADER = 'x-payxy-attempt';

    private const MAX_BODY_BYTES = 1_048_576;

    private readonly WebhookSecret $secret;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param int $toleranceSeconds maximum age (and clock skew) accepted for x-payxy-timestamp
     * @param (Closure(): int)|null $clock current Unix time; injectable for tests
     */
    public function __construct(
        #[SensitiveParameter]
        WebhookSecret|string $secret,
        private readonly int $toleranceSeconds = 300,
        ?Closure $clock = null,
    ) {
        if ($toleranceSeconds < 1 || $toleranceSeconds > 3600) {
            throw new ConfigurationException('Webhook tolerance must be between 1 and 3600 seconds.');
        }
        $this->secret = \is_string($secret) ? new WebhookSecret($secret) : $secret;
        $this->clock = $clock ?? time(...);
    }

    /**
     * True when the webhook is authentic, fresh and structurally valid. Pass the exact raw body bytes.
     */
    public function verify(string $rawBody, ?string $signatureHeader, ?string $timestampHeader): bool
    {
        try {
            $this->constructEvent($rawBody, $signatureHeader, $timestampHeader);

            return true;
        } catch (WebhookVerificationException) {
            return false;
        }
    }

    /**
     * Authenticates, then parses the webhook. Throws WebhookVerificationException (answer 401/400
     * and do not process). Pass the exact raw body bytes, before any JSON parsing or re-encoding.
     */
    public function constructEvent(string $rawBody, ?string $signatureHeader, ?string $timestampHeader, ?string $attemptHeader = null): WebhookEvent
    {
        $this->authenticate($rawBody, $signatureHeader, $timestampHeader);

        try {
            $payload = json_decode($rawBody, true, 64, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw new WebhookVerificationException('Webhook body is not valid JSON.');
        }
        if (!\is_array($payload)) {
            throw new WebhookVerificationException('Webhook body is not a JSON object.');
        }
        $this->validatePayload($payload);

        $attempt = $attemptHeader !== null && ctype_digit(trim($attemptHeader)) ? (int) $attemptHeader : 1;

        return new WebhookEvent($payload, $attempt);
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function validatePayload(array $payload): void
    {
        // Key case varies by event type ("Event" on payments, "event" on endpoint verification).
        $payload = array_change_key_case($payload);
        $event = $payload['event'] ?? null;
        if (!\is_string($event) || trim($event) === '') {
            throw new WebhookVerificationException('Webhook Event is missing or malformed.');
        }

        // Some events carry no Mode. The signature already binds the environment, so only a wrong one is rejected.
        if (\array_key_exists('mode', $payload)) {
            $mode = $payload['mode'];
            if (!\is_string($mode) || !\in_array($mode, ['Test', 'Production'], true)) {
                throw new WebhookVerificationException('Webhook Mode must be Test or Production.');
            }

            $expectedMode = $this->secret->environment() === Environment::Sandbox ? 'Test' : 'Production';
            if ($mode !== $expectedMode) {
                throw new WebhookVerificationException('Webhook Mode does not match the configured webhook secret.');
            }
        }

        $data = $payload['data'] ?? null;
        if (!\is_array($data)) {
            throw new WebhookVerificationException('Webhook Data is missing or malformed.');
        }

        // Payment events are reconciled by your reference. Other event types identify themselves
        // differently (settlement, virtual account) and may carry no TransactionId.
        if (\in_array($event, [WebhookEvent::PAYMENT_SUCCESS, WebhookEvent::PAYMENT_FAILED], true)) {
            $transactionId = array_change_key_case($data)['transactionid'] ?? null;
            if (!\is_string($transactionId) || trim($transactionId) === '') {
                throw new WebhookVerificationException('Webhook Data.TransactionId is missing or malformed.');
            }
        }
    }

    /**
     * {@see constructEvent()} for PSR-7 requests (Slim, Mezzio, Symfony via PSR bridge, ...).
     */
    public function constructEventFromRequest(ServerRequestInterface $request): WebhookEvent
    {
        $body = $request->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        return $this->constructEvent(
            $body->getContents(),
            $request->getHeaderLine(self::SIGNATURE_HEADER),
            $request->getHeaderLine(self::TIMESTAMP_HEADER),
            $request->getHeaderLine(self::ATTEMPT_HEADER),
        );
    }

    /**
     * {@see constructEvent()} for plain PHP, reading php://input and $_SERVER.
     */
    public function constructEventFromGlobals(): WebhookEvent
    {
        $raw = file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);

        return $this->constructEvent(
            $raw === false ? '' : $raw,
            self::serverHeader('HTTP_X_PAYXY_SIGNATURE'),
            self::serverHeader('HTTP_X_PAYXY_TIMESTAMP'),
            self::serverHeader('HTTP_X_PAYXY_ATTEMPT'),
        );
    }

    private function authenticate(string $rawBody, ?string $signatureHeader, ?string $timestampHeader): void
    {
        if ($signatureHeader === null || trim($signatureHeader) === '') {
            throw new WebhookVerificationException('Missing ' . self::SIGNATURE_HEADER . ' header.');
        }
        if ($timestampHeader === null || !ctype_digit(trim($timestampHeader))) {
            throw new WebhookVerificationException('Missing or malformed ' . self::TIMESTAMP_HEADER . ' header.');
        }
        if (\strlen($rawBody) > self::MAX_BODY_BYTES) {
            throw new WebhookVerificationException('Webhook body is too large.');
        }

        $timestamp = trim($timestampHeader);
        if (abs(($this->clock)() - (int) $timestamp) > $this->toleranceSeconds) {
            throw new WebhookVerificationException('Webhook timestamp is outside the accepted window.');
        }

        $expected = hash_hmac('sha512', $timestamp . '.' . $rawBody, $this->secret->value());
        $matched = false;
        foreach (explode(',', $signatureHeader) as $candidate) {
            // Evaluate every candidate so timing does not reveal which one matched.
            $matched = hash_equals($expected, strtolower(trim($candidate))) || $matched;
        }
        if (!$matched) {
            throw new WebhookVerificationException('Webhook signature does not match.');
        }
    }

    private static function serverHeader(string $key): ?string
    {
        $value = $_SERVER[$key] ?? null;

        return \is_string($value) ? $value : null;
    }
}
