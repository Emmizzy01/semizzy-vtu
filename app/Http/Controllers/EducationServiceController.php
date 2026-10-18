<?php

namespace App\Http\Controllers;

use App\Models\EducationServiceProduct;
use App\Models\PlatformFeatureControl;
use App\Models\PlatformSettingAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class EducationServiceController extends Controller
{
    public function index(): InertiaResponse
    {
        $feature = PlatformFeatureControl::query()->where('feature_key', 'service.education')->first();
        $available = $feature !== null && $feature->is_enabled && $feature->is_implemented;
        $products = $available
            ? EducationServiceProduct::query()->where('is_active', true)->where('purchase_approved', true)
                ->orderBy('exam_body')->orderBy('price_minor')->get()
            : collect();

        return Inertia::render('Services/Education', [
            'available' => $available,
            'products' => $products->map(fn (EducationServiceProduct $p): array => [
                'id' => $p->id, 'service_type' => $p->service_type, 'exam_body' => $p->exam_body,
                'name' => $p->name, 'code' => $p->code, 'price_minor' => $p->price_minor,
                'currency' => $p->currency, 'requires_candidate_validation' => $p->requires_candidate_validation,
            ])->values(),
            'purchaseEnabled' => false,
            'message' => 'Education purchases remain locked until an authorized provider, candidate validation, transaction lookup and recovery workflow are verified.',
        ]);
    }

    public function adminIndex(Request $request): InertiaResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'service_type' => ['nullable', Rule::in(['all', 'exam_pin', 'registration', 'verification', 'other'])],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $type = (string) ($filters['service_type'] ?? 'all');
        $products = EducationServiceProduct::query()
            ->when($search !== '', fn ($q) => $q->where(fn ($n) => $n
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('exam_body', 'like', '%'.$search.'%')
                ->orWhere('code', 'like', '%'.$search.'%')))
            ->when($type !== 'all', fn ($q) => $q->where('service_type', $type))
            ->orderBy('exam_body')->orderBy('name')->paginate(30)->withQueryString();

        $feature = PlatformFeatureControl::query()->where('feature_key', 'service.education')->first();

        return Inertia::render('Admin/EducationServices/Index', [
            'products' => $products->getCollection()->map(fn (EducationServiceProduct $p): array => [
                'id' => $p->id, 'service_type' => $p->service_type, 'exam_body' => $p->exam_body,
                'name' => $p->name, 'code' => $p->code, 'provider' => $p->provider,
                'provider_product_code' => $p->provider_product_code,
                'provider_cost_minor' => $p->provider_cost_minor, 'price_minor' => $p->price_minor,
                'currency' => $p->currency, 'requires_candidate_validation' => $p->requires_candidate_validation,
                'is_active' => $p->is_active, 'purchase_approved' => $p->purchase_approved,
            ])->values(),
            'pagination' => ['currentPage' => $products->currentPage(), 'lastPage' => $products->lastPage(), 'total' => $products->total()],
            'filters' => ['search' => $search, 'service_type' => $type],
            'feature' => $feature ? ['is_enabled' => $feature->is_enabled, 'is_implemented' => $feature->is_implemented] : ['is_enabled' => false, 'is_implemented' => false],
            'purchaseGate' => false,
            'gateReason' => 'Catalogue management only: no education purchase endpoint is enabled in this Phase 3 slice.',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateProduct($request, true);
        DB::transaction(function () use ($request, $data): void {
            $product = EducationServiceProduct::query()->create([
                ...$this->productValues($data),
                'currency' => 'NGN',
                'metadata' => ['catalogue_only' => true],
                'is_active' => false,
                'purchase_approved' => false,
            ]);
            $this->audit($request, 'education_product.created.'.$product->id, null, $product->only([
                'service_type', 'exam_body', 'name', 'code', 'provider', 'provider_product_code',
                'provider_cost_minor', 'price_minor', 'is_active', 'purchase_approved',
            ]), $data['reason']);
        }, attempts: 3);

        return back()->with('status', 'Education product saved. It remains inactive and cannot be purchased until provider verification is complete.');
    }

    public function update(Request $request, EducationServiceProduct $product): RedirectResponse
    {
        $data = $this->validateProduct($request, false);
        DB::transaction(function () use ($request, $product, $data): void {
            $locked = EducationServiceProduct::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $before = $locked->only([
                'service_type', 'exam_body', 'name', 'code', 'provider', 'provider_product_code',
                'provider_cost_minor', 'price_minor', 'is_active', 'purchase_approved',
            ]);
            $locked->forceFill([
                ...$this->productValues($data),
                'is_active' => false,
                'purchase_approved' => false,
                'metadata' => array_merge($locked->metadata ?? [], ['catalogue_only' => true]),
            ])->save();
            $this->audit($request, 'education_product.updated.'.$locked->id, $before, $locked->fresh()->only([
                'service_type', 'exam_body', 'name', 'code', 'provider', 'provider_product_code',
                'provider_cost_minor', 'price_minor', 'is_active', 'purchase_approved',
            ]), $data['reason']);
        }, attempts: 3);

        return back()->with('status', 'Education product updated; activation and purchases remain locked pending verification.');
    }

    private function validateProduct(Request $request, bool $creating): array
    {
        return $request->validate([
            'service_type' => ['required', Rule::in(['exam_pin', 'registration', 'verification', 'other'])],
            'exam_body' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:160'],
            'code' => ['required', 'string', 'max:100', Rule::unique('education_service_products', 'code')->ignore($request->route('product'))],
            'provider' => ['nullable', 'string', 'max:60'],
            'provider_product_code' => ['nullable', 'string', 'max:120'],
            'provider_cost_minor' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'price_minor' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'requires_candidate_validation' => ['sometimes', 'boolean'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);
    }

    private function productValues(array $data): array
    {
        return [
            'service_type' => $data['service_type'],
            'exam_body' => trim($data['exam_body']),
            'name' => trim($data['name']),
            'code' => trim($data['code']),
            'provider' => isset($data['provider']) ? trim($data['provider']) : null,
            'provider_product_code' => isset($data['provider_product_code']) ? trim($data['provider_product_code']) : null,
            'provider_cost_minor' => $data['provider_cost_minor'] ?? null,
            'price_minor' => $data['price_minor'],
            'requires_candidate_validation' => $data['requires_candidate_validation'] ?? true,
        ];
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
