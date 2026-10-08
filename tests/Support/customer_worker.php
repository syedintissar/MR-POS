<?php

/**
 * Helper for CustomerConcurrencyTest: one of several separate PHP processes working on customer money at the same moment.
 *
 *   php customer_worker.php credit  <start_at> <user_id> <customer_id> <paid_now> <method_id> <product_id>:<qty> ...
 *   php customer_worker.php collect <start_at> <user_id> <customer_id> <method_id> <amount> [auto|advance]
 *   php customer_worker.php voidsale <start_at> <user_id> <sale_id>
 *   php customer_worker.php voidpay  <start_at> <user_id> <payment_id>
 *
 * Prints exactly one line: OK:<id>, REFUSED (a business rule said no), APPROVAL (a manager would have to approve) or
 * ERROR: <message> (anything else, such as a deadlock reaching the caller, which must never happen).
 */

use App\Actions\Customers\PostCustomerPayment;
use App\Actions\Customers\VoidCustomerPayment;
use App\Actions\Sales\CompleteSale;
use App\Actions\Sales\VoidSale;
use App\Exceptions\ApprovalRequiredException;
use App\Exceptions\InsufficientStockException;
use App\Models\CustomerPayment;
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

    if ($mode === 'credit') {
        $lines = [];
        foreach (array_slice($argv, 7) as $pair) {
            [$productId, $qty] = explode(':', $pair);
            $lines[] = ['product_id' => (int) $productId, 'quantity' => $qty];
        }
        $paidNow = $argv[5];
        $sale = app(CompleteSale::class)->execute([
            'idempotency_key' => (string) Str::uuid(),
            'customer_id' => (int) $argv[4],
            'lines' => $lines,
            'payments' => $paidNow === '0' ? [] : [['payment_method_id' => (int) $argv[6], 'amount' => $paidNow]],
        ], $user);
        echo "OK:{$sale->id}\n";
    } elseif ($mode === 'collect') {
        $payment = app(PostCustomerPayment::class)->execute([
            'customer_id' => (int) $argv[4], 'payment_method_id' => (int) $argv[5], 'amount' => $argv[6], 'allocation' => $argv[7] ?? 'auto',
        ], $user);
        echo "OK:{$payment->id}\n";
    } elseif ($mode === 'voidsale') {
        $sale = app(VoidSale::class)->execute(Sale::query()->findOrFail((int) $argv[4]), 'concurrency test', $user);
        echo "OK:{$sale->id}\n";
    } elseif ($mode === 'voidpay') {
        $payment = app(VoidCustomerPayment::class)->execute(CustomerPayment::query()->findOrFail((int) $argv[4]), 'concurrency test', $user);
        echo "OK:{$payment->id}\n";
    } else {
        echo "ERROR: unknown mode\n";
        exit(2);
    }
} catch (ApprovalRequiredException) {
    echo "APPROVAL\n";
} catch (InsufficientStockException|DomainException) {
    echo "REFUSED\n";
} catch (Throwable $e) {
    echo 'ERROR: '.get_class($e).': '.$e->getMessage()."\n";
}
