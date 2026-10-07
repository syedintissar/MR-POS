<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Actions\Inventory\PostStockAdjustment;
use App\Actions\Products\SaveProduct;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Decimal;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Bulk import of products (and their opening stock) from a CSV or XLSX file.
 *
 * Safe by design: the whole file is checked first and shown as a preview. Nothing is saved unless every row is valid,
 * and the save itself is one database transaction, so a half-imported catalogue cannot happen.
 */
class ProductImporter
{
    public const MAX_ROWS = 5000;

    /** canonical column => accepted header spellings (after lowercasing and replacing spaces/dashes by "_") */
    public const COLUMNS = [
        'sku' => ['sku', 'code', 'item_code', 'product_code'],
        'name' => ['name', 'product', 'product_name', 'item', 'item_name', 'description_short'],
        'type' => ['type', 'product_type'],
        'category' => ['category', 'group'],
        'brand' => ['brand', 'make', 'manufacturer'],
        'unit' => ['unit', 'uom'],
        'barcode' => ['barcode', 'bar_code', 'ean', 'upc'],
        'model' => ['model', 'model_no', 'model_number'],
        'description' => ['description', 'details'],
        'purchase_cost' => ['purchase_cost', 'purchase_price', 'cost', 'cost_price', 'buying_price'],
        'sale_price' => ['sale_price', 'price', 'selling_price', 'retail_price', 'sales_price'],
        'wholesale_price' => ['wholesale_price', 'wholesale'],
        'min_sale_price' => ['min_sale_price', 'minimum_price', 'min_price'],
        'opening_stock' => ['opening_stock', 'stock', 'qty', 'quantity', 'opening_qty', 'current_stock'],
        'min_stock_level' => ['min_stock_level', 'min_stock', 'reorder_level', 'minimum_stock'],
        'rack' => ['rack', 'rack_location', 'location', 'shelf'],
        'warranty_months' => ['warranty_months', 'warranty'],
        'supplier' => ['supplier', 'vendor'],
        'tax_rate' => ['tax_rate', 'tax', 'gst'],
    ];

