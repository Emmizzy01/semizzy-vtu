<?php

use App\Models\User;
use App\Models\PaymentRefund;
use App\Services\Payments\PaymentRefundService;
use App\Http\Controllers\Payments\ManualDepositController;
use App\Services\Wallets\WalletLedgerService;
use Illuminate\Http\Request;
use App\Models\VtuOrder;
use App\Models\VtuProduct;
use App\Services\Vtu\VtuOrderService;
use App\Services\Wallets\WalletAccountService;
use App\Services\Vtu\VtuProvider;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\ConcurrentVtuTestProvider;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? '';
$statePath = $argv[2] ?? '';
if ($statePath === '' || ! in_array($mode, ['purchase', 'reconcile', 'open-wallet', 'review-deposit', 'refund-webhook', 'submit-deposit'], true)) {
    fwrite(STDERR, "Invalid worker arguments.\n");
    exit(2);
}

config([
    'services.vtu.name' => 'concurrency-test-provider',
    'services.vtu.endpoint' => 'https://provider.example.test/api',
    'services.vtu.token' => 'test-only-token',
    'services.vtu.driver' => 'configured',
]);

$provider = new ConcurrentVtuTestProvider($statePath);
$app->instance(VtuProvider::class, $provider);

try {
    if ($mode === 'refund-webhook') {
        $refund = PaymentRefund::query()->findOrFail((int) ($argv[3] ?? 0));
        $intent = $refund->paymentIntent()->firstOrFail();
        $providerRef = (string) ($argv[4] ?? '');
        $provider->arriveAndWait('refund_webhook_barrier', 2);
        app(PaymentRefundService::class)->applyPaystackEvent('refund.failed', [
            'data' => [
                'status' => 'failed',
                'transaction_reference' => $intent->reference,
                'refund_reference' => $providerRef,
                'amount' => $refund->amount_minor,
                'currency' => $intent->currency,
            ],
        ]);
        fwrite(STDOUT, "processed\\n");
        exit(0);
    }
    if ($mode === 'submit-deposit') {
        $user = User::query()->findOrFail((int) ($argv[3] ?? 0));
        $wallet = \App\Models\Wallet::query()
            ->where('user_id', $user->getKey())
            ->where('currency', 'NGN')
            ->firstOrFail();
        $amountMinor = (int) ($argv[4] ?? 0);
        $transferReference = (string) ($argv[5] ?? '');
        $evidenceHash = (string) ($argv[6] ?? '');
        $evidencePath = (string) ($argv[7] ?? '');
        $provider->arriveAndWait('deposit_evidence_barrier', 2);
        $deposit = app(\App\Services\Payments\ManualDepositSubmissionService::class)->submit(
            $user,
            $wallet,
            $amountMinor,
            $transferReference,
            $evidencePath,
            $evidenceHash,
        );
        fwrite(STDOUT, $deposit->duplicate_evidence ? "duplicate\n" : "first\n");
        exit(0);
    }
    if ($mode === 'review-deposit') {
        $admin = User::query()->findOrFail((int) ($argv[3] ?? 0));
        $publicId = (string) ($argv[4] ?? '');
        $request = Request::create('/admin/manual-deposits/'.$publicId.'/review', 'POST', [
            'decision' => 'approve',
            'reason' => 'Verified bank transfer concurrency test',
        ]);
        $request->setUserResolver(fn () => $admin);
        $provider->arriveAndWait('deposit_review_barrier', 2);
        app(ManualDepositController::class)->review($request, $publicId, app(WalletLedgerService::class));
        fwrite(STDOUT, "approved\\n");
        exit(0);
    }

    $service = app(VtuOrderService::class);

    if ($mode === 'purchase') {
        $user = User::query()->findOrFail((int) ($argv[3] ?? 0));
        $productCode = (string) ($argv[4] ?? '');
        $idempotencyKey = (string) ($argv[5] ?? '');
        $provider->arriveAndWait('purchase_barrier', 2);
        $order = $service->purchase($user, $productCode, '08012345678', $idempotencyKey);
    } elseif ($mode === 'reconcile') {
        $order = VtuOrder::query()->findOrFail((int) ($argv[3] ?? 0));
        $order = $service->reconcile($order);
        fwrite(STDOUT, $order->status."\n");
        exit(0);
    } else {
        $user = User::query()->findOrFail((int) ($argv[3] ?? 0));
        $provider->arriveAndWait('wallet_open_barrier', 2);
        $wallet = app(WalletAccountService::class)->openForUser($user);
        fwrite(STDOUT, $wallet->getKey()."\n");
        exit(0);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage()."\n");
    exit(1);
}
