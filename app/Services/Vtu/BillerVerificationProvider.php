<?php

namespace App\Services\Vtu;

use App\Models\VtuProduct;

interface BillerVerificationProvider
{
    public function assertConfigured(): void;

    /** @return array{status:string, message_code:?string, customer_name:?string, customer_address:?string} */
    public function verifyElectricityMeter(VtuProduct $product, string $meterNumber, string $meterType): array;

    /** @return array{status:string, message_code:?string, customer_name:?string, customer_address:?string} */
    public function verifyTvSmartcard(VtuProduct $product, string $smartcardNumber): array;
}
