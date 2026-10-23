<?php

namespace App\Services\Addons;

use App\Services\IntegrationSettings;

interface ServicePurchaseRuntimeContract
{
    /** @param array<string, mixed> $validated
     *  @return array{message: string, status: int}|null
     */
    public function missingAmountError(array $validated): ?array;

    /** @param array<string, mixed> $validated
     *  @return array{message: string, status: int}|null
     */
    public function serviceInputError(array $validated): ?array;

    /** @return array{message: string, status: int}|null */
    public function unavailableError(IntegrationSettings $settings): ?array;
}
