<?php

use App\Models\EducationOrder;
use App\Models\User;
use App\Services\Education\EducationOrderCoordinator;
use App\Services\Addons\AddonImplementationRegistry;
use App\Http\Controllers\EducationServiceController;
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

// This independent-process harness explicitly registers a test-only purchase
// operation so it can exercise coordinator concurrency without enabling the
// production education purchase operation in the real addon registry.
$app->instance(AddonImplementationRegistry::class, new class extends AddonImplementationRegistry {
    public function find(string $addonKey): ?array
    {
        if ($addonKey === 'service.education') {
            return [
                'handler' => EducationServiceController::class,
                'operations' => ['education-services.purchase', 'education-services.delivery'],
            ];
        }

        return parent::find($addonKey);
    }
});

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
