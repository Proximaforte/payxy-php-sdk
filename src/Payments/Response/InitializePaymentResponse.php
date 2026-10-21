<?php

declare(strict_types=1);

namespace Payxy\Payments\Response;

final class InitializePaymentResponse extends PaymentResponse
{
    private ?string $fallbackPaymentUrl = null;

    /**
     * Pass to the official browser / mobile SDKs, or store to look the session up later.
     */
    public function getAccessCode(): ?string
    {
        return $this->data->string('accessCode');
    }

    /**
     * Payxy's hosted checkout page as an absolute https:// URL: safe to use in a Location header.
     * A gateway value that isn't an absolute https URL (e.g. a bare access code or a relative path)
     * is replaced by the environment's checkout URL for the access code; null only when neither exists.
     */
    public function getPaymentUrl(): ?string
    {
        $raw = $this->getRawPaymentUrl();

        return $raw !== null && self::isAbsoluteHttpsUrl($raw) ? $raw : $this->fallbackPaymentUrl;
    }

    /**
     * The paymentUrl exactly as Payxy returned it, for logging and diagnostics.
     */
    public function getRawPaymentUrl(): ?string
    {
        return $this->data->string('paymentUrl', 'checkoutUrl', 'authorizationUrl');
    }

    /**
     * @internal set by PaymentsService from the configured environment
     */
    public function withFallbackPaymentUrl(string $url): self
    {
        $clone = clone $this;
        $clone->fallbackPaymentUrl = $url;

        return $clone;
    }

    private static function isAbsoluteHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);

        return \is_array($parts)
            && strtolower($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '') !== ''
            && !isset($parts['user'])
            && !isset($parts['pass']);
    }
}
