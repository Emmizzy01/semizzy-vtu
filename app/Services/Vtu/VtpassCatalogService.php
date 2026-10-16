<?php

namespace App\Services\Vtu;

use App\Models\VtuProduct;
use App\Services\IntegrationSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LogicException;
use RuntimeException;

class VtpassCatalogService
{
    private string $baseUrl;
    private string $apiKey;
    private string $secretKey;

    /** @return array<int, array<string, mixed>> */
    public function fetchProducts(): array
    {
        $settings = app(IntegrationSettings::class);
        $baseUrl = rtrim((string) $settings->get('vtu.vtpass.base_url', config('services.vtu.vtpass.base_url', '')), '/');
        $apiKey = (string) $settings->get('vtu.vtpass.api_key', config('services.vtu.vtpass.api_key', ''));
        $secretKey = (string) $settings->get('vtu.vtpass.secret_key', config('services.vtu.vtpass.secret_key', ''));

        if (! in_array($baseUrl, ['https://vtpass.com/api', 'https://sandbox.vtpass.com/api'], true) || $apiKey === '' || $secretKey === '') {
            throw new LogicException('VTpass catalogue sync requires the official HTTPS endpoint and server-side credentials.');
        }

        $this->baseUrl = $baseUrl;
        $this->apiKey = $apiKey;
        $this->secretKey = $secretKey;

        $services = [];
        foreach (['airtime', 'data'] as $type) {
            $response = $this->get('/services', ['identifier' => $type]);
            $body = $response->json();
            $items = is_array($body) ? ($body['content'] ?? null) : null;
            if (! $response->successful() || ! is_array($items)) {
                throw new RuntimeException("VTpass did not return a valid {$type} service catalogue.");
            }
            $services[$type] = $items;
        }

        $products = [];
        foreach ($services['airtime'] as $service) {
            if (! is_array($service) || ! is_string($service['serviceID'] ?? null)) {
                continue;
            }

            $serviceId = $service['serviceID'];
            $network = $this->airtimeNetworkFromService($serviceId);
            if ($network === null) {
                continue;
            }

            $denominations = config('services.vtu.airtime_denominations', [100, 200, 500, 1000]);
            foreach ($denominations as $amount) {
                $amount = (int) $amount;
                if ($amount < 50 || $amount > 100000 || $amount % 50 !== 0) {
                    continue;
                }
                $products[] = [
                    'service_type' => 'airtime',
                    'network' => $network,
                    'code' => "vtpass-airtime-{$network}-{$amount}",
                    'name' => strtoupper($network).' Airtime ₦'.number_format($amount),
                    'price_minor' => $amount * 100,
                    'face_value_minor' => $amount * 100,
                    'currency' => 'NGN',
                    'is_active' => true,
                    'metadata' => ['provider_service_id' => $serviceId, 'catalog_source' => 'vtpass'],
                ];
            }
        }

        // Include every mobile-network data catalogue VTpass actually returns,
        // not just one hard-coded plan family per network (e.g. SME variants).
        foreach ($services['data'] as $service) {
            if (! is_array($service)) {
                continue;
            }

            $serviceId = $service['serviceID'] ?? null;
            if (! is_string($serviceId) || ! preg_match('/^(mtn|glo|airtel|etisalat|9mobile|lebara)(?:-[a-z0-9]+)*-data$/i', $serviceId)) {
                continue;
            }

            $network = $this->networkFromService($serviceId);
            $response = $this->get('/service-variations', ['serviceID' => $serviceId]);
            $body = $response->json();
            $variations = is_array($body) ? ($body['content']['variations'] ?? null) : null;
            if (! $response->successful() || ! is_array($variations)) {
                throw new RuntimeException("VTpass did not return valid data variations for {$serviceId}; no catalogue changes were applied.");
            }

            foreach ($variations as $variation) {
                if (! is_array($variation)) {
                    continue;
                }
                $variationCode = $variation['variation_code'] ?? null;
                $name = $variation['name'] ?? null;
                $amount = $variation['variation_amount'] ?? null;
                if (! is_string($variationCode) || $variationCode === ''
                    || ! is_string($name) || $name === ''
                    || ! is_numeric($amount) || (float) $amount <= 0) {
                    continue;
                }

                $minor = (int) round((float) $amount * 100);
                $safeService = preg_replace('/[^A-Za-z0-9_-]/', '-', strtolower($serviceId));
                $safeVariation = preg_replace('/[^A-Za-z0-9_-]/', '-', $variationCode);
                $legacyServiceIds = ['mtn' => 'mtn-data', 'glo' => 'glo-data', 'airtel' => 'airtel-data', '9mobile' => 'etisalat-data'];
                $code = ($legacyServiceIds[$network] ?? null) === strtolower($serviceId)
                    ? 'vtpass-data-'.$network.'-'.$safeVariation
                    : substr('vtpass-data-'.$network.'-'.$safeService.'-'.$safeVariation, 0, 67).'-'.substr(hash('sha256', $serviceId.'|'.$variationCode), 0, 10);
                $displayNetwork = strtoupper($network);
                $products[] = [
                    'service_type' => 'data',
                    'network' => $network,
                    'code' => $code,
                    'name' => $displayNetwork.' '.$name,
                    'price_minor' => $minor,
                    'face_value_minor' => null,
                    'currency' => 'NGN',
                    'is_active' => true,
                    'metadata' => [
                        'provider_service_id' => $serviceId,
                        'provider_variation_code' => $variationCode,
                        'catalog_source' => 'vtpass',
                        'provider_listed_price_minor' => $minor,
                        'validity' => $this->inferValidity($name),
                        'data_type' => $this->inferDataType($name),
                    ],
                ];
            }
        }

        if ($products === []) {
            throw new RuntimeException('VTpass returned no usable mobile airtime/data products; no catalogue changes were applied.');
        }

        return $products;
    }

