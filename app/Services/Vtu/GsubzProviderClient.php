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
            'api' => $this->apiKey(),
            'serviceID' => $mapping['network'],
            'phone' => $order->recipient,
            'requestID' => $order->public_id,
            'amount' => $product->service_type === 'airtime' ? $this->amountNaira($order) : '',
        ];
        if ($product->service_type === 'data') $payload['plan'] = $mapping['plan_code'];

        $response = $this->http()->asForm()->post('/api/pay/', $payload);
        // Transport/HTTP errors are ambiguous; verify the original requestID first.
        if (! $response->successful() || ! is_array($response->json())) return ['status' => 'unknown'];

        $body = $response->json();
        $status = $this->status($body['status'] ?? null,
            ['success', 'successful'], ['failed', 'fail'], ['pending', 'processing']);
        $result = $this->normalized($status, $body);

        // GSUBZ also uses status=failed for rate limits and upstream errors.
        // Only documented codes that establish rejection/non-delivery qualify;
        // 429/500/502 and unknown codes remain ambiguous and require lookup.
        $result['definitive_failure'] = $this->isDefinitiveFailure($status, $body);

        return $result;
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
        $status = $this->status($body['status'] ?? null,
            ['success', 'successful'], ['failed', 'fail'], ['pending', 'processing']);
        $result = $this->normalized($status, $body);
        // A verify response must meet the same strict failure-code boundary.
        $result['definitive_failure'] = $this->isDefinitiveFailure($status, $body);

        return $result;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function isDefinitiveFailure(string $status, array $body): bool
    {
        if ($status !== 'failed') {
            return false;
        }

        $code = filter_var($body['code'] ?? null, FILTER_VALIDATE_INT);

        // 200 is a processed business response; 204/206/400/402 are documented
        // request/validation/funds failures. Unknown codes fail closed.
        return in_array($code, [200, 204, 206, 400, 402], true);
    }
}
