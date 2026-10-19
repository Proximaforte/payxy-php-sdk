<?php

declare(strict_types=1);

namespace Payxy\Checkout\Html;

use Payxy\Checkout\CheckoutDisplay;
use Payxy\Exception\ConfigurationException;
use Payxy\Support\Assert;

/**
 * Validated presentation settings. Colors are restricted to hex literals because they are
 * written into a stylesheet; anything looser would allow CSS injection.
 *
 * @internal
 */
final readonly class CheckoutAppearance
{
    private const HEX_COLOR = '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/';

    public function __construct(
        public CheckoutDisplay $display,
        public string $callbackUrl,
        public string $buttonText,
        public ?string $buttonColor,
        public ?string $bodyColor,
        public string $merchantName,
        public ?string $footerText,
        public ?string $footerLink,
        public ?string $footerLogo,
    ) {
        Assert::url($callbackUrl, 'callbackUrl');
        Assert::notBlank($buttonText, 'buttonText', 100);
        Assert::notBlank($merchantName, 'merchantName', 100);
        Assert::text($footerText, 'footerText', 255);
        Assert::url($footerLink, 'footerLink');
        Assert::url($footerLogo, 'footerLogo');
        foreach (['buttonColor' => $buttonColor, 'bodyColor' => $bodyColor] as $name => $color) {
            if ($color !== null && preg_match(self::HEX_COLOR, $color) !== 1) {
                throw new ConfigurationException(\sprintf('%s must be a hex color such as "#0A84FF".', $name));
            }
        }
    }
}
