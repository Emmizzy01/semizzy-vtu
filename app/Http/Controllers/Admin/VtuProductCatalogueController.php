<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformSettingAudit;
use App\Models\VtuProduct;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class VtuProductCatalogueController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'service_type' => ['nullable', Rule::in(['all', 'airtime', 'data'])],
            'status' => ['nullable', Rule::in(['all', 'active', 'inactive'])],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $type = (string) ($filters['service_type'] ?? 'all');
        $status = (string) ($filters['status'] ?? 'all');

        $products = VtuProduct::query()
            ->when($search !== '', fn ($query) => $query->where(fn ($nested) => $nested
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('code', 'like', '%'.$search.'%')
                ->orWhere('network', 'like', '%'.$search.'%')))
            ->when($type !== 'all', fn ($query) => $query->where('service_type', $type))
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderBy('service_type')->orderBy('network')->orderBy('price_minor')
            ->paginate(30)->withQueryString();

        return Inertia::render('Admin/VtuProducts/Index', [
            'products' => $products->getCollection()->map(fn (VtuProduct $product): array => [
                'id' => $product->id, 'service_type' => $product->service_type,
                'network' => $product->network, 'code' => $product->code, 'name' => $product->name,
                'price_minor' => $product->price_minor, 'face_value_minor' => $product->face_value_minor,
                'currency' => $product->currency, 'is_active' => $product->is_active,
                'metadata' => $product->metadata ?? [],
            ])->values(),
            'pagination' => ['currentPage' => $products->currentPage(), 'lastPage' => $products->lastPage(), 'total' => $products->total()],
            'filters' => ['search' => $search, 'service_type' => $type, 'status' => $status],
            'networks' => ['MTN', 'Airtel', 'Glo', '9mobile', 'Lebara'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedData($request, true);
        $product = DB::transaction(function () use ($request, $data): VtuProduct {
            $metadata = $this->metadata($data, $locked->metadata ?? []);
            $active = (bool) ($data['is_active'] ?? false);
            $this->assertActivatable($data['service_type'], $data['network'], $metadata, $active);
            $product = VtuProduct::query()->create([
                'service_type' => $data['service_type'], 'network' => trim($data['network']),
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
            $before = $locked->only(['service_type', 'network', 'code', 'name', 'price_minor', 'face_value_minor', 'is_active', 'metadata']);
            $metadata = $this->metadata($data);
            $active = (bool) ($data['is_active'] ?? false);
            $this->assertActivatable($locked->service_type, $locked->network, $metadata, $active);
            $locked->forceFill([
                'name' => trim($data['name']), 'price_minor' => $data['price_minor'],
                'face_value_minor' => $data['face_value_minor'] ?? null,
                'is_active' => $active, 'metadata' => $metadata,
            ])->save();
            $this->audit($request, 'vtu_product.updated.'.$locked->id, $before, $locked->fresh()->only(['service_type', 'network', 'code', 'name', 'price_minor', 'face_value_minor', 'is_active', 'metadata']), $data['reason']);
        }, attempts: 3);

        return back()->with('status', 'VTU catalogue product updated and audited.');
    }

    private function validatedData(Request $request, bool $creating): array
    {
        return $request->validate([
            'service_type' => [$creating ? 'required' : 'sometimes', 'string', Rule::in(['airtime', 'data'])],
            'network' => [$creating ? 'required' : 'sometimes', 'string', 'max:40'],
            'code' => [$creating ? 'required' : 'sometimes', 'string', 'max:80', Rule::unique('vtu_products', 'code')->ignore($request->route('product'))],
            'name' => ['required', 'string', 'max:160'],
            'price_minor' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'face_value_minor' => ['nullable', 'integer', 'min:1', 'max:1000000000'],
            'is_active' => ['required', 'boolean'],
            'validity' => ['nullable', 'string', 'max:60'],
            'data_type' => ['nullable', 'string', 'max:60'],
            'provider_service_id' => ['nullable', 'string', 'max:80', 'regex:/^[a-zA-Z0-9-]*$/'],
            'provider_variation_code' => ['nullable', 'string', 'max:120', 'regex:/^[a-zA-Z0-9._-]*$/'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);
    }

    private function metadata(array $data, array $existing = []): array
    {
        $known = ['validity', 'data_type', 'provider_service_id', 'provider_variation_code'];
        foreach ($known as $key) unset($existing[$key]);
        return array_merge($existing, array_filter([
            'validity' => isset($data['validity']) ? trim($data['validity']) : null,
            'data_type' => isset($data['data_type']) ? trim($data['data_type']) : null,
            'provider_service_id' => isset($data['provider_service_id']) ? trim($data['provider_service_id']) : null,
            'provider_variation_code' => isset($data['provider_variation_code']) ? trim($data['provider_variation_code']) : null,
        ], fn ($value): bool => $value !== null && $value !== ''));
    }

    private function assertActivatable(string $type, string $network, array $metadata, bool $active): void
    {
        if (! $active) return;
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
