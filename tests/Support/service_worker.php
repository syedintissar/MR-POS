<?php

/**
 * Helper for ServiceConcurrencyTest: one of several separate PHP processes working on service jobs at the same moment.
 *
 *   php service_worker.php part     <start_at> <user_id> <job_id> <product_id> <qty>
 *   php service_worker.php status   <start_at> <user_id> <job_id> <to_status>
 *   php service_worker.php deliver  <start_at> <user_id> <job_id> <method_id> <amount>      (amount 0 = pay nothing now)
 *   php service_worker.php cancel   <start_at> <user_id> <job_id>
 *   php service_worker.php advance  <start_at> <user_id> <job_id> <method_id> <amount>
 *
 * Prints exactly one line: OK:<id>, REFUSED (a business rule or a permission said no) or ERROR: <message> (anything else, such as a
 * deadlock reaching the caller, which must never happen).
 */

use App\Actions\Service\AddServicePart;
use App\Actions\Service\CancelServiceJob;
use App\Actions\Service\ChangeServiceJobStatus;
use App\Actions\Service\DeliverServiceJob;
use App\Actions\Service\TakeServiceAdvance;
use App\Exceptions\InsufficientStockException;
use App\Models\ServiceJob;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (config('database.connections.'.config('database.default').'.database') !== 'mrpos_test') {
    echo "ERROR: refusing to run outside the mrpos_test schema\n";
    exit(2);
}

$mode = $argv[1] ?? '';
$startAt = (float) ($argv[2] ?? 0);

try {
    $user = User::query()->findOrFail((int) ($argv[3] ?? 0));
    $job = ServiceJob::query()->findOrFail((int) ($argv[4] ?? 0));
    while (microtime(true) < $startAt) {
        usleep(500);
    }

    if ($mode === 'part') {
        $item = app(AddServicePart::class)->execute($job, ['product_id' => (int) $argv[5], 'quantity' => $argv[6]], $user);
        echo "OK:{$item->id}\n";
    } elseif ($mode === 'status') {
        $after = app(ChangeServiceJobStatus::class)->execute($job, $argv[5], $user);
        echo "OK:{$after->id}\n";
    } elseif ($mode === 'deliver') {
        $payments = $argv[6] === '0' ? [] : [['payment_method_id' => (int) $argv[5], 'amount' => $argv[6]]];
        $after = app(DeliverServiceJob::class)->execute($job, $payments, $user);
        echo "OK:{$after->id}\n";
    } elseif ($mode === 'cancel') {
        $after = app(CancelServiceJob::class)->execute($job, 'concurrency test', $user);
        echo "OK:{$after->id}\n";
    } elseif ($mode === 'advance') {
        $payment = app(TakeServiceAdvance::class)->execute($job, ['payment_method_id' => (int) $argv[5], 'amount' => $argv[6]], $user);
        echo "OK:{$payment->id}\n";
    } else {
        echo "ERROR: unknown mode\n";
        exit(2);
    }
} catch (InsufficientStockException|DomainException|AuthorizationException) {
    echo "REFUSED\n";
} catch (Throwable $e) {
    echo 'ERROR: '.get_class($e).': '.$e->getMessage()."\n";
}
