<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\Customers\ReceivablesService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Who owes the shop and for how long: outstanding invoices and an aging summary (SRS CUS-07). */
class ReceivablesController extends Controller
{
    public function __construct(private readonly ReceivablesService $report) {}

    public function index(Request $request): View
    {
        $this->authorize('reports.customers');
        $request->validate(['customer_id' => ['nullable', 'integer']]);
        $customerId = $request->filled('customer_id') ? $request->integer('customer_id') : null;

        return view('receivables.index', [
            'report' => $this->report->report($customerId),
            'buckets' => ReceivablesService::BUCKETS,
            'customers' => Customer::query()->where('is_walk_in', false)->orderBy('name')->get(['id', 'name']),
            'customerId' => $customerId,
            'detail' => $request->boolean('detail', $customerId !== null),
        ]);
    }
}
