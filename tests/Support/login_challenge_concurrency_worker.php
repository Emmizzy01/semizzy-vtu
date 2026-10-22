<?php

use App\Services\Auth\LoginChallengeVerificationService;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$challengeId, $code] = array_pad(array_slice($argv, 1), 2, '');
if ($challengeId === '' || $code === '') {
    fwrite(STDERR, "Missing challenge ID or code.\n");
    exit(2);
}

try {
    $result = app(LoginChallengeVerificationService::class)->verify($challengeId, $code);
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR)."\n");
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage()."\n");
    exit(1);
}
