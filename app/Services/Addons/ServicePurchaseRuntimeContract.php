<?php

namespace App\Services\Addons;

use App\Models\VtuProduct;
use App\Services\IntegrationSettings;

interface ServicePurchaseRuntimeContract
{
    /** @param array<string, mixed> $serviceDetails
     *  @return array{service_details: array<string, mixed>, amount_minor: int|null}
     */
    public function prepareOrder(VtuProduct $product, array $serviceDetails, ?int $amountMinor): array;


    /**
     * Run service-owned identity/biller verification before Core creates or debits an order.
     * Implementations must not create orders or mutate wallets.
     * @param array<string, mixed> $serviceDetails
     */
    public function verifyBeforePurchase(VtuProduct $product, string $recipient, array $serviceDetails, \\App\\Services\\Vtu\\VtuProvider $provider): void;

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
