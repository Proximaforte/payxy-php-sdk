<?php

declare(strict_types=1);

namespace Payxy\Merchant\Response;

use Payxy\Response\PayxyResponse;

final class ProvidersResponse extends PayxyResponse
{
    /**
     * The providers and account types currently available, as returned.
     *
     * @return list<array<array-key, mixed>>
     */
    public function getProviders(): array
    {
        return ListOf::records($this->data, 'providers', 'items');
    }
}