    /** @param array<int, array<string, mixed>> $products */
    public function applyProducts(array $products): array
    {
        return DB::transaction(function () use ($products): array {
            $created = 0;
            $updated = 0;

            foreach ($products as $incoming) {
                $existing = VtuProduct::query()->where('code', $incoming['code'])->lockForUpdate()->first();
                $providerMetadata = $incoming['metadata'] ?? [];

                if ($existing === null) {
                    // Provider-returned IDs and variation codes are required before
                    // a synced product can be sold. Unsupported networks never enter
                    // this list and therefore are not silently enabled.
                    $incoming['is_active'] = $this->hasRequiredMapping($incoming);
                    VtuProduct::query()->create($incoming);
                    $created++;
                    continue;
                }

                $metadata = $existing->metadata ?? [];
                foreach (['provider_service_id', 'provider_variation_code', 'catalog_source', 'provider_listed_price_minor', 'validity', 'data_type'] as $key) {
                    if (array_key_exists($key, $providerMetadata)) {
                        $metadata[$key] = $providerMetadata[$key];
                    }
                }
                $existing->forceFill(['metadata' => $metadata])->save();
                $updated++;
            }

            return ['created' => $created, 'updated' => $updated, 'total' => count($products)];
        });
    }

    private function hasRequiredMapping(array $product): bool
    {
        $metadata = $product['metadata'] ?? [];
        if (empty($metadata['provider_service_id'])) {
            return false;
        }

        return ($product['service_type'] ?? null) !== 'data'
            || ! empty($metadata['provider_variation_code']);
    }

    private function airtimeNetworkFromService(string $serviceId): ?string
    {
        return match (strtolower($serviceId)) {
            'mtn' => 'mtn',
            'glo' => 'glo',
            'airtel' => 'airtel',
            'etisalat', '9mobile' => '9mobile',
            'lebara' => 'lebara',
            default => null,
        };
    }

    private function networkFromService(string $serviceId): string
    {
        $id = strtolower($serviceId);
        if (str_starts_with($id, '9mobile-') || str_starts_with($id, 'etisalat-')) {
            return '9mobile';
        }
        foreach (['mtn', 'glo', 'airtel', 'lebara'] as $network) {
            if (str_starts_with($id, $network.'-')) {
                return $network;
            }
        }

        return 'unknown';
    }

    private function inferValidity(string $name): ?string
    {
        if (preg_match('/\b(\d+)\s*(day|days|week|weeks|month|months|hour|hours)\b/i', $name, $m)) {
            $unit = strtolower(rtrim($m[2], 's'));
            return $m[1].' '.$unit.($m[1] === '1' ? '' : 's');
        }

        return preg_match('/\bnight\b/i', $name) ? 'night' : null;
    }

    private function inferDataType(string $name): ?string
    {
        foreach (['SME', 'Corporate', 'Gifting', 'Direct'] as $type) {
            if (stripos($name, $type) !== false) {
                return $type;
            }
        }

        return null;
    }

    private function get(string $path, array $query): \Illuminate\Http\Client\Response
    {
        return Http::withHeaders([
            'api-key' => $this->apiKey,
            'secret-key' => $this->secretKey,
        ])->acceptJson()->connectTimeout(5)->timeout(15)->get($this->baseUrl.$path, $query);
    }
}
