<?php

namespace App\Services\Vtu;

use App\Models\VtuProduct;
use App\Services\IntegrationSettings;
use Illuminate\Support\Facades\Http;
use LogicException;
use RuntimeException;

class ProviderCatalogueImportService
{
    /**
     * Import verified provider mappings and fixed-price data plans.
     * Airtime imports represent networks only because customers enter the amount at checkout.
     * Imported rows are always inactive and unapproved; import never enables purchases.
     * Other bill categories remain outside this product importer until their price, recipient,
     * validation, submit, lookup, and recovery contracts are implemented and tested.
     *
     * @return array{provider:string,category:string,created:int,updated:int,skipped:int}
     */
    public function import(string $provider, string $category): array
    {
        if (! in_array($provider, ['sogo', 'gsubz', 'dataway'], true)) {
            throw new LogicException('Choose Sogo Africa, GSUBZ, or Dataway for provider catalogue import.');
        }
        $allowed = ['airtime', 'data', 'electricity', 'cable_tv', 'education', 'betting', 'epin', 'esim', 'social-media', 'recharge-pin', 'internet', 'exam', 'airtime_cash'];
        if (! in_array($category, $allowed, true)) {
            throw new LogicException('Choose a service category supported by this provider catalogue.');
        }

        $items = match ($provider) {
            'sogo' => $this->sogo($category),
            'gsubz' => $this->gsubz($category),
            'dataway' => $this->dataway($category),
        };

        $created = $updated = $skipped = 0;
        foreach ($items as $item) {
            if (! is_array($item) || empty($item['code']) || empty($item['name']) || empty($item['network'])) {
                $skipped++;
                continue;
            }

            $code = substr($provider.'-'.$category.'-'.preg_replace('/[^a-zA-Z0-9._-]/', '-', (string) $item['code']), 0, 80);
            $cost = max(0, (int) ($item['cost_minor'] ?? 0));
            $existing = VtuProduct::query()->where('code', $code)->first();
            $values = [
                'service_type' => $item['service_type'] ?? $this->serviceType($category),
                'provider_key' => $provider,
                'provider_product_code' => substr((string) $item['code'], 0, 120),
                'network' => substr((string) $item['network'], 0, 40),
                'data_type' => isset($item['data_type']) ? substr((string) $item['data_type'], 0, 40) : null,
                'validity' => isset($item['validity']) ? substr((string) $item['validity'], 0, 80) : null,
                'name' => substr((string) $item['name'], 0, 160),
                'provider_cost_minor' => $cost ?: null,
                'price_minor' => max(1, (int) ($item['selling_minor'] ?? $cost ?: 1)),
                'currency' => 'NGN',
                'is_active' => false,
                'pricing_by_user_category' => null,
                'commission_by_user_category' => null,
                'metadata' => [
                    'catalog_source' => $provider,
                    'purchase_approved' => false,
                    'provider_mappings' => [$provider => $item['mapping'] ?? []],
                    'provider_category' => $category,
                    'catalog_only' => ! in_array($item['service_type'] ?? $this->serviceType($category), ['airtime', 'data'], true),
                    'purchase_supported' => in_array($item['service_type'] ?? $this->serviceType($category), ['airtime', 'data'], true),
                    'amount_based' => (bool) ($item['mapping']['amount_based'] ?? false),
                    'imported_at' => now()->toIso8601String(),
                ],
            ];

            if ($existing) {
                // Refresh provider facts only. Preserve admin price decisions and active/approval flags.
                $existing->forceFill([
                    'provider_product_code' => $values['provider_product_code'],
                    // A catalogue may omit provider cost (for example, fixed-value airtime).
                    // Never erase a previously verified cost with an unknown/null value.
                    ...($values['provider_cost_minor'] !== null ? ['provider_cost_minor' => $values['provider_cost_minor']] : []),
                    'network' => $values['network'],
                    'data_type' => $values['data_type'],
                    'validity' => $values['validity'],
                    'metadata' => array_merge($existing->metadata ?? [], [
                        'catalog_source' => $provider,
                        'provider_mappings' => array_merge($existing->metadata['provider_mappings'] ?? [], $values['metadata']['provider_mappings']),
                        'provider_category' => $category,
                        'catalog_only' => ! in_array($item['service_type'] ?? $this->serviceType($category), ['airtime', 'data'], true),
                        'purchase_supported' => in_array($item['service_type'] ?? $this->serviceType($category), ['airtime', 'data'], true),
                        'amount_based' => (bool) ($item['mapping']['amount_based'] ?? false),
                        'last_imported_at' => now()->toIso8601String(),
                    ]),
                ])->save();
                $updated++;
            } else {
                $values['code'] = $code;
                $values['face_value_minor'] = null;
                VtuProduct::query()->create($values);
                $created++;
            }
        }

        return compact('provider', 'category', 'created', 'updated', 'skipped');
    }

