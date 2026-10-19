<?php

namespace App\Http\Controllers\Wallet;

use App\Exceptions\Wallet\IdempotencyConflict;
use App\Http\Controllers\Controller;
use App\Models\BankBeneficiary;
use App\Models\BankPayout;
use App\Services\Security\TransactionPinService;
use App\Services\Wallets\BankPayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use InvalidArgumentException;
use LogicException;

class BankPayoutController extends Controller
{
    public function page(): InertiaResponse
    {
        return Inertia::render('Wallet/BankTransfer');
    }

    public function banks(BankPayoutService $service): JsonResponse
    {
        try {
            $banks = $service->banks();
        } catch (LogicException $exception) {
            report($exception);
            return response()->json(['message' => 'Bank directory is unavailable right now.'], 503);
        }

        return response()->json(['data' => array_map(static fn (array $bank) => [
            'name' => $bank['name'], 'code' => $bank['code'],
        ], $banks)]);
    }

    public function beneficiaries(Request $request, BankPayoutService $service): JsonResponse
    {
        return response()->json(['data' => $service->beneficiaries($request->user())->map(fn (BankBeneficiary $item) => $this->beneficiaryData($item))]);
    }

    public function storeBeneficiary(Request $request, BankPayoutService $service): JsonResponse
    {
        $data = $request->validate([
            'bank_code' => ['required', 'string', 'max:20'],
            'account_number' => ['required', 'regex:/^\d{10}$/'],
        ]);

        try {
            $beneficiary = $service->addBeneficiary($request->user(), $data['bank_code'], $data['account_number']);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (LogicException $exception) {
            report($exception);
            return response()->json(['message' => $exception->getMessage()], 503);
        }

        return response()->json(['data' => $this->beneficiaryData($beneficiary)], 201);
    }

    public function destroyBeneficiary(Request $request, int $beneficiary, BankPayoutService $service): JsonResponse
    {
        try {
            $service->removeBeneficiary($request->user(), $beneficiary);
        } catch (LogicException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['message' => 'Saved bank account removed.']);
    }

    public function payouts(Request $request, BankPayoutService $service): JsonResponse
    {
        $page = $service->history($request->user());

        return response()->json([
            'data' => $page->getCollection()->map(fn (BankPayout $payout) => $this->payoutData($payout))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function storePayout(Request $request, TransactionPinService $pins, BankPayoutService $service): JsonResponse
    {
        $data = $request->validate([
            'beneficiary_id' => ['required', 'integer', 'min:1'],
            'amount_minor' => ['required', 'integer', 'min:100', 'max:500000000'],
            'transaction_pin' => ['required', 'digits:4'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:128'],
        ]);

        $pinStatus = $pins->verify($request->user(), $data['transaction_pin']);
        if ($pinStatus !== 'verified') {
            return response()->json(['message' => match ($pinStatus) {
                'not_configured' => 'Set up your transaction PIN before making a bank transfer.',
                'locked' => 'Transaction PIN is temporarily locked. Try again later.',
                default => 'Transaction PIN is incorrect.',
            }], 422);
        }

        try {
            $payout = $service->requestPayout($request->user(), (int) $data['beneficiary_id'], (int) $data['amount_minor'], $data['idempotency_key']);
        } catch (IdempotencyConflict $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (LogicException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }

        $status = in_array($payout->status, ['reconciliation_required'], true) ? 202 : 201;
        return response()->json(['data' => $this->payoutData($payout), 'message' => $this->statusMessage($payout->status)], $status);
    }

    private function beneficiaryData(BankBeneficiary $item): array
    {
        return [
            'id' => $item->id,
            'bank_name' => $item->bank_name,
            'account_name' => $item->account_name,
            'account_number_masked' => '******'. $item->account_number_last4,
            'verification_status' => $item->verification_status,
        ];
    }

    private function payoutData(BankPayout $payout): array
    {
        return [
            'id' => $payout->public_id,
            'bank_name' => $payout->beneficiary?->bank_name,
            'account_name' => $payout->beneficiary?->account_name,
            'account_number_masked' => $payout->beneficiary ? '******'.$payout->beneficiary->account_number_last4 : null,
            'amount_minor' => $payout->amount_minor,
            'fee_minor' => $payout->fee_minor,
            'currency' => $payout->currency,
            'status' => $payout->status,
            'reference' => $payout->provider_reference,
            'created_at' => $payout->created_at?->toISOString(),
        ];
    }

    private function statusMessage(string $status): string
    {
        return match ($status) {
            'succeeded' => 'The payout provider reported success.',
            'provider_pending' => 'Your bank transfer is pending provider confirmation.',
            'reconciliation_required' => 'The transfer outcome needs reconciliation. Do not submit it again.',
            default => 'Bank transfer request recorded.',
        };
    }
}
