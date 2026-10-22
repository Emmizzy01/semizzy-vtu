<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformSettingAudit;
use App\Models\PlatformAddon;
use App\Models\VtuProduct;
use App\Services\Addons\AddonImplementationRegistry;
use App\Services\Addons\AddonLifecyclePolicy;
use App\Services\IntegrationSettings;
use App\Services\Vtu\VtpassCatalogService;
use Throwable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class VtuProductCatalogueController extends Controller
{
    public function index(Request $request, IntegrationSettings $settings): InertiaResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'service_type' => ['nullable', 'string', 'max:24', 'regex:/^(all|[a-z0-9][a-z0-9-]*)$/i'],
            'status' => ['nullable', Rule::in(['all', 'active', 'inactive'])],
            'provider_key' => ['nullable', Rule::in(['all', 'vtpass', 'sogo', 'gsubz', 'dataway', 'manual'])],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $type = (string) ($filters['service_type'] ?? 'all');
        $status = (string) ($filters['status'] ?? 'all');
        $providerKey = (string) ($filters['provider_key'] ?? 'all');

        $products = VtuProduct::query()
            ->when($search !== '', fn ($query) => $query->where(fn ($nested) => $nested
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('code', 'like', '%'.$search.'%')
                ->orWhere('network', 'like', '%'.$search.'%')))
            ->when($type !== 'all', fn ($query) => $query->where('service_type', $type))
            ->when($providerKey !== 'all', fn ($query) => $query->where('provider_key', $providerKey))
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderBy('service_type')->orderBy('network')->orderBy('price_minor')
            ->paginate(30)->withQueryString();

        return Inertia::render('Admin/VtuProducts/Index', [
            'products' => $products->getCollection()->map(fn (VtuProduct $product): array => [
                'id' => $product->id, 'service_type' => $product->service_type,
                'provider_key' => $product->provider_key, 'provider_product_code' => $product->provider_product_code,
                'provider_cost_minor' => $product->provider_cost_minor, 'data_type' => $product->data_type, 'validity' => $product->validity,
                'pricing_by_user_category' => $product->pricing_by_user_category ?? [],
                'commission_by_user_category' => $product->commission_by_user_category ?? [],
                'network' => $product->network, 'code' => $product->code, 'name' => $product->name,
                'price_minor' => $product->price_minor, 'face_value_minor' => $product->face_value_minor,
                'currency' => $product->currency, 'is_active' => $product->is_active,
                'metadata' => $product->metadata ?? [],
            ])->values(),
            'pagination' => ['currentPage' => $products->currentPage(), 'lastPage' => $products->lastPage(), 'total' => $products->total()],
            'filters' => ['search' => $search, 'service_type' => $type, 'status' => $status, 'provider_key' => $providerKey],
            'networks' => ['MTN', 'Airtel', 'Glo', '9mobile', 'Lebara'],
            'providerReadiness' => collect(['sogo' => 'Sogo Africa', 'gsubz' => 'GSUBZ', 'dataway' => 'Dataway'])
                ->mapWithKeys(function (string $name, string $provider) use ($settings): array {
                    $base = trim((string) $settings->get("vtu.{$provider}.base_url", config("services.vtu.{$provider}.base_url", '')));
                    $key = trim((string) $settings->get("vtu.{$provider}.api_key", config("services.vtu.{$provider}.api_key", '')));

                    return [$provider => [
                        'name' => $name,
                        'configured' => $key !== '' && parse_url($base, PHP_URL_SCHEME) === 'https',
                        'environment' => str_contains(strtolower($base), 'sandbox') ? 'Sandbox' : ($base !== '' ? 'Configured endpoint' : 'Endpoint missing'),
                    ]];
                }),
            'serviceTypes' => VtuProduct::query()->select('service_type')->distinct()->orderBy('service_type')->pluck('service_type')->values(),
        ]);
    }

    public function sync(Request $request, VtpassCatalogService $catalog): RedirectResponse
    {
        try {
            $products = $catalog->fetchProducts();
            $result = $catalog->applyProducts($products);
        } catch (Throwable $exception) {
            report($exception);
            return back()->withErrors(['catalog_sync' => 'Provider catalogue sync failed safely. No catalogue changes were applied. Verify the provider credentials and response.']);
        }

        PlatformSettingAudit::query()->create([
            'actor_user_id' => $request->user()->id,
            'setting_key' => 'vtu_catalogue.synced.vtpass',
            'old_value_hash' => hash('sha256', 'manual-sync'),
            'new_value_hash' => hash('sha256', json_encode($result, JSON_THROW_ON_ERROR)),
            'reason' => 'Admin initiated VTpass catalogue sync; new products remain inactive and existing prices/statuses are preserved.',
            'created_at' => now(),
        ]);
        return back()->with('success', "VTpass catalogue synced: {$result['created']} new inactive products, {$result['updated']} existing mappings refreshed.");
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedData($request, true);
        $product = DB::transaction(function () use ($request, $data): VtuProduct {
            $metadata = $this->metadata($data);
            $active = (bool) ($data['is_active'] ?? false);
            $this->assertActivatable($data['service_type'], $data['network'], $metadata, $active, $data['provider_key'] ?? null);
            $product = VtuProduct::query()->create([
                'service_type' => $data['service_type'], 'network' => trim($data['network']),
                'provider_key' => $data['provider_key'] ?? null, 'provider_product_code' => $data['provider_product_code'] ?? null,
                'provider_cost_minor' => $data['provider_cost_minor'] ?? null, 'data_type' => $data['data_type'] ?? null, 'validity' => $data['validity'] ?? null,
                'pricing_by_user_category' => $data['pricing_by_user_category'] ?? null,
                'commission_by_user_category' => $data['commission_by_user_category'] ?? null,
                'code' => trim($data['code']), 'name' => trim($data['name']),
                'price_minor' => $data['price_minor'], 'face_value_minor' => $data['face_value_minor'] ?? null,
                'currency' => 'NGN', 'is_active' => $active, 'metadata' => $metadata,
            ]);
            $this->audit($request, 'vtu_product.created.'.$product->id, null, $product->only(['service_type', 'network', 'code', 'name', 'price_minor', 'face_value_minor', 'is_active', 'metadata']), $data['reason']);
            return $product;
        }, attempts: 3);

        return back()->with('status', 'VTU catalogue product created.');
    }

    public function update(Request $request, VtuProduct $product): RedirectResponse
    {
        $data = $this->validatedData($request, false);
        DB::transaction(function () use ($request, $product, $data): void {
            $locked = VtuProduct::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            $before = $locked->only(['service_type', 'provider_key', 'provider_product_code', 'provider_cost_minor', 'data_type', 'validity', 'pricing_by_user_category', 'commission_by_user_category', 'network', 'code', 'name', 'price_minor', 'face_value_minor', 'is_active', 'metadata']);
            $metadata = $this->metadata($data, $locked->metadata ?? []);
            $active = (bool) ($data['is_active'] ?? false);
            $this->assertActivatable($locked->service_type, $locked->network, $metadata, $active, $data['provider_key'] ?? $locked->provider_key);
            $locked->forceFill([
                'name' => trim($data['name']), 'price_minor' => $data['price_minor'],
                'face_value_minor' => $data['face_value_minor'] ?? null,
                'provider_key' => $data['provider_key'] ?? $locked->provider_key,
                'provider_product_code' => $data['provider_product_code'] ?? $locked->provider_product_code,
                'provider_cost_minor' => $data['provider_cost_minor'] ?? $locked->provider_cost_minor,
                'data_type' => $data['data_type'] ?? $locked->data_type,
                'validity' => $data['validity'] ?? $locked->validity,
                'pricing_by_user_category' => $data['pricing_by_user_category'] ?? $locked->pricing_by_user_category,
                'commission_by_user_category' => $data['commission_by_user_category'] ?? $locked->commission_by_user_category,
                'is_active' => $active, 'metadata' => $metadata,
            ])->save();
            $this->audit($request, 'vtu_product.updated.'.$locked->id, $before, $locked->fresh()->only(['service_type', 'network', 'code', 'name', 'price_minor', 'face_value_minor', 'is_active', 'metadata']), $data['reason']);
        }, attempts: 3);

        return back()->with('status', 'VTU catalogue product updated and audited.');
    }

    private function validatedData(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'service_type' => [$creating ? 'required' : 'sometimes', 'string', Rule::in(['airtime', 'data', 'electricity-bill', 'tv-subscription'])],
            'provider_key' => ['nullable', 'string', Rule::in(['vtpass', 'sogo', 'gsubz', 'dataway', 'manual'])],
            'provider_product_code' => ['nullable', 'string', 'max:120'],
            'provider_cost_minor' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'pricing_by_user_category' => ['nullable', 'array'],
            'pricing_by_user_category.*' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'commission_by_user_category' => ['nullable', 'array'],
            'commission_by_user_category.*' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'network' => [$creating ? 'required' : 'sometimes', 'string', 'max:40'],
            'code' => [$creating ? 'required' : 'sometimes', 'string', 'max:80', Rule::unique('vtu_products', 'code')->ignore($request->route('product'))],
            'name' => ['required', 'string', 'max:160'],
            'price_minor' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'face_value_minor' => ['nullable', 'integer', 'min:1', 'max:1000000000'],
            'is_active' => ['required', 'boolean'],
            'purchase_approved' => ['sometimes', 'boolean'],
            'validity' => ['nullable', 'string', 'max:60'],
            'data_type' => ['nullable', 'string', 'max:60'],
            'provider_service_id' => ['nullable', 'string', 'max:80', 'regex:/^[a-zA-Z0-9-]*$/'],
            'provider_variation_code' => ['nullable', 'string', 'max:120', 'regex:/^[a-zA-Z0-9._-]*$/'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        // User tiers are canonical blueprint levels; accepting arbitrary keys such as
        // "USER" or "MERCHANT" would silently leave those prices unused by tier routing.
        foreach (['pricing_by_user_category', 'commission_by_user_category'] as $field) {
            foreach (array_keys($data[$field] ?? []) as $level) {
                if (! in_array((string) $level, ['level_1', 'level_2', 'level_3', 'level_4'], true)) {
                    throw ValidationException::withMessages([
                        $field => 'Use only Semizzy user levels: level_1, level_2, level_3, level_4.',
                    ]);
                }
            }
        }

        return $data;
    }

    private function metadata(array $data, array $existing = []): array
    {
        $purchaseApproved = array_key_exists('purchase_approved', $data)
            ? (bool) $data['purchase_approved']
            : (bool) ($existing['purchase_approved'] ?? false);
        $known = ['validity', 'data_type', 'provider_service_id', 'provider_variation_code', 'purchase_approved'];
        foreach ($known as $key) unset($existing[$key]);

        return array_merge($existing, array_filter([
            'validity' => isset($data['validity']) ? trim($data['validity']) : null,
            'data_type' => isset($data['data_type']) ? trim($data['data_type']) : null,
            'provider_service_id' => isset($data['provider_service_id']) ? trim($data['provider_service_id']) : null,
            'provider_variation_code' => isset($data['provider_variation_code']) ? trim($data['provider_variation_code']) : null,
            'purchase_approved' => $purchaseApproved,
        ], fn ($value): bool => $value !== null && $value !== ''));
    }

    private function assertActivatable(string $type, string $network, array $metadata, bool $active, ?string $providerKey = null): void
    {
        if (! $active) return;
        if (! in_array($type, ['airtime', 'data'], true)) {
            abort(422, 'This category remains catalogue-only until its dedicated purchase and recovery workflow is implemented and verified.');
        }

        // Catalogue activation is an administrative mutation that can expose a
        // product for sale. Enforce the same Core addon admission policy here,
        // not only at checkout. Disabling an addon never changes accepted orders.
        $addonKey = 'service.'.$type;
        $addon = PlatformAddon::query()->where('addon_key', $addonKey)->first();
        $implementation = app(AddonImplementationRegistry::class)->find($addonKey);
        $registered = $implementation !== null
            && in_array('wallet.vtu.orders.create', $implementation['operations'], true);
        $settings = app(IntegrationSettings::class);
        $independentFeature = $settings->get('service.'.$type.'.enabled', null);
        $legacyFeature = $settings->get('service.airtime_data.enabled', false);
        $featureEnabled = filter_var($independentFeature ?? $legacyFeature, FILTER_VALIDATE_BOOLEAN);
        $admission = app(AddonLifecyclePolicy::class)->assessAdmission(
            (string) ($addon?->status ?? 'not_installed'),
            (string) ($addon?->compatibility_status ?? 'unknown'),
            $registered,
            true,
            $featureEnabled,
        );
        abort_unless(
            $admission['available'],
            422,
            'This product cannot be activated because its addon is not accepting new purchases ('.$admission['reason'].').',
        );

        // Dataway is importable for catalogue/pricing review, but remains excluded from
        // customer purchase routing until its account-specific lookup contract is verified.
        if ($providerKey === 'dataway') {
            abort(422, 'Dataway products remain inactive for customer purchases until its transaction lookup and recovery contract is verified.');
        }

        if (in_array($providerKey, ['sogo', 'gsubz'], true)) {
            $settings = app(IntegrationSettings::class);
            $baseUrl = trim((string) $settings->get("vtu.{$providerKey}.base_url", config("services.vtu.{$providerKey}.base_url", '')));
            $apiKey = trim((string) $settings->get("vtu.{$providerKey}.api_key", config("services.vtu.{$providerKey}.api_key", '')));

            if ($apiKey === '' || parse_url($baseUrl, PHP_URL_SCHEME) !== 'https') {
                abort(422, strtoupper($providerKey).' needs a configured server-side API key and HTTPS endpoint before customer sales can be enabled.');
            }
            if (str_contains(strtolower($baseUrl), 'sandbox')) {
                abort(422, strtoupper($providerKey).' is configured for sandbox. Sandbox catalogue products cannot be activated for production customer purchases; configure the verified live endpoint and live credentials first.');
            }
            if (($metadata['purchase_approved'] ?? false) !== true) {
                abort(422, 'Verify the provider mapping and explicitly approve this product before enabling customer sales.');
            }
            $mapping = data_get($metadata, 'provider_mappings.'.$providerKey, []);
            $required = ['network'];
            if ($type === 'data') $required[] = $providerKey === 'sogo' ? 'variation_code' : 'plan_code';
            foreach ($required as $field) {
                if (! is_array($mapping) || ! isset($mapping[$field]) || trim((string) $mapping[$field]) === '') {
                    abort(422, 'The '.$providerKey.' mapping is missing required field '.$field.'.');
                }
            }
            return;
        }

        if (($metadata['catalog_source'] ?? null) === 'vtpass' && ($metadata['purchase_approved'] ?? false) !== true) {
            abort(422, 'A VTpass product cannot be activated until sandbox verification is recorded and an authorised operator approves sales.');
        }
        if ($type === 'data' && empty($metadata['provider_variation_code'])) {
            abort(422, 'A data plan cannot be activated until its provider variation code is configured.');
        }
        $knownNetwork = in_array(strtolower(trim($network)), ['mtn', 'airtel', 'glo', '9mobile', 'etisalat'], true);
        if (! $knownNetwork && empty($metadata['provider_service_id'])) {
            abort(422, 'This network needs an explicit verified provider service ID before activation.');
        }
    }

    private function audit(Request $request, string $key, mixed $before, mixed $after, string $reason): void
    {
        PlatformSettingAudit::query()->create([
            'actor_user_id' => $request->user()->id,
            'setting_key' => $key,
            'old_value_hash' => hash('sha256', json_encode($before, JSON_THROW_ON_ERROR)),
            'new_value_hash' => hash('sha256', json_encode($after, JSON_THROW_ON_ERROR)),
            'reason' => trim($reason),
            'created_at' => now(),
        ]);
    }
}