    private function sogo(string $category): array
    {
        $settings = app(IntegrationSettings::class);
        $key = (string) $settings->get('vtu.sogo.api_key', config('services.vtu.sogo.api_key', ''));
        $base = rtrim((string) $settings->get('vtu.sogo.base_url', config('services.vtu.sogo.base_url', '')), '/');
        $this->assertCredentials($base, $key, 'Sogo');
        $http = Http::baseUrl($base.'/')->withToken($key)->acceptJson()->connectTimeout(5)->timeout(30);
        $response = $http->get('bills/catalog');
        if (! $response->successful()) throw new RuntimeException('Sogo catalogue request failed.');
        $body = $response->json();
        $providers = data_get($body, 'data.'.$category.'.providers', []);
        if (! is_array($providers)) throw new RuntimeException('Sogo returned an invalid bill catalogue.');

        $items = [];
        foreach ($providers as $network) {
            if (! is_array($network) || ! is_string($network['slug'] ?? null)) continue;
            if ($category === 'airtime') {
                // Airtime is amount-based at checkout, not a fixed-denomination product list.
                $items[] = ['code' => $network['slug'], 'name' => ($network['name'] ?? strtoupper($network['slug'])).' Airtime',
                    'network' => $network['slug'], 'cost_minor' => null, 'selling_minor' => 1,
                    'mapping' => ['network' => $network['slug'], 'amount_based' => true]];
                continue;
            }
            $planEndpoint = match ($category) {
                'data' => 'bills/data-plans',
                'cable_tv' => 'bills/cable-packages',
                'education' => 'bills/education-plans',
                default => null,
            };
            if ($planEndpoint === null) {
                $items[] = ['code' => $network['slug'], 'name' => (string) ($network['name'] ?? $network['slug']),
                    'network' => $network['slug'], 'cost_minor' => null, 'selling_minor' => 1,
                    'service_type' => $this->serviceType($category),
                    'mapping' => ['network' => $network['slug'], 'catalog_only' => true]];
                continue;
            }
            $queryKey = $category === 'data' ? 'network' : 'provider';
            $plansResponse = $http->get($planEndpoint, [$queryKey => $network['slug']]);
            if (! $plansResponse->successful()) throw new RuntimeException('Sogo '.$category.' catalogue failed; no import was applied.');
            $body = $plansResponse->json();
            $plans = data_get($body, 'data.plans', data_get($body, 'data.packages', data_get($body, 'data', [])));
            if (! is_array($plans)) throw new RuntimeException('Sogo returned an invalid '.$category.' plan catalogue.');
            foreach ($plans as $plan) {
                if (! is_array($plan)) continue;
                $code = $plan['variation_code'] ?? $plan['code'] ?? $plan['id'] ?? null;
                $amount = $plan['amount'] ?? $plan['price'] ?? $plan['cost'] ?? null;
                if (! is_string($code) && ! is_int($code)) continue;
                if (! is_numeric($amount)) continue;
                $items[] = ['code' => $network['slug'].'-'.$code, 'name' => (string) ($plan['name'] ?? $plan['label'] ?? $code),
                    'network' => $network['slug'], 'cost_minor' => (int) round((float) $amount * 100),
                    'data_type' => (string) ($plan['type'] ?? $category),
                    'validity' => (string) ($plan['validity'] ?? $plan['duration'] ?? ''),
                    'service_type' => $this->serviceType($category),
                    'mapping' => ['network' => $network['slug'], 'variation_code' => (string) $code, 'catalog_only' => $category !== 'data']];
            }
        }
        return $items;
    }

