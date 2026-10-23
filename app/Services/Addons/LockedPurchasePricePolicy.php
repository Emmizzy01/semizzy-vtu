<?php

namespace App\Services\Addons;

use App\Models\VtuProduct;

/**
 * Optional addon-owned policy evaluated against the locked catalogue row before
 * Core debits a wallet. Implementations must not mutate financial state.
 */
interface LockedPurchasePricePolicy
{
    public function assertLockedPriceUnchanged(
        VtuProduct $product,
        int $requestedAmountMinor,
        int $lockedAmountMinor,
    ): void;
}
