<?php

namespace App\Services\Vtu;

use App\Models\VtuOrder;
use App\Models\VtuProduct;

interface VtuProvider
{
    public function assertConfigured(): void;

    public function assertProductConfigured(VtuProduct $product): void;

    /** @return array<string, mixed> */
    public function submit(VtuOrder $order): array;

    /** @return array<string, mixed> */
    public function lookup(VtuOrder $order): array;
}