    private function gsubz(string $category): array
    {
        $settings = app(IntegrationSettings::class);
        $key = (string) $settings->get('vtu.gsubz.api_key', config('services.vtu.gsubz.api_key', ''));
        $base = rtrim((string) $settings->get('vtu.gsubz.base_url', config('services.vtu.gsubz.base_url', '')), '/');
        $this->assertCredentials($base, $key, 'GSUBZ');
        $http = Http::baseUrl($base)->withToken($key)->acceptJson()->connectTimeout(5)->timeout(30);
        $response = $http->get('api/category/');
        if (! $response->successful()) throw new RuntimeException('GSUBZ service catalogue request failed.');
        $body = $response->json();
        $services = data_get($body, 'data', $body);
        if (! is_array($services)) throw new RuntimeException('GSUBZ returned an invalid service catalogue.');

        $items = [];
        foreach ($this->flattenServices($services) as $service) {
            $id = (string) ($service['serviceID'] ?? $service['service_id'] ?? $service['id'] ?? '');
            $label = (string) ($service['name'] ?? $service['service'] ?? $service['label'] ?? $id);
            if ($id === '') continue;
            $search = strtolower($label.' '.$id);
            $detected = match (true) {
                str_contains($search, 'airtime') || in_array(strtolower($id), ['mtn', 'airtel', 'glo', 'etisalat', '9mobile', 'lebara'], true) => 'airtime',
                str_contains($search, 'electric') => 'electricity',
                str_contains($search, 'dstv') || str_contains($search, 'gotv') || str_contains($search, 'startimes') || str_contains($search, 'cable') => 'cable_tv',
                str_contains($search, 'waec') || str_contains($search, 'neco') || str_contains($search, 'nabteb') || str_contains($search, 'jamb') || str_contains($search, 'exam') => 'education',
                str_contains($search, 'social') => 'social-media',
                str_contains($search, 'pin') || str_contains($search, 'epin') || str_contains($search, 'recharge') => 'recharge-pin',
                str_contains($search, 'data') || preg_match('/_(sme|gifting|data)|(?:^|_)(mtn|airtel|glo|etisalat)_/', $search) => 'data',
                default => null,
            };
            if ($detected !== $category) continue;

            if ($category === 'airtime') {
                $items[] = ['code' => $id, 'name' => $label.' Airtime', 'network' => $this->network($label.' '.$id),
                    'cost_minor' => null, 'selling_minor' => 1, 'mapping' => ['network' => $id, 'amount_based' => true]];
                continue;
            }

            if (in_array($category, ['data', 'cable_tv', 'social-media'], true)) {
                $plansResponse = $http->get('api/plans/', ['service' => $id]);
                if (! $plansResponse->successful()) throw new RuntimeException('GSUBZ '.$category.' plan catalogue failed; no import was applied.');
                $plans = data_get($plansResponse->json(), 'data', $plansResponse->json());
                if (! is_array($plans)) throw new RuntimeException('GSUBZ returned an invalid plan catalogue.');
                foreach ($plans as $plan) {
                    if (! is_array($plan)) continue;
                    $code = (string) ($plan['plan'] ?? $plan['variation_code'] ?? $plan['value'] ?? $plan['PlanName'] ?? '');
                    $amount = $plan['api_price'] ?? $plan['price'] ?? null;
                    if ($code === '' || ! is_numeric($amount)) continue;
                    $items[] = ['code' => $id.'-'.$code, 'name' => (string) ($plan['name'] ?? $plan['PlanName'] ?? $code),
                        'network' => $this->network($label.' '.$id), 'cost_minor' => (int) round((float) $amount * 100),
                        'data_type' => $id, 'validity' => (string) ($plan['validity'] ?? ''),
                        'service_type' => $this->serviceType($category),
                        'mapping' => ['network' => $id, 'plan_code' => $code, 'catalog_only' => $category !== 'data']];
                }
                continue;
            }

            // Electricity, education pins and recharge PINs are visible for catalogue review only.
            // Their distinct verification/recipient/fulfilment flows must be implemented before sales.
            $items[] = ['code' => $id, 'name' => $label, 'network' => $this->network($label.' '.$id),
                'cost_minor' => null, 'selling_minor' => 1, 'service_type' => $this->serviceType($category),
                'mapping' => ['network' => $id, 'service_id' => $id, 'catalog_only' => true]];
        }
        return $items;
    }

