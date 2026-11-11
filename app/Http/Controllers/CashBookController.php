<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CashBook\CloseDay;
use App\Actions\CashBook\PostMoneyTransaction;
use App\Actions\CashBook\VoidDayClosing;
use App\Actions\CashBook\VoidMoneyTransaction;
use App\Models\BankReconciliation;
use App\Models\DayClosing;
use App\Models\MoneyAccount;
use App\Models\MoneyTransaction;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CashBook\BankReconciliationService;
use App\Services\CashBook\MoneyAccountService;
use App\Services\Reports\ReportResult;
use App\Services\SettingsService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Cash & bank: where the shop's money is (the drawer, the bank, the wallets), the book of each account, money moved between them
 * or taken out and put in by the owner, and the count of the drawer that closes the day.
 */
class CashBookController extends Controller
{
    public function __construct(
        private readonly MoneyAccountService $accounts,
        private readonly PostMoneyTransaction $post,
        private readonly VoidMoneyTransaction $voidEntry,
        private readonly CloseDay $close,
        private readonly VoidDayClosing $voidClosing,
        private readonly BankReconciliationService $statements,
        private readonly AuditLogger $audit,
        private readonly SettingsService $settings,
    ) {}

    public function index(): View
    {
        $this->authorize('cashbook.view');
        $list = MoneyAccount::query()->orderBy('sort_order')->orderBy('name')->get();

        return view('cashbook.index', [
            'accounts' => $list, 'balances' => $this->accounts->balances(), 'unlinked' => $this->accounts->unlinkedMethods(),
            'lastClosings' => DayClosing::query()->where('status', DayClosing::POSTED)->whereIn('id', DayClosing::query()->where('status', DayClosing::POSTED)->selectRaw('MAX(id)')->groupBy('money_account_id'))->get()->keyBy('money_account_id'),
            'entries' => MoneyTransaction::query()->with(['from:id,name', 'to:id,name'])->where('status', MoneyTransaction::POSTED)->orderByDesc('id')->limit(8)->get(),
        ]);
    }

    // ------------------------------------------------------------------------------------------------ the book of one account

    public function account(Request $request, MoneyAccount $account): View
    {
        $this->authorize('cashbook.view');
        [$from, $to] = $this->period($request);

        return view('cashbook.account', [
            'account' => $account, 'from' => $from, 'to' => $to, 'book' => $this->accounts->book($account, $from, $to), 'now' => $this->accounts->balance($account->id),
            'methods' => $account->methods()->orderBy('sort_order')->pluck('name')->all(),
        ]);
    }

    public function printAccount(Request $request, MoneyAccount $account): View
    {
        $this->authorize('cashbook.view');
        [$from, $to] = $this->period($request);
        $book = $this->accounts->book($account, $from, $to);
        $user = $request->user();

        $rows = [['date' => $from, 'what' => 'Balance brought forward', 'balance' => $book['opening']]];
        foreach ($book['rows'] as $r) {
            $rows[] = ['date' => $r['date'], 'doc_no' => $r['doc_no'], 'what' => $r['what'], 'party' => $r['party'], 'money_in' => $r['money_in'], 'money_out' => $r['money_out'], 'balance' => $r['balance']];
        }
        $period = date('d M Y', strtotime($from)).($from === $to ? '' : ' to '.date('d M Y', strtotime($to)));
        $result = (new ReportResult("Cash & bank book: {$account->name}", $period))->table(null, [
            ReportResult::col('date', 'Date', 'date'), ReportResult::col('doc_no', 'Document'), ReportResult::col('what', 'What'), ReportResult::col('party', 'Who / where'),
            ReportResult::col('money_in', 'Money in', 'money'), ReportResult::col('money_out', 'Money out', 'money'), ReportResult::col('balance', 'Balance', 'money'),
        ], $rows, ['date' => null, 'what' => 'Total and closing balance', 'money_in' => $book['money_in'], 'money_out' => $book['money_out'], 'balance' => $book['closing']]);
        if ($book['truncated']) {
            $result->note('Only the first '.MoneyAccountService::MAX_ROWS.' movements are listed; the totals cover the whole period. Choose a shorter period to see every line.');
        }

        $this->audit->log('report_printed', 'cashbook', "Book of {$account->name} ({$period}) opened for printing by {$user->name}", $account, user: $user);

        return view('reports.document', [
            'key' => 'cash-book', 'result' => $result, 'pdf' => false, 'shop' => $this->settings->string('shop.name') ?: config('app.name'),
            'printedBy' => $user->name, 'printedAt' => now(), 'wide' => false,
        ]);
    }

