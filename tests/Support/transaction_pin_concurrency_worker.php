<?php

use App\Models\User;
use App\Services\Security\TransactionPinService;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$mode, $gatePath, $userId, $currentPin, $newPin] = array_pad(array_slice($argv, 1), 5, '');
if ($mode !== 'change' || $gatePath === '' || $userId === '' || $currentPin === '' || $newPin === '') {
    fwrite(STDERR, "Invalid transaction PIN worker arguments.\n");
    exit(2);
}

$deadline = microtime(true) + 30;
do {
    $gate = @file_get_contents($gatePath);
    if (trim((string) $gate) === 'go') {
        break;
    }
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, "Timed out waiting for the PIN concurrency gate.\n");
        exit(3);
    }
    usleep(1000);
} while (true);

try {
    $user = User::query()->findOrFail((int) $userId);
    $result = app(TransactionPinService::class)->change($user, $newPin, $currentPin);
    fwrite(STDOUT, json_encode(['result' => $result], JSON_THROW_ON_ERROR)."\n");
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage()."\n");
    exit(1);
}