    public function __construct(
        private readonly SaveProduct $products,
        private readonly PostStockAdjustment $adjustments,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Read the file into raw rows keyed by canonical column names.
     *
     * @return array{rows: list<array{line: int, raw: array<string, string>}>, unknown_columns: list<string>}
     *
     * @throws InvalidArgumentException when the file is unreadable or has no usable header
     */
    public function parse(string $path, string $extension): array
    {
        $table = strtolower($extension) === 'xlsx' ? $this->readXlsx($path) : $this->readCsv($path);
        if (count($table) < 2) {
            throw new InvalidArgumentException('The file has no data rows. The first row must contain the column names.');
        }

        $header = array_shift($table);
        $map = [];
        $unknown = [];
        foreach ($header as $i => $h) {
            $norm = trim(preg_replace('/[\s\-]+/', '_', mb_strtolower(trim((string) $h))), '_');
            if ($norm === '') {
                continue;
            }
            $canonical = null;
            foreach (self::COLUMNS as $col => $aliases) {
                if (in_array($norm, $aliases, true)) {
                    $canonical = $col;
                    break;
                }
            }
            if ($canonical === null) {
                $unknown[] = trim((string) $h);
            } elseif (! in_array($canonical, $map, true)) {
                $map[$i] = $canonical;
            }
        }

        if (! in_array('name', $map, true)) {
            throw new InvalidArgumentException('No "name" column was found in the first row. Download the template to see the expected columns.');
        }
        if (count($table) > self::MAX_ROWS) {
            throw new InvalidArgumentException('The file has more than '.self::MAX_ROWS.' rows. Please split it into smaller files.');
        }

        $rows = [];
        foreach ($table as $n => $cells) {
            $raw = [];
            foreach ($map as $i => $col) {
                $raw[$col] = trim((string) ($cells[$i] ?? ''));
            }
            if (implode('', $raw) === '') {
                continue; // completely empty line
            }
            $rows[] = ['line' => $n + 2, 'raw' => $raw];   // +2: header is line 1
        }

        return ['rows' => $rows, 'unknown_columns' => $unknown];
    }

    /**
     * Check every row against the database and against the other rows.
     *
     * @param  list<array{line: int, raw: array<string, string>}>  $rows
     * @return array{rows: list<array<string, mixed>>, errors: list<array{line: int, message: string}>, new_categories: list<string>, new_brands: list<string>, notes: list<string>}
     */
    public function validate(array $rows, User $by): array
    {
        $units = Unit::query()->get();
        $unitByKey = [];
        foreach ($units as $u) {
            $unitByKey[mb_strtolower($u->name)] = $u;
            $unitByKey[mb_strtolower($u->short_name)] = $u;
        }
        $categories = Category::query()->get();
        $brands = Brand::query()->pluck('id', 'name')->mapWithKeys(fn ($id, $name) => [mb_strtolower($name) => $id]);
        $suppliers = Supplier::query()->get()->flatMap(fn ($s) => array_filter([mb_strtolower($s->name) => $s->id, mb_strtolower((string) $s->company) => $s->id, mb_strtolower($s->code) => $s->id]));

        $existingSkus = Product::query()->withTrashed()->pluck('sku')->map(fn ($s) => mb_strtolower($s))->flip();
        $existingBarcodes = Product::query()->withTrashed()->whereNotNull('barcode')->pluck('barcode')->map(fn ($s) => mb_strtolower($s))->flip();

        $seenSku = [];
        $seenBarcode = [];
        $errors = [];
        $out = [];
        $newCategories = [];
        $newBrands = [];
        $noCategory = 0;
        $canOpen = $by->can('inventory.adjust');

        foreach ($rows as $r) {
            $line = $r['line'];
            $raw = $r['raw'];
            $rowErrors = [];
            $err = function (string $m) use (&$rowErrors) {
                $rowErrors[] = $m;
            };

            $name = $raw['name'] ?? '';
            if ($name === '') {
                $err('The name is empty.');
            } elseif (mb_strlen($name) > 200) {
                $err('The name is longer than 200 characters.');
            }

            // type
            $type = mb_strtolower($raw['type'] ?? '');
            $type = $type === '' ? 'stock' : $type;
            if (! in_array($type, ['stock', 'service'], true)) {
                $err('Type must be "stock" or "service" (or left empty for stock).');
                $type = 'stock';
            }

            // unit
            $unitKey = mb_strtolower($raw['unit'] ?? '') ?: 'pcs';
            $unit = $unitByKey[$unitKey] ?? null;
            if (! $unit) {
                $err("Unknown unit \"{$raw['unit']}\". Add it under Catalogue → Units first.");
            }

            // category: "Printer Parts > Drums", "Toners", or empty
            $catPath = trim((string) ($raw['category'] ?? ''));
            $categoryId = null;
            $categoryLabel = $catPath;
            if ($catPath === '') {
                $noCategory++;
                $catPath = 'Other';
                $categoryLabel = 'Other';
            }
            $parts = array_values(array_filter(array_map('trim', preg_split('/\s*>\s*/', $catPath)), fn ($p) => $p !== ''));
            if (count($parts) > 2) {
                $err('A category can have at most two levels ("Main > Sub").');
            } elseif (count($parts) >= 1) {
                $parent = $categories->first(fn ($c) => $c->parent_id === null && mb_strtolower($c->name) === mb_strtolower($parts[0]));
                if (count($parts) === 1) {
                    if ($parent) {
                        $categoryId = $parent->id;
                    } else {
                        $newCategories[mb_strtolower($parts[0])] = $parts[0];
                    }
                } else {
                    $child = $parent ? $categories->first(fn ($c) => (int) $c->parent_id === $parent->id && mb_strtolower($c->name) === mb_strtolower($parts[1])) : null;
                    if ($child) {
                        $categoryId = $child->id;
                    } else {
                        $newCategories[mb_strtolower($parts[0].' > '.$parts[1])] = $parts[0].' > '.$parts[1];
                    }
                }
            }

            // brand
            $brand = trim((string) ($raw['brand'] ?? ''));
            if ($brand !== '' && ! $brands->has(mb_strtolower($brand))) {
                $newBrands[mb_strtolower($brand)] = $brand;
            }

            // supplier
            $supplierId = null;
            if (($raw['supplier'] ?? '') !== '') {
                $supplierId = $suppliers[mb_strtolower($raw['supplier'])] ?? null;
                if (! $supplierId) {
                    $err("Unknown supplier \"{$raw['supplier']}\". Add the supplier first.");
                }
            }

            // numbers
            $num = [];
            foreach (['purchase_cost', 'sale_price', 'wholesale_price', 'min_sale_price', 'opening_stock', 'min_stock_level', 'tax_rate', 'warranty_months'] as $col) {
                $v = $this->number($raw[$col] ?? '');
                if ($v === false) {
                    $err("\"{$raw[$col]}\" in column {$col} is not a valid number.");
                    $v = null;
                } elseif ($v !== null && Decimal::isNegative($v)) {
                    $err("Column {$col} cannot be negative.");
                    $v = null;
                }
                $num[$col] = $v;
            }
            if ($num['tax_rate'] !== null && Decimal::cmp($num['tax_rate'], '100') > 0) {
                $err('Tax rate cannot be above 100.');
            }
            if ($num['warranty_months'] !== null && ! Decimal::isWhole($num['warranty_months'])) {
                $err('Warranty months must be a whole number.');
            }
            if ($num['sale_price'] === null) {
                $num['sale_price'] = '0';
            }
            if ($num['min_sale_price'] !== null && Decimal::cmp($num['min_sale_price'], $num['sale_price']) > 0) {
                $err('The minimum sale price is higher than the sale price.');
            }
            if ($num['opening_stock'] !== null && Decimal::isPositive($num['opening_stock'])) {
                if ($type === 'service') {
                    $err('A service item cannot have stock.');
                } elseif (! $canOpen) {
                    $err('You are not allowed to enter stock quantities.');
                } elseif ($unit && ! $unit->allows_decimal && ! Decimal::isWhole($num['opening_stock'])) {
                    $err("{$unit->name} is counted in whole numbers: {$num['opening_stock']} is not allowed.");
                }
            }

            // sku and barcode: unique in the database and inside the file
            $sku = trim((string) ($raw['sku'] ?? ''));
            if ($sku !== '') {
                if (mb_strlen($sku) > 50) {
                    $err('The SKU is longer than 50 characters.');
                }
                if (! preg_match('/^[ -~]+$/', $sku)) {
                    $err("SKU \"{$sku}\" has accented or unusual characters; use letters, digits and ordinary symbols only.");
                }
                if ($existingSkus->has(mb_strtolower($sku))) {
                    $err("SKU \"{$sku}\" already exists in the system (the import only adds new products).");
                } elseif (isset($seenSku[mb_strtolower($sku)])) {
                    $err("SKU \"{$sku}\" appears again; first seen on line {$seenSku[mb_strtolower($sku)]}.");
                }
                $seenSku[mb_strtolower($sku)] ??= $line;
            }
            $barcode = trim((string) ($raw['barcode'] ?? ''));
            if ($barcode !== '') {
                if (mb_strlen($barcode) > 64) {
                    $err('The barcode is longer than 64 characters.');
                }
                if (! preg_match('/^[!-~]+$/', $barcode)) {
                    $err("Barcode \"{$barcode}\" has spaces or unusual characters; use letters, digits and ordinary symbols only.");
                }
                if ($existingBarcodes->has(mb_strtolower($barcode))) {
                    $err("Barcode \"{$barcode}\" already belongs to another product.");
                } elseif (isset($seenBarcode[mb_strtolower($barcode)])) {
                    $err("Barcode \"{$barcode}\" appears again; first seen on line {$seenBarcode[mb_strtolower($barcode)]}.");
                }
                $seenBarcode[mb_strtolower($barcode)] ??= $line;
            }

            foreach ($rowErrors as $m) {
                $errors[] = ['line' => $line, 'message' => $m];
            }

            $out[] = [
                'line' => $line, 'ok' => $rowErrors === [],
                'type' => $type, 'name' => $name, 'sku' => $sku, 'barcode' => $barcode, 'category' => $categoryLabel, 'category_path' => $parts, 'category_id' => $categoryId,
                'brand' => $brand, 'unit_id' => $unit?->id, 'unit' => $unit?->short_name, 'supplier_id' => $supplierId,
                'model' => $raw['model'] ?? '', 'description' => $raw['description'] ?? '', 'rack' => $raw['rack'] ?? '',
                'purchase_cost' => $num['purchase_cost'], 'sale_price' => $num['sale_price'], 'wholesale_price' => $num['wholesale_price'],
                'min_sale_price' => $num['min_sale_price'], 'opening_stock' => $num['opening_stock'], 'min_stock_level' => $num['min_stock_level'],
                'tax_rate' => $num['tax_rate'], 'warranty_months' => $num['warranty_months'] === null ? null : (int) $num['warranty_months'],
                'errors' => $rowErrors,
            ];
        }

        $notes = [];
        if ($noCategory > 0) {
            $notes[] = "{$noCategory} row(s) have no category and will be put under \"Other\".";
        }

        return [
            'rows' => $out, 'errors' => $errors, 'new_categories' => array_values($newCategories), 'new_brands' => array_values($newBrands), 'notes' => $notes,
        ];
    }

    /**
     * Save everything in one transaction.
     *
     * @param  array{rows: list<array<string, mixed>>, errors: list<mixed>}  $validated  the result of validate(), with no errors
     * @return array{products: int, categories: int, brands: int, opening_lines: int, opening_value: string, adjustment_no: string|null}
     */
    public function commit(array $validated, User $by, string $filename = ''): array
    {
        if ($validated['errors'] !== []) {
            throw new InvalidArgumentException('The file still has errors; nothing was imported.');
        }
        if ($validated['rows'] === []) {
            throw new InvalidArgumentException('There are no rows to import.');
        }

        return DB::transaction(function () use ($validated, $by, $filename) {
            $createdCategories = 0;
            $createdBrands = 0;
            $catCache = [];
            $brandCache = Brand::query()->pluck('id', 'name')->mapWithKeys(fn ($id, $n) => [mb_strtolower($n) => $id])->all();

            $resolveCategory = function (array $path) use (&$catCache, &$createdCategories): int {
                $key = mb_strtolower(implode(' > ', $path));
                if (isset($catCache[$key])) {
                    return $catCache[$key];
                }
                $parent = Category::query()->firstOrCreate(['parent_id' => null, 'name' => $path[0]], ['is_active' => true, 'sort_order' => 0]);
                $createdCategories += $parent->wasRecentlyCreated ? 1 : 0;
                $catCache[mb_strtolower($path[0])] = $parent->id;
                if (count($path) === 1) {
                    return $parent->id;
                }
                $child = Category::query()->firstOrCreate(['parent_id' => $parent->id, 'name' => $path[1]], ['is_active' => true, 'sort_order' => 0]);
                $createdCategories += $child->wasRecentlyCreated ? 1 : 0;

                return $catCache[$key] = $child->id;
            };

            $created = 0;
            $openingLines = [];
            $openingValue = '0';

            foreach ($validated['rows'] as $row) {
                $categoryId = $row['category_id'] ?? $resolveCategory($row['category_path'] ?: ['Other']);

                $brandId = null;
                if ($row['brand'] !== '') {
                    $k = mb_strtolower($row['brand']);
                    if (! isset($brandCache[$k])) {
                        $brandCache[$k] = Brand::query()->create(['name' => $row['brand'], 'is_active' => true])->id;
                        $createdBrands++;
                    }
                    $brandId = $brandCache[$k];
                }

                $product = $this->products->create([
                    'type' => $row['type'], 'name' => $row['name'], 'sku' => $row['sku'], 'barcode' => $row['barcode'],
                    'category_id' => $categoryId, 'brand_id' => $brandId, 'unit_id' => $row['unit_id'],
                    'model' => $row['model'] ?: null, 'description' => $row['description'] ?: null,
                    'sale_price' => $row['sale_price'], 'wholesale_price' => $row['wholesale_price'], 'min_sale_price' => $row['min_sale_price'],
                    'tax_rate' => $row['tax_rate'], 'min_stock_level' => $row['type'] === 'service' ? null : $row['min_stock_level'],
                    'rack_location' => $row['type'] === 'service' ? null : ($row['rack'] ?: null),
                    'default_supplier_id' => $row['supplier_id'], 'warranty_months' => $row['warranty_months'], 'is_active' => true,
                ], null, $by, audit: false);
                $created++;

                if ($row['type'] === 'stock' && $row['opening_stock'] !== null && Decimal::isPositive($row['opening_stock'])) {
                    $cost = $by->can('products.view_cost') ? ($row['purchase_cost'] ?? null) : null;
                    $openingLines[] = ['product_id' => $product->id, 'quantity' => Decimal::round($row['opening_stock'], 3), 'unit_cost' => $cost];
                    $openingValue = Decimal::add($openingValue, Decimal::mul($row['opening_stock'], $cost ?? '0', 4), 2);
                }
            }

            $adjustmentNo = null;
            if ($openingLines !== []) {
                $adjustmentNo = $this->adjustments->execute([
                    'reason' => 'opening', 'notes' => 'Opening stock from import'.($filename !== '' ? " ({$filename})" : ''), 'lines' => $openingLines,
                ], $by)->adjustment_no;
            }

            $this->audit->log('products_imported', 'products', "Imported {$created} product(s)".($filename !== '' ? " from {$filename}" : ''), null, new: [
                'products' => $created, 'categories_created' => $createdCategories, 'brands_created' => $createdBrands,
                'opening_stock_lines' => count($openingLines), 'adjustment_no' => $adjustmentNo,
            ], user: $by);

            return [
                'products' => $created, 'categories' => $createdCategories, 'brands' => $createdBrands,
                'opening_lines' => count($openingLines), 'opening_value' => $openingValue, 'adjustment_no' => $adjustmentNo,
            ];
        }, 3);
    }

    /** Template CSV (with Excel-friendly byte order mark). */
    public function template(): string
    {
        $rows = [
            ['sku', 'name', 'type', 'category', 'brand', 'unit', 'barcode', 'model', 'purchase_cost', 'sale_price', 'wholesale_price', 'min_sale_price', 'opening_stock', 'min_stock_level', 'rack', 'warranty_months', 'supplier', 'tax_rate', 'description'],
            ['', 'HP 85A Toner Cartridge', 'stock', 'Toners', 'HP', 'pcs', '6901234567890', 'CE285A', '3200', '4500', '4200', '3800', '12', '3', 'A-02', '', '', '', 'Compatible with P1102 / M1132'],
            ['', 'Printer repair (labour)', 'service', 'Services', '', 'pcs', '', '', '', '1500', '', '', '', '', '', '', '', '', 'Inspection and repair charge'],
            ['', 'Toner powder (refill)', 'stock', 'Printer Parts > Other parts', 'Generic', 'g', '', '', '2.5', '6', '', '', '1500.5', '500', 'B-01', '', '', '', 'Sold by the gram'],
        ];
        $h = fopen('php://temp', 'r+');
        fwrite($h, "\xEF\xBB\xBF");
        foreach ($rows as $r) {
            fputcsv($h, $r);
        }
        rewind($h);

        return (string) stream_get_contents($h);
    }

    /** @return list<list<string>> */
    private function readCsv(string $path): array
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new InvalidArgumentException('The file could not be opened.');
        }

