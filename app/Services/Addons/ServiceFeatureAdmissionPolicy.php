<?php

namespace App\Services\Addons;

use App\Models\VtuProduct;

/**
 * Keeps new-purchase feature admission with the service addon that owns it.
 * Core invokes this before contacting providers and again under the purchase lock.
 */
interface ServiceFeatureAdmissionPolicy
{
    public function assertFeatureEnabled(VtuProduct $product): void;
}
