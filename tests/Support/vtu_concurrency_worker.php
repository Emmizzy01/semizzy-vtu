<?php

use App\Models\User;
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
if ($statePath === '' || ! in_array($mode, ['purchase', 'reconcile', 'open-wallet'], true)) {
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
