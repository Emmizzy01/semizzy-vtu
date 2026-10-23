<?php

namespace App\Services\Addons;

use App\Models\VtuProduct;
use App\Services\IntegrationSettings;

final class TvPurchaseRuntime implements ServicePurchaseRuntimeContract
{
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
        if (($serviceDetails['subscription_type'] ?? 'change') !== 'change') {
            throw new \InvalidArgumentException('Only verified TV bouquet changes are available right now. Renewal is not enabled.');
        }
        $serviceDetails['subscription_type'] = 'change';
        $amountMinor = (int) $product->price_minor;
        if ($amountMinor < 1) {
            throw new \InvalidArgumentException('This TV subscription has no valid provider price.');
        }

        return ['service_details' => $serviceDetails, 'amount_minor' => $amountMinor];
    }


    public function verifyBeforePurchase(VtuProduct $product, string $recipient, array $serviceDetails, \\App\\Services\\Vtu\\VtuProvider $provider): void
    {
        if ($provider instanceof \\App\\Services\\Vtu\\MultiProviderVtuRouter) {
            $result = $provider->verifyTvSmartcard($product, $recipient);
        } elseif ($provider instanceof \\App\\Services\\Vtu\\BillerVerificationProvider) {
            $result = $provider->verifyTvSmartcard($product, $recipient);
        } else {
            throw new \\LogicException('This service requires a provider with the reviewed biller-verification contract.');
        }

        if (($result['status'] ?? null) !== 'verified') {
            throw new \\InvalidArgumentException('The provider could not confirm this smartcard. No purchase was created.');
        }
    }

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
