<?php

namespace App\Services\Vtu;

use App\Models\VtuOrder;
use App\Models\VtuProduct;

class GsubzProviderClient extends AbstractMappedVtuProvider
{
    protected function providerKey(): string { return 'gsubz'; }

    protected function requiredMappingFields(VtuProduct $product): array
    {
        return $product->service_type === 'airtime' ? ['network'] : ['network', 'plan_code'];
    }

    public function submit(VtuOrder $order): array
    {
        $this->assertConfigured();
        $product = $this->orderProduct($order);
        $mapping = $this->mapping($product);
        $payload = [
            'api' => (string) config('services.vtu.gsubz.api_key'),
            'serviceID' => $mapping['network'],
            'phone' => $order->recipient,
            'requestID' => $order->public_id,
            'amount' => $product->service_type === 'airtime' ? $this->amountNaira($order) : '',
        ];
        if ($product->service_type === 'data') $payload['plan'] = $mapping['plan_code'];

        $response = $this->http()->asForm()->post('/api/pay/', $payload);
        if (! $response->successful() || ! is_array($response->json())) return ['status' => 'unknown'];

        $body = $response->json();
        return $this->normalized($this->status($body['status'] ?? null,
            ['success', 'successful'], ['failed', 'fail'], ['pending', 'processing']), $body);
    }

    public function lookup(VtuOrder $order): array
    {
        $this->assertConfigured();
        $response = $this->http()->asForm()->post('/api/verify/', [
            'api' => (string) config('services.vtu.gsubz.api_key'),
            'requestID' => $order->public_id,
        ]);
        if (! $response->successful() || ! is_array($response->json())) return ['status' => 'unknown'];

        $body = $response->json();
        return $this->normalized($this->status($body['status'] ?? null,
            ['success', 'successful'], ['failed', 'fail'], ['pending', 'processing']), $body);
    }
}
