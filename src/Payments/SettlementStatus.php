<?php

declare(strict_types=1);

namespace Payxy\Payments;

/**
 * Settlement lifecycle, independent of payment status: a successful payment can still be
 * settling. Unknown future values are preserved as raw strings by getSettlementStatus().
 */
enum SettlementStatus: string
{
    case NotCreated = 'NotCreated';
    case Pending = 'Pending';
    case InProgress = 'InProgress';
    case Completed = 'Completed';
    case Failed = 'Failed';
    case PartiallyFailed = 'PartiallyFailed';
}
