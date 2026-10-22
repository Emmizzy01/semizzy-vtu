<?php

use App\Models\User;
use App\Services\Security\LoginChallengeService;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$userId = (int) ($argv[1] ?? 0);
if ($userId < 1) {
    fwrite(STDERR, "Missing user ID.\n");
    exit(2);
}

try {
    $challenge = app(LoginChallengeService::class)->replacePendingChallenge(
        User::query()->findOrFail($userId),
        false,
    );
    fwrite(STDOUT, $challenge['id']."\n");
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage()."\n");
    exit(1);
}
