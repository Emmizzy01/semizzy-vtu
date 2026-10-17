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
        $rawStatus = strtolower(trim((string) ($data['status'] ?? $body['status'] ?? '')));
        $result = $this->normalized($this->status($rawStatus,
            ['completed'], ['failed', 'refunded', 'cancelled'], ['processing', 'pending']), $data + $body);

        // Sogo documents failed as a final non-charge/refunded outcome and
        // refunded as reversed. Do not treat cancelled, processing, transport
        // errors, or lookup failures as proof of non-delivery.
        $result['definitive_failure'] = in_array($rawStatus, ['failed', 'refunded'], true);

        return $result;
    }

    public function lookup(VtuOrder $order): array
    {
        $this->assertConfigured();
        $response = $this->http()->get('/transactions/'.rawurlencode($order->public_id), ['type' => 'bill_payment']);
        // Sogo documents a 404 for this idempotency-key lookup as proof
        // that the transaction was never created. Preserve that explicit signal
        // for recovery workers; ordinary reconciliation still treats it as
        // ambiguous and never resubmits by itself.
        if ($response->status() === 404) {
            return ['status' => 'unknown', 'lookup_state' => 'not_found', 'safe_to_submit_same_key' => true];
        }

        if (! $response->successful() || ! is_array($response->json())) {
            return ['status' => 'unknown'];
        }

        $body = $response->json();
        $data = is_array($body['data'] ?? null) ? $body['data'] : $body;
        $rawStatus = strtolower(trim((string) ($data['status'] ?? '')));
        $result = $this->normalized($this->status($rawStatus,
            ['completed'], ['failed', 'refunded', 'cancelled'], ['processing', 'pending']), $data);
        $result['definitive_failure'] = in_array($rawStatus, ['failed', 'refunded'], true);

        return $result;
    }
}
