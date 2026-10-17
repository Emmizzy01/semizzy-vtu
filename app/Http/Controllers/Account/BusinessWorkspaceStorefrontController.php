<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\BusinessWorkspace;
use App\Models\BusinessWorkspaceStorefront;
use App\Models\IntegrationSettings;
use App\Models\VtuProduct;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class BusinessWorkspaceStorefrontController extends Controller
{
    private const SERVICES = ['airtime', 'data'];

    public function edit(Request $request, BusinessWorkspace $workspace): Response
    {
        $user = $request->user();
        abort_unless($workspace->type === 'reseller' && $workspace->status === 'active', 404);
        abort_unless($user && $workspace->canManageStorefront($user), 403);

        $storefront = $workspace->storefront()->firstOrCreate([], [
            'slug' => $workspace->slug,
            'display_name' => $workspace->name,
            'tagline' => null,
            'description' => null,
            'status' => 'draft',
            'enabled_services' => ['airtime', 'data'],
            'accent_color' => '#087443',
        ]);

        return Inertia::render('Workspaces/StorefrontEdit', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'storefront' => [
                'id' => $storefront->id,
                'slug' => $storefront->slug,
                'displayName' => $storefront->display_name,
                'tagline' => $storefront->tagline,
                'description' => $storefront->description,
                'status' => $storefront->status,
                'enabledServices' => $storefront->enabled_services ?? [],
                'accentColor' => $storefront->accent_color,
                'publicUrl' => route('storefronts.show', ['storefront' => $storefront->slug]),
            ],
            'availableServices' => self::SERVICES,
        ]);
    }

    public function update(Request $request, BusinessWorkspace $workspace): RedirectResponse
    {
        $user = $request->user();
        abort_unless($workspace->type === 'reseller' && $workspace->status === 'active', 404);
        abort_unless($user && $workspace->canManageStorefront($user), 403);

        $storefront = $workspace->storefront()->firstOrFail();
        $validated = $request->validate([
            'slug' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:business_workspace_storefronts,slug,' . $storefront->getKey()],
            'display_name' => ['required', 'string', 'min:2', 'max:120'],
            'tagline' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', 'string', 'in:draft,published'],
            'enabled_services' => ['required', 'array', 'min:1', 'max:2'],
            'enabled_services.*' => ['required', 'string', 'distinct', 'in:' . implode(',', self::SERVICES)],
            'accent_color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        if ($validated['status'] === 'published') {
            $hasAvailableProducts = VtuProduct::query()
                ->where('is_active', true)
                ->where('currency', 'NGN')
                ->whereIn('service_type', $validated['enabled_services'])
                ->get(['service_type', 'metadata'])
                ->contains(function (VtuProduct $product): bool {
                    return ($product->metadata['catalog_source'] ?? null) !== 'vtpass'
                        || ($product->metadata['purchase_approved'] ?? false) === true;
                });

            if (!$hasAvailableProducts) {
                return back()->withErrors(['status' => 'This storefront cannot be published until at least one selected service has an active, approved catalogue product.']);
            }

            if (!filter_var(app(IntegrationSettings::class)->get('service.airtime_data.enabled', true), FILTER_VALIDATE_BOOLEAN)) {
                return back()->withErrors(['status' => 'Airtime and data services are currently disabled platform-wide.']);
            }
        }

        $storefront->fill([
            ...$validated,
            'slug' => Str::lower($validated['slug']),
            'enabled_services' => array_values(array_unique($validated['enabled_services'])),
        ])->save();

        return back()->with('success', $storefront->status === 'published'
            ? 'Your reseller storefront is published.'
            : 'Your storefront settings were saved as a draft.');
    }

    public function show(BusinessWorkspaceStorefront $storefront): Response
    {
        abort_unless($storefront->status === 'published', 404);
        $workspace = $storefront->workspace;
        abort_unless($workspace && $workspace->status === 'active' && $workspace->type === 'reseller', 404);

        $enabledServices = array_values(array_intersect($storefront->enabled_services ?? [], self::SERVICES));
        $serviceEnabled = filter_var(app(IntegrationSettings::class)->get('service.airtime_data.enabled', true), FILTER_VALIDATE_BOOLEAN);

        $products = collect();
        if ($serviceEnabled && $enabledServices !== []) {
            $products = VtuProduct::query()
                ->where('is_active', true)
                ->where('currency', 'NGN')
                ->whereIn('service_type', $enabledServices)
                ->orderBy('service_type')
                ->orderBy('network')
                ->orderBy('price_minor')
                ->get(['code', 'service_type', 'network', 'name', 'price_minor', 'face_value_minor', 'currency', 'metadata'])
                ->filter(fn (VtuProduct $product): bool => ($product->metadata['catalog_source'] ?? null) !== 'vtpass'
                    || ($product->metadata['purchase_approved'] ?? false) === true)
                ->map(fn (VtuProduct $product): array => [
                    'code' => $product->code,
                    'serviceType' => $product->service_type,
                    'network' => $product->network,
                    'name' => $product->name,
                    'priceMinor' => $product->service_type === 'data' ? (int) $product->price_minor : null,
                    'faceValueMinor' => $product->face_value_minor,
                    'currency' => $product->currency,
                ])
                ->values();
        }

        return Inertia::render('Storefronts/Show', [
            'storefront' => [
                'slug' => $storefront->slug,
                'displayName' => $storefront->display_name,
                'tagline' => $storefront->tagline,
                'description' => $storefront->description,
                'accentColor' => $storefront->accent_color,
                'services' => $enabledServices,
                'ownerName' => $workspace->owner?->name,
            ],
            'products' => $products,
            'servicesAvailable' => $serviceEnabled && $products->isNotEmpty(),
        ]);
    }
}
