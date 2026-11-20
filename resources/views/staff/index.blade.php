@php
    use App\Support\Decimal;
    use App\Support\Money;
    $monthKey = $month->format('Y-m');
@endphp
<x-layouts.app title="Staff salaries">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div><label class="label" for="month">Month</label><input id="month" type="month" name="month" value="{{ $monthKey }}" class="input w-44"></div>
            <div><label class="label" for="show">Show</label>
                <select id="show" name="show" class="input w-40"><option value="active" @selected(! $all)>Working now</option><option value="all" @selected($all)>Everybody</option></select></div>
            <button class="btn btn-secondary" data-no-loading>Show</button>
            <a href="{{ route('staff.index', ['month' => $month->subMonth()->format('Y-m'), 'show' => $all ? 'all' : 'active']) }}" class="btn btn-secondary">← {{ $month->subMonth()->format('M') }}</a>
            <a href="{{ route('staff.index', ['month' => $month->addMonth()->format('Y-m'), 'show' => $all ? 'all' : 'active']) }}" class="btn btn-secondary">{{ $month->addMonth()->format('M') }} →</a>
        </form>
        <a href="{{ route('expenses.index') }}" class="btn btn-secondary">All expenses</a>
    </div>

    <div class="mb-4 grid gap-4 sm:grid-cols-3">
        <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Salaries for {{ $month->format('F Y') }}</div><div class="mt-1 text-2xl font-semibold text-slate-900">{{ Money::withSymbol($totals['salary']) }}</div></div>
        <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Paid so far (advances and salary)</div><div class="mt-1 text-2xl font-semibold text-slate-900">{{ Money::withSymbol($totals['paid']) }}</div></div>
        <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Still to pay</div><div class="mt-1 text-2xl font-semibold {{ (float) $totals['left'] > 0 ? 'text-amber-600' : 'text-slate-900' }}">{{ Money::withSymbol($totals['left']) }}</div></div>
    </div>

    @if($canPay)
        <details class="card mb-4" @if($errors->has('name') || $errors->has('monthly_salary')) open @endif>
            <summary class="cursor-pointer px-5 py-3 text-sm font-semibold text-slate-800">+ Add an employee</summary>
            <form method="POST" action="{{ route('staff.store') }}" class="grid gap-4 border-t border-slate-100 p-5 sm:grid-cols-4">
                @csrf
                <x-form.input name="name" label="Name" required />
                <x-form.input name="job_title" label="Work" placeholder="Technician, helper…" />
                <x-form.input name="phone" label="Phone" />
                <x-form.input name="monthly_salary" label="Monthly salary" type="number" step="0.01" min="0" required />
                <x-form.input name="joined_on" label="Joined on" type="date" />
                <x-form.input name="notes" label="Note" class="sm:col-span-2" />
                <div class="flex items-end"><button class="btn btn-primary" data-loading-text="Saving…">Add employee</button></div>
            </form>
        </details>
    @endif

    <div class="space-y-4">
        @forelse($rows as $row)
            @php
                $e = $row['employee'];
                $left = $row['left'];
            @endphp
            <div class="card {{ $e->is_active ? '' : 'opacity-70' }}" x-data="{ edit: false }">
                <div class="card-body flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="text-base font-semibold text-slate-900">{{ $e->name }} @unless($e->is_active)<span class="badge badge-gray">left</span>@endunless</div>
                        <div class="text-sm text-slate-600">{{ collect([$e->job_title, $e->phone, $e->joined_on ? 'since '.$e->joined_on->format('M Y') : null])->filter()->implode(' · ') }}</div>
                        @if($e->notes)<div class="text-xs text-slate-500">{{ $e->notes }}</div>@endif
                    </div>
                    <div class="grid grid-cols-3 gap-6 text-right text-sm">
                        <div><div class="text-xs uppercase text-slate-500">Salary</div><div class="font-semibold">{{ Money::format($e->monthly_salary) }}</div></div>
                        <div><div class="text-xs uppercase text-slate-500">Paid</div><div class="font-semibold">{{ Money::format($row['paid']) }}</div></div>
                        <div><div class="text-xs uppercase text-slate-500">{{ Decimal::isNegative($left) ? 'Overpaid' : 'Still to pay' }}</div>
                            <div class="font-semibold {{ Decimal::isNegative($left) ? 'text-red-600' : (Decimal::isPositive($left) ? 'text-amber-600' : 'text-emerald-700') }}">{{ Money::format(Decimal::abs($left, 2)) }}</div></div>
                    </div>
                </div>

                @if($row['payments']->isNotEmpty())
                    <table class="table border-t border-slate-100">
                        <tbody>
                        @foreach($row['payments'] as $p)
                            <tr>
                                <td class="w-28 whitespace-nowrap">{{ $p->expense->expense_date->format('d M Y') }}</td>
                                <td>@if($p->type === 'advance')<span class="badge badge-amber">advance</span>@else<span class="badge badge-green">salary</span>@endif</td>
                                <td class="font-mono text-xs"><a href="{{ route('expenses.show', $p->expense_id) }}" title="Open the expense (cancel it there if it was a mistake)">{{ $p->expense->expense_no }}</a></td>
                                <td class="text-right font-medium">{{ Money::format($p->amount) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif

                @if($canPay)
                    <div class="flex flex-wrap items-end justify-between gap-3 border-t border-slate-100 px-5 py-3">
                        @if($e->is_active)
                            <form method="POST" action="{{ route('staff.pay', $e) }}" class="flex flex-wrap items-end gap-2">
                                @csrf
                                <input type="hidden" name="salary_month" value="{{ $monthKey }}">
                                <div><label class="label">Pay</label>
                                    <select name="type" class="input h-9 w-32"><option value="advance">Advance</option><option value="salary" @selected(Decimal::isPositive($left) && $row['payments']->isNotEmpty())>Salary</option></select></div>
                                <div><label class="label">Amount</label><input type="number" name="amount" step="0.01" min="0.01" required class="input h-9 w-32" placeholder="{{ Decimal::isPositive($left) ? Decimal::trim($left) : '' }}"></div>
                                <div><label class="label">Paid by</label>
                                    <select name="payment_method_id" class="input h-9 w-36">@foreach($methods as $m)<option value="{{ $m->id }}">{{ $m->name }}</option>@endforeach</select></div>
                                <div><label class="label">Note</label><input type="text" name="notes" maxlength="200" class="input h-9 w-44"></div>
                                <button class="btn btn-primary btn-sm" data-loading-text="Paying…" data-confirm="Pay {{ $e->name }} for {{ $month->format('F Y') }}? The money is recorded as a Salary expense now." data-confirm-label="Pay">Pay for {{ $month->format('M Y') }}</button>
                            </form>
                        @else
                            <span class="text-sm text-slate-500">No longer working here.</span>
                        @endif
                        <button type="button" class="btn btn-secondary btn-sm" data-no-loading @click="edit = !edit">Edit</button>
                    </div>
                    <form method="POST" action="{{ route('staff.update', $e) }}" class="grid gap-3 border-t border-slate-100 bg-slate-50 p-5 sm:grid-cols-4" x-show="edit" x-cloak>
                        @csrf @method('PUT')
                        <x-form.input name="name" label="Name" :value="$e->name" required :field="'name'" />
                        <x-form.input name="job_title" label="Work" :value="$e->job_title" :field="'job_title'" />
                        <x-form.input name="phone" label="Phone" :value="$e->phone" :field="'phone'" />
                        <x-form.input name="monthly_salary" label="Monthly salary" type="number" step="0.01" min="0" :value="Decimal::trim((string) $e->monthly_salary)" required :field="'monthly_salary'" />
                        <x-form.input name="joined_on" label="Joined on" type="date" :value="optional($e->joined_on)->toDateString()" :field="'joined_on'" />
                        <x-form.input name="notes" label="Note" :value="$e->notes" :field="'notes'" />
                        <x-form.checkbox name="is_active" label="Still working here" :checked="$e->is_active" :field="'is_active'" />
                        <div class="flex items-end"><button class="btn btn-primary btn-sm" data-loading-text="Saving…">Save</button></div>
                    </form>
                @endif
            </div>
        @empty
            <div class="card card-body py-12 text-center text-slate-500">No employees yet. @if($canPay)Add the first one above.@endif</div>
        @endforelse
    </div>

    <p class="mt-4 text-xs text-slate-500">Every advance and salary paid here is saved as a “Salary” expense, so it is in the cash book and the profit report already. Paid by mistake? Open the expense and cancel it: it stops counting here.</p>
</x-layouts.app>
