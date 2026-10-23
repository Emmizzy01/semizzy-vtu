<?php

namespace App\Services\Addons;

use App\Services\IntegrationSettings;

final class TvPurchaseRuntime implements ServicePurchaseRuntimeContract
{
    /** @param array<string, mixed> $validated
     *  @return array{message: string, status: int}|null
     */
    public function missingAmountError(array $validated): ?array
    {
        // TV bouquet-change price is server-calculated from the selected catalogue product.
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

        if (($validated['subscription_type'] ?? 'change') !== 'change') {
            return ['message' => 'Only verified TV bouquet changes are available right now. Renewal is not enabled.', 'status' => 422];
        }

        return null;
    }

    /** @return array{message: string, status: int}|null */
    public function unavailableError(IntegrationSettings $settings): ?array
    {
        // TV availability is enforced by Core feature and addon admission gates.
        return null;
    }
}
