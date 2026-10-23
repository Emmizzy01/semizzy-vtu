<?php

namespace App\Services\Addons;

use App\Services\IntegrationSettings;

final class ElectricityPurchaseRuntime implements ServicePurchaseRuntimeContract
{
    /** @param array<string, mixed> $validated
     *  @return array{message: string, status: int}|null
     */
    public function missingAmountError(array $validated): ?array
    {
        return null;
    }

    /** @param array<string, mixed> $validated
     *  @return array{message: string, status: int}|null
     */
    public function serviceInputError(array $validated): ?array
    {
        $contactPhone = preg_replace('/[\\s-]+/', '', trim((string) ($validated['contact_phone'] ?? ''))) ?? '';
        if (! preg_match('/^\\+?[0-9]{10,15}$/', $contactPhone)) {
            return ['message' => 'Enter a valid contact phone number for the provider transaction.', 'status' => 422];
        }

        if (! in_array($validated['meter_type'] ?? null, ['prepaid', 'postpaid'], true)) {
            return ['message' => 'Select prepaid or postpaid for the electricity meter.', 'status' => 422];
        }

        $amountMinor = isset($validated['amount'])
            ? (int) round((float) $validated['amount'] * 100)
            : null;
        if ($amountMinor === null || $amountMinor < 100) {
            return ['message' => 'Enter a valid electricity purchase amount.', 'status' => 422];
        }

        return null;
    }

    /** @return array{message: string, status: int}|null */
    public function unavailableError(IntegrationSettings $settings): ?array
    {
        // Electricity availability is enforced by Core feature and addon admission gates.
        return null;
    }
}
