<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Services\Payments\UserVirtualAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use LogicException;

class UserVirtualAccountController extends Controller
{
    public function index(Request $request, UserVirtualAccountService $accounts): InertiaResponse
    {
        return Inertia::render('Wallet/VirtualAccounts', [
            'accounts' => array_map(fn ($account) => [
                'id' => $account->public_id,
                'bank_name' => $account->bank_name,
                'account_name' => $account->account_name,
                'account_number' => $account->account_number,
                'status' => $account->status,
                'currency' => $account->currency,
            ], $accounts->accountsFor($request->user())),
            'provisioningEnabled' => (bool) config('services.semizzy_virtual_accounts.enabled', false),
        ]);
    }

    public function provision(Request $request, UserVirtualAccountService $accounts): JsonResponse
    {
        $data = $request->validate([
            'preferred_bank' => ['required', 'in:wema-bank,titan-paystack'],
            'consent' => ['required', 'accepted'],
        ]);

        try {
            $account = $accounts->provisionPaystackAccount($request->user(), $data['preferred_bank'], (bool) $data['consent']);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (LogicException $exception) {
            report($exception);
            return response()->json(['message' => $exception->getMessage()], 503);
        }

        return response()->json(['data' => [
            'id' => $account->public_id,
            'bank_name' => $account->bank_name,
            'account_name' => $account->account_name,
            'account_number' => $account->account_number,
            'status' => $account->status,
            'currency' => $account->currency,
        ]], 201);
    }
}
