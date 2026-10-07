<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Products\SaveProduct;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Unit;
use App\Services\Inventory\ProductImageService;
use App\Services\SettingsService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProductController extends Controller
{
    public function __construct(
        private readonly SaveProduct $products,
        private readonly ProductImageService $images,
        private readonly SettingsService $settings,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('products.view');

        $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'category' => ['nullable', 'integer'], 'brand' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:active,inactive,all'], 'stock' => ['nullable', 'in:all,in,low,out'], 'type' => ['nullable', 'in:all,stock,service'],
        ]);

        $threshold = (string) $this->settings->get('inventory.low_stock_threshold');
        $status = $request->input('status', 'active');
        $stock = $request->input('stock', 'all');

        $query = Product::query()->with(['category.parent', 'brand', 'unit'])
            ->search($request->input('q'))
            ->when($request->filled('category'), function ($q) use ($request) {
                $cat = Category::query()->find($request->integer('category'));
                $q->whereIn('category_id', $cat ? $cat->selfAndChildIds() : [0]);
            })
            ->when($request->filled('brand'), fn ($q) => $q->where('brand_id', $request->integer('brand')))
            ->when($status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when(in_array($request->input('type'), ['stock', 'service'], true), fn ($q) => $q->where('type', $request->input('type')))
            ->when($stock === 'low', fn ($q) => $q->lowStock($threshold))
            ->when($stock === 'out', fn ($q) => $q->outOfStock())
            ->when($stock === 'in', fn ($q) => $q->stockItems()->where('current_stock', '>', 0));

        $query->orderBy('name');   // after the exact-match ranking from search()

        return view('products.index', [
            'products' => $query->paginate(25)->withQueryString(),
            'categories' => Category::query()->whereNull('parent_id')->with('children')->orderBy('sort_order')->orderBy('name')->get(),
            'brands' => Brand::query()->orderBy('name')->get(),
            'threshold' => $threshold,
        ]);
    }

    public function create(): View
    {
        $this->authorize('products.create');

        return view('products.form', $this->formData(new Product(['type' => 'stock', 'is_active' => true, 'sale_price' => '0.00'])));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('products.create');
        [$data, $opening] = $this->validated($request, null);

        $image = $request->file('image') ? $this->images->store($request->file('image')) : null;
        if ($image) {
            $data['image_path'] = $image;
        }

        try {
            $product = $this->products->create($data, $opening, $request->user());
        } catch (DomainException|\InvalidArgumentException $e) {
            $this->images->delete($image);

            return back()->withInput()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            $this->images->delete($image);
            throw $e;
        }

        return redirect()->route('products.show', $product)->with('success', "Product {$product->sku} created.");
    }

    public function show(Product $product): View
    {
        $this->authorize('products.view');
        $product->load(['category.parent', 'brand', 'unit', 'defaultSupplier']);

        $movements = auth()->user()->can('inventory.view')
            ? StockMovement::query()->with('user:id,name')->where('product_id', $product->id)->orderByDesc('id')->limit(15)->get()
            : collect();

        return view('products.show', [
            'product' => $product,
            'movements' => $movements,
            'threshold' => (string) $this->settings->get('inventory.low_stock_threshold'),
            'hasHistory' => $product->hasHistory(),
        ]);
    }

    public function edit(Product $product): View
    {
        $this->authorize('products.edit');

        return view('products.form', $this->formData($product) + ['hasHistory' => $product->hasHistory()]);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $this->authorize('products.edit');
        [$data] = $this->validated($request, $product);

        $oldImage = $product->image_path;
        $newImage = null;
        if ($request->file('image')) {
            $newImage = $this->images->store($request->file('image'));
            $data['image_path'] = $newImage;
        } elseif ($request->boolean('remove_image')) {
            $data['image_path'] = null;
        }

        try {
            $this->products->update($product, $data, $request->user());
        } catch (DomainException $e) {
            $this->images->delete($newImage);

            return back()->withInput()->with('error', $e->getMessage());
        }

        if (array_key_exists('image_path', $data) && $oldImage && $oldImage !== ($data['image_path'] ?? null)) {
            $this->images->delete($oldImage);
        }

        return redirect()->route('products.show', $product)->with('success', 'Product saved.');
    }

    public function toggle(Request $request, Product $product): RedirectResponse
    {
        $this->authorize('products.edit');
        $this->products->setActive($product, ! $product->is_active, $request->user());

        return back()->with('success', $product->is_active ? 'Product is active again.' : 'Product deactivated: it is hidden from sales but stays in all reports.');
    }

    public function destroy(Request $request, Product $product): RedirectResponse
    {
        $this->authorize('products.delete');

        try {
            $this->products->delete($product, $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
        $this->images->delete($product->image_path);

        return redirect()->route('products.index')->with('success', 'Product deleted.');
    }

    /** Pictures are private files served only to logged-in people who may see products. */
    public function image(Product $product): BinaryFileResponse
    {
        $this->authorize('products.view');
        $path = $product->image_path ? $this->images->absolute($product->image_path) : null;
        abort_unless($path && is_file($path), 404);

        return response()->file($path, ['Cache-Control' => 'private, max-age=3600', 'Content-Type' => 'image/jpeg']);
    }

    /** Type-ahead search for pickers. Never exposes cost. */
    public function lookup(Request $request): JsonResponse
    {
        $this->authorize('products.view');
        $request->validate(['q' => ['required', 'string', 'min:1', 'max:100'], 'stock_only' => ['nullable', 'boolean']]);

        $rows = Product::query()->with('unit')->active()
            ->when($request->boolean('stock_only', true), fn ($q) => $q->stockItems())
            ->search($request->input('q'))->limit(15)->get();

        return response()->json($rows->map(fn (Product $p) => [
            'id' => $p->id, 'sku' => $p->sku, 'barcode' => $p->barcode, 'name' => $p->name,
            'stock' => Decimal::trim($p->current_stock), 'unit' => $p->unit->short_name, 'allows_decimal' => $p->unit->allows_decimal,
        ]));
    }

    /** @return array<string, mixed> */
    private function formData(Product $product): array
    {
        return [
            'product' => $product,
            'categories' => Category::query()->whereNull('parent_id')->with('children')->orderBy('sort_order')->orderBy('name')->get(),
            'brands' => Brand::query()->where('is_active', true)->orderBy('name')->get(),
            'units' => Unit::query()->orderBy('name')->get(),
            'suppliers' => Supplier::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'company']),
            'hasHistory' => false,
        ];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array{quantity: string, unit_cost: string|null}|null}
     */
    private function validated(Request $request, ?Product $editing): array
    {
        $id = $editing?->id;
        $user = $request->user();

        // People without "change prices" see the price boxes read-only (browsers do not send those), and the server
        // ignores any price they might post. Fill in the stored values so the rest of the form can still be saved.
        if ($editing && ! $user->can('products.edit_price')) {
            $request->merge([
                'sale_price' => (string) $editing->sale_price,
                'wholesale_price' => $editing->wholesale_price === null ? null : (string) $editing->wholesale_price,
                'min_sale_price' => $editing->min_sale_price === null ? null : (string) $editing->min_sale_price,
            ]);
        }

        $d = $request->validate([
            'type' => ['required', Rule::in(['stock', 'service'])],
            'name' => ['required', 'string', 'max:200'],
            'sku' => ['nullable', 'string', 'max:50', 'regex:/^[ -~]+$/', Rule::unique('products', 'sku')->ignore($id)],
            'barcode' => ['nullable', 'string', 'max:64', 'regex:/^[!-~]+$/', Rule::unique('products', 'barcode')->ignore($id)],
            'category_id' => ['required', Rule::exists('categories', 'id')],
            'brand_id' => ['nullable', Rule::exists('brands', 'id')],
            'unit_id' => ['required', Rule::exists('units', 'id')],
            'model' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'sale_price' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'min_sale_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'lte:sale_price'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'min_stock_level' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'rack_location' => ['nullable', 'string', 'max:50'],
            'default_supplier_id' => ['nullable', Rule::exists('suppliers', 'id')],
            'warranty_months' => ['nullable', 'integer', 'min:0', 'max:600'],
            'warranty_terms' => ['nullable', 'string', 'max:255'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'opening_stock' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'opening_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
        ], [
            'min_sale_price.lte' => 'The minimum sale price cannot be higher than the sale price.',
            'sku.unique' => 'Another product already uses this SKU.',
            'sku.regex' => 'The SKU may only use letters, digits and ordinary symbols (no accented letters), so it can be printed as a barcode.',
            'barcode.regex' => 'The barcode may only use letters, digits and ordinary symbols, with no spaces or accented letters.',
            'barcode.unique' => 'Another product already uses this barcode.',
        ]);

        // A scanner types the code and presses Enter: a barcode equal to another product's SKU (or the reverse) would be ambiguous.
        $sku = trim((string) ($d['sku'] ?? ''));
        $barcode = trim((string) ($d['barcode'] ?? ''));
        if ($barcode !== '' && Product::query()->withTrashed()->where('sku', $barcode)->when($id, fn ($q) => $q->whereKeyNot($id))->exists()) {
            throw ValidationException::withMessages(['barcode' => 'This barcode is the SKU of another product, which would confuse the scanner.']);
        }
        if ($sku !== '' && Product::query()->withTrashed()->where('barcode', $sku)->when($id, fn ($q) => $q->whereKeyNot($id))->exists()) {
            throw ValidationException::withMessages(['sku' => 'This SKU is the barcode of another product, which would confuse the scanner.']);
        }

        $unit = Unit::query()->findOrFail($d['unit_id']);
        $opening = null;
        if ($editing === null && ($d['type'] === 'stock') && isset($d['opening_stock']) && Decimal::isPositive($d['opening_stock'])) {
            if (! $user->can('inventory.adjust')) {
                throw ValidationException::withMessages(['opening_stock' => 'You are not allowed to enter stock quantities.']);
            }
            if (! $unit->allows_decimal && ! Decimal::isWhole($d['opening_stock'])) {
                throw ValidationException::withMessages(['opening_stock' => "{$unit->name} is counted in whole numbers."]);
            }
            $opening = [
                'quantity' => Decimal::round($d['opening_stock'], 3),
                // People who cannot see costs cannot enter one either: the stock then starts at cost 0 and a manager can correct it.
                'unit_cost' => $user->can('products.view_cost') && isset($d['opening_cost']) ? Decimal::round($d['opening_cost'], 4) : null,
            ];
        }

        $data = collect($d)->only([
            'type', 'name', 'sku', 'barcode', 'category_id', 'brand_id', 'unit_id', 'model', 'description', 'sale_price', 'wholesale_price',
            'min_sale_price', 'tax_rate', 'min_stock_level', 'rack_location', 'default_supplier_id', 'warranty_months', 'warranty_terms',
        ])->all();
        $data['is_active'] = $request->boolean('is_active', true);
        $data['sku'] = $sku;
        $data['barcode'] = $barcode;

        if ($d['type'] === 'service') {
            $data['min_stock_level'] = null;
            $data['rack_location'] = null;
        }

        return [$data, $opening];
    }
}
