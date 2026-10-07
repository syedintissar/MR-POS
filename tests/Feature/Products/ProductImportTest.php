<?php

namespace Tests\Feature\Products;

use App\Actions\Inventory\PostStockAdjustment;
use App\Actions\Products\SaveProduct;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DocumentNumberService;
use App\Services\Inventory\ProductImporter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Tests\Concerns\ActsAsRoles;
use Tests\Concerns\MakesStock;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

class ProductImportTest extends TestCase
{
    use ActsAsRoles;
    use MakesStock;
    use RefreshesSeededDatabase;

    private function csv(array $rows, string $delimiter = ',', bool $bom = false): UploadedFile
    {
        $h = fopen('php://temp', 'r+');
        if ($bom) {
            fwrite($h, "\xEF\xBB\xBF");
        }
        foreach ($rows as $r) {
            fputcsv($h, $r, $delimiter, '"', '');
        }
        rewind($h);

        return UploadedFile::fake()->createWithContent('products.csv', stream_get_contents($h));
    }

    private function preview(UploadedFile $file)
    {
        return $this->post('/products-import/preview', ['file' => $file]);
    }

    private function importFile(UploadedFile $file): void
    {
        $this->preview($file)->assertOk();
        $this->post('/products-import/commit')->assertSessionHas('success');
    }

    private const HEADER = ['sku', 'name', 'type', 'category', 'brand', 'unit', 'barcode', 'model', 'purchase_cost', 'sale_price', 'opening_stock', 'min_stock_level'];

