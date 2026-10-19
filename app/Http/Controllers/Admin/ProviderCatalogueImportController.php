<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformSettingAudit;
use App\Services\Vtu\ProviderCatalogueImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use LogicException;
use Throwable;

class ProviderCatalogueImportController extends Controller
{
    public function import(Request $request, string $provider, ProviderCatalogueImportService $catalogue): RedirectResponse
    {
        $data = $request->validate([
            'category' => ['required', 'in:all,airtime,data,electricity,cable,cable_tv,education,betting,epin,esim,social-media,recharge-pin,rechargepin,internet,exam,airtime_cash'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        try {
            $result = $data['category'] === 'all'\n                ? $catalogue->importAll($provider)\n                : $catalogue->import($provider, $data['category']);
        } catch (LogicException $exception) {
            return back()->withErrors(['catalog_import' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);
            return back()->withErrors(['catalog_import' => 'Provider catalogue import failed safely. No purchase was submitted. Check provider credentials and the provider response.']);
        }

        PlatformSettingAudit::query()->create([
            'actor_user_id' => $request->user()->id,
            'setting_key' => 'vtu_catalogue.imported.'.$provider.'.'.$data['category'],
            'old_value_hash' => hash('sha256', 'manual-import'),
            'new_value_hash' => hash('sha256', json_encode($result, JSON_THROW_ON_ERROR)),
            'reason' => trim($data['reason']),
            'created_at' => now(),
        ]);

        $message = strtoupper($provider).' '.$data['category'].' catalogue import finished: '.$result['created'].' new inactive products, '.$result['updated'].' provider records refreshed, '.$result['skipped'].' skipped.';
        if (! empty($result['errors'])) {
            $message .= ' Some categories failed safely: '.implode(', ', array_keys($result['errors'])).'. No purchases were submitted.';
        }
        return back()->with('success', $message);
    }
}