    // ------------------------------------------------------------------------------------------------ entries (transfers, owner money, opening balances)

    public function entries(Request $request): View
    {
        $this->authorize('cashbook.view');
        $d = $request->validate([
            'type' => ['nullable', Rule::in(array_keys(MoneyTransaction::LABELS))], 'account' => ['nullable', 'integer'], 'status' => ['nullable', 'in:posted,voided,all'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
        ]);
        $status = $d['status'] ?? 'posted';

        $query = MoneyTransaction::query()->with(['from:id,name', 'to:id,name', 'creator:id,name'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when(! empty($d['type']), fn ($q) => $q->where('type', $d['type']))
            ->when(! empty($d['account']), fn ($q) => $q->where(fn ($w) => $w->where('from_account_id', (int) $d['account'])->orWhere('to_account_id', (int) $d['account'])))
            ->when(! empty($d['from']), fn ($q) => $q->whereDate('txn_date', '>=', $d['from']))
            ->when(! empty($d['to']), fn ($q) => $q->whereDate('txn_date', '<=', $d['to']));

        return view('cashbook.entries', [
            'entries' => $query->orderByDesc('txn_date')->orderByDesc('id')->paginate(25)->withQueryString(), 'status' => $status,
            'accounts' => MoneyAccount::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function createEntry(Request $request): View
    {
        abort_unless($request->user()->canAny(['cashbook.transact', 'cashbook.manage']), 403);
        $types = MoneyTransaction::ENTERED_TYPES;
        if (! $request->user()->can('cashbook.manage')) {
            unset($types['opening_balance']);
        }
        if (! $request->user()->can('cashbook.transact')) {
            $types = array_intersect_key($types, ['opening_balance' => 1]);
        }
        $type = array_key_exists((string) $request->query('type'), $types) ? (string) $request->query('type') : (string) array_key_first($types);
        $list = MoneyAccount::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();

        return view('cashbook.entry-form', ['types' => $types, 'type' => $type, 'accounts' => $list, 'balances' => $this->accounts->balances(), 'account' => (int) $request->query('account')]);
    }

    public function storeEntry(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canAny(['cashbook.transact', 'cashbook.manage']), 403);
        $d = $request->validate([
            'type' => ['required', Rule::in(array_keys(MoneyTransaction::ENTERED_TYPES))], 'txn_date' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999', 'decimal:0,2'], 'from_account_id' => ['nullable', 'integer'], 'to_account_id' => ['nullable', 'integer'],
            'reference_no' => ['nullable', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $txn = $this->post->execute($d, $request->user());
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('cashbook.entries.show', $txn)->with('success', "{$txn->txn_no} recorded.");
    }

    public function showEntry(MoneyTransaction $entry): View
    {
        $this->authorize('cashbook.view');
        $entry->load(['from', 'to', 'creator', 'voider', 'closing']);

        return view('cashbook.entry-show', ['entry' => $entry]);
    }

    public function voidEntry(Request $request, MoneyTransaction $entry): RedirectResponse
    {
        $this->authorize('cashbook.void');
        $d = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        try {
            $this->voidEntry->execute($entry, $d['reason'], $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('cashbook.entries.show', $entry)->with('success', "{$entry->txn_no} cancelled.");
    }

    // ------------------------------------------------------------------------------------------------ closing the day

    public function closings(Request $request): View
    {
        $this->authorize('cashbook.view');
        $d = $request->validate(['status' => ['nullable', 'in:posted,voided,all'], 'result' => ['nullable', 'in:exact,over,short']]);
        $status = $d['status'] ?? 'posted';

        $query = DayClosing::query()->with(['account:id,name', 'creator:id,name'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when(($d['result'] ?? '') === 'exact', fn ($q) => $q->where('difference', 0))
            ->when(($d['result'] ?? '') === 'over', fn ($q) => $q->where('difference', '>', 0))
            ->when(($d['result'] ?? '') === 'short', fn ($q) => $q->where('difference', '<', 0));

        return view('cashbook.closings', ['closings' => $query->orderByDesc('id')->paginate(25)->withQueryString(), 'status' => $status]);
    }

    public function createClosing(Request $request): View|RedirectResponse
    {
        $this->authorize('cashbook.close_day');
        $drawers = MoneyAccount::query()->where('kind', 'cash')->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();
        $account = $drawers->firstWhere('id', (int) $request->query('account')) ?? $drawers->first();
        if (! $account) {
            return redirect()->route('cashbook.index')->with('error', 'There is no cash account to count.');
        }
        $today = now()->toDateString();

        return view('cashbook.closing-form', [
            'account' => $account, 'drawers' => $drawers, 'expected' => $this->accounts->balance($account->id), 'today' => $this->accounts->book($account, $today, $today),
            'last' => DayClosing::query()->where('money_account_id', $account->id)->where('status', DayClosing::POSTED)->orderByDesc('id')->first(),
        ]);
    }

    public function storeClosing(Request $request): RedirectResponse
    {
        $this->authorize('cashbook.close_day');
        $d = $request->validate([
            'money_account_id' => ['required', 'integer'], 'denominations' => ['nullable', 'array'], 'denominations.*' => ['nullable', 'numeric', 'min:0'],
            'counted_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'], 'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $account = MoneyAccount::query()->findOrFail((int) $d['money_account_id']);

        try {
            $closing = $this->close->execute($account, $d, $request->user());
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('cashbook.closings.show', $closing)->with('success', "{$closing->closing_no}: the day is closed.");
    }

    public function showClosing(DayClosing $closing): View
    {
        $this->authorize('cashbook.view');
        $closing->load(['account', 'adjustment', 'creator', 'voider']);
        $latest = (int) DayClosing::query()->where('money_account_id', $closing->money_account_id)->where('status', DayClosing::POSTED)->max('id') === $closing->id;

        return view('cashbook.closing-show', ['closing' => $closing, 'isLatest' => $latest, 'shop' => $this->settings->string('shop.name') ?: config('app.name')]);
    }

    public function voidClosing(Request $request, DayClosing $closing): RedirectResponse
    {
        $this->authorize('cashbook.void');
        $d = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        try {
            $this->voidClosing->execute($closing, $d['reason'], $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('cashbook.closings.show', $closing)->with('success', "{$closing->closing_no} cancelled.");
    }

    // ------------------------------------------------------------------------------------------------ accounts and where each payment method pays into

    public function setup(): View
    {
        $this->authorize('cashbook.manage');

        return view('cashbook.setup', [
            'accounts' => MoneyAccount::query()->withCount('methods')->orderBy('sort_order')->orderBy('name')->get(), 'balances' => $this->accounts->balances(),
            'methods' => PaymentMethod::query()->where('kind', '<>', 'customer_credit')->with('account:id,name')->orderBy('sort_order')->orderBy('name')->get(),
            'people' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'money_account_id']),
        ]);
    }

    public function storeAccount(Request $request): RedirectResponse
    {
        $this->authorize('cashbook.manage');
        $d = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('money_accounts', 'name')], 'kind' => ['required', Rule::in(array_keys(MoneyAccount::KINDS))],
            'account_number' => ['nullable', 'string', 'max:60'], 'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $account = new MoneyAccount;
        $account->forceFill([
            'name' => trim($d['name']), 'kind' => $d['kind'], 'account_number' => $d['account_number'] ?? null, 'notes' => $d['notes'] ?? null,
            'is_active' => true, 'is_system' => false, 'sort_order' => ((int) MoneyAccount::query()->max('sort_order')) + 10,
        ])->save();
        $this->audit->log('money_account_created', 'cashbook', "Account \"{$account->name}\" added", $account, new: ['name' => $account->name, 'kind' => $account->kind], user: $request->user());

        return back()->with('success', "Account \"{$account->name}\" added.");
    }

    public function updateAccount(Request $request, MoneyAccount $account): RedirectResponse
    {
        $this->authorize('cashbook.manage');
        $d = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('money_accounts', 'name')->ignore($account->id)],
            'account_number' => ['nullable', 'string', 'max:60'], 'notes' => ['nullable', 'string', 'max:255'],
        ]);

        // what an account is (cash, bank, wallet) never changes: its history was recorded as that
        $old = $account->only(['name', 'account_number', 'notes']);
        $new = ['name' => trim($d['name']), 'account_number' => $d['account_number'] ?? null, 'notes' => $d['notes'] ?? null];
        $account->forceFill($new)->save();
        if ($old !== $new) {
            $this->audit->log('money_account_updated', 'cashbook', "Account \"{$account->name}\" updated", $account, old: $old, new: $new, user: $request->user());
        }

        return back()->with('success', 'Saved.');
    }

    public function toggleAccount(Request $request, MoneyAccount $account): RedirectResponse
    {
        $this->authorize('cashbook.manage');
        if ($account->is_system) {
            return back()->with('error', "\"{$account->name}\" is the shop's cash drawer and cannot be switched off.");
        }
        if ($account->is_active && $account->methods()->where('is_active', true)->exists()) {
            return back()->with('error', "Payment methods still pay into \"{$account->name}\". Point them at another account first.");
        }
        if ($account->is_active && User::query()->where('money_account_id', $account->id)->exists()) {
            return back()->with('error', "\"{$account->name}\" is somebody's drawer. Give them another drawer first.");
        }

        $account->forceFill(['is_active' => ! $account->is_active])->save();
        $this->audit->log($account->is_active ? 'money_account_activated' : 'money_account_deactivated', 'cashbook', "Account \"{$account->name}\" ".($account->is_active ? 'switched on' : 'switched off'), $account, user: $request->user());

        return back()->with('success', $account->is_active ? 'Switched on.' : 'Switched off. Its history stays in the book.');
    }

    /**
     * Which account a payment method pays into, from now on. What it already took stays in the account it landed in;
     * only money it took while it had no account at all is put into the new one. It asks for the password.
     */
    public function linkMethod(Request $request, PaymentMethod $method): RedirectResponse
    {
        $this->authorize('cashbook.manage');
        abort_if($method->kind === 'customer_credit', 422, 'Customer credit is not money and pays into no account.');
        $d = $request->validate(['money_account_id' => ['nullable', 'integer', Rule::exists('money_accounts', 'id')->where('is_active', true)]]);

        $old = $method->account?->name;
        $target = ! empty($d['money_account_id']) ? (int) $d['money_account_id'] : null;
        $adopted = DB::transaction(function () use ($method, $target) {
            $method->forceFill(['money_account_id' => $target])->save();

            return $target !== null ? $this->accounts->adoptUnlinked($method->id, $target) : 0;
        });
        $new = $method->fresh()->account?->name;
        if ($old !== $new || $adopted > 0) {
            $this->audit->log('payment_method_account_changed', 'cashbook', "Payment method \"{$method->name}\" now pays into ".($new ?? 'no account').' (was '.($old ?? 'no account').')', $method, old: ['account' => $old], new: ['account' => $new, 'earlier_rows_without_account' => $adopted], user: $request->user());
        }

        return back()->with('success', $new ? "From now on \"{$method->name}\" pays into \"{$new}\".".($adopted > 0 ? " {$adopted} earlier payment(s) that had no account were put there too." : '') : "\"{$method->name}\" pays into no account now.");
    }

    /** One drawer per counter: the cash this person takes or pays from now on lands in their own drawer (or in the main drawer when none is chosen). */
    public function assignDrawer(Request $request, User $user): RedirectResponse
    {
        $this->authorize('cashbook.manage');
        $d = $request->validate(['money_account_id' => ['nullable', 'integer', Rule::exists('money_accounts', 'id')->where('is_active', true)->where('kind', 'cash')]]);

        $old = $user->money_account_id ? MoneyAccount::query()->whereKey($user->money_account_id)->value('name') : null;
        $target = ! empty($d['money_account_id']) ? MoneyAccount::query()->findOrFail((int) $d['money_account_id']) : null;
        if ($target?->is_system) {
            $target = null;   // the main drawer is where everybody without a drawer of their own already pays into
        }
        $user->forceFill(['money_account_id' => $target?->id])->save();
        if ($old !== $target?->name) {
            $this->audit->log('user_drawer_changed', 'cashbook', "{$user->name}'s cash now goes to ".($target?->name ?? 'the main drawer').' (was '.($old ?? 'the main drawer').')', $user, old: ['drawer' => $old], new: ['drawer' => $target?->name], user: $request->user());
        }

        return back()->with('success', "From now on {$user->name}'s cash goes to ".($target ? "\"{$target->name}\"." : 'the main drawer.'));
    }

    // ------------------------------------------------------------------------------------------------ bank statement matching

    public function reconciliations(): View
    {
        $this->authorize('cashbook.view');

        return view('cashbook.reconciliations', [
            'reconciliations' => BankReconciliation::query()->with(['account:id,name', 'creator:id,name'])->orderByDesc('id')->paginate(25),
            'accounts' => MoneyAccount::query()->where('kind', '<>', 'cash')->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'balances' => $this->accounts->balances(),
        ]);
    }

    public function storeReconciliation(Request $request): RedirectResponse
    {
        $this->authorize('cashbook.reconcile');
        $d = $request->validate([
            'money_account_id' => ['required', 'integer'], 'statement_date' => ['required', 'date', 'before_or_equal:today'],
            'statement_balance' => ['required', 'numeric', 'min:-9999999999', 'max:9999999999', 'decimal:0,2'], 'notes' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $r = $this->statements->start(MoneyAccount::query()->findOrFail((int) $d['money_account_id']), (string) $d['statement_date'], (string) $d['statement_balance'], $d['notes'] ?? null, $request->user());
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('cashbook.reconciliations.show', $r)->with('success', "{$r->reconciliation_no} started: tick the lines that are on the statement.");
    }

    public function showReconciliation(BankReconciliation $reconciliation): View
    {
        $this->authorize('cashbook.view');
        $reconciliation->load(['account', 'creator', 'completer', 'voider']);

        return view('cashbook.reconciliation', [
            'r' => $reconciliation, 'totals' => $this->statements->totals($reconciliation),
            'lines' => $reconciliation->isOpen() ? $this->statements->lines($reconciliation) : [],
            'matched' => $reconciliation->isCompleted() ? DB::table('bank_reconciliation_items')->where('bank_reconciliation_id', $reconciliation->id)->orderBy('line_date')->orderBy('id')->get() : collect(),
        ]);
    }

    public function saveReconciliation(Request $request, BankReconciliation $reconciliation): RedirectResponse
    {
        $this->authorize('cashbook.reconcile');
        $d = $request->validate(['lines' => ['nullable', 'array'], 'lines.*' => ['string', 'max:60'], 'complete' => ['nullable', 'boolean']]);

        try {
            $r = $this->statements->save($reconciliation, array_values($d['lines'] ?? []), (bool) ($d['complete'] ?? false), $request->user());
        } catch (DomainException $e) {
            // the ticks were not kept (the whole step is undone), so they are sent back to the page
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('cashbook.reconciliations.show', $r)->with('success', $r->isCompleted() ? "{$r->reconciliation_no}: the statement agrees with the book and is closed." : 'Ticks saved.');
    }

    public function voidReconciliation(Request $request, BankReconciliation $reconciliation): RedirectResponse
    {
        $this->authorize('cashbook.void');
        $d = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        try {
            $this->statements->void($reconciliation, $d['reason'], $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('cashbook.reconciliations.show', $reconciliation)->with('success', "{$reconciliation->reconciliation_no} cancelled.");
    }

    // ------------------------------------------------------------------------------------------------ helpers

    /** @return array{0: string, 1: string} the period of a book: today unless dates are given (never the wrong way round, never the future) */
    private function period(Request $request): array
    {
        $d = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $today = now()->toDateString();
        $from = ! empty($d['from']) ? date('Y-m-d', strtotime((string) $d['from'])) : $today;
        $to = ! empty($d['to']) ? date('Y-m-d', strtotime((string) $d['to'])) : max($from, $today);
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }
}
