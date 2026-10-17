<?php

namespace App\Services\Vtu;

use App\Models\VtuOrder;
use App\Models\VtuProduct;
use LogicException;

class DatawayProviderClient extends AbstractMappedVtuProvider
{
    protected function providerKey(): string { return 'dataway'; }

    protected function requiredMappingFields(VtuProduct $product): array
    {
        return $product->service_type === 'airtime' ? ['network'] : ['product_id'];
    }

    public function submit(VtuOrder $order): array
    {
        $this->assertConfigured();
        $product = $this->orderProduct($order);
        $mapping = $this->mapping($product);

        if ($product->service_type === 'airtime') {
            $payload = [
                'network' => $mapping['network'],
                'phone' => $order->recipient,
                'amount' => $this->amountNaira($order),
                'reference' => $order->public_id,
            ];
            $path = '/airtime/purchase/';
        } else {
            if (! is_numeric($mapping['product_id'])) {
                throw new LogicException('Dataway data mapping requires a verified numeric product_id.');
            }
            $payload = [
                'product_id' => (int) $mapping['product_id'],
                'phone' => $order->recipient,
                'reference' => $order->public_id,
            ];
            $path = '/data/purchase/';
        }

        $response = $this->http()->post($path, $payload);
        if (! $response->successful() || ! is_array($response->json())) return ['status' => 'unknown'];

        $body = $response->json();
        return $this->normalized($this->status($body['status'] ?? null,
            ['successful', 'success'], ['failed'], ['pending', 'processing']), $body);
    }

    public function lookup(VtuOrder $order): array
    {
        throw new LogicException('Dataway transaction lookup is not enabled until its account-specific requery contract is confirmed.');
    }
}
