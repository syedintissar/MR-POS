<?php

namespace Tests\Feature;

use App\Models\DocumentSequence;
use App\Services\DocumentNumberService;
use Database\Seeders\DocumentSequenceSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

class DocumentNumberTest extends TestCase
{
    use RefreshesSeededDatabase;

    private function next(string $type): string
    {
        return DB::transaction(fn () => app(DocumentNumberService::class)->next($type));
    }

    public function test_numbers_are_sequential_and_padded(): void
    {
        $this->assertSame('INV-000001', $this->next('sale'));
        $this->assertSame('INV-000002', $this->next('sale'));
        $this->assertSame('JOB-000001', $this->next('service_job'), 'Each document type has its own counter.');
    }

    public function test_a_rolled_back_document_returns_its_number_so_there_are_no_gaps(): void
    {
        $this->assertSame('PUR-000001', $this->next('purchase'));

        try {
            DB::transaction(function () {
                $n = app(DocumentNumberService::class)->next('purchase');
                $this->assertSame('PUR-000002', $n);
                throw new \RuntimeException('posting failed after the number was taken');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame('PUR-000002', $this->next('purchase'), 'The number from the failed posting must be reused.');
    }

    public function test_a_custom_prefix_and_padding_are_used(): void
    {
        DocumentSequence::where('doc_type', 'expense')->update(['prefix' => 'MPT-E', 'padding' => 4]);

        $this->assertSame('MPT-E0001', $this->next('expense'));
    }

    public function test_yearly_reset_restarts_at_one_and_shows_the_year(): void
    {
        DocumentSequence::where('doc_type', 'sale_return')->update([
            'reset_yearly' => true, 'current_year' => (int) now()->format('Y') - 1, 'next_number' => 57,
        ]);

        $this->assertSame('SRN-'.now()->format('y').'-000001', $this->next('sale_return'));
        $this->assertSame('SRN-'.now()->format('y').'-000002', $this->next('sale_return'));
    }

    public function test_an_unknown_document_type_fails_loudly(): void
    {
        $this->expectException(ModelNotFoundException::class);
        $this->next('does_not_exist');
    }

    public function test_reseeding_never_resets_a_running_counter_or_a_custom_prefix(): void
    {
        $this->next('stock_adjustment');
        $this->next('stock_adjustment');
        DocumentSequence::where('doc_type', 'stock_adjustment')->update(['prefix' => 'ST-']);

        $this->seed(DocumentSequenceSeeder::class);

        $this->assertSame('ST-000003', $this->next('stock_adjustment'));
    }
}
