<?php

use App\Models\User;
use App\Services\Payments\PaystackClient;
use App\Exceptions\Wallet\IdempotencyConflict;
use App\Services\Wallets\BankPayoutService;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$mode, $statePath, $userId, $beneficiaryId, $amount, $key] = array_pad(array_slice($argv, 1), 6, '');
if ($mode !== 'request' || $statePath === '' || $userId === '' || $beneficiaryId === '' || $amount === '' || $key === '') {
    fwrite(STDERR, "Invalid payout concurrency worker arguments.\n");
    exit(2);
}

$paystack = Mockery::mock(PaystackClient::class);
$paystack->shouldReceive('mode')->andReturn('test');
$paystack->shouldReceive('initiateBankTransfer')->once()->andReturnUsing(
    static function (int $amountMinor, string $recipient, string $reference, string $reason) use ($statePath): array {
        $handle = fopen($statePath, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Could not open shared provider submission counter.');
        }
        try {
            flock($handle, LOCK_EX);
            $raw = stream_get_contents($handle);
            $state = json_decode($raw === false ? '{}' : $raw, true);
            $state = is_array($state) ? $state : [];
            $state['submissions'] = (int) ($state['submissions'] ?? 0) + 1;
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR));
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        // Widen the overlap window after the DB transaction has committed.
        usleep(100000);
        return ['reference' => $reference, 'transfer_code' => 'TRF_concurrent_payout', 'status' => 'pending'];
    }
);
$app->instance(PaystackClient::class, $paystack);

try {
    $user = User::query()->findOrFail((int) $userId);
    $payout = app(BankPayoutService::class)->requestPayout($user, (int) $beneficiaryId, (int) $amount, $key);
    fwrite(STDOUT, $payout->public_id."\n");
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage()."\n");
    exit($exception instanceof IdempotencyConflict ? 21 : 1);
}
