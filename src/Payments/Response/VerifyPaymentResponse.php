<?php

declare(strict_types=1);

namespace Payxy\Payments\Response;

use Payxy\Payments\SettlementStatus;
use Payxy\ResponseCode;
use Payxy\Support\Amount;

/**
 * Canonical state and financial breakdown of a transaction (Core: 00 successful, 05 pending /
 * unknown, 02 failed). Fulfil only when isPaid() is true and amountMatches() your order.
 */
final class VerifyPaymentResponse extends PaymentResponse
{
    /**
     * The fulfilment rule: responseCode "00", a data record, and data.isSuccessful === true.
     * Settlement may still be pending; that alone should not delay fulfilment.
     */
    public function isPaid(): bool
    {
        return $this->responseCode() === ResponseCode::Successful
            && \is_array($this->get('data'))
            && $this->isSuccessful() === true;
    }

    /**
     * Not final yet (05 processing / unknown outcome, or another in-progress code): verify again later.
     */
    public function isPending(): bool
    {
        return $this->responseCode()?->isInProgress() === true || strcasecmp((string) $this->getStatus(), 'Pending') === 0;
    }

    /**
     * Raw data.isSuccessful; null if absent. Prefer isPaid(), which also checks responseCode.
     */
    public function isSuccessful(): ?bool
    {
        return $this->data->bool('isSuccessful');
    }

    /**
     * Total collected from the customer; can exceed getAmount() when the customer bears fees.
     */
    public function getCustomerPaid(): ?Amount
    {
        return Amount::tryFromApi($this->data->get('customerPaid'));
    }

    /**
     * Amount credited or due to the merchant.
     */
    public function getAmountPaidToMerchant(): ?Amount
    {
        return Amount::tryFromApi($this->data->get('amountPaidToMerchant'));
    }

    /**
     * Processing charge, excluding platform VAT.
     */
    public function getProcessingFee(): ?Amount
    {
        return Amount::tryFromApi($this->data->get('processingFee'));
    }

    /**
     * VAT on the platform processing fee.
     */
    public function getPlatformVat(): ?Amount
    {
        return Amount::tryFromApi($this->data->get('platformVat'));
    }

    /**
     * VAT arising from the merchant's vatable products.
     */
    public function getMerchantVat(): ?Amount
    {
        return Amount::tryFromApi($this->data->get('merchantVat'));
    }

    /**
     * Total Payxy/platform processing deductions or additions.
     */
    public function getTotalCharges(): ?Amount
    {
        return Amount::tryFromApi($this->data->get('totalCharges'));
    }

    /**
     * Usually "Customer" or "Merchant".
     */
    public function getFeeBearer(): ?string
    {
        return $this->data->string('feeBearer');
    }

    /**
     * Raw settlement status, including values newer than this SDK.
     */
    public function getSettlementStatus(): ?string
    {
        return $this->data->string('settlementStatus');
    }

    /**
     * Typed settlement status; null when absent or not yet known to this SDK.
     */
    public function settlementStatus(): ?SettlementStatus
    {
        $raw = $this->getSettlementStatus();

        return $raw === null ? null : SettlementStatus::tryFrom($raw);
    }

    /**
     * Card, Transfer, USSD, ... as reported by Payxy.
     */
    public function getPaymentType(): ?string
    {
        return $this->data->string('paymentType');
    }

    public function getGatewayResponse(): ?string
    {
        return $this->data->string('gatewayResponse');
    }

    /**
     * Payxy payment reference.
     */
    public function getPaymentReference(): ?string
    {
        return $this->data->string('paymentReference');
    }

    /**
     * Processor reference.
     */
    public function getProviderReference(): ?string
    {
        return $this->data->string('providerReference');
    }

    public function getMerchantId(): ?int
    {
        return $this->data->int('merchantId');
    }

    /**
     * Already masked by Payxy, e.g. 506099********7499.
     */
    public function getMaskedCardPan(): ?string
    {
        return $this->nonEmpty('cardPan');
    }

    public function getCardType(): ?string
    {
        return $this->nonEmpty('cardType');
    }

    public function getSessionId(): ?string
    {
        return $this->nonEmpty('sessionId');
    }

    /**
     * Compares the commercial amount (data.amount), never customerPaid, which includes fees.
     * Currency is compared only when given and reported.
     */
    public function amountMatches(Amount $expected, ?string $expectedCurrency = null): bool
    {
        $amount = $this->getAmount();
        if ($amount === null || !$amount->equals($expected)) {
            return false;
        }
        $currency = $this->getCurrency();

        return $expectedCurrency === null || $currency === null || strtoupper($currency) === strtoupper($expectedCurrency);
    }

    private function nonEmpty(string $key): ?string
    {
        $value = $this->data->string($key);

        return $value === '' ? null : $value;
    }
}
