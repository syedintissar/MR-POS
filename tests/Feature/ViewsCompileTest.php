<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Every Blade template must compile to valid PHP. A mistake in a page that no other test happens to open
 * (for example mixing @php(...) and @php ... @endphp in one file) would otherwise only show up as a blank error page in the shop.
 */
class ViewsCompileTest extends TestCase
{
    public function test_every_blade_view_compiles_to_valid_php(): void
    {
        $files = collect(File::allFiles(resource_path('views')))->filter(fn ($f) => str_ends_with($f->getFilename(), '.blade.php'));
        $this->assertGreaterThan(25, $files->count());

        $broken = [];
        foreach ($files as $file) {
            try {
                token_get_all(Blade::compileString(file_get_contents($file->getRealPath())), TOKEN_PARSE);
            } catch (\Throwable $e) {
                $broken[] = str_replace(resource_path('views').DIRECTORY_SEPARATOR, '', $file->getRealPath()).': '.$e->getMessage();
            }
        }

        $this->assertSame([], $broken, "Views with syntax errors:\n".implode("\n", $broken));
    }

    public function test_no_view_mixes_the_inline_and_block_php_directives_in_the_wrong_order(): void
    {
        // Blade pairs the FIRST "@php" with the first "@endphp", so an inline "@php(...)" before a block silently swallows markup.
        $risky = [];
        foreach (File::allFiles(resource_path('views')) as $file) {
            $src = file_get_contents($file->getRealPath());
            $block = preg_match('/^\s*@php\s*$/m', $src, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : null;
            $inline = preg_match('/@php\(/', $src, $m2, PREG_OFFSET_CAPTURE) ? $m2[0][1] : null;
            if ($block !== null && $inline !== null && $inline < $block) {
                $risky[] = $file->getFilename();
            }
        }

        $this->assertSame([], $risky);
    }
}
