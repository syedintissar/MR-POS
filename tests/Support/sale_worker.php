<?php

/**
 * Helper for SaleConcurrencyTest: one of several separate PHP processes ringing up sales at the same moment.
 *
 *   php sale_worker.php sell <start_at> <user_id> <key|auto> <payment_method_id> <product_id>:<qty> [<product_id>:<qty> ...]
 *   php sale_worker.php void <start_at> <user_id> <sale_id>
 *
 * Prints exactly one line: OK:<sale id>, REFUSED (a business rule said no: not enough stock, already cancelled ...) or
 * ERROR: <message> (anything else, such as a deadlock reaching the caller, which must never happen).
 */

use App\Actions\Sales\CompleteSale;
use App\Actions\Sales\VoidSale;
use App\Exceptions\InsufficientStockException;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

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
    while (microtime(true) < $startAt) {
        usleep(500);
    }

    if ($mode === 'sell') {
        [, , , , $key, $methodId] = $argv;
        $lines = [];
        foreach (array_slice($argv, 6) as $pair) {
            [$productId, $qty] = explode(':', $pair);
            $lines[] = ['product_id' => (int) $productId, 'quantity' => $qty];
        }

        $sale = app(CompleteSale::class)->execute([
            'idempotency_key' => $key === 'auto' ? (string) Str::uuid() : $key,
            'lines' => $lines,
            'payments' => [['payment_method_id' => (int) $methodId, 'amount' => '1000000']],
        ], $user);
        echo "OK:{$sale->id}\n";
    } elseif ($mode === 'void') {
        $sale = app(VoidSale::class)->execute(Sale::query()->findOrFail((int) $argv[4]), 'concurrency test', $user);
        echo "OK:{$sale->id}\n";
    } else {
        echo "ERROR: unknown mode\n";
        exit(2);
    }
} catch (InsufficientStockException|DomainException) {
    echo "REFUSED\n";
} catch (Throwable $e) {
    echo 'ERROR: '.get_class($e).': '.$e->getMessage()."\n";
}
