<?php

declare(strict_types=1);

namespace Payxy\Checkout\Html;

use Payxy\Checkout\CheckoutDisplay;
use Payxy\Checkout\CheckoutSession;
use Payxy\Exception\InvalidArgumentException;
use Payxy\Support\Amount;
use RuntimeException;

/**
 * Turns a checkout session into HTML. Every dynamic value is escaped; per-instance colors
 * go into a scoped stylesheet rather than style attributes so strict CSPs keep working.
 *
 * @internal
 */
final class CheckoutRenderer
{
    private const ASSET_DIR = __DIR__ . '/../../../resources/';
    private const BROWSER_SDK_VERSION = '1.0.10';
    private const CURRENCY_SYMBOLS = ['NGN' => '₦', 'USD' => '$', 'GBP' => '£', 'EUR' => '€', 'GHS' => 'GH₵', 'KES' => 'KSh'];

    /** @var array<string, string> */
    private static array $assets = [];

    public function button(CheckoutSession $session, CheckoutAppearance $appearance, ?string $cspNonce = null): string
    {
        $id = 'payxy-' . bin2hex(random_bytes(6));
        $nonce = self::nonceAttribute($cspNonce);
        $popup = $appearance->display === CheckoutDisplay::Popup;

        $html = '<div class="payxy-checkout" id="' . $id . '">'
            . '<style' . $nonce . '>' . self::asset('checkout.css') . self::colorRules($id, $appearance) . '</style>'
            . '<a class="payxy-checkout__button" data-payxy-checkout'
            . ' data-payxy-display="' . $appearance->display->value . '"'
            . ' data-payxy-callback="' . self::e($appearance->callbackUrl) . '"'
            . ' data-payxy-complete="' . self::e(self::completeUrl($appearance->callbackUrl, $session->transactionId)) . '"'
            . ' data-payxy-access-code="' . self::e($session->accessCode) . '"'
            . ' data-payxy-environment="' . $session->environment->browserSdkEnvironment() . '"'
            . ' href="' . self::e($session->paymentUrl) . '" rel="noopener">'
            . self::e(str_replace('{amount}', self::formatAmount($session->amount, $session->currency), $appearance->buttonText))
            . '</a>'
            . '<span class="payxy-checkout__secure">Secured by Payxy</span>';

        // Popup = Payxy's official overlay. Pre-rendering its #payxy-style lets it run under a
        // nonce-based CSP, since the SDK skips injecting its own unsigned <style> when present.
        if ($popup) {
            $html .= '<style id="payxy-style"' . $nonce . '>' . self::asset('vendor/payxy-' . self::BROWSER_SDK_VERSION . '.css') . '</style>'
                . '<script' . $nonce . '>' . self::asset('vendor/payxy-' . self::BROWSER_SDK_VERSION . '.umd.js') . '</script>'
                . '<script' . $nonce . '>' . self::asset('checkout.js') . '</script>';
        }

        return $html . '</div>';
    }

    public function page(CheckoutSession $session, CheckoutAppearance $appearance, ?string $cspNonce = null): string
    {
        $nonce = self::nonceAttribute($cspNonce);
        // Higher specificity than the shared stylesheet, which the button emits later in the body.
        $pageStyle = $appearance->bodyColor !== null ? 'body.payxy-page{background:' . $appearance->bodyColor . '}' : '';

        $footer = '';
        if ($appearance->footerLogo !== null) {
            $footer .= '<img src="' . self::e($appearance->footerLogo) . '" alt="">';
        }
        if ($appearance->footerText !== null) {
            $footer .= $appearance->footerLink !== null
                ? '<a href="' . self::e($appearance->footerLink) . '" rel="noopener">' . self::e($appearance->footerText) . '</a>'
                : self::e($appearance->footerText);
        }

        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="referrer" content="strict-origin-when-cross-origin">'
            . '<title>' . self::e($appearance->merchantName) . '</title>'
            . '<style' . $nonce . '>' . $pageStyle . '</style></head>'
            . '<body class="payxy-page"><main class="payxy-card">'
            . '<p class="payxy-card__merchant">' . self::e($appearance->merchantName) . '</p>'
            . '<p class="payxy-card__email">' . self::e($session->email) . '</p>'
            . '<p class="payxy-card__amount">' . self::e(self::formatAmount($session->amount, $session->currency)) . '</p>'
            . $this->button($session, $appearance, $cspNonce)
            . ($footer !== '' ? '<div class="payxy-card__footer">' . $footer . '</div>' : '')
            . '</main></body></html>';
    }

    public static function formatAmount(Amount $amount, string $currency): string
    {
        [$major, $minor] = explode('.', $amount->toDecimalString());
        $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $major) ?? $major;
        $symbol = self::CURRENCY_SYMBOLS[$currency] ?? $currency . ' ';

        return $symbol . $grouped . '.' . $minor;
    }

    private static function colorRules(string $id, CheckoutAppearance $appearance): string
    {
        return $appearance->buttonColor !== null
            ? '#' . $id . ' .payxy-checkout__button{background:' . $appearance->buttonColor . '}'
            : '';
    }

    private static function completeUrl(string $callbackUrl, string $transactionId): string
    {
        return $callbackUrl . (str_contains($callbackUrl, '?') ? '&' : '?') . 'transactionId=' . rawurlencode($transactionId);
    }

    private static function nonceAttribute(?string $nonce): string
    {
        if ($nonce === null) {
            return '';
        }
        if (preg_match('/^[A-Za-z0-9+\/=_-]{8,128}$/', $nonce) !== 1) {
            throw InvalidArgumentException::forField('cspNonce', 'must be a base64 value');
        }

        return ' nonce="' . $nonce . '"';
    }

    private static function asset(string $file): string
    {
        return self::$assets[$file] ??= (static function () use ($file): string {
            $contents = file_get_contents(self::ASSET_DIR . $file);

            return $contents !== false ? $contents : throw new RuntimeException('Missing Payxy asset ' . $file);
        })();
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5, 'UTF-8');
    }
}
