<?php

declare(strict_types=1);

namespace Payxy\Checkout;

enum CheckoutDisplay: string
{
    /** Full-page redirect to the hosted checkout. Works everywhere, including without JavaScript. */
    case Redirect = 'redirect';

    /** Hosted checkout in a modal over your page. Falls back to redirect if JavaScript is off. */
    case Popup = 'popup';
}
