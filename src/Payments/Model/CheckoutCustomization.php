<?php

declare(strict_types=1);

namespace Payxy\Payments\Model;

use Payxy\Support\Assert;

/**
 * Branding applied to the hosted checkout page.
 */
final readonly class CheckoutCustomization
{
    public function __construct(
        public ?string $bodyColor = null,
        public ?string $buttonColor = null,
        public ?string $footerText = null,
        public ?string $footerLink = null,
        public ?string $footerLogo = null,
    ) {
        Assert::text($bodyColor, 'bodyColor', 32);
        Assert::text($buttonColor, 'buttonColor', 32);
        Assert::text($footerText, 'footerText', 255);
        Assert::url($footerLink, 'footerLink');
        Assert::url($footerLogo, 'footerLogo');
    }

    /**
     * @return array<string, string|null>
     */
    public function toPayload(): array
    {
        return [
            'bodyColor' => $this->bodyColor,
            'buttonColor' => $this->buttonColor,
            'footerText' => $this->footerText,
            'footerLink' => $this->footerLink,
            'footerLogo' => $this->footerLogo,
        ];
    }
}
