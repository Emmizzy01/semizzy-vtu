<?php

namespace App\Services\Addons;

use App\Models\VtuProduct;
use App\Services\IntegrationSettings;

final class ElectricityPurchaseRuntime implements ServicePurchaseRuntimeContract, PurchaseRequestIdentityPolicy
{
    /** @return array<string, mixed> */
    public function requestIdentity(
        VtuProduct $product,
        int $userId,
        string $recipient,
        array $serviceDetails,
        ?int $amountMinor,
    ): array {
        return [
            'user_id' => $userId,
            'product_code' => $product->code,
            'recipient' => $recipient,
            'service_details' => $serviceDetails,
            'amount_minor' => $amountMinor,
        ];
    }

    /** @param array<string, mixed> $serviceDetails
     *  @return array{service_details: array<string, mixed>, amount_minor: int|null}
     */
    public function prepareOrder(VtuProduct $product, array $serviceDetails, ?int $amountMinor): array
    {
        if (($product->metadata['purchase_supported'] ?? null) !== true) {
            throw new \InvalidArgumentException('This service is not yet enabled for customer purchases.');
        }

        $contactPhone = preg_replace('/[\\s-]+/', '', (string) ($serviceDetails['contact_phone'] ?? '')) ?? '';
        if (! preg_match('/^\\+?[0-9]{10,15}$/', $contactPhone)) {
            throw new \InvalidArgumentException('Enter a valid contact phone number for the provider transaction.');
        }
        $serviceDetails['contact_phone'] = $contactPhone;

        if (! in_array($serviceDetails['meter_type'] ?? null, ['prepaid', 'postpaid'], true)) {
            throw new \InvalidArgumentException('Select prepaid or postpaid for the electricity meter.');
        }
        if ($amountMinor === null || $amountMinor < 100) {
            throw new \InvalidArgumentException('Enter a valid electricity purchase amount.');
        }
        $minimum = (int) round((float) ($product->metadata['provider_minimum_amount'] ?? 1) * 100);
        $maximum = (int) round((float) ($product->metadata['provider_maximum_amount'] ?? 1000000) * 100);
        if ($amountMinor < $minimum || $amountMinor > $maximum) {
            throw new \InvalidArgumentException('The electricity amount is outside the provider limits.');
        }

        return ['service_details' => $serviceDetails, 'amount_minor' => $amountMinor];
    }


    public function verifyBeforePurchase(VtuProduct $product, string $recipient, array $serviceDetails, \App\Services\Vtu\VtuProvider $provider): void
    {
        if ($provider instanceof \App\Services\Vtu\MultiProviderVtuRouter) {
            $result = $provider->verifyElectricityMeter($product, $recipient, (string) ($serviceDetails['meter_type'] ?? ''));
        } elseif ($provider instanceof \App\Services\Vtu\BillerVerificationProvider) {
            $result = $provider->verifyElectricityMeter($product, $recipient, (string) ($serviceDetails['meter_type'] ?? ''));
        } else {
            throw new \LogicException('This service requires a provider with the reviewed biller-verification contract.');
        }

        if (($result['status'] ?? null) !== 'verified') {
            throw new \InvalidArgumentException('The provider could not confirm this meter. No purchase was created.');
        }
    }

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
