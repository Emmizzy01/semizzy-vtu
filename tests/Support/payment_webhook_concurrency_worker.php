<?php

use App\Services\Payments\PaymentFundingService;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

if ($provider === 'paystack') {\n    // The isolated CI worker opts into the test-only live settlement path; no real gateway is called.\n    putenv('SEMIZZY_LIVE_FUNDING_ENABLED=true');\n    $_ENV['SEMIZZY_LIVE_FUNDING_ENABLED'] = 'true';\n    $_SERVER['SEMIZZY_LIVE_FUNDING_ENABLED'] = 'true';\n}\n\n$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$statePath = $argv[1] ?? '';
$eventId = $argv[2] ?? '';
$reference = $argv[3] ?? '';
$transactionId = $argv[4] ?? '';
$amount = filter_var($argv[5] ?? null, FILTER_VALIDATE_INT);\n$provider = $argv[6] ?? 'semizzy';

if ($statePath === '' || $eventId === '' || $reference === '' || $transactionId === '' || $amount === false || ! in_array($provider, ['semizzy', 'paystack'], true)) {
    fwrite(STDERR, "Invalid payment webhook worker arguments.\n");
    exit(2);
}

$deadline = microtime(true) + 15;
do {
    $state = json_decode((string) file_get_contents($statePath), true);
    if (is_array($state) && ($state['start'] ?? false) === true) {
        break;
    }
    usleep(10000);
} while (microtime(true) < $deadline);

if (! is_array($state) || ($state['start'] ?? false) !== true) {
    fwrite(STDERR, "Timed out waiting for the payment webhook concurrency barrier.\n");
    exit(90);
}

// Give both workers time to leave the barrier together before entering the settlement transaction.
usleep(25000);

try {
    $result = app(PaymentFundingService::class)->processVerifiedEvent($provider, [
        'id' => $eventId,
        'type' => 'payment.succeeded',
        'data' => [
            'reference' => $reference,
            'transaction_id' => $transactionId,
            'status' => 'succeeded',
            'amount_minor' => (int) $amount,
            'currency' => 'NGN',
        ],
    ]);

    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR)."\n");
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage()."\n");
    exit(1);
}