    public function test_a_valid_file_is_previewed_then_imported_with_opening_stock_and_one_audit_entry(): void
    {
        $this->as('Manager');
        $file = $this->csv([
            self::HEADER,
            ['', 'HP 85A Toner', 'stock', 'Toners', 'HP', 'pcs', '6901234567890', 'CE285A', '3200', '4500', '12', '3'],
            ['MY-LAB', 'Repair labour', 'service', 'Services', '', 'pcs', '', '', '', '1500', '', ''],
            ['', 'Toner powder', 'stock', 'Printer Parts > Other parts', 'Generic', 'g', '', '', '2.5', '6', '1500.5', '500'],
        ]);

        $this->preview($file)->assertOk()->assertSee('Import 3 products')->assertSee('HP 85A Toner');
        $this->assertSame(0, Product::count(), 'Nothing is saved by the preview.');

        $this->post('/products-import/commit')->assertRedirect('/products')->assertSessionHas('success', fn ($m) => str_contains($m, 'Imported 3 product(s)') && str_contains($m, 'ADJ-000001'));

        $this->assertSame(3, Product::count());
        $toner = Product::query()->where('name', 'HP 85A Toner')->firstOrFail();
        $this->assertSame('P-000001', $toner->sku);
        $this->assertSame('12.000', $toner->current_stock);
        $this->assertSame('3200.0000', $toner->average_cost);
        $this->assertSame('3.000', $toner->min_stock_level);
        $this->assertSame('6901234567890', $toner->barcode);
        $this->assertSame('HP', $toner->brand->name);
        $this->assertSame('Toners', $toner->category->name);

        $service = Product::query()->where('sku', 'MY-LAB')->firstOrFail();
        $this->assertSame('service', $service->type);
        $this->assertSame('0.000', $service->current_stock);

        $powder = Product::query()->where('name', 'Toner powder')->firstOrFail();
        $this->assertSame('1500.500', $powder->current_stock);
        $this->assertSame('Other parts', $powder->category->name);
        $this->assertSame('Printer Parts', $powder->category->parent->name);

        // one opening-stock document for the whole file, one audit entry for the whole import
        $this->assertSame(1, StockAdjustment::count());
        $this->assertSame('opening', StockAdjustment::query()->firstOrFail()->reason);
        $this->assertSame(2, StockMovement::count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'products_imported')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'product_created')->count());
    }

    public function test_one_bad_row_blocks_the_whole_import_and_every_problem_is_listed_with_its_row(): void
    {
        $this->as('Manager');
        Product::factory()->create(['sku' => 'EXISTING', 'barcode' => '5550001']);
        $file = $this->csv([
            self::HEADER,
            ['OK-1', 'Good product', 'stock', 'Toners', '', 'pcs', '', '', '10', '20', '5', ''],
            ['EXISTING', 'Duplicate of database SKU', 'stock', 'Toners', '', 'pcs', '', '', '', '20', '', ''],
            ['OK-2', '', 'stock', 'Toners', '', 'pcs', '', '', '', '20', '', ''],
            ['OK-3', 'Bad unit', 'stock', 'Toners', '', 'furlong', '', '', '', '20', '', ''],
            ['OK-4', 'Bad number', 'stock', 'Toners', '', 'pcs', '', '', 'ten', '20', '', ''],
            ['OK-5', 'Negative', 'stock', 'Toners', '', 'pcs', '', '', '', '-5', '', ''],
            ['OK-1', 'Repeated in file', 'stock', 'Toners', '', 'pcs', '', '', '', '20', '', ''],
            ['OK-6', 'Fraction of a piece', 'stock', 'Toners', '', 'pcs', '', '', '', '20', '2.5', ''],
            ['OK-7', 'Barcode taken', 'stock', 'Toners', '', 'pcs', '5550001', '', '', '20', '', ''],
            ['OK-8', 'Too deep', 'stock', 'A > B > C', '', 'pcs', '', '', '', '20', '', ''],
            ['OK-9', 'Service with stock', 'service', 'Services', '', 'pcs', '', '', '', '20', '3', ''],
            ['OK-10', 'Strange type', 'gadget', 'Toners', '', 'pcs', '', '', '', '20', '', ''],
        ]);

        $html = $this->preview($file)->assertOk()->getContent();

        foreach ([
            'Row 3', 'already exists in the system', 'Row 4', 'name is empty', 'Row 5', 'Unknown unit', 'Row 6', 'not a valid number',
            'Row 7', 'cannot be negative', 'Row 8', 'appears again', 'Row 9', 'whole numbers', 'Row 10', 'already belongs to another product',
            'Row 11', 'two levels', 'Row 12', 'service item cannot have stock', 'Row 13', 'Type must be',
        ] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        $this->assertStringNotContainsString('Import 1 products', $html, 'No import button while there are problems.');

        $this->post('/products-import/commit')->assertRedirect('/products-import')->assertSessionHas('error');
        $this->assertSame(1, Product::count(), 'Nothing was imported, not even the good row.');
    }

    public function test_real_world_formatting_bom_semicolons_thousands_separators_and_header_aliases(): void
    {
        $this->as('Manager');
        $file = $this->csv([
            ['Item Name', 'Code', 'Price', 'Cost', 'Qty', 'Category'],
            ['Epson 003 Ink', 'EP-003', 'Rs. 1,200', '850.50', '4', 'Ink'],
            ['Cable VGA', 'CB-VGA', '350', '', '', ''],
        ], ';', bom: true);

        $this->importFile($file);

        $ink = Product::query()->where('sku', 'EP-003')->firstOrFail();
        $this->assertSame('1200.00', $ink->sale_price);
        $this->assertSame('4.000', $ink->current_stock);
        $this->assertSame('850.5000', $ink->average_cost);
        $this->assertSame('Ink', $ink->category->name);

        $cable = Product::query()->where('sku', 'CB-VGA')->firstOrFail();
        $this->assertSame('Other', $cable->category->name, 'A missing category falls back to Other.');
        $this->assertSame('pcs', $cable->unit->short_name, 'A missing unit means pieces.');
        $this->assertSame('0.000', $cable->current_stock);
    }

    public function test_new_categories_and_brands_are_announced_and_created(): void
    {
        $this->as('Manager');
        $file = $this->csv([
            self::HEADER,
            ['', 'Laptop battery', 'stock', 'Laptop Parts > Batteries', 'Dell', 'pcs', '', '', '', '5000', '', ''],
            ['', 'Laptop charger', 'stock', 'Laptop Parts', 'Dell', 'pcs', '', '', '', '3000', '', ''],
        ]);

        $this->preview($file)->assertSee('New categories that will be created')->assertSee('Laptop Parts &gt; Batteries', false)->assertSee('New brands that will be created')->assertSee('Dell');
        $this->post('/products-import/commit');

        $this->assertTrue(Brand::query()->where('name', 'Dell')->exists());
        $parent = Category::query()->where('name', 'Laptop Parts')->whereNull('parent_id')->firstOrFail();
        $this->assertSame(1, Category::query()->where('name', 'Laptop Parts')->count(), 'The parent is created once, not per row.');
        $this->assertSame($parent->id, Category::query()->where('name', 'Batteries')->value('parent_id'));
    }

    public function test_suppliers_are_matched_by_name_and_unknown_ones_are_an_error(): void
    {
        $this->as('Manager');
        Supplier::factory()->create(['name' => 'Al-Noor Traders', 'code' => 'SUP-X1']);
        $header = [...self::HEADER, 'supplier'];

        $this->importFile($this->csv([$header, ['', 'Matched', 'stock', 'Toners', '', 'pcs', '', '', '', '10', '', '', 'al-noor traders']]));
        $this->assertSame('Al-Noor Traders', Product::query()->where('name', 'Matched')->firstOrFail()->defaultSupplier->name);

        $this->preview($this->csv([$header, ['', 'Unmatched', 'stock', 'Toners', '', 'pcs', '', '', '', '10', '', '', 'Nobody Ltd']]))->assertSee('Unknown supplier');
    }

    public function test_an_xlsx_file_works_too(): void
    {
        $this->as('Manager');
        $path = $this->sandbox.DIRECTORY_SEPARATOR.'products.xlsx';
        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['name', 'sku', 'category', 'sale_price', 'purchase_cost', 'opening_stock']));
        $writer->addRow(Row::fromValues(['Canon 725 toner', 'CN-725', 'Toners', 4200.5, 3000, 8]));
        $writer->addRow(Row::fromValues(['USB cable', 'USB-1', 'Cables', 250, 120, 40]));
        $writer->close();

        $this->importFile(new UploadedFile($path, 'products.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true));

        $p = Product::query()->where('sku', 'CN-725')->firstOrFail();
        $this->assertSame('4200.50', $p->sale_price);
        $this->assertSame('8.000', $p->current_stock);
        $this->assertSame('3000.0000', $p->average_cost);
        $this->assertSame(2, Product::count());
    }

    public function test_unusable_files_are_refused_with_a_clear_message(): void
    {
        $this->as('Manager');

        $this->preview(UploadedFile::fake()->createWithContent('empty.csv', ''))->assertSessionHas('error');
        $this->preview(UploadedFile::fake()->createWithContent('only-header.csv', "name,price\n"))->assertSessionHas('error', fn ($m) => str_contains($m, 'no data rows'));
        $this->preview($this->csv([['sku', 'price'], ['A', '1']]))->assertSessionHas('error', fn ($m) => str_contains($m, '"name" column'));
        $this->post('/products-import/preview', ['file' => UploadedFile::fake()->create('pic.png', 10, 'image/png')])->assertSessionHasErrors('file');
        $this->post('/products-import/preview', [])->assertSessionHasErrors('file');
        $this->assertSame(0, Product::count());
    }

    public function test_unknown_columns_are_reported_and_ignored(): void
    {
        $this->as('Manager');

        $this->preview($this->csv([['name', 'sale_price', 'favourite colour'], ['Thing', '10', 'blue']]))->assertOk()->assertSee('favourite colour')->assertSee('will be ignored');
    }

    public function test_accented_or_spaced_codes_are_flagged_because_scanners_cannot_read_them(): void
    {
        $this->as('Manager');

        $html = $this->preview($this->csv([['sku', 'name', 'barcode'], ['Käse-1', 'Accent sku', ''], ['OK-1', 'Spaced barcode', '12 34']]))->assertOk()->getContent();

        $this->assertStringContainsString('has accented or unusual characters', $html);
        $this->assertStringContainsString('has spaces or unusual characters', $html);
    }

    public function test_the_import_only_adds_so_running_the_same_file_twice_is_refused_the_second_time(): void
    {
        $this->as('Manager');
        $rows = [['sku', 'name', 'sale_price'], ['ONCE-1', 'Only once', '10']];

        $this->importFile($this->csv($rows));
        $this->preview($this->csv($rows))->assertSee('already exists in the system');

        $this->assertSame(1, Product::count());
    }

    public function test_the_commit_needs_a_pending_file_and_cannot_be_replayed(): void
    {
        $this->as('Manager');

        $this->post('/products-import/commit')->assertRedirect('/products-import')->assertSessionHas('error');

        $this->importFile($this->csv([['name', 'sale_price'], ['One', '10']]));
        $this->post('/products-import/commit')->assertSessionHas('error', fn ($m) => str_contains($m, 'no longer available'));
        $this->assertSame(1, Product::count());
    }

    public function test_cancelling_or_uploading_again_removes_the_stored_file(): void
    {
        $this->as('Manager');
        $dir = config('mrpos.files_path').DIRECTORY_SEPARATOR.'imports';

        $this->preview($this->csv([['name'], ['A']]));
        $this->assertCount(1, File::files($dir));
        $this->preview($this->csv([['name'], ['B']]));
        $this->assertCount(1, File::files($dir), 'The earlier upload was discarded.');
        $this->post('/products-import/cancel')->assertRedirect('/products-import');
        $this->assertCount(0, File::files($dir));

        $this->preview($this->csv([['name'], ['C']]));
        $this->post('/products-import/commit');
        $this->assertCount(0, File::files($dir), 'A finished import leaves no file behind.');
    }

    public function test_the_data_is_checked_again_at_commit_in_case_it_changed_since_the_preview(): void
    {
        $this->as('Manager');
        $this->preview($this->csv([['sku', 'name'], ['RACE-1', 'Racer']]))->assertOk();

        Product::factory()->create(['sku' => 'RACE-1']);   // someone else takes the SKU in the meantime

        $this->post('/products-import/commit')->assertSessionHas('error');
        $this->assertSame(0, Product::query()->where('name', 'Racer')->count());
    }

    public function test_a_failure_halfway_through_the_save_leaves_nothing_behind(): void
    {
        $this->as('Manager');
        $this->preview($this->csv([['sku', 'name', 'category', 'brand', 'opening_stock'], ['OK-A', 'Fine', 'Brand New Category', 'Brand New Make', '3'], ['OK-B', 'Also fine', 'Toners', '', '']]))->assertOk();

        // The second product blows up inside the save (disk error, killed process, ...), after the first was already created.
        $this->app->bind(SaveProduct::class, function ($app) {
            return new class($app->make(DocumentNumberService::class), $app->make(AuditLogger::class), $app->make(PostStockAdjustment::class)) extends SaveProduct
            {
                private int $calls = 0;

                public function create(array $data, ?array $opening, User $by, bool $audit = true): Product
                {
                    if (++$this->calls === 2) {
                        throw new \RuntimeException('simulated failure');
                    }

                    return parent::create($data, $opening, $by, $audit);
                }
            };
        });
        $this->app->forgetInstance(ProductImporter::class);

        $this->withoutExceptionHandling();
        try {
            $this->post('/products-import/commit');
            $this->fail('The commit should have thrown.');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated failure', $e->getMessage());
        }

        $this->assertSame(0, Product::query()->whereIn('sku', ['OK-A', 'OK-B'])->count(), 'The first row must have been rolled back with the failed second one.');
        $this->assertSame(0, Category::query()->where('name', 'Brand New Category')->count(), 'Categories created for the import are rolled back too.');
        $this->assertSame(0, Brand::query()->where('name', 'Brand New Make')->count());
        $this->assertSame(0, StockAdjustment::count());
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'products_imported')->count());
    }

    public function test_someone_who_may_not_enter_stock_cannot_import_opening_stock_but_can_import_products(): void
    {
        $this->asWithOnly(['products.view', 'products.create', 'products.import']);

        $this->preview($this->csv([['name', 'sale_price', 'opening_stock'], ['With stock', '10', '5']]))->assertSee('not allowed to enter stock');
        $this->importFile($this->csv([['name', 'sale_price'], ['Without stock', '10']]));
        $this->assertSame('0.000', Product::query()->where('name', 'Without stock')->value('current_stock'));
    }

    public function test_someone_who_cannot_see_costs_gets_stock_at_zero_cost_from_an_import(): void
    {
        $this->asWithOnly(['products.view', 'products.create', 'products.import', 'inventory.adjust']);

        $this->importFile($this->csv([['name', 'sale_price', 'purchase_cost', 'opening_stock'], ['Costed', '10', '777', '5']]));

        $p = Product::query()->firstOrFail();
        $this->assertSame('5.000', $p->current_stock);
        $this->assertSame('0.0000', $p->average_cost);
    }

    public function test_only_people_with_the_import_permission_can_reach_it(): void
    {
        foreach (['Cashier', 'Technician'] as $role) {
            $this->as($role);
            $this->get('/products-import')->assertForbidden();
            $this->get('/products-import/template')->assertForbidden();
            $this->post('/products-import/preview', ['file' => $this->csv([['name'], ['x']])])->assertForbidden();
            $this->post('/products-import/commit')->assertForbidden();
        }
        $this->as('Manager')->get('/products-import')->assertOk()->assertSee('Download the template');
    }

    public function test_the_template_downloads_and_can_itself_be_imported(): void
    {
        $this->as('Manager');
        $csv = $this->get('/products-import/template')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'Excel needs the byte order mark to show accents correctly.');

        $this->importFile(UploadedFile::fake()->createWithContent('template.csv', $csv));

        $this->assertSame(3, Product::count());
        $this->assertSame('1500.500', Product::query()->where('name', 'Toner powder (refill)')->value('current_stock'));
    }

    public function test_a_very_large_file_is_refused(): void
    {
        $this->as('Manager');
        $rows = [['name']];
        for ($i = 0; $i < 5001; $i++) {
            $rows[] = ["Item {$i}"];
        }

        $this->preview($this->csv($rows))->assertSessionHas('error', fn ($m) => str_contains($m, 'more than 5000 rows'));
    }
}
