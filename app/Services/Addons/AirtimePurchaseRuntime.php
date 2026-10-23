<?php

namespace App\Services\Addons;

use App\Services\IntegrationSettings;

final class AirtimePurchaseRuntime implements ServicePurchaseRuntimeContract
{
    /** @param array<string, mixed> $validated
     *  @return array{message: string, status: int}|null
     */
    public function missingAmountError(array $validated): ?array
    {
        return isset($validated['amount'])
            ? null
            : ['message' => 'Enter the airtime amount you want to purchase.', 'status' => 422];
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
        $independent = $settings->get('service.airtime.enabled', null);
        $enabled = $independent ?? $settings->get('service.airtime_data.enabled', false);

        return filter_var($enabled, FILTER_VALIDATE_BOOLEAN)
            ? null
            : ['message' => 'Airtime and data purchases are temporarily unavailable.', 'status' => 503];
    }
}
