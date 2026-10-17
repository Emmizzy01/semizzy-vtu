<?php

namespace App\Services\Vtu;

use App\Models\VtuOrder;
use App\Models\VtuProduct;

class SogoProviderClient extends AbstractMappedVtuProvider
{
    protected function providerKey(): string { return 'sogo'; }

    protected function requiredMappingFields(VtuProduct $product): array
    {
        return $product->service_type === 'airtime' ? ['network'] : ['network', 'variation_code'];
    }

    public function submit(VtuOrder $order): array
    {
        $this->assertConfigured();
        $product = $this->orderProduct($order);
        $mapping = $this->mapping($product);
        $endpoint = $product->service_type === 'airtime' ? '/bills/airtime' : '/bills/data';
        $payload = ['network' => $mapping['network'], 'phone' => $order->recipient];

        if ($product->service_type === 'airtime') {
            $payload['amount'] = (int) ($order->amount_minor / 100);
        } else {
            $payload['variation_code'] = $mapping['variation_code'];
        }

        $response = $this->http()->withHeaders(['Idempotency-Key' => $order->public_id])->post($endpoint, $payload);
        if (! $response->successful() || ! is_array($response->json())) return ['status' => 'unknown'];

        $body = $response->json();
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        return $this->normalized($this->status($data['status'] ?? $body['status'] ?? null,
            ['completed'], ['failed', 'refunded', 'cancelled'], ['processing', 'pending']), $data + $body);
    }

    public function lookup(VtuOrder $order): array
    {
        $this->assertConfigured();
        $response = $this->http()->get('/transactions/'.rawurlencode($order->public_id), ['type' => 'bill_payment']);
        if ($response->status() === 404 || ! $response->successful() || ! is_array($response->json())) {
            return ['status' => 'unknown'];
        }

        $body = $response->json();
        $data = is_array($body['data'] ?? null) ? $body['data'] : $body;
        return $this->normalized($this->status($data['status'] ?? null,
            ['completed'], ['failed', 'refunded', 'cancelled'], ['processing', 'pending']), $data);
    }
}
