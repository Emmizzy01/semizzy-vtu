<?php

namespace App\Services\Addons;

use App\Services\IntegrationSettings;

final class DataPurchaseRuntime
{
    /** @param array<string, mixed> $validated
     *  @return array{message: string, status: int}|null
     */
    public function missingAmountError(array $validated): ?array
    {
        // Data plans are priced by catalogue option; only airtime uses a free-form amount.
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
