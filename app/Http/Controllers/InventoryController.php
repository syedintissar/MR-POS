<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InventoryController extends Controller
{
    public function __construct(private readonly SettingsService $settings) {}

    /** Stock overview: what is on the shelf, what is low, what is out. */
    public function stock(Request $request): View
    {
        $this->authorize('inventory.view');
        $request->validate(['q' => ['nullable', 'string', 'max:100'], 'category' => ['nullable', 'integer'], 'brand' => ['nullable', 'integer'], 'level' => ['nullable', 'in:all,low,out,negative,ok']]);

        $threshold = (string) $this->settings->get('inventory.low_stock_threshold');
        $level = $request->input('level', 'all');

        $base = Product::query()->stockItems()->where('is_active', true)
            ->when($request->filled('category'), function ($q) use ($request) {
                $cat = Category::query()->find($request->integer('category'));
                $q->whereIn('category_id', $cat ? $cat->selfAndChildIds() : [0]);
            })
            ->when($request->filled('brand'), fn ($q) => $q->where('brand_id', $request->integer('brand')));

        $summary = [
            'items' => (clone $base)->count(),
            'low' => (clone $base)->lowStock($threshold)->count(),
            'out' => (clone $base)->outOfStock()->count(),
            'value' => auth()->user()->can('products.view_cost')
                ? (string) ((clone $base)->where('current_stock', '>', 0)->selectRaw('COALESCE(SUM(current_stock * average_cost), 0) AS v')->value('v') ?? '0')
                : null,
        ];

        $products = (clone $base)->with(['category.parent', 'brand', 'unit'])
            ->search($request->input('q'))
            ->when($level === 'low', fn ($q) => $q->lowStock($threshold))
            ->when($level === 'out', fn ($q) => $q->outOfStock())
            ->when($level === 'negative', fn ($q) => $q->where('current_stock', '<', 0))
            ->when($level === 'ok', fn ($q) => $q->where('current_stock', '>', 0)->whereRaw('current_stock > COALESCE(min_stock_level, ?)', [$threshold]))
            ->orderBy('name')   // after the exact-match ranking from search()
            ->paginate(30)->withQueryString();

        return view('inventory.stock', [
            'products' => $products,
            'summary' => $summary,
            'threshold' => $threshold,
            'categories' => Category::query()->whereNull('parent_id')->with('children')->orderBy('sort_order')->orderBy('name')->get(),
            'brands' => Brand::query()->orderBy('name')->get(),
        ]);
    }

    /** The stock ledger: every movement, newest first. */
    public function movements(Request $request): View
    {
        $this->authorize('inventory.view');
        $request->validate([
            'product' => ['nullable', 'integer'], 'type' => ['nullable', 'string', 'max:30'], 'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'], 'user' => ['nullable', 'integer'],
        ]);

        $movements = StockMovement::query()->with(['product.unit', 'user:id,name'])
            ->when($request->filled('product'), fn ($q) => $q->where('product_id', $request->integer('product')))
            ->when($request->filled('type'), fn ($q) => $q->where('movement_type', $request->input('type')))
            ->when($request->filled('user'), fn ($q) => $q->where('user_id', $request->integer('user')))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->date('to')->endOfDay()))
            ->orderByDesc('id')->paginate(50)->withQueryString();

        // Adjustment numbers for the "document" column, loaded in one query.
        $adjustmentNos = StockAdjustment::query()
            ->whereIn('id', $movements->getCollection()->where('source_type', 'stock_adjustment')->pluck('source_id'))
            ->pluck('adjustment_no', 'id');

        return view('inventory.movements', [
            'movements' => $movements,
            'adjustmentNos' => $adjustmentNos,
            'types' => array_keys(InventoryService::DIRECTION),
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
            'product' => $request->filled('product') ? Product::query()->withTrashed()->find($request->integer('product')) : null,
            'showCost' => auth()->user()->can('products.view_cost'),
        ]);
    }

    /** Count of low / out-of-stock items for the dashboard. @return array{low:int,out:int} */
    public static function alertCounts(string $threshold): array
    {
        return [
            'low' => Product::query()->where('is_active', true)->lowStock($threshold)->count(),
            'out' => Product::query()->where('is_active', true)->outOfStock()->count(),
        ];
    }
}
