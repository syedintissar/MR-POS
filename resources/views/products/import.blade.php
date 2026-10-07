<x-layouts.app title="Import products">
    <div class="mx-auto max-w-5xl space-y-5">
        @if(! $preview)
            <div class="card">
                <div class="card-header"><h2 class="card-title">Import products from Excel or CSV</h2></div>
                <div class="card-body space-y-4">
                    <ol class="list-decimal space-y-1 pl-5 text-sm text-slate-700">
                        <li><a href="{{ route('products.import.template') }}">Download the template</a> (opens in Excel) and fill it in, one product per row. Only <strong>name</strong> is required.</li>
                        <li>Save as <strong>.xlsx</strong> or <strong>.csv</strong> and upload it below. You see a checked preview first: <strong>nothing is saved until you confirm</strong>.</li>
                        <li>If any row has a problem, nothing is imported and every problem is listed with its row number, so you can fix the file and upload it again.</li>
                    </ol>
                    <div class="rounded-md bg-slate-50 p-3 text-xs text-slate-600">
                        <strong>Columns:</strong> sku, name, type (stock / service), category (or <em>Main &gt; Sub</em>), brand, unit, barcode, model, purchase_cost, sale_price, wholesale_price, min_sale_price,
                        opening_stock, min_stock_level, rack, warranty_months, supplier, tax_rate, description. Headers like <em>price</em>, <em>cost</em>, <em>qty</em> are understood too.
                        The import only <em>adds</em> products: a SKU or barcode that already exists is reported as an error. Stock quantities are recorded as one opening-stock document at the purchase_cost you give.
                    </div>
                    <form method="POST" action="{{ route('products.import.preview') }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3">
                        @csrf
                        <div><label class="label" for="file">File</label><input id="file" type="file" name="file" accept=".csv,.xlsx,.txt" required class="block text-sm"></div>
                        <button class="btn btn-primary" data-loading-text="Checking the file…">Check file</button>
                    </form>
                    @error('file')<p class="error-text">{{ $message }}</p>@enderror
                </div>
            </div>
        @else
            @php($bad = $preview['error_total'] > 0)
            <div class="card">
                <div class="card-header"><h2 class="card-title">Preview: {{ $preview['name'] }}</h2></div>
                <div class="card-body space-y-4">
                    <div class="grid gap-3 sm:grid-cols-4">
                        <div class="rounded-md bg-slate-50 p-3"><div class="text-xs text-slate-500">Rows</div><div class="text-xl font-semibold">{{ $preview['total'] }}</div></div>
                        <div class="rounded-md bg-emerald-50 p-3"><div class="text-xs text-emerald-700">Ready</div><div class="text-xl font-semibold text-emerald-800">{{ $preview['ok'] }}</div></div>
                        <div class="rounded-md {{ $bad ? 'bg-red-50' : 'bg-slate-50' }} p-3"><div class="text-xs {{ $bad ? 'text-red-700' : 'text-slate-500' }}">Problems</div><div class="text-xl font-semibold {{ $bad ? 'text-red-700' : '' }}">{{ $preview['error_total'] }}</div></div>
                        <div class="rounded-md bg-slate-50 p-3"><div class="text-xs text-slate-500">With opening stock</div><div class="text-xl font-semibold">{{ $preview['opening_rows'] }}</div></div>
                    </div>

                    @foreach($preview['notes'] as $n)<div class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">{{ $n }}</div>@endforeach
                    @if($preview['unknown_columns'])<div class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600">These columns were not recognised and will be ignored: <strong>{{ implode(', ', $preview['unknown_columns']) }}</strong></div>@endif
                    @if($preview['new_categories'])<div class="text-sm text-slate-700">New categories that will be created: <strong>{{ implode(', ', $preview['new_categories']) }}</strong></div>@endif
                    @if($preview['new_brands'])<div class="text-sm text-slate-700">New brands that will be created: <strong>{{ implode(', ', $preview['new_brands']) }}</strong></div>@endif

                    @if($bad)
                        <div class="rounded-md border border-red-200 bg-red-50 p-3">
                            <div class="mb-2 text-sm font-semibold text-red-800">Nothing can be imported until these are fixed ({{ $preview['error_total'] }}@if($preview['error_total'] > count($preview['errors'])), showing the first {{ count($preview['errors']) }}@endif):</div>
                            <ul class="max-h-64 space-y-1 overflow-y-auto text-sm text-red-800">
                                @foreach($preview['errors'] as $e)<li><span class="font-mono text-xs">Row {{ $e['line'] }}</span> · {{ $e['message'] }}</li>@endforeach
                            </ul>
                        </div>
                    @endif

                    <div class="flex flex-wrap gap-2">
                        @unless($bad)
                            <form method="POST" action="{{ route('products.import.commit') }}" data-confirm="Import {{ $preview['ok'] }} products now?" data-confirm-label="Import">
                                @csrf <button class="btn btn-primary" data-loading-text="Importing…">Import {{ $preview['ok'] }} products</button></form>
                        @endunless
                        <form method="POST" action="{{ route('products.import.cancel') }}">@csrf <button class="btn btn-secondary" data-no-loading>{{ $bad ? 'Choose another file' : 'Cancel' }}</button></form>
                    </div>
                </div>
            </div>

            <div class="card overflow-x-auto">
                <div class="card-header"><h3 class="card-title">First {{ count($preview['rows']) }} rows</h3></div>
                <table class="table">
                    <thead><tr><th>Row</th><th>SKU</th><th>Name</th><th>Category</th><th>Unit</th><th class="text-right">Sale</th><th class="text-right">Cost</th><th class="text-right">Stock</th><th></th></tr></thead>
                    <tbody>
                    @foreach($preview['rows'] as $r)
                        <tr class="{{ $r['ok'] ? '' : 'bg-red-50/60' }}">
                            <td class="font-mono text-xs text-slate-500">{{ $r['line'] }}</td>
                            <td class="font-mono text-xs">{{ $r['sku'] ?: 'auto' }}</td>
                            <td>{{ $r['name'] }}@if($r['type'] === 'service') <span class="badge badge-blue">service</span>@endif</td>
                            <td class="text-slate-600">{{ $r['category'] }}</td>
                            <td>{{ $r['unit'] }}</td>
                            <td class="text-right">{{ number_format((float) $r['sale_price'], 2) }}</td>
                            <td class="text-right text-slate-600">{{ $r['purchase_cost'] !== null ? number_format((float) $r['purchase_cost'], 2) : '' }}</td>
                            <td class="text-right">{{ $r['opening_stock'] !== null ? \App\Support\Decimal::trim($r['opening_stock']) : '' }}</td>
                            <td>@if($r['ok'])<span class="badge badge-green">OK</span>@else<span class="badge badge-red">{{ count($r['errors']) }} problem(s)</span>@endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-layouts.app>
