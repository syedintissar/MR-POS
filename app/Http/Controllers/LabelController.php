<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Services\SettingsService;
use App\Support\Decimal;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Picqer\Barcode\BarcodeGenerator;
use Picqer\Barcode\BarcodeGeneratorSVG;
use Throwable;

/** Printable barcode labels (Code 128) for shelf and product stickers. */
class LabelController extends Controller
{
    public const SIZES = [
        '50x30' => ['label' => '50 × 30 mm label roll', 'w' => '50mm', 'h' => '30mm', 'sheet' => false],
        '38x25' => ['label' => '38 × 25 mm label roll', 'w' => '38mm', 'h' => '25mm', 'sheet' => false],
        'a4-3x8' => ['label' => 'A4 sheet, 3 × 8 labels (70 × 37 mm)', 'w' => '70mm', 'h' => '37mm', 'sheet' => true],
    ];

    private const MAX_LABELS = 1000;

    public function index(Request $request): View
    {
        $this->authorize('products.view');
        $request->validate(['q' => ['nullable', 'string', 'max:100'], 'category' => ['nullable', 'integer']]);

        $products = Product::query()->with('unit')->active()->stockItems()
            ->search($request->input('q'))
            ->when($request->filled('category'), function ($q) use ($request) {
                $cat = Category::query()->find($request->integer('category'));
                $q->whereIn('category_id', $cat ? $cat->selfAndChildIds() : [0]);
            })
            ->orderBy('name')   // after the exact-match ranking from search()
            ->limit(60)->get();

        return view('labels.index', [
            'products' => $products,
            'categories' => Category::query()->whereNull('parent_id')->with('children')->orderBy('sort_order')->orderBy('name')->get(),
            'sizes' => self::SIZES,
        ]);
    }

    public function print(Request $request, SettingsService $settings): View
    {
        $this->authorize('products.view');
        $d = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['nullable', 'integer', 'min:0', 'max:500'],
            'size' => ['required', 'in:'.implode(',', array_keys(self::SIZES))],
            'show_price' => ['nullable', 'boolean'],
        ]);

        $wanted = collect($d['items'])->filter(fn ($q) => (int) $q > 0)->mapWithKeys(fn ($q, $id) => [(int) $id => (int) $q]);
        abort_if($wanted->isEmpty(), 422, 'Choose at least one product and a quantity.');
        abort_if($wanted->sum() > self::MAX_LABELS, 422, 'At most '.self::MAX_LABELS.' labels can be printed at once.');

        $generator = new BarcodeGeneratorSVG;
        $labels = [];
        $skipped = [];

        foreach (Product::query()->whereIn('id', $wanted->keys())->get() as $p) {
            $value = $p->barcode ?: $p->sku;
            try {
                if (! preg_match('/^[ -~]+$/', $value)) {
                    throw new \InvalidArgumentException('not plain ASCII');
                }
                $svg = $generator->getBarcode($value, BarcodeGenerator::TYPE_CODE_128, 1.6, 36);
            } catch (Throwable) {
                $skipped[] = "{$p->sku} · {$p->name} (the code \"{$value}\" cannot be printed as a barcode)";

                continue;
            }
            for ($i = 0; $i < $wanted[$p->id]; $i++) {
                $labels[] = [
                    'svg' => $svg, 'value' => $value, 'name' => mb_strimwidth($p->name, 0, 38, '…'), 'sku' => $p->sku,
                    'price' => $request->boolean('show_price') ? Decimal::trim(Decimal::round((string) $p->sale_price, 2)) : null,
                ];
            }
        }

        return view('labels.print', [
            'labels' => $labels,
            'skipped' => $skipped,
            'size' => self::SIZES[$d['size']],
            'currency' => $settings->string('currency.symbol'),
        ]);
    }
}
