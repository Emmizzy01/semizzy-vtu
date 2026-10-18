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
     * Import only catalogue data supported by the current airtime/data purchase domain.
     * Imported rows are always inactive and unapproved; import never enables purchases.
     *
     * @return array{provider:string,category:string,created:int,updated:int,skipped:int}
     */
    public function import(string $provider, string $category): array
    {
        if (! in_array($provider, ['sogo', 'gsubz', 'dataway'], true)) {
            throw new LogicException('Choose Sogo Africa, GSUBZ, or Dataway for provider catalogue import.');
        }
        if (! in_array($category, ['airtime', 'data'], true)) {
            throw new LogicException('This import currently supports Airtime and Data only; other categories need their own purchase and recovery workflow.');
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
                'service_type' => $category,
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
                foreach (config('services.vtu.airtime_denominations', [100, 200, 500, 1000]) as $amount) {
                    $items[] = ['code' => $network['slug'].'-'.$amount, 'name' => ($network['name'] ?? strtoupper($network['slug'])).' Airtime ₦'.$amount,
                        'network' => $network['slug'], 'cost_minor' => null, 'selling_minor' => (int) $amount * 100,
                        'mapping' => ['network' => $network['slug']]];
                }
                continue;
            }
            $plansResponse = $http->get('bills/data-plans', ['network' => $network['slug']]);
            if (! $plansResponse->successful()) throw new RuntimeException('Sogo data-plan catalogue failed; no import was applied.');
            $plans = data_get($plansResponse->json(), 'data.plans', data_get($plansResponse->json(), 'data', []));
            if (! is_array($plans)) continue;
            foreach ($plans as $plan) {
                if (! is_array($plan)) continue;
                $code = $plan['variation_code'] ?? $plan['code'] ?? null;
                $amount = $plan['amount'] ?? $plan['price'] ?? null;
                if (! is_string($code) || ! is_numeric($amount)) continue;
                $items[] = ['code' => $network['slug'].'-'.$code, 'name' => (string) ($plan['name'] ?? $plan['label'] ?? $code),
                    'network' => $network['slug'], 'cost_minor' => (int) round((float) $amount * 100),
                    'data_type' => (string) ($plan['type'] ?? 'provider-defined'),
                    'validity' => (string) ($plan['validity'] ?? $plan['duration'] ?? ''),
                    'mapping' => ['network' => $network['slug'], 'variation_code' => $code]];
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
            $isAirtime = str_contains(strtolower($label.' '.$id), 'airtime')
                || in_array(strtolower($id), ['mtn', 'airtel', 'glo', '9mobile', 'etisalat', 'lebara'], true);
            $isData = str_contains(strtolower($label.' '.$id), 'data') || preg_match('/mtn|airtel|glo|9mobile/i', $id);
            if (($category === 'airtime' && ! $isAirtime) || ($category === 'data' && ! $isData)) continue;
            if ($category === 'airtime') {
                foreach (config('services.vtu.airtime_denominations', [100, 200, 500, 1000]) as $amount) {
                    $items[] = ['code' => $id.'-'.$amount, 'name' => $label.' ₦'.$amount, 'network' => $this->network($label.' '.$id),
                        'cost_minor' => null, 'selling_minor' => (int) $amount * 100, 'mapping' => ['network' => $id]];
                }
                continue;
            }
            $plansResponse = $http->get('api/plans/', ['service' => $id]);
            if (! $plansResponse->successful()) throw new RuntimeException('GSUBZ plan catalogue failed; no import was applied.');
            $plans = data_get($plansResponse->json(), 'data', $plansResponse->json());
            if (! is_array($plans)) continue;
            foreach ($plans as $plan) {
                if (! is_array($plan)) continue;
                $code = (string) ($plan['plan'] ?? $plan['value'] ?? $plan['PlanName'] ?? '');
                $amount = $plan['api_price'] ?? $plan['price'] ?? null;
                if ($code === '' || ! is_numeric($amount)) continue;
                $items[] = ['code' => $id.'-'.$code, 'name' => (string) ($plan['name'] ?? $plan['PlanName'] ?? $code),
                    'network' => $this->network($label.' '.$id), 'cost_minor' => (int) round((float) $amount * 100),
                    'data_type' => $id, 'validity' => (string) ($plan['validity'] ?? ''),
                    'mapping' => ['network' => $id, 'plan_code' => $code]];
            }
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
                foreach (config('services.vtu.airtime_denominations', [100, 200, 500, 1000]) as $amount) {
                    $items[] = ['code' => $slug.'-'.$amount, 'name' => ($biller['name'] ?? strtoupper($slug)).' ₦'.$amount,
                        'network' => $slug, 'cost_minor' => null, 'selling_minor' => (int) $amount * 100, 'mapping' => ['network' => $slug]];
                }
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
