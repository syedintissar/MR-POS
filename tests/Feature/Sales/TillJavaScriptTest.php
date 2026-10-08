<?php

namespace Tests\Feature\Sales;

use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

/**
 * The till's browser code (resources/js/pos.js and pos-calc.js) has its own tests in tests/js, run with Node's built-in
 * test runner. This runs them with the rest of the suite, together with a comparison of the browser's arithmetic against
 * the PHP SaleCalculator on thousands of random sales.
 */
class TillJavaScriptTest extends TestCase
{
    public function test_the_tills_browser_code_passes_its_tests_and_agrees_with_the_php_calculator(): void
    {
        $node = (new ExecutableFinder)->find('node');
        if ($node === null) {
            $this->markTestSkipped('Node.js is not installed on this computer, so the till\'s browser tests were not run.');
        }

        $cases = storage_path('framework/testing/parity_cases.json');
        @mkdir(dirname($cases), 0775, true);

        $gen = Process::path(base_path())->timeout(120)->run([PHP_BINARY, 'tests/js/gen_parity_cases.php', $cases, '2500']);
        $this->assertTrue($gen->successful(), 'Could not generate the comparison cases: '.$gen->errorOutput().$gen->output());

        $run = Process::path(base_path())->timeout(240)->env(['PARITY_CASES' => $cases])->run([$node, '--test', '--test-reporter=tap', 'tests/js/pos-calc.test.mjs', 'tests/js/pos-app.test.mjs']);   // TAP: the same wording on every Node version
        @unlink($cases);

        $this->assertTrue($run->successful(), "The till's browser tests failed:\n".$run->output().$run->errorOutput());
        $this->assertMatchesRegularExpression('/# pass (\d+)/', $run->output());
        $this->assertStringContainsString('# fail 0', $run->output());
        $this->assertStringNotContainsString('# skipped 1', $run->output(), 'The comparison with the PHP calculator must really run.');
    }
}
