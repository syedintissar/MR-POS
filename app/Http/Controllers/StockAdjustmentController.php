<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Inventory\PostStockAdjustment;
use App\Actions\Inventory\VoidStockAdjustment;
use App\Exceptions\InsufficientStockException;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Services\Inventory\InventoryService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class StockAdjustmentController extends Controller
{
    public function __construct(private readonly PostStockAdjustment $post, private readonly VoidStockAdjustment $void) {}

    public function index(Request $request): View
    {
        $this->authorize('inventory.view');
        $request->validate(['reason' => ['nullable', 'string', 'max:30'], 'status' => ['nullable', 'in:posted,voided,all']]);

        $adjustments = StockAdjustment::query()->with('creator:id,name')->withCount('items')
            ->when($request->filled('reason'), fn ($q) => $q->where('reason', $request->input('reason')))
            ->when(in_array($request->input('status'), ['posted', 'voided'], true), fn ($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('id')->paginate(25)->withQueryString();

        return view('inventory.adjustments.index', compact('adjustments'));
    }

    public function create(Request $request): View
    {
        $this->authorize('inventory.adjust');

        // Lines to show: the ones just submitted (after a validation error) or the product the user came from.
        $submitted = collect($request->old('lines', []))->values();
        $ids = $submitted->pluck('product_id')->filter()->map(fn ($id) => (int) $id)
            ->whenEmpty(fn ($c) => $request->filled('product') ? collect([$request->integer('product')]) : $c)->all();
        $products = Product::query()->with('unit')->stockItems()->whereIn('id', $ids)->get()->keyBy('id');

        $lines = [];
        foreach ($submitted->isNotEmpty() ? $submitted : collect($ids)->map(fn ($id) => ['product_id' => $id]) as $l) {
            $p = $products[(int) ($l['product_id'] ?? 0)] ?? null;
            if (! $p) {
                continue;
            }
            $lines[] = [
                'id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'unit' => $p->unit->short_name, 'allows_decimal' => (bool) $p->unit->allows_decimal,
                'stock' => Decimal::trim($p->current_stock), 'direction' => $l['direction'] ?? 'in', 'quantity' => $l['quantity'] ?? '',
                'unit_cost' => $l['unit_cost'] ?? '', 'notes' => $l['notes'] ?? '',
            ];
        }

        return view('inventory.adjustments.create', [
            'reasons' => StockAdjustment::REASONS,
            'lines' => $lines,
            'canCost' => $request->user()->can('products.view_cost'),
            'allowNegative' => app(InventoryService::class)->allowNegativeStock(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('inventory.adjust');
        $user = $request->user();
        $canSeeCost = $user->can('products.view_cost');

        $d = $request->validate([
            'reason' => ['required', Rule::in(array_keys(StockAdjustment::REASONS))],
            'adjustment_date' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', Rule::exists('products', 'id')->where('type', 'stock')->whereNull('deleted_at')],
            'lines.*.direction' => ['required', 'in:in,out'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'lines.*.notes' => ['nullable', 'string', 'max:255'],
        ], [
            'lines.required' => 'Add at least one product.',
            'lines.*.quantity.gt' => 'Enter a quantity greater than zero (use the Add / Remove choice for the direction).',
            'lines.*.product_id.exists' => 'One of the products is not a stock item any more.',
        ]);

        if ($d['reason'] === 'opening' && collect($d['lines'])->contains(fn ($l) => $l['direction'] === 'out')) {
            throw ValidationException::withMessages(['lines' => 'Opening stock can only add stock.']);
        }

        $units = Product::query()->with('unit')->whereIn('id', collect($d['lines'])->pluck('product_id'))->get()->keyBy('id');
        $lines = [];
        foreach ($d['lines'] as $i => $l) {
            $p = $units[(int) $l['product_id']];
            if (! $p->unit->allows_decimal && ! Decimal::isWhole($l['quantity'])) {
                throw ValidationException::withMessages(["lines.{$i}.quantity" => "\"{$p->name}\" is counted in whole {$p->unit->name}s."]);
            }
            $qty = Decimal::round($l['quantity'], 3);
            $lines[] = [
                'product_id' => (int) $l['product_id'],
                'quantity' => $l['direction'] === 'out' ? Decimal::neg($qty, 3) : $qty,
                // Cost can only be typed by people who may see costs; otherwise additions use the current average.
                'unit_cost' => $canSeeCost && $l['direction'] === 'in' ? ($l['unit_cost'] ?? null) : null,
                'notes' => $l['notes'] ?? null,
            ];
        }

        try {
            $adjustment = $this->post->execute([
                'reason' => $d['reason'], 'adjustment_date' => $d['adjustment_date'], 'notes' => $d['notes'] ?? null, 'lines' => $lines,
            ], $user);
        } catch (InsufficientStockException|InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('adjustments.show', $adjustment)->with('success', "Adjustment {$adjustment->adjustment_no} posted.");
    }

    public function show(StockAdjustment $adjustment): View
    {
        $this->authorize('inventory.view');
        $adjustment->load(['items.product.unit', 'creator', 'voider']);

        $movements = StockMovement::query()->where('source_type', 'stock_adjustment')->where('source_id', $adjustment->id)
            ->orderBy('id')->get()->groupBy('source_line_id');

        return view('inventory.adjustments.show', [
            'adjustment' => $adjustment,
            'movements' => $movements,
            'showCost' => auth()->user()->can('products.view_cost'),
        ]);
    }

    public function void(Request $request, StockAdjustment $adjustment): RedirectResponse
    {
        $this->authorize('inventory.void_adjustment');
        $d = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        try {
            $this->void->execute($adjustment, $d['reason'], $request->user());
        } catch (DomainException|InsufficientStockException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('adjustments.show', $adjustment)->with('success', "Adjustment {$adjustment->adjustment_no} cancelled and its stock changes reversed.");
    }
}
