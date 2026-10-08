<?php

/**
 * Writes random sales and what the PHP SaleCalculator makes of them, for tests/js/pos-calc.test.mjs to compare with the browser maths.
 * Usage: php tests/js/gen_parity_cases.php <output.json> [count]
 */

use App\Services\Sales\SaleCalculator;

require __DIR__.'/../../vendor/autoload.php';

$out = $argv[1] ?? 'php://stdout';
$count = (int) ($argv[2] ?? 3000);
$calc = new SaleCalculator;
mt_srand(5150);

$money = fn (int $min, int $max) => number_format(mt_rand($min, $max) / 100, 2, '.', '');
$cases = [];
for ($n = 0; $n < $count; $n++) {
    $lines = [];
    for ($i = 0, $k = mt_rand(1, 6); $i < $k; $i++) {
        $line = ['quantity' => mt_rand(0, 3) === 0 ? number_format(mt_rand(1, 12000) / 1000, 3, '.', '') : (string) mt_rand(1, 20), 'unit_price' => $money(0, 2500000)];
        $kind = mt_rand(0, 3);
        if ($kind === 1) {
            $line['discount_percent'] = number_format(mt_rand(1, 3500) / 100, 2, '.', '');
        } elseif ($kind === 2) {
            $line['discount_amount'] = $money(1, 5000);
        }
        $line['tax_rate'] = ['0', '5', '17', '18', '16.50', '2.25'][mt_rand(0, 5)];
        $lines[] = $line;
    }

    $options = ['tax_inclusive' => (bool) mt_rand(0, 1), 'round_to' => [0, 1, 5, 10][mt_rand(0, 3)]];
    $kind = mt_rand(0, 2);
    if ($kind === 1) {
        $options['discount_percent'] = number_format(mt_rand(1, 3000) / 100, 2, '.', '');
    } elseif ($kind === 2) {
        $options['discount_amount'] = $money(1, 20000);
    }

    try {
        $result = $calc->calculate($lines, $options);
    } catch (InvalidArgumentException) {
        continue;   // a discount larger than the goods: the browser clamps those, the server refuses them; not compared
    }

    $cases[] = ['lines' => $lines, 'options' => $options, 'expected' => $result];
}

file_put_contents($out, json_encode($cases));
echo count($cases)." cases written\n";
