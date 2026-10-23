<?php

namespace App\Services\Addons;

use App\Models\VtuProduct;
use App\Services\IntegrationSettings;

final class DataPurchaseRuntime implements ServicePurchaseRuntimeContract, PurchaseRequestIdentityPolicy
{

    /** @return array<string, mixed> */
    public function requestIdentity(
        VtuProduct $product,
        int $userId,
        string $recipient,
        array $serviceDetails,
        ?int $amountMinor,
    ): array {
        // Data plans are identified by the selected catalogue product and recipient.
        // Keep the existing payload shape so accepted retries remain idempotent.
        return [
            'user_id' => $userId,
            'product_code' => $product->code,
            'recipient' => $recipient,
        ];
    }

    /** @param array<string, mixed> $serviceDetails
     *  @return array{service_details: array<string, mixed>, amount_minor: int|null}
     */
    public function prepareOrder(VtuProduct $product, array $serviceDetails, ?int $amountMinor): array
    {
        return ['service_details' => $serviceDetails, 'amount_minor' => (int) $product->price_minor];
    }


    public function verifyBeforePurchase(\App\Models\VtuProduct $product, string $recipient, array $serviceDetails, \App\Services\Vtu\VtuProvider $provider): void
    {
        // Airtime/data do not require a biller identity lookup.
    }

    /** @param array<string, mixed> $validated
     *  @return array{message: string, status: int}|null
     */
    public function missingAmountError(array $validated): ?array
    {
        // Data plans are priced by catalogue option; only airtime uses a free-form amount.
        return null;
    }

    /** @param array<string, mixed> $validated
     *  @return array{message: string, status: int}|null
     */
    public function serviceInputError(array $validated): ?array
    {
        return null;
    }

    /** @return array{message: string, status: int}|null */
    public function unavailableError(IntegrationSettings $settings): ?array
    {
        $independent = $settings->get('service.data.enabled', null);
        $enabled = $independent ?? $settings->get('service.airtime_data.enabled', false);

        return filter_var($enabled, FILTER_VALIDATE_BOOLEAN)
            ? null
            : ['message' => 'Airtime and data purchases are temporarily unavailable.', 'status' => 503];
    }
}
