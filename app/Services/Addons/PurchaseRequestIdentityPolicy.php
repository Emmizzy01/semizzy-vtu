<?php

namespace App\Services\Addons;

use App\Models\VtuProduct;

/**
 * Optional addon-owned definition of the stable request identity for a service.
 * Return the same payload shape for the same logical request; Core hashes it,
 * applies storefront scoping, and enforces idempotency against persisted orders.
 */
interface PurchaseRequestIdentityPolicy
{
    /** @param array<string, mixed> $serviceDetails
     *  @return array<string, mixed>
     */
    public function requestIdentity(
        VtuProduct $product,
        int $userId,
        string $recipient,
        array $serviceDetails,
        ?int $amountMinor,
    ): array;
}
