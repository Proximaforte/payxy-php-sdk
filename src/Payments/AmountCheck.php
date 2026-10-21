<?php

declare(strict_types=1);

namespace Payxy\Payments;

/**
 * Result of comparing a verified payment with the order amount you expect.
 * Only Exact and WithinFeeAllowance are acceptable for fulfilment.
 */
enum AmountCheck: string
{
    /** data.amount equals the order amount (and the currency matches). */
    case Exact = 'exact';

    /**
     * Payxy returned no fee breakdown, and data.amount exceeds the order by no more than the fee
     * allowance you supplied. Accepting this is your business decision: the surplus may be fees
     * the customer paid, or an overpayment.
     */
    case WithinFeeAllowance = 'within_fee_allowance';

    /** data.amount is below the order amount. Never acceptable. */
    case Underpaid = 'underpaid';

    /** The response carries a fee breakdown, so data.amount is the commercial amount, and it exceeds the order. */
    case Overpaid = 'overpaid';

    /**
     * Payxy returned no fee breakdown and data.amount exceeds the order beyond any fee allowance.
     * It may include fees (seen on successful Transfers) or be an overpayment; review it.
     */
    case UnexplainedSurplus = 'unexplained_surplus';

    /** The response reports a different currency. */
    case CurrencyMismatch = 'currency_mismatch';

    /** Nothing to compare: the response has no usable amount, or the order isn't recognised. */
    case Missing = 'missing';

    public function isAcceptable(): bool
    {
        return $this === self::Exact || $this === self::WithinFeeAllowance;
    }
}
