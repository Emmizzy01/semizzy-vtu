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

        if (config('app.env') === 'production' && $baseUrl === 'https://sandbox.vtpass.com/api') {
            throw new LogicException('VTpass sandbox is disabled in production. Configure the official live endpoint and live credentials before enabling real purchases.');
        }
    }

    public function assertProductConfigured(VtuProduct $product): void
    {
        if (($product->metadata['catalog_source'] ?? null) === 'vtpass'
            && ($product->metadata['purchase_approved'] ?? false) !== true) {
            throw new LogicException('This VTpass catalogue product requires recorded sandbox verification and authorised approval before purchase.');
        }

        if (! in_array($product->service_type, ['airtime', 'data', 'electricity-bill', 'tv-subscription'], true)) {
            throw new LogicException('This VTpass service category is not supported for purchases yet.');
        }

        if (in_array($product->service_type, ['electricity-bill', 'tv-subscription'], true)
            && ($product->metadata['purchase_supported'] ?? null) !== true) {
            throw new LogicException('This VTpass product has not been approved for its service-specific purchase flow.');
        }

        $this->serviceId($product);

        if ($product->service_type === 'electricity-bill') {
            $minimum = (float) ($product->metadata['provider_minimum_amount'] ?? 1);
            $maximum = (float) ($product->metadata['provider_maximum_amount'] ?? 1000000);
            if ($minimum < 1 || $maximum < $minimum) {
                throw new LogicException('VTpass electricity amount limits are not configured safely.');
            }
        } elseif ($product->service_type === 'tv-subscription') {
            $variationCode = $product->metadata['provider_variation_code'] ?? null;
            if (! is_string($variationCode) || $variationCode === '') {
                throw new LogicException('VTpass TV product is missing its verified subscription variation code.');
            }
        } elseif ($product->service_type === 'data') {
            $variationCode = $product->metadata['provider_variation_code'] ?? null;
            if (! is_string($variationCode) || $variationCode === '') {
                throw new LogicException('VTpass data product is missing its verified variation code.');
            }
            if (($product->metadata['purchase_supported'] ?? true) === false) {
                throw new LogicException('This VTpass data service requires provider-specific recipient validation that is not yet supported for purchases.');
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

        $details = is_array($order->service_details) ? $order->service_details : [];
        $payload = [
            'request_id' => $this->requestId($order),
            'serviceID' => $this->serviceId($product),
            'phone' => is_string($details['contact_phone'] ?? null) ? $details['contact_phone'] : $order->recipient,
        ];

        if ($order->service_type === 'electricity-bill') {
            $meterType = $details['meter_type'] ?? null;
            if (! in_array($meterType, ['prepaid', 'postpaid'], true) || $order->amount_minor < 100) {
                throw new LogicException('Electricity purchase requires a verified meter type and positive amount.');
            }
            $payload['billersCode'] = $order->recipient;
            $payload['variation_code'] = $meterType;
            $payload['amount'] = intdiv((int) $order->amount_minor, 100);
        } elseif ($order->service_type === 'tv-subscription') {
            $payload['billersCode'] = $order->recipient;
            $payload['variation_code'] = (string) $product->metadata['provider_variation_code'];
        } elseif ($order->service_type === 'airtime') {
            if ($order->amount_minor < 100 || $order->amount_minor > 100000000 || $order->amount_minor % 100 !== 0) {
                throw new LogicException('VTpass airtime amount must be a whole-naira amount between ₦1 and ₦1,000,000.');
            }
            $payload['amount'] = intdiv((int) $order->amount_minor, 100);
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

    /**
     * Verify an electricity meter without creating a bill payment.
     *
     * @return array{status:string, message_code:?string, customer_name:?string, customer_address:?string}
     */
    public function verifyElectricityMeter(VtuProduct $product, string $meterNumber, string $meterType): array
    {
        if ($product->service_type !== 'electricity-bill') {
            throw new LogicException('Choose an electricity product to verify a meter.');
        }

        if (! in_array($meterType, ['prepaid', 'postpaid'], true)) {
            throw new LogicException('Electricity meter type must be prepaid or postpaid.');
        }

        return $this->verifyBiller($product, $meterNumber, $meterType);
    }

    /**
     * Verify a TV smartcard without purchasing a subscription.
     *
     * @return array{status:string, message_code:?string, customer_name:?string, customer_address:?string}
     */
    public function verifyTvSmartcard(VtuProduct $product, string $smartcardNumber): array
    {
        if ($product->service_type !== 'tv-subscription') {
            throw new LogicException('Choose a TV subscription product to verify a smartcard.');
        }

        return $this->verifyBiller($product, $smartcardNumber, null);
    }

    /**
     * @return array{status:string, message_code:?string, customer_name:?string, customer_address:?string}
     */
    private function verifyBiller(VtuProduct $product, string $billerCode, ?string $type): array
    {
        $billerCode = trim($billerCode);
        if (! preg_match('/^[a-zA-Z0-9-]{5,40}$/', $billerCode)) {
            throw new LogicException('Enter a valid meter or smartcard number before verification.');
        }

        $this->assertConfigured();
        $serviceId = $product->metadata['provider_service_id'] ?? null;
        if (! is_string($serviceId) || ! preg_match('/^[a-z0-9-]{2,80}$/i', $serviceId)) {
            throw new LogicException('The selected VTpass service is missing its verified provider service ID.');
        }

        $requestId = CarbonImmutable::now('Africa/Lagos')->format('YmdHi').str_replace('-', '', (string) \Illuminate\Support\Str::uuid());
        $payload = [
            'request_id' => $requestId,
            'serviceID' => $serviceId,
            'billersCode' => $billerCode,
        ];
        if ($type !== null) {
            $payload['type'] = $type;
        }

        try {
            $response = $this->post('/merchant-verify', $payload);
        } catch (ConnectionException $exception) {
            // A failed connection is not proof that the biller number is invalid.
            throw $exception;
        }

        $body = $response->json();
        $code = is_array($body) && is_string($body['code'] ?? null) ? $body['code'] : null;
        $content = is_array($body) && is_array($body['content'] ?? null) ? $body['content'] : [];
        $verified = $response->successful() && $code === '000' && $content !== [];

        $customerName = $content['Customer_Name'] ?? $content['customer_name'] ?? $content['name'] ?? null;
        $customerAddress = $content['Customer_Address'] ?? $content['customer_address'] ?? $content['address'] ?? null;

        return [
            'status' => $verified ? 'verified' : ($response->successful() ? 'unverified' : 'unknown'),
            'message_code' => $code ?? 'http_'.$response->status(),
            'customer_name' => is_string($customerName) ? substr($customerName, 0, 160) : null,
            'customer_address' => is_string($customerAddress) ? substr($customerAddress, 0, 240) : null,
        ];
    }

    private function post(string $path, array $payload): \Illuminate\Http\Client\Response
    {
        $baseUrl = rtrim((string) app(IntegrationSettings::class)->get('vtu.vtpass.base_url', config('services.vtu.vtpass.base_url')), '/');

        return Http::withHeaders([
            'api-key' => (string) app(IntegrationSettings::class)->get('vtu.vtpass.api_key', config('services.vtu.vtpass.api_key')),
            'secret-key' => (string) app(IntegrationSettings::class)->get('vtu.vtpass.secret_key', config('services.vtu.vtpass.secret_key')),
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
        if (is_string($metadataServiceId) && preg_match('/^[a-z0-9-]{2,80}$/i', $metadataServiceId)) {
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
