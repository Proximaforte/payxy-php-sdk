<?php

declare(strict_types=1);

namespace Payxy\Payments\Response;

final class InitializePaymentResponse extends PaymentResponse
{
    private ?string $canonicalPaymentUrl = null;

    /**
     * Pass to the official browser / mobile SDKs, or store to look the session up later.
     */
    public function getAccessCode(): ?string
    {
        return $this->data->string('accessCode');
    }

    /**
     * Payxy's hosted checkout page as an absolute https:// URL. When the response carries an
     * access code, PaymentsService derives this URL from the configured public key instead of
     * trusting the gateway value: pk_test_ routes to .ng and pk_live_ routes to .africa.
     */
    public function getPaymentUrl(): ?string
    {
        $raw = $this->getRawPaymentUrl();

        return $this->canonicalPaymentUrl
            ?? ($raw !== null && self::isAbsoluteHttpsUrl($raw) ? $raw : null);
    }

    /**
     * The paymentUrl exactly as Payxy returned it, for logging and diagnostics.
     */
    public function getRawPaymentUrl(): ?string
    {
        return $this->data->string('paymentUrl', 'checkoutUrl', 'authorizationUrl');
    }

    /**
     * @internal set by PaymentsService from the public key's environment
     */
    public function withCanonicalPaymentUrl(string $url): self
    {
        $clone = clone $this;
        $clone->canonicalPaymentUrl = $url;

        return $clone;
    }

    /**
     * @internal backward-compatible alias; use withCanonicalPaymentUrl().
     */
    public function withFallbackPaymentUrl(string $url): self
    {
        return $this->withCanonicalPaymentUrl($url);
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
