<?php

/**
 * Helper for PurchaseConcurrencyTest: one of several separate PHP processes working on the same supplier account at the same moment.
 *
 *   php purchase_worker.php receive <start_at> <purchase_id>
 *   php purchase_worker.php void    <start_at> <purchase_id>
 *   php purchase_worker.php pay     <start_at> <supplier_id> <payment_method_id> <amount> <auto|manual> [<purchase_id>]
 *   php purchase_worker.php voidpay <start_at> <payment_id>
 *
 * Prints exactly one line: OK, REFUSED (a business rule said no: already received, over-allocated ...) or ERROR: <message>
 * (anything else, such as a deadlock reaching the caller, which must never happen).
 */

use App\Actions\Purchasing\PostSupplierPayment;
use App\Actions\Purchasing\ReceivePurchase;
use App\Actions\Purchasing\VoidPurchase;
use App\Actions\Purchasing\VoidSupplierPayment;
use App\Models\Purchase;
use App\Models\SupplierPayment;
use App\Models\User;
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
    $user = User::query()->firstOrFail();
    while (microtime(true) < $startAt) {
        usleep(500);
    }

    if ($mode === 'receive') {
        app(ReceivePurchase::class)->execute(Purchase::query()->findOrFail((int) $argv[3]), $user);
    } elseif ($mode === 'void') {
        app(VoidPurchase::class)->execute(Purchase::query()->findOrFail((int) $argv[3]), 'concurrency test', $user);
    } elseif ($mode === 'pay') {
        [, , , $supplierId, $methodId, $amount, $allocation] = $argv;
        $purchaseId = $argv[7] ?? null;
        app(PostSupplierPayment::class)->execute([
            'supplier_id' => (int) $supplierId, 'payment_method_id' => (int) $methodId, 'amount' => $amount, 'allocation' => $allocation,
            'allocations' => $purchaseId ? [(int) $purchaseId => $amount] : [],
        ], $user);
    } elseif ($mode === 'voidpay') {
        app(VoidSupplierPayment::class)->execute(SupplierPayment::query()->findOrFail((int) $argv[3]), 'concurrency test', $user);
    } else {
        echo "ERROR: unknown mode\n";
        exit(2);
    }
    echo "OK\n";
} catch (DomainException) {
    echo "REFUSED\n";
} catch (Throwable $e) {
    echo 'ERROR: '.get_class($e).': '.$e->getMessage()."\n";
}
