<?php

namespace App\Services\Vtu;

use App\Models\VtuOrder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use LogicException;

class VtuProviderClient implements VtuProvider
{
    public function assertConfigured(): void
    {
        $endpoint = (string) config('services.vtu.endpoint', '');
        $token = (string) config('services.vtu.token', '');
        $scheme = parse_url($endpoint, PHP_URL_SCHEME);

        if ($endpoint === '' || $token === '' || $scheme !== 'https') {
            throw new LogicException('A secure VTU provider endpoint and token must be configured before purchases are enabled.');
        }
    }

    public function assertProductConfigured(VtuProduct $product): void
    {
        if ($product->code === '') {
            throw new LogicException('VTU product code is required.');
        }
    }

    /**
     * Provider contract: POST {endpoint}/orders accepts client_reference,
     * product_code, recipient, amount_minor and currency. It returns a JSON
     * status of success, failed or pending and may return provider_reference.
     *
     * @return array<string, mixed>
     */
    public function submit(VtuOrder $order): array
    {
        $this->assertConfigured();
        $endpoint = rtrim((string) config('services.vtu.endpoint'), '/');

        $response = Http::withToken((string) config('services.vtu.token'))
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(15)
            ->post($endpoint.'/orders', [
                'client_reference' => $order->public_id,
                'product_code' => $order->product_code,
                'service_type' => $order->service_type,
                'network' => $order->network,
                'recipient' => $order->recipient,
                'amount_minor' => $order->amount_minor,
                'currency' => $order->currency,
            ]);

        if (! $response->successful() || ! is_array($response->json())) {
            // A non-2xx response is not automatically a definitive failure:
            // the provider may have accepted the order before an intermediary failed.
            return ['status' => 'unknown'];
        }

        $body = $response->json();
        $status = $body['status'] ?? null;

        if (! in_array($status, ['success', 'failed', 'pending'], true)) {
            return ['status' => 'unknown'];
        }

        $reference = $body['provider_reference'] ?? null;

        return [
            'status' => $status,
            'provider_reference' => is_string($reference) ? substr($reference, 0, 150) : null,
            'message_code' => is_string($body['message_code'] ?? null)
                ? substr($body['message_code'], 0, 80)
                : null,
        ];
    }

    /**
     * Query provider state using the stable Semizzy client reference.
     * The configured provider must implement GET {endpoint}/orders/{client_reference}.
     *
     * @return array<string, mixed>
     */
    public function lookup(VtuOrder $order): array
    {
        $this->assertConfigured();
        $endpoint = rtrim((string) config('services.vtu.endpoint'), '/');

        try {
            $response = Http::withToken((string) config('services.vtu.token'))
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(10)
                ->get($endpoint.'/orders/'.rawurlencode($order->public_id));
        } catch (ConnectionException $exception) {
            throw new LogicException('VTU provider state is still unavailable; leave the order awaiting reconciliation.', previous: $exception);
        }

        if (! $response->successful() || ! is_array($response->json())) {
            throw new LogicException('VTU provider did not return a definitive order state.');
        }

        $body = $response->json();
        $status = $body['status'] ?? null;
        if (! in_array($status, ['success', 'failed', 'pending'], true)) {
            throw new LogicException('VTU provider returned an unrecognized order state.');
        }

        return [
            'status' => $status,
            'provider_reference' => is_string($body['provider_reference'] ?? null)
                ? substr($body['provider_reference'], 0, 150)
                : null,
            'message_code' => is_string($body['message_code'] ?? null)
                ? substr($body['message_code'], 0, 80)
                : null,
        ];
    }
}