        // Excel puts a byte-order mark in front of the first field. It must go BEFORE parsing: left in place it hides the
        // quote of a quoted first header, and the header would no longer be recognised.
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        // Legacy Windows exports are not UTF-8: convert so accents survive.
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $firstLine = strtok($content, "\n") ?: '';
        $delimiter = ',';
        foreach ([';', "\t", '|'] as $d) {
            if (substr_count($firstLine, $d) > substr_count($firstLine, $delimiter)) {
                $delimiter = $d;
            }
        }

        $h = fopen('php://temp', 'r+');
        fwrite($h, $content);
        rewind($h);

        $rows = [];
        while (($cells = fgetcsv($h, 0, $delimiter, '"', '')) !== false) {
            if ($cells === [null]) {
                continue;
            }
            $rows[] = array_map(fn ($c) => $this->toText($c), $cells);
            if (count($rows) > self::MAX_ROWS + 5) {
                break;
            }
        }
        fclose($h);

        return $rows;
    }

    /** @return list<list<string>> */
    private function readXlsx(string $path): array
    {
        $reader = new XlsxReader;
        $rows = [];
        try {
            $reader->open($path);
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = array_map(fn ($c) => $this->toText($c), $row->toArray());
                    if (count($rows) > self::MAX_ROWS + 5) {
                        break;
                    }
                }
                break; // first sheet only
            }
        } catch (\Throwable $e) {
            throw new InvalidArgumentException('The Excel file could not be read. Save it again as .xlsx or .csv and retry.');
        } finally {
            try {
                $reader->close();
            } catch (\Throwable) {
            }
        }

        return $rows;
    }

    private function toText(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if ($v instanceof DateTimeInterface) {
            return $v->format('Y-m-d');
        }
        if (is_float($v)) {
            return rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.');
        }

        return trim((string) $v);
    }

    /**
     * "1,200.50", "Rs. 1200", "1 200" -> "1200.50". Empty -> null. Garbage -> false.
     */
    private function number(string $v): string|false|null
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        $v = preg_replace('/^(rs\.?|pkr)\s*/i', '', $v);
        $v = str_replace([',', ' ', "\u{00A0}"], '', $v);

        return Decimal::isNumber($v) ? Decimal::of($v) : false;
    }
}
