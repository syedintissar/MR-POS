<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Inventory\ProductImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\View\View;
use InvalidArgumentException;

/** Upload → check → preview → import. Nothing is saved until the whole file is valid and the user confirms. */
class ProductImportController extends Controller
{
    public function __construct(private readonly ProductImporter $importer) {}

    public function index(): View
    {
        $this->authorize('products.import');

        return view('products.import', ['preview' => null]);
    }

    public function preview(Request $request): View|RedirectResponse
    {
        $this->authorize('products.import');
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:5120']], [
            'file.mimes' => 'Please upload a .csv or .xlsx file.',
            'file.max' => 'The file is larger than 5 MB.',
        ]);

        $this->discardPending($request);

        $upload = $request->file('file');
        $ext = strtolower($upload->getClientOriginalExtension() ?: 'csv');
        $ext = $ext === 'xlsx' ? 'xlsx' : 'csv';
        $dir = $this->dir();
        File::ensureDirectoryExists($dir);
        $stored = Str::uuid()->toString().'.'.$ext;
        $upload->move($dir, $stored);

        try {
            $parsed = $this->importer->parse($dir.DIRECTORY_SEPARATOR.$stored, $ext);
            $validated = $this->importer->validate($parsed['rows'], $request->user());
        } catch (InvalidArgumentException $e) {
            @unlink($dir.DIRECTORY_SEPARATOR.$stored);

            return back()->with('error', $e->getMessage());
        }

        $request->session()->put('product_import', ['file' => $stored, 'ext' => $ext, 'name' => $upload->getClientOriginalName()]);

        return view('products.import', ['preview' => [
            'name' => $upload->getClientOriginalName(),
            'total' => count($validated['rows']),
            'ok' => collect($validated['rows'])->where('ok', true)->count(),
            'errors' => array_slice($validated['errors'], 0, 200),
            'error_total' => count($validated['errors']),
            'rows' => array_slice($validated['rows'], 0, 40),
            'new_categories' => $validated['new_categories'],
            'new_brands' => $validated['new_brands'],
            'notes' => $validated['notes'],
            'unknown_columns' => $parsed['unknown_columns'],
            'opening_rows' => collect($validated['rows'])->filter(fn ($r) => $r['opening_stock'] !== null && $r['opening_stock'] !== '0' && (float) $r['opening_stock'] > 0)->count(),
        ]]);
    }

    public function commit(Request $request): RedirectResponse
    {
        $this->authorize('products.import');
        $pending = $request->session()->get('product_import');
        $path = $pending ? $this->dir().DIRECTORY_SEPARATOR.basename($pending['file']) : null;

        if (! $pending || ! $path || ! is_file($path)) {
            return redirect()->route('products.import')->with('error', 'The uploaded file is no longer available. Please upload it again.');
        }

        try {
            // Checked again: the data may have changed since the preview (for example a SKU taken by someone else).
            $parsed = $this->importer->parse($path, $pending['ext']);
            $validated = $this->importer->validate($parsed['rows'], $request->user());
            $result = $this->importer->commit($validated, $request->user(), $pending['name']);
        } catch (InvalidArgumentException $e) {
            return redirect()->route('products.import')->with('error', $e->getMessage());
        }

        @unlink($path);
        $request->session()->forget('product_import');

        $msg = "Imported {$result['products']} product(s)";
        $msg .= $result['categories'] ? ", {$result['categories']} new categor".($result['categories'] === 1 ? 'y' : 'ies') : '';
        $msg .= $result['brands'] ? ", {$result['brands']} new brand(s)" : '';
        $msg .= $result['adjustment_no'] ? ". Opening stock posted as {$result['adjustment_no']}." : '.';

        return redirect()->route('products.index')->with('success', $msg);
    }

    public function cancel(Request $request): RedirectResponse
    {
        $this->authorize('products.import');
        $this->discardPending($request);

        return redirect()->route('products.import');
    }

    public function template(): Response
    {
        $this->authorize('products.import');

        return response($this->importer->template(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="product-import-template.csv"',
        ]);
    }

    private function discardPending(Request $request): void
    {
        if ($pending = $request->session()->pull('product_import')) {
            @unlink($this->dir().DIRECTORY_SEPARATOR.basename($pending['file']));
        }
    }

    private function dir(): string
    {
        return rtrim((string) config('mrpos.files_path'), '\\/').DIRECTORY_SEPARATOR.'imports';
    }
}
