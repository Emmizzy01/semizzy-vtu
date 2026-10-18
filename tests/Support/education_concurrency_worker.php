<?php

use App\Models\EducationOrder;
use App\Models\User;
use App\Services\Education\EducationOrderCoordinator;
use Tests\Support\ConcurrentEducationTestProvider;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? '';
$statePath = $argv[2] ?? '';
if ($statePath === '' || ! in_array($mode, ['purchase', 'reconcile'], true)) {
    fwrite(STDERR, "Invalid education worker arguments.\n");
    exit(2);
}

$provider = new ConcurrentEducationTestProvider($statePath);
$app->instance('education.providers.education-concurrency-test', $provider);

try {
    $coordinator = app(EducationOrderCoordinator::class);

    if ($mode === 'purchase') {
        $user = User::query()->findOrFail((int) ($argv[3] ?? 0));
        $productCode = (string) ($argv[4] ?? '');
        $idempotencyKey = (string) ($argv[5] ?? '');
        $order = $coordinator->purchase($user, $productCode, $idempotencyKey, [
            'candidate_number' => '1234567890',
        ]);
    } else {
        $order = EducationOrder::query()->findOrFail((int) ($argv[3] ?? 0));
        $order = $coordinator->reconcile($order);
    }

    fwrite(STDOUT, $order->status."\n");
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage()."\n");
    exit(1);
}
