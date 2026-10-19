<?php

declare(strict_types=1);

namespace Payxy;

/**
 * Payxy Core responseCode values. Meanings are endpoint-specific: Initialize succeeds on 00,
 * VerifyPayment is final-success only on 00 with data.isSuccessful = true. Unknown future codes
 * stay available as raw strings via getResponseCode().
 */
enum ResponseCode: string
{
    case Successful = '00';
    case Unauthorized = '01';
    case Failed = '02';
    case NotFound = '03';
    case BadRequest = '04';
    case Processing = '05';
    case AwaitingOtp = '06';
    case PendingValidation = '07';
    case InsufficientFunds = '08';
    case VirtualAccountCreated = '09';
    case Declined = '10';
    case Pending3ds = '12';
    case PasswordChangeRequired = '13';
    case Chargeback = '14';
    case TimedOut = '15';
    case AccountLocked = '16';

    /**
     * The payment is still moving (processing, awaiting OTP/3DS, transfer pending): not final.
     */
    public function isInProgress(): bool
    {
        return \in_array($this, [self::Processing, self::AwaitingOtp, self::PendingValidation, self::VirtualAccountCreated, self::Pending3ds], true);
    }
}
