<?php

namespace App\Services\Addons;

use App\Models\PlatformFeatureControl;
use App\Models\VtuProduct;
use App\Services\IntegrationSettings;

final class AirtimePurchaseRuntime implements ServicePurchaseRuntimeContract, PurchaseRequestIdentityPolicy, ServiceFeatureAdmissionPolicy
{

    public function assertFeatureEnabled(VtuProduct $product): void
    {
        // This service's feature control is authoritative. The legacy aggregate
        // setting may narrow access further, but cannot override this service's toggle.
        $feature = PlatformFeatureControl::query()->where('feature_key', 'service.airtime')->first();
        if ($feature === null || ! $feature->is_enabled || ! $feature->is_implemented) {
            throw new \InvalidArgumentException('This service is not yet enabled for customer purchases.');
        }

        $settings = app(IntegrationSettings::class);
        $independent = $settings->get('service.airtime.enabled', null);
        $enabled = $independent ?? $settings->get('service.airtime_data.enabled', false);

        if (! filter_var($enabled, FILTER_VALIDATE_BOOLEAN)) {
            throw new \InvalidArgumentException('This service is not yet enabled for customer purchases.');
        }
    }

    /** @return array<string, mixed> */
    public function requestIdentity(
        VtuProduct $product,
        int $userId,
        string $recipient,
        array $serviceDetails,
        ?int $amountMinor,
    ): array {
        // Keep the legacy airtime idempotency payload stable during conversion.
        return [
            'user_id' => $userId,
            'product_code' => $product->code,
            'recipient' => $recipient,
            'amount_minor' => $amountMinor,
        ];
    }

    /** @param array<string, mixed> $serviceDetails
     *  @return array{service_details: array<string, mixed>, amount_minor: int|null}
     */
    public function prepareOrder(VtuProduct $product, array $serviceDetails, ?int $amountMinor): array
    {
        $amountMinor ??= (int) $product->price_minor;
        if ($amountMinor < 100 || $amountMinor > 100000000 || $amountMinor % 100 !== 0) {
            throw new \InvalidArgumentException('Airtime amount must be a whole-naira amount between ₦1 and ₦1,000,000.');
        }

        return ['service_details' => $serviceDetails, 'amount_minor' => $amountMinor];
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
        $feature = PlatformFeatureControl::query()->where('feature_key', 'service.airtime')->first();
        $featureEnabled = $feature !== null && $feature->is_enabled && $feature->is_implemented;
        $independent = $settings->get('service.airtime.enabled', null);
        $enabled = $independent ?? $settings->get('service.airtime_data.enabled', false);

        return $featureEnabled && filter_var($enabled, FILTER_VALIDATE_BOOLEAN)
            ? null
            : ['message' => 'Airtime and data purchases are temporarily unavailable.', 'status' => 503];
    }
}