    private function dataway(string $category): array
    {
        $settings = app(IntegrationSettings::class);
        $key = (string) $settings->get('vtu.dataway.api_key', config('services.vtu.dataway.api_key', ''));
        $base = rtrim((string) $settings->get('vtu.dataway.base_url', config('services.vtu.dataway.base_url', '')), '/');
        $this->assertCredentials($base, $key, 'Dataway');
        $http = Http::baseUrl($base)->withToken($key)->acceptJson()->connectTimeout(5)->timeout(30);
        $response = $http->get('billers/', ['category' => $category]);
        if (! $response->successful()) throw new RuntimeException('Dataway biller catalogue request failed.');
        $billers = data_get($response->json(), 'data', []);
        if (! is_array($billers)) throw new RuntimeException('Dataway returned an invalid biller catalogue.');

        $items = [];
        foreach ($billers as $biller) {
            if (! is_array($biller) || ! is_string($biller['slug'] ?? null)) continue;
            $slug = $biller['slug'];
            if ($category === 'airtime') {
                // Airtime is variable-amount; import the network mapping, not fake denominations.
                $items[] = ['code' => $slug, 'name' => ($biller['name'] ?? strtoupper($slug)).' Airtime',
                    'network' => $slug, 'cost_minor' => null, 'selling_minor' => 1,
                    'mapping' => ['network' => $slug, 'amount_based' => true]];
                continue;
            }
            $plans = $biller['plans'] ?? null;
            if (! is_array($plans)) {
                $productsResponse = $http->get('billers/'.rawurlencode($slug).'/products/');
                if (! $productsResponse->successful()) throw new RuntimeException('Dataway product catalogue failed; no import was applied.');
                $plans = data_get($productsResponse->json(), 'data', []);
            }
            if (! is_array($plans)) continue;
            foreach ($plans as $plan) {
                if (! is_array($plan)) continue;
                $code = (string) ($plan['id'] ?? $plan['code'] ?? '');
                $amount = $plan['effective_total'] ?? $plan['effective_amount'] ?? $plan['amount'] ?? null;
                if ($code === '' || ! is_numeric($amount)) continue;
                $items[] = ['code' => $slug.'-'.$code, 'name' => (string) ($plan['name'] ?? $code), 'network' => $slug,
                    'cost_minor' => (int) round((float) $amount * 100), 'validity' => (string) ($plan['validity_days'] ?? ''),
                    'data_type' => (string) ($plan['benefit_type'] ?? 'provider-defined'),
                    'mapping' => ['product_id' => $code]];
            }
        }
        return $items;
    }

    /**
     * Discover and import each provider's documented bill catalogue category.
     * Category failures are reported individually; imports never activate sales.
     *
     * @return array{provider:string,created:int,updated:int,skipped:int,categories:array<string,array<string,mixed>>,errors:array<string,string>}
     */
    public function importAll(string $provider): array
    {
        $categories = match ($provider) {
            'sogo' => ['airtime', 'data', 'electricity', 'cable_tv', 'education', 'betting', 'epin', 'esim'],
            'gsubz' => ['airtime', 'data', 'electricity', 'cable_tv', 'education', 'social-media', 'recharge-pin'],
            'dataway' => ['airtime', 'data', 'electricity', 'cable_tv', 'betting', 'education', 'exam', 'internet', 'recharge-pin', 'airtime_cash'],
            default => throw new LogicException('Choose Sogo Africa, GSUBZ, or Dataway for provider catalogue import.'),
        };

        $created = $updated = $skipped = 0;
        $results = $errors = [];
        foreach ($categories as $category) {
            try {
                $result = $this->import($provider, $category);
                $results[$category] = $result;
                $created += $result['created'];
                $updated += $result['updated'];
                $skipped += $result['skipped'];
            } catch (\\Throwable $exception) {
                report($exception);
                $errors[$category] = 'Catalogue request failed or returned an unsupported response.';
            }
        }

        return compact('provider', 'created', 'updated', 'skipped') + ['categories' => $results, 'errors' => $errors];
    }

    private function serviceType(string $category): string
    {
        return match ($category) {
            'electricity' => 'electricity-bill',
            'cable_tv' => 'tv-subscription',
            'education', 'exam' => 'education',
            'betting' => 'betting',
            'epin', 'recharge-pin' => 'recharge-pin',
            'esim', 'internet' => 'internet',
            'social-media' => 'social-media',
            'airtime_cash' => 'airtime-cash',
            default => $category,
        };
    }

    private function assertCredentials(string $base, string $key, string $provider): void
    {
        if (parse_url($base, PHP_URL_SCHEME) !== 'https' || $key === '') {
            throw new LogicException($provider.' catalogue import requires its HTTPS endpoint and server-side API key.');
        }
    }

    private function network(string $value): string
    {
        $lower = strtolower($value);
        foreach (['mtn', 'airtel', 'glo', '9mobile', 'lebara'] as $network) {
            if (str_contains($lower, $network)) return $network;
        }
        return 'other';
    }

    /** @return list<array<string, mixed>> */
    private function flattenServices(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            if (! is_array($item)) continue;
            if (isset($item['serviceID']) || isset($item['service_id'])) {
                $result[] = $item;
            } elseif (array_is_list($item)) {
                array_push($result, ...$this->flattenServices($item));
            } else {
                if (! isset($item['name']) && is_string($key)) $item['name'] = $key;
                array_push($result, ...$this->flattenServices($item));
            }
        }
        return $result;
    }
}
