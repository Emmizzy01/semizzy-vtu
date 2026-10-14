<?php

namespace App\Services\Vtu;

use App\Models\VtuProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LogicException;
use RuntimeException;

class VtpassCatalogService
{
    private const NETWORKS = [
        'mtn' => ['airtime' => 'mtn', 'data' => 'mtn-data'],
        'glo' => ['airtime' => 'glo', 'data' => 'glo-data'],
        'airtel' => ['airtime' => 'airtel', 'data' => 'airtel-data'],
        '9mobile' => ['airtime' => 'etisalat', 'data' => 'etisalat-data'],
    ];

    /** @return array<int, array<string, mixed>> */
    public function fetchProducts(): array
    {
        $baseUrl = rtrim((string) config('services.vtu.vtpass.base_url', ''), '/');
        if (! in_array($baseUrl, ['https://vtpass.com/api', 'https://sandbox.vtpass.com/api'], true)
            || ! config('services.vtu.vtpass.api_key')
            || ! config('services.vtu.vtpass.secret_key')) {
            throw new LogicException('VTpass catalogue sync requires the official HTTPS endpoint and server-side credentials.');
        }

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
        $serviceIds = [];
        foreach (self::NETWORKS as $network => $identifiers) {
            foreach (['airtime', 'data'] as $type) {
                $expected = $identifiers[$type];
                $match = null;
                foreach ($services[$type] as $service) {
                    if (is_array($service) && ($service['serviceID'] ?? null) === $expected) {
                        $match = $service;
                        break;
                    }
                }
                if ($match === null) {
                    // Providers can omit a network from their current catalogue;
                    // do not silently remove or overwrite previously configured products.
                    continue;
                }
                $serviceIds[$type][$network] = $expected;
                if ($type === 'airtime') {
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
                            'metadata' => ['provider_service_id' => $expected, 'catalog_source' => 'vtpass'],
                        ];
                    }
                    continue;
                }

                $response = $this->get('/service-variations', ['serviceID' => $expected]);
                $body = $response->json();
                $variations = is_array($body) ? ($body['content']['variations'] ?? null) : null;
                if (! $response->successful() || ! is_array($variations)) {
                    throw new RuntimeException("VTpass did not return valid data variations for {$expected}; no catalogue changes were applied.");
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
                    $safeCode = preg_replace('/[^A-Za-z0-9_-]/', '-', $variationCode);
                    $products[] = [
                        'service_type' => 'data',
                        'network' => $network,
                        'code' => 'vtpass-data-'.$network.'-'.$safeCode,
                        'name' => strtoupper($network).' '.$name,
                        'price_minor' => $minor,
                        'face_value_minor' => null,
                        'currency' => 'NGN',
                        'is_active' => true,
                        'metadata' => [
                            'provider_service_id' => $expected,
                            'provider_variation_code' => $variationCode,
                            'catalog_source' => 'vtpass',
                        ],
                    ];
                }
            }
        }

        if ($products === []) {
            throw new RuntimeException('VTpass returned no usable products; no catalogue changes were applied.');
        }

        return $products;
    }

    /** @param array<int, array<string, mixed>> $products */
    public function applyProducts(array $products): int
    {
        return DB::transaction(function () use ($products): int {
            foreach ($products as $product) {
                VtuProduct::query()->updateOrCreate(
                    ['code' => $product['code']],
                    $product,
                );
            }

            return count($products);
        });
    }

    private function get(string $path, array $query): \Illuminate\Http\Client\Response
    {
        return Http::withHeaders([
            'api-key' => (string) config('services.vtu.vtpass.api_key'),
            'secret-key' => (string) config('services.vtu.vtpass.secret_key'),
        ])->acceptJson()->connectTimeout(5)->timeout(15)
            ->get(rtrim((string) config('services.vtu.vtpass.base_url'), '/').$path, $query);
    }
}
