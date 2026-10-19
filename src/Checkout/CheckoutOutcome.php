<?php

declare(strict_types=1);

namespace Payxy\Checkout;

enum CheckoutOutcome: string
{
    /** Verified successful, for the amount and currency you expected. Safe to fulfil. */
    case Successful = 'successful';

    /** Not final yet (e.g. bank transfer in flight). Verify again later. */
    case Pending = 'pending';

    /** Declined, abandoned or cancelled. */
    case Failed = 'failed';

    /** The gateway reports success, but not for the amount or currency you expected. Do not fulfil. */
    case AmountMismatch = 'amount_mismatch';
}
