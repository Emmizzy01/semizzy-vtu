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

        $categoryResponse = $this->get('/service-categories', []);
        $categoryBody = $categoryResponse->json();
        $categories = is_array($categoryBody) ? ($categoryBody['content'] ?? null) : null;
        if (! $categoryResponse->successful() || ! is_array($categories)) {
            throw new RuntimeException('VTpass did not return a valid service category catalogue.');
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
            if (! is_string($serviceId) || ! preg_match('/^[a-z0-9][a-z0-9-]{1,79}$/i', $serviceId)) {
                continue;
            }

            $network = $this->networkFromService($serviceId);
            $serviceName = is_string($service['name'] ?? null) ? $service['name'] : strtoupper($network).' Data';
            $phoneRecipientSupported = $this->supportsPhoneRecipient($serviceId);
            $response = $this->get('/service-variations', ['serviceID' => $serviceId]);
            $body = $response->json();
            $variations = is_array($body) ? ($body['content']['variations'] ?? $body['content']['varations'] ?? null) : null;
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
                    'name' => $serviceName.' — '.$name,
                    'price_minor' => $minor,
                    'face_value_minor' => null,
                    'currency' => 'NGN',
                    'is_active' => $phoneRecipientSupported,
                    'metadata' => [
                        'provider_service_id' => $serviceId,
                        'provider_service_name' => $serviceName,
                        'provider_category' => 'data',
                        'recipient_type' => $phoneRecipientSupported ? 'phone' : 'provider_specific',
                        'purchase_supported' => $phoneRecipientSupported,
                        'provider_variation_code' => $variationCode,
                        'catalog_source' => 'vtpass',
                        'provider_listed_price_minor' => $minor,
                        'validity' => $this->inferValidity($name),
                        'data_type' => $this->inferDataType($name),
                    ],
                ];
            }
        }

        // Discover all other provider categories dynamically. These products are
        // catalogued for administration, but deliberately remain inactive until
        // their own customer verification and purchase flows are implemented.
        foreach ($categories as $category) {
            if (! is_array($category)) {
                continue;
            }
            $identifier = $category['identifier'] ?? null;
            $categoryName = $category['name'] ?? null;
            if (! is_string($identifier)
                || ! preg_match('/^[a-z0-9][a-z0-9-]{1,23}$/i', $identifier)
                || in_array(strtolower($identifier), ['airtime', 'data'], true)) {
                continue;
            }

            $serviceResponse = $this->get('/services', ['identifier' => $identifier]);
            $serviceBody = $serviceResponse->json();
            $categoryServices = is_array($serviceBody) ? ($serviceBody['content'] ?? null) : null;
            if (! $serviceResponse->successful() || ! is_array($categoryServices)) {
                throw new RuntimeException("VTpass did not return a valid {$identifier} service catalogue; no catalogue changes were applied.");
            }

            foreach ($categoryServices as $service) {
                if (! is_array($service)) {
                    continue;
                }
                $serviceId = $service['serviceID'] ?? null;
                if (! is_string($serviceId) || ! preg_match('/^[a-z0-9][a-z0-9-]{1,79}$/i', $serviceId)) {
                    continue;
                }
                $serviceName = is_string($service['name'] ?? null) ? $service['name'] : $serviceId;
                $variationResponse = $this->get('/service-variations', ['serviceID' => $serviceId]);
                $variationBody = $variationResponse->json();
                $variations = is_array($variationBody)
                    ? ($variationBody['content']['variations'] ?? $variationBody['content']['varations'] ?? null)
                    : null;

                if ($variationResponse->serverError()) {
                    throw new RuntimeException("VTpass returned a server error while loading {$serviceId}; no catalogue changes were applied.");
                }

                $network = strtolower((string) strtok($serviceId, '-'));
                $categoryMetadata = [
                    'provider_service_id' => $serviceId,
                    'provider_service_name' => $serviceName,
                    'provider_category' => $identifier,
                    'provider_category_name' => is_string($categoryName) ? $categoryName : $identifier,
                    'recipient_type' => 'provider_specific',
                    'purchase_supported' => in_array(strtolower($identifier), ['electricity-bill', 'tv-subscription'], true),
                    'catalog_source' => 'vtpass',
                ];

                if ($variationResponse->successful() && is_array($variations) && $variations !== []) {
                    foreach ($variations as $variation) {
                        if (! is_array($variation)) {
                            continue;
                        }
                        $variationCode = $variation['variation_code'] ?? null;
                        $variationName = $variation['name'] ?? null;
                        $amount = $variation['variation_amount'] ?? null;
                        if (! is_string($variationCode) || $variationCode === ''
                            || ! is_string($variationName) || $variationName === ''
                            || ! is_numeric($amount) || (float) $amount <= 0) {
                            continue;
                        }
                        $minor = (int) round((float) $amount * 100);
                        $safeId = strtolower($serviceId);
                        $code = substr('vtpass-'.$identifier.'-'.$safeId, 0, 55).'-'.substr(hash('sha256', $identifier.'|'.$serviceId.'|'.$variationCode), 0, 16);
                        $products[] = [
                            'service_type' => $identifier,
                            'network' => $network,
                            'code' => $code,
                            'name' => $serviceName.' — '.$variationName,
                            'price_minor' => $minor,
                            'face_value_minor' => null,
                            'currency' => 'NGN',
                            'is_active' => false,
                            'metadata' => array_merge($categoryMetadata, [
                                'provider_variation_code' => $variationCode,
                                'provider_listed_price_minor' => $minor,
                                'catalog_entry_type' => 'variation',
                            ]),
                        ];
                    }
                    continue;
                }

                // Some services (notably electricity) require a customer-entered
                // amount or a service-specific meter/account type rather than a
                // fixed-price variation. Keep a disabled registry entry instead
                // of inventing a sell price or making it purchasable.
                $products[] = [
                    'service_type' => $identifier,
                    'network' => $network,
                    'code' => substr('vtpass-'.$identifier.'-'.$serviceId, 0, 63).'-'.substr(hash('sha256', $identifier.'|'.$serviceId), 0, 12),
                    'name' => $serviceName.' — setup required',
                    'price_minor' => 0,
                    'face_value_minor' => null,
                    'currency' => 'NGN',
                    'is_active' => false,
                    'metadata' => array_merge($categoryMetadata, [
                        'catalog_entry_type' => 'service_placeholder',
                        'provider_minimum_amount' => is_numeric($service['minimium_amount'] ?? null) ? (float) $service['minimium_amount'] : null,
                        'provider_maximum_amount' => is_numeric($service['maximum_amount'] ?? null) ? (float) $service['maximum_amount'] : null,
                        'setup_required' => ['customer_verification', 'recipient_fields', 'purchase_payload'],
                    ]),
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
                foreach (['provider_service_id', 'provider_service_name', 'provider_category', 'recipient_type', 'purchase_supported', 'provider_variation_code', 'catalog_source', 'provider_listed_price_minor', 'validity', 'data_type'] as $key) {
                    if (array_key_exists($key, $providerMetadata)) {
                        $metadata[$key] = $providerMetadata[$key];
                    }
                }
                $attributes = ['metadata' => $metadata];
                if (! in_array(($incoming['service_type'] ?? null), ['airtime', 'data'], true)) {
                    $attributes['is_active'] = false;
                }
                if (($incoming['service_type'] ?? null) === 'data' && empty($providerMetadata['purchase_supported'])) {
                    // Existing plans requiring unsupported account/recipient fields must
                    // not remain purchasable merely because they were previously active.
                    $attributes['is_active'] = false;
                }
                $existing->forceFill($attributes)->save();
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

        if (($product['service_type'] ?? null) === 'data') {
            return ! empty($metadata['provider_variation_code'])
                && ! empty($metadata['purchase_supported']);
        }

        // Airtime has no variation code, while every other category must wait
        // for its own validation and purchase adapter before activation.
        return ($product['service_type'] ?? null) === 'airtime';
    }

    private function supportsPhoneRecipient(string $serviceId): bool
    {
        return (bool) preg_match('/^(mtn|glo|airtel|etisalat|9mobile|lebara)(?:-[a-z0-9]+)*-data$/i', $serviceId);
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
            if ($id === $network || str_starts_with($id, $network.'-')) {
                return $network;
            }
        }

        // Preserve all other VTpass data-category services (e.g. Smile,
        // Spectranet) under their own stable service family instead of dropping them.
        return strtolower((string) strtok($id, '-'));
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
        // Some provider catalogues label this as one combined plan family.
        // Check it before the individual words so it is not reduced to "Corporate".
        if (preg_match('/\\bcorporate\\s*[\\/+]\\s*gifting\\b/i', $name)) {
            return 'Corporate/Gifting';
        }

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
