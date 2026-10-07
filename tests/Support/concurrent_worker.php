<?php

/**
 * Helper for ConcurrencyTest: one of several separate PHP processes hammering the same product at the same time.
 *
 *   php concurrent_worker.php sale   <product_id> <qty> <hold_seconds> <start_at_microtime>
 *   php concurrent_worker.php adjust <start_at_microtime> <qty> <product_id> [<product_id> ...]
 *
 * Prints exactly one line: OK, REFUSED (not enough stock) or ERROR: <message>.
 */

use App\Actions\Inventory\PostStockAdjustment;
use App\Exceptions\InsufficientStockException;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (config('database.connections.'.config('database.default').'.database') !== 'mrpos_test') {
    echo "ERROR: refusing to run outside the mrpos_test schema\n";
    exit(2);
}

$mode = $argv[1] ?? '';

try {
    if ($mode === 'sale') {
        [, , $productId, $qty, $hold, $startAt] = $argv;
        while (microtime(true) < (float) $startAt) {
            usleep(500);
        }

        DB::transaction(function () use ($productId, $qty, $hold) {
            app(InventoryService::class)->move((int) $productId, '-'.$qty, 'sale');
            // Keep the row lock for a moment, as a slow sale would, so the other processes really have to queue.
            usleep((int) ((float) $hold * 1_000_000));
        });
        echo "OK\n";
    } elseif ($mode === 'adjust') {
        $startAt = (float) $argv[2];
        $qty = $argv[3];
        $ids = array_slice($argv, 4);
        while (microtime(true) < $startAt) {
            usleep(500);
        }

        $user = User::query()->firstOrFail();
        app(PostStockAdjustment::class)->execute([
            'reason' => 'found',
            'lines' => array_map(fn ($id) => ['product_id' => (int) $id, 'quantity' => $qty], $ids),
        ], $user);
        echo "OK\n";
    } else {
        echo "ERROR: unknown mode\n";
        exit(2);
    }
} catch (InsufficientStockException) {
    echo "REFUSED\n";
} catch (Throwable $e) {
    echo 'ERROR: '.get_class($e).': '.$e->getMessage()."\n";
}
