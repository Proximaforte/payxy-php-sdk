<?php

declare(strict_types=1);

namespace Payxy\Payments\Response;

use Payxy\Response\PayxyResponse;
use Payxy\Support\Amount;

/**
 * Fields shared by payment responses, read from Payxy Core's `data` object; null means "not present".
 */
abstract class PaymentResponse extends PayxyResponse
{
    public function getTransactionId(): ?string
    {
        return $this->data->string('transactionId');
    }

    public function getStatus(): ?string
    {
        return $this->data->string('status');
    }

    /**
     * The commercial / order amount, before Payxy charges.
     */
    public function getAmount(): ?Amount
    {
        return Amount::tryFromApi($this->data->get('amount'));
    }

    public function getCurrency(): ?string
    {
        return $this->data->string('currency');
    }
}
