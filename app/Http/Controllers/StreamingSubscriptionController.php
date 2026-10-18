<?php

namespace App\Http\Controllers;

use App\Models\PlatformFeatureControl;
use App\Models\PlatformSettingAudit;
use App\Models\StreamingSubscriptionProduct;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class StreamingSubscriptionController extends Controller
{
    public function index(): InertiaResponse
    {
        $feature = PlatformFeatureControl::query()->where('feature_key', 'service.streaming_subscriptions')->first();
        $available = $feature !== null && $feature->is_enabled && $feature->is_implemented;
        $products = $available
            ? StreamingSubscriptionProduct::query()->where('is_active', true)->where('purchase_approved', true)->orderBy('platform')->orderBy('price_minor')->get()
            : collect();

        return Inertia::render('Services/StreamingSubscriptions', [
            'available' => $available,
            'products' => $products->map(fn (StreamingSubscriptionProduct $product): array => [
                'id' => $product->id, 'platform' => $product->platform, 'name' => $product->name,
                'code' => $product->code, 'duration' => $product->duration,
                'price_minor' => $product->price_minor, 'currency' => $product->currency,
            ])->values(),
        ]);
    }

    public function adminIndex(Request $request): InertiaResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['all', 'active', 'inactive'])],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $status = (string) ($filters['status'] ?? 'all');
        $products = StreamingSubscriptionProduct::query()
            ->when($search !== '', fn ($q) => $q->where(fn ($n) => $n->where('platform', 'like', '%'.$search.'%')->orWhere('name', 'like', '%'.$search.'%')->orWhere('code', 'like', '%'.$search.'%')))
            ->when($status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('platform')->orderBy('price_minor')->paginate(30)->withQueryString();

        return Inertia::render('Admin/StreamingSubscriptions/Index', [
            'products' => $products->getCollection()->map(fn (StreamingSubscriptionProduct $p): array => [
                'id' => $p->id, 'platform' => $p->platform, 'name' => $p->name, 'code' => $p->code,
                'provider' => $p->provider, 'provider_product_code' => $p->provider_product_code,
                'duration' => $p->duration, 'price_minor' => $p->price_minor, 'currency' => $p->currency,
                'metadata' => $p->metadata ?? [], 'is_active' => $p->is_active,
                'purchase_approved' => $p->purchase_approved,
            ])->values(),
            'pagination' => ['currentPage' => $products->currentPage(), 'lastPage' => $products->lastPage(), 'total' => $products->total()],
            'filters' => ['search' => $search, 'status' => $status],
            'purchaseGate' => false,
            'gateReason' => 'Purchases are locked until a provider contract, lookup/recovery behavior and end-to-end tests are verified.',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateProduct($request, true);
        DB::transaction(function () use ($request, $data): void {
            $product = StreamingSubscriptionProduct::query()->create([
                'platform' => trim($data['platform']), 'name' => trim($data['name']),
                'code' => trim($data['code']), 'provider' => isset($data['provider']) ? trim($data['provider']) : null,
                'provider_product_code' => isset($data['provider_product_code']) ? trim($data['provider_product_code']) : null,
                'duration' => isset($data['duration']) ? trim($data['duration']) : null,
                'price_minor' => $data['price_minor'], 'currency' => 'NGN',
                'metadata' => ['catalogue_only' => true], 'is_active' => false, 'purchase_approved' => false,
            ]);
            $this->audit($request, 'streaming_product.created.'.$product->id, null, $product->only(['platform','name','code','provider','provider_product_code','duration','price_minor']), $data['reason']);
        }, attempts: 3);

        return back()->with('status', 'Streaming plan saved to the catalogue. It remains inactive until provider integration and recovery tests pass.');
    }

    public function update(Request $request, StreamingSubscriptionProduct $product): RedirectResponse
    {
        $data = $this->validateProduct($request, false);
        DB::transaction(function () use ($request, $product, $data): void {
            $locked = StreamingSubscriptionProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $before = $locked->only(['platform','name','code','provider','provider_product_code','duration','price_minor','is_active','purchase_approved']);
            $locked->forceFill([
                'platform' => trim($data['platform']), 'name' => trim($data['name']),
                'provider' => isset($data['provider']) ? trim($data['provider']) : null,
                'provider_product_code' => isset($data['provider_product_code']) ? trim($data['provider_product_code']) : null,
                'duration' => isset($data['duration']) ? trim($data['duration']) : null,
                'price_minor' => $data['price_minor'],
                // This bulk action deliberately cannot activate purchases without a verified adapter.
                'is_active' => false, 'purchase_approved' => false,
                'metadata' => array_merge($locked->metadata ?? [], ['catalogue_only' => true]),
            ])->save();
            $this->audit($request, 'streaming_product.updated.'.$locked->id, $before, $locked->fresh()->only(['platform','name','code','provider','provider_product_code','duration','price_minor','is_active','purchase_approved']), $data['reason']);
        }, attempts: 3);

        return back()->with('status', 'Streaming plan updated; activation remains locked pending provider verification.');
    }

    private function validateProduct(Request $request, bool $creating): array
    {
        return $request->validate([
            'platform' => ['required','string','max:60'],
            'name' => ['required','string','max:160'],
            'code' => [$creating ? 'required' : 'sometimes','string','max:100',Rule::unique('streaming_subscription_products','code')->ignore($request->route('product'))],
            'provider' => ['nullable','string','max:60'],
            'provider_product_code' => ['nullable','string','max:120'],
            'duration' => ['nullable','string','max:80'],
            'price_minor' => ['required','integer','min:1','max:1000000000'],
            'reason' => ['required','string','min:8','max:500'],
        ]);
    }

    private function audit(Request $request, string $key, mixed $before, mixed $after, string $reason): void
    {
        PlatformSettingAudit::query()->create([
            'actor_user_id' => $request->user()->id, 'setting_key' => $key,
            'old_value_hash' => hash('sha256', json_encode($before, JSON_THROW_ON_ERROR)),
            'new_value_hash' => hash('sha256', json_encode($after, JSON_THROW_ON_ERROR)),
            'reason' => trim($reason), 'created_at' => now(),
        ]);
    }
}
