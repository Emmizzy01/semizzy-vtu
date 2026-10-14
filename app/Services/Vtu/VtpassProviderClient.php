<?php

namespace App\Services\Vtu;

use App\Models\VtuOrder;
use App\Models\VtuProduct;
use App\Services\IntegrationSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use LogicException;

class VtpassProviderClient implements VtuProvider
{
    public function assertConfigured(): void
    {
        $baseUrl = rtrim((string) app(IntegrationSettings::class)->get('vtu.vtpass.base_url', config('services.vtu.vtpass.base_url', '')), '/');

        if (
            ! in_array($baseUrl, [
                'https://vtpass.com/api',
                'https://sandbox.vtpass.com/api',
            ], true)
            || ! app(IntegrationSettings::class)->get('vtu.vtpass.api_key', config('services.vtu.vtpass.api_key'))
            || ! app(IntegrationSettings::class)->get('vtu.vtpass.secret_key', config('services.vtu.vtpass.secret_key'))
        ) {
            throw new LogicException('VTpass requires its official HTTPS API base URL and server-side API credentials.');
        }
    }

    public function assertProductConfigured(VtuProduct $product): void
    {
        if (! in_array($product->service_type, ['airtime', 'data'], true)) {
            throw new LogicException('VTpass only supports configured airtime and data products in this adapter.');
        }

        $this->serviceId($product);

        if ($product->service_type === 'data') {
            $variationCode = $product->metadata['provider_variation_code'] ?? null;
            if (! is_string($variationCode) || $variationCode === '') {
                throw new LogicException('VTpass data product is missing its verified variation code.');
            }
        } else {
            $faceValueMinor = (int) ($product->face_value_minor ?? $product->price_minor);
            if ($faceValueMinor < 1 || $faceValueMinor % 100 !== 0) {
                throw new LogicException('VTpass airtime face value must be a positive whole amount in NGN.');
            }
        }
    }

    public function submit(VtuOrder $order): array
    {
        $this->assertConfigured();
        $product = $order->product;

        if (! $product instanceof VtuProduct) {
            throw new LogicException('VTpass order product metadata is unavailable.');
        }
        $this->assertProductConfigured($product);

        $payload = [
            'request_id' => $this->requestId($order),
            'serviceID' => $this->serviceId($product),
            'phone' => $order->recipient,
        ];

        if ($order->service_type === 'airtime') {
            $faceValueMinor = (int) ($product->face_value_minor ?? $order->amount_minor);
            $payload['amount'] = intdiv($faceValueMinor, 100);
        } else {
            $payload['billersCode'] = $order->recipient;
            $payload['variation_code'] = (string) $product->metadata['provider_variation_code'];
        }

        try {
            $response = $this->post('/pay', $payload);
        } catch (ConnectionException $exception) {
            // A timeout can occur after VTpass has accepted the request.
            throw $exception;
        }

        if (! $response->successful()) {
            return [
                'status' => 'unknown',
                'provider_reference' => null,
                'message_code' => is_string($response->json('code'))
                    ? substr($response->json('code'), 0, 80)
                    : 'http_'.$response->status(),
            ];
        }

        return $this->normalize($response->json());
    }

    public function lookup(VtuOrder $order): array
    {
        $this->assertConfigured();

        try {
            $response = $this->post('/requery', [
                'request_id' => $this->requestId($order),
            ]);
        } catch (ConnectionException $exception) {
            throw $exception;
        }

        if (! $response->successful()) {
            return [
                'status' => 'unknown',
                'provider_reference' => null,
                'message_code' => is_string($response->json('code'))
                    ? substr($response->json('code'), 0, 80)
                    : 'http_'.$response->status(),
            ];
        }

        return $this->normalize($response->json());
    }

    private function post(string $path, array $payload): \Illuminate\Http\Client\Response
    {
        $baseUrl = rtrim((string) app(IntegrationSettings::class)->get('vtu.vtpass.base_url', app(IntegrationSettings::class)->get('vtu.vtpass.base_url', config('services.vtu.vtpass.base_url'))), '/');

        return Http::withHeaders([
            'api-key' => (string) config('services.vtu.vtpass.api_key'),
            'secret-key' => (string) config('services.vtu.vtpass.secret_key'),
        ])
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(15)
            ->post($baseUrl.$path, $payload);
    }

    /** @return array{status:string, provider_reference:?string, message_code:?string} */
    private function normalize(mixed $body): array
    {
        $unknown = ['status' => 'pending', 'provider_reference' => null, 'message_code' => null];

        if (! is_array($body)) {
            return $unknown;
        }

        $code = is_string($body['code'] ?? null) ? $body['code'] : null;
        $transactions = $body['content']['transactions'] ?? null;
        $providerStatus = is_array($transactions) && is_string($transactions['status'] ?? null)
            ? strtolower($transactions['status'])
            : null;
        $reference = is_array($transactions) && is_string($transactions['transactionId'] ?? null)
            ? substr($transactions['transactionId'], 0, 150)
            : null;

        // VTpass says code 000 requires inspecting content.transactions.status.
        // Only delivered is success; initiated/pending and every unknown shape
        // remain unresolved until a requery returns a definitive state.
        if ($code === '000' && $providerStatus === 'delivered') {
            $status = 'success';
        } elseif ($code === '000' && in_array($providerStatus, ['initiated', 'pending'], true)) {
            $status = 'pending';
        } elseif (in_array($code, ['016', '091'], true)) {
            // Official response-code guide defines these as transaction failed
            // and transaction not processed. Other codes stay pending.
            $status = 'failed';
        } else {
            $status = 'pending';
        }

        return [
            'status' => $status,
            'provider_reference' => $reference,
            'message_code' => $code,
        ];
    }

    private function requestId(VtuOrder $order): string
    {
        $createdAt = $order->created_at
            ? CarbonImmutable::parse($order->created_at)->setTimezone('Africa/Lagos')
            : CarbonImmutable::now('Africa/Lagos');

        // VTpass requires today's Lagos date/time in the first 12 characters.
        // The stable suffix ensures the same order is queried with the same ID.
        return $createdAt->format('YmdHi').str_replace('-', '', (string) $order->public_id);
    }

    private function serviceId(VtuProduct $product): string
    {
        $metadataServiceId = $product->metadata['provider_service_id'] ?? null;
        if (is_string($metadataServiceId) && preg_match('/^[a-z0-9-]{2,80}$/', $metadataServiceId)) {
            return $metadataServiceId;
        }

        $network = strtolower(trim($product->network));
        $airtime = [
            'mtn' => 'mtn',
            'glo' => 'glo',
            'airtel' => 'airtel',
            '9mobile' => 'etisalat',
            'etisalat' => 'etisalat',
        ];
        $data = [
            'mtn' => 'mtn-data',
            'glo' => 'glo-data',
            'airtel' => 'airtel-data',
            '9mobile' => 'etisalat-data',
            'etisalat' => 'etisalat-data',
        ];

        $serviceId = $product->service_type === 'airtime'
            ? ($airtime[$network] ?? null)
            : ($data[$network] ?? null);

        if (! is_string($serviceId)) {
            throw new LogicException('VTpass service ID is not mapped for this network.');
        }

        return $serviceId;
    }
}
