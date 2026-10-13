<?php

namespace App\\Services\\Vtu;

use App\\Models\\VtuOrder;

interface VtuProvider
{
    public function assertConfigured(): void;

    /** @return array<string, mixed> */
    public function submit(VtuOrder $order): array;

    /** @return array<string, mixed> */
    public function lookup(VtuOrder $order): array;
}
