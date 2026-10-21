<?php

use App\Models\User;
use App\Services\Payments\PaymentRefundService;
use App\Services\Payments\PaystackClient;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$statePath, $refundPublicId, $approverId] = array_pad(array_slice($argv, 1), 3, '');
if ($statePath === '' || $refundPublicId === '' || $approverId === '') {
    fwrite(STDERR, "Invalid refund concurrency worker arguments.\n");
    exit(2);
}

$paystack = Mockery::mock(PaystackClient::class);
$paystack->shouldReceive('refund')->zeroOrMoreTimes()->andReturnUsing(
    static function (...$args) use ($statePath): array {
        $handle = fopen($statePath, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Could not open shared provider submission counter.');
        }
        try {
            flock($handle, LOCK_EX);
            rewind($handle);
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

        // Hold the provider call open to overlap the competing approval request.
        usleep(150000);
        return ['id' => 543210, 'status' => 'pending'];
    }
);
$app->instance(PaystackClient::class, $paystack);

try {
    $approver = User::query()->findOrFail((int) $approverId);
    app(PaymentRefundService::class)->approve($refundPublicId, $approver);
    fwrite(STDOUT, "approved\n");
    exit(0);
} catch (Symfony\Component\HttpKernel\Exception\ConflictHttpException) {
    fwrite(STDOUT, "already-approved\n");
    exit(21);
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage()."\n");
    exit(1);
}
