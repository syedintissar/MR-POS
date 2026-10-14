<?php

/**
 * Helper for ReturnConcurrencyTest: one of several separate PHP processes taking goods back, or sending them back to a supplier, at the same moment.
 *
 *   php return_worker.php sreturn   <start_at> <user_id> <sale_id> <qty> [refund|credit]     first line of the invoice
 *   php return_worker.php svoid     <start_at> <user_id> <sale_return_id>
 *   php return_worker.php preturn   <start_at> <user_id> <purchase_id> <qty>                first line of the bill
 *   php return_worker.php pvoid     <start_at> <user_id> <purchase_return_id>
 *   php return_worker.php voidpurchase <start_at> <user_id> <purchase_id>
 *
 * Prints exactly one line: OK:<id>, REFUSED (a business rule said no) or ERROR: <message> (anything else, such as a deadlock
 * reaching the caller, which must never happen).
 */

use App\Actions\Purchasing\ApplySupplierCredit;
use App\Actions\Purchasing\CreatePurchaseReturn;
use App\Actions\Purchasing\VoidPurchase;
use App\Actions\Purchasing\VoidPurchaseReturn;
use App\Actions\Sales\CreateSaleReturn;
use App\Actions\Sales\ExchangeSale;
use App\Actions\Sales\VoidSaleReturn;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleReturn;
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

    if ($mode === 'sreturn') {
        $sale = Sale::query()->findOrFail((int) $argv[4]);
        $item = $sale->items()->orderBy('id')->firstOrFail();
        $return = app(CreateSaleReturn::class)->execute($sale, [['sale_item_id' => $item->id, 'quantity' => $argv[5], 'condition' => 'resellable', 'restock' => true]],
            ['remainder' => $argv[6] ?? 'refund', 'refund_method_id' => PaymentMethod::query()->where('code', 'cash')->value('id')], 'concurrency test', $user);
        echo "OK:{$return->id}\n";
    } elseif ($mode === 'svoid') {
        $return = app(VoidSaleReturn::class)->execute(SaleReturn::query()->findOrFail((int) $argv[4]), 'concurrency test', $user);
        echo "OK:{$return->id}\n";
    } elseif ($mode === 'preturn') {
        $purchase = Purchase::query()->findOrFail((int) $argv[4]);
        $item = $purchase->items()->orderBy('id')->firstOrFail();
        $return = app(CreatePurchaseReturn::class)->execute([
            'purchase_id' => $purchase->id, 'lines' => [['purchase_item_id' => $item->id, 'quantity' => $argv[5]]], 'reason' => 'concurrency test',
        ], $user);
        echo "OK:{$return->id}\n";
    } elseif ($mode === 'pvoid') {
        $return = app(VoidPurchaseReturn::class)->execute(PurchaseReturn::query()->findOrFail((int) $argv[4]), 'concurrency test', $user);
        echo "OK:{$return->id}\n";
    } elseif ($mode === 'usecredit') {
        $used = app(ApplySupplierCredit::class)->execute((int) $argv[4], null, $user);
        echo 'OK:'.count($used)."\n";
    } elseif ($mode === 'exchange') {
        $sale = Sale::query()->findOrFail((int) $argv[4]);
        $item = $sale->items()->orderBy('id')->firstOrFail();
        $done = app(ExchangeSale::class)->execute($sale, [['sale_item_id' => $item->id, 'quantity' => $argv[5], 'condition' => 'resellable', 'restock' => true]],
            [['product_id' => (int) $argv[6], 'quantity' => '1']], ['mode' => 'refund'], 'concurrency test', (string) Str::uuid(), $user);
        echo "OK:{$done['return']->id}\n";
    } elseif ($mode === 'voidpurchase') {
        $purchase = app(VoidPurchase::class)->execute(Purchase::query()->findOrFail((int) $argv[4]), 'concurrency test', $user);
        echo "OK:{$purchase->id}\n";
    } else {
        echo "ERROR: unknown mode\n";
        exit(2);
    }
} catch (DomainException) {
    echo "REFUSED\n";
} catch (Throwable $e) {
    echo 'ERROR: '.get_class($e).': '.$e->getMessage()."\n";
}
