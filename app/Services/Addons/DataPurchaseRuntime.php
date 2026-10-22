<?php

namespace App\Services\Addons;

use App\Services\IntegrationSettings;

final class DataPurchaseRuntime
{
    /** @return array{message: string, status: int}|null */
    public function unavailableError(IntegrationSettings $settings): ?array
    {
        $independent = $settings->get('service.data.enabled', null);
        $legacy = $settings->get('service.airtime_data.enabled', false);

        return filter_var($independent ?? $legacy, FILTER_VALIDATE_BOOLEAN)
            ? null
            : ['message' => 'Airtime and data purchases are temporarily unavailable.', 'status' => 503];
    }
}
