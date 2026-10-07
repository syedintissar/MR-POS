<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Unit;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Categories (two levels), brands and units of measure. One screen with three tabs. */
class CatalogController extends Controller
{
    public const TABS = ['categories' => 'Categories', 'brands' => 'Brands', 'units' => 'Units'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(string $tab = 'categories'): View
    {
        $this->authorize('products.view');
        abort_unless(isset(self::TABS[$tab]), 404);

        return view('catalog.index', [
            'tab' => $tab,
            'tabs' => self::TABS,
            'roots' => Category::query()->whereNull('parent_id')->with(['children' => fn ($q) => $q->withCount('products')])
                ->withCount('products')->orderBy('sort_order')->orderBy('name')->get(),
            'brands' => Brand::query()->withCount('products')->orderBy('name')->get(),
            'units' => Unit::query()->withCount('products')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, string $tab): RedirectResponse
    {
        $this->authorize('catalog.manage');
        $data = $this->validated($request, $tab, null);

        $model = match ($tab) {
            'categories' => Category::query()->create($data),
            'brands' => Brand::query()->create($data),
            'units' => Unit::query()->create($data),
        };

        $this->audit->log('catalog_created', 'catalog', ucfirst(rtrim($tab, 's')).' "'.$model->name.'" created', $model, new: $data);

        return redirect()->route('catalog.index', $tab)->with('success', ucfirst(rtrim($tab, 's')).' added.');
    }

    public function update(Request $request, string $tab, int $id): RedirectResponse
    {
        $this->authorize('catalog.manage');
        $model = $this->find($tab, $id);
        $data = $this->validated($request, $tab, $model);

        $old = $model->only(array_keys($data));
        $model->fill($data)->save();

        $changed = array_filter($data, fn ($v, $k) => (string) ($old[$k] ?? '') !== (string) $v, ARRAY_FILTER_USE_BOTH);
        if ($changed !== []) {
            $this->audit->log('catalog_updated', 'catalog', ucfirst(rtrim($tab, 's')).' "'.$model->name.'" updated', $model, array_intersect_key($old, $changed), $changed);
        }

        return redirect()->route('catalog.index', $tab)->with('success', 'Saved.');
    }

    public function destroy(string $tab, int $id): RedirectResponse
    {
        $this->authorize('catalog.manage');
        $model = $this->find($tab, $id);

        if ($model->products()->exists()) {
            return back()->with('error', "\"{$model->name}\" is used by products, so it cannot be deleted. Untick Active to hide it instead, or move its products first.");
        }
        if ($model instanceof Category && $model->children()->exists()) {
            return back()->with('error', "\"{$model->name}\" has subcategories. Delete or move them first.");
        }

        $this->audit->log('catalog_deleted', 'catalog', ucfirst(rtrim($tab, 's')).' "'.$model->name.'" deleted', $model, $model->only(['name']));
        $model->delete();

        return redirect()->route('catalog.index', $tab)->with('success', 'Deleted.');
    }

    private function find(string $tab, int $id): Model
    {
        return match ($tab) {
            'categories' => Category::query()->findOrFail($id),
            'brands' => Brand::query()->findOrFail($id),
            'units' => Unit::query()->findOrFail($id),
            default => abort(404),
        };
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, string $tab, ?Model $editing): array
    {
        abort_unless(isset(self::TABS[$tab]), 404);
        $ignore = $editing?->getKey();

        if ($tab === 'brands') {
            $d = $request->validate([
                'name' => ['required', 'string', 'max:100', Rule::unique('brands', 'name')->ignore($ignore)],
                'is_active' => ['nullable', 'boolean'],
            ]);

            return ['name' => trim($d['name']), 'is_active' => $request->boolean('is_active', true)];
        }

        if ($tab === 'units') {
            $d = $request->validate([
                'name' => ['required', 'string', 'max:30', Rule::unique('units', 'name')->ignore($ignore)],
                'short_name' => ['required', 'string', 'max:10', Rule::unique('units', 'short_name')->ignore($ignore)],
                'allows_decimal' => ['nullable', 'boolean'],
            ]);
            $allows = $request->boolean('allows_decimal');

            // Switching a unit to whole numbers while products already hold stock history could leave fractions behind.
            if ($editing instanceof Unit && $editing->allows_decimal && ! $allows
                && $editing->products()->whereHas('movements')->exists()) {
                throw ValidationException::withMessages(['allows_decimal' => 'Some products in this unit already have stock history, so it must keep allowing decimals.']);
            }

            return ['name' => trim($d['name']), 'short_name' => trim($d['short_name']), 'allows_decimal' => $allows];
        }

        $d = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'parent_id' => ['nullable', Rule::exists('categories', 'id')->whereNull('parent_id')],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['nullable', 'boolean'],
        ], ['parent_id.exists' => 'A subcategory can only go under a main category (two levels at most).']);

        $parentId = $d['parent_id'] ?? null;
        $name = trim($d['name']);

        if ($editing instanceof Category) {
            if ($parentId !== null && (int) $parentId === $editing->id) {
                throw ValidationException::withMessages(['parent_id' => 'A category cannot be its own parent.']);
            }
            if ($parentId !== null && $editing->children()->exists()) {
                throw ValidationException::withMessages(['parent_id' => 'This category has subcategories, so it cannot itself become a subcategory.']);
            }
        }

        $clash = Category::query()->where('name', $name)->when($parentId === null, fn ($q) => $q->whereNull('parent_id'), fn ($q) => $q->where('parent_id', $parentId))
            ->when($ignore, fn ($q) => $q->whereKeyNot($ignore))->exists();
        if ($clash) {
            throw ValidationException::withMessages(['name' => 'There is already a category with this name here.']);
        }

        return ['name' => $name, 'parent_id' => $parentId, 'sort_order' => (int) ($d['sort_order'] ?? 0), 'is_active' => $request->boolean('is_active', true)];
    }
}
