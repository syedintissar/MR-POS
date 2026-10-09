<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Reports\Periods;
use App\Services\Reports\ReportCatalogue;
use App\Services\Reports\ReportExporter;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportResult;
use App\Services\SettingsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use InvalidArgumentException;

/** The reports hub, a report on screen, its print view, and its PDF / CSV / Excel export. */
class ReportController extends Controller
{
    public function __construct(
        private readonly ReportCatalogue $catalogue,
        private readonly ReportExporter $exporter,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless(ReportCatalogue::canOpenAny($user), 403);

        return view('reports.index', ['groups' => ReportCatalogue::visibleTo($user), 'canExport' => $user->can('reports.export')]);
    }

    public function show(Request $request, string $report): View|RedirectResponse
    {
        [$def, $filters] = $this->resolve($request, $report);
        if ($filters instanceof RedirectResponse) {
            return $filters;
        }
        $result = $this->catalogue->build($report, $filters, $request->user());

        return view('reports.show', [
            'key' => $report, 'def' => $def, 'filters' => $filters, 'result' => $result, 'options' => $this->options($def),
            'canExport' => $request->user()->can('reports.export'), 'query' => $this->query($request, $def, $filters),
        ]);
    }

    public function print(Request $request, string $report): View|RedirectResponse
    {
        [$def, $filters] = $this->resolve($request, $report);
        if ($filters instanceof RedirectResponse) {
            return $filters;
        }

        return $this->document($report, $filters, $this->catalogue->build($report, $filters, $request->user()), $request->user(), false);
    }

    public function export(Request $request, string $report, string $format): Response|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('reports.export') && isset(ReportExporter::FORMATS[$format]), 403);

        [$def, $filters] = $this->resolve($request, $report);
        if ($filters instanceof RedirectResponse) {
            return $filters;
        }
        $result = $this->catalogue->build($report, $filters, $user);

        $html = $format === 'pdf' ? $this->document($report, $filters, $result, $user, true)->render() : '';
        [$content, $mime] = $this->exporter->render($format, $result, $this->settings->string('shop.name') ?: config('app.name'), $html);

        $this->audit->log('report_exported', 'reports', "{$result->title} ({$filters->label()}) exported as ".ReportExporter::FORMATS[$format]." by {$user->name}", null, new: [
            'report' => $report, 'format' => $format, 'from' => $filters->from, 'to' => $filters->to, 'filters' => $filters->extra, 'rows' => $result->rowCount(),
        ], user: $user);

        return response($content, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="'.$this->exporter->fileName($report, $filters, $format).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    // ------------------------------------------------------------------------------------------------

    /** @return array{0: array<string, mixed>, 1: ReportFilters|RedirectResponse} */
    private function resolve(Request $request, string $report): array
    {
        try {
            $def = ReportCatalogue::find($report);
        } catch (InvalidArgumentException) {
            abort(404);
        }
        if (! $request->user()->can($def['permission'])) {
            throw new AuthorizationException('You are not allowed to open this report.');
        }

        $request->validate([
            'preset' => ['nullable', 'in:'.implode(',', array_keys(ReportFilters::PRESETS))], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
            'group' => ['nullable', 'in:'.implode(',', array_keys(Periods::GROUPS))],
            'customer_id' => ['nullable', 'integer'], 'supplier_id' => ['nullable', 'integer'], 'category_id' => ['nullable', 'integer'], 'user_id' => ['nullable', 'integer'],
            'technician_id' => ['nullable', 'integer'], 'method_id' => ['nullable', 'integer'], 'status' => ['nullable', 'string', 'max:30'],
        ]);

        $input = $request->all() + ['preset' => $def['preset'] ?? 'this_month', 'group' => $def['group_default'] ?? 'day'];
        if (($def['asAt'] ?? false) === true) {
            $input['preset'] = 'today';
        }
        // the status must be one this report knows, otherwise it is ignored
        if (isset($input['status']) && ! array_key_exists((string) $input['status'], $def['statuses'] ?? [])) {
            unset($input['status']);
        }
        if (empty($input['status']) && ! empty($def['statuses'])) {
            $input['status'] = (string) array_key_first($def['statuses']);
        }

        try {
            $filters = ReportFilters::make($input);
            if ($filters->days() > 3660) {
                throw new InvalidArgumentException('Please choose a period of at most ten years.');
            }
        } catch (InvalidArgumentException $e) {
            return [$def, redirect()->route('reports.show', $report)->with('error', $e->getMessage())];
        }

        return [$def, $filters];
    }

    /** What the filter bar offers: only the lists the report actually uses. @return array<string, mixed> */
    private function options(array $def): array
    {
        $uses = fn (string $f) => in_array($f, $def['filters'], true);

        return [
            'customers' => $uses('customer') ? Customer::query()->where('is_walk_in', false)->orderBy('name')->limit(500)->get(['id', 'name']) : collect(),
            'suppliers' => $uses('supplier') ? Supplier::query()->orderBy('name')->get(['id', 'name']) : collect(),
            'categories' => $uses('category') ? Category::query()->orderBy('name')->get(['id', 'name']) : collect(),
            'users' => $uses('user') || $uses('technician') ? User::query()->orderBy('name')->get(['id', 'name']) : collect(),
            'methods' => $uses('method') ? PaymentMethod::query()->where('kind', '!=', 'customer_credit')->orderBy('sort_order')->get(['id', 'name']) : collect(),
        ];
    }

    /** @return array<string, mixed> the query string that reproduces this report (for the export and print links) */
    private function query(Request $request, array $def, ReportFilters $filters): array
    {
        return array_filter(['preset' => 'custom', 'from' => $filters->from, 'to' => $filters->to, 'group' => $filters->group] + $filters->extra, fn ($v) => $v !== null && $v !== '');
    }

    private function document(string $key, ReportFilters $filters, ReportResult $result, User $user, bool $pdf): View
    {
        if (! $pdf) {
            $this->audit->log('report_printed', 'reports', "{$result->title} ({$filters->label()}) opened for printing by {$user->name}", null, user: $user);
        }

        return view('reports.document', [
            'key' => $key, 'result' => $result, 'pdf' => $pdf, 'shop' => $this->settings->string('shop.name') ?: config('app.name'),
            'printedBy' => $user->name, 'printedAt' => now(), 'wide' => collect($result->tables)->max(fn ($t) => count($t['columns'])) > 9,
        ]);
    }
}
