@php use App\Support\Money; use App\Models\DayClosing; @endphp
<x-layouts.app title="Close the day">
    <div class="mx-auto max-w-4xl space-y-5">
        @if($drawers->count() > 1)
            <div class="flex flex-wrap gap-2">
                @foreach($drawers as $d)<a href="{{ route('cashbook.closings.create', ['account' => $d->id]) }}" class="btn btn-sm {{ $d->id === $account->id ? 'btn-primary' : 'btn-secondary' }}">{{ $d->name }}</a>@endforeach
            </div>
        @endif

        <div class="grid gap-4 sm:grid-cols-4">
            <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">This morning</div><div class="mt-1 text-lg font-semibold text-slate-900">{{ Money::withSymbol($today['opening']) }}</div></div>
            <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">In today</div><div class="mt-1 text-lg font-semibold text-emerald-700">{{ Money::withSymbol($today['money_in']) }}</div></div>
            <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Out today</div><div class="mt-1 text-lg font-semibold text-red-700">{{ Money::withSymbol($today['money_out']) }}</div></div>
            <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Should be in {{ $account->name }}</div><div class="mt-1 text-lg font-semibold text-slate-900">{{ Money::withSymbol($expected) }}</div></div>
        </div>
        <p class="text-xs text-slate-500">
            @if($last)Last counted {{ $last->closed_at->format('d M Y, H:i') }} by {{ $last->creator?->name }} ({{ $last->closing_no }}).@else This drawer has never been counted. If it held money before you started, enter its opening balance first (Accounts).@endif
            <a href="{{ route('cashbook.accounts.show', $account) }}">See today's movements</a>.
        </p>

        <form method="POST" action="{{ route('cashbook.closings.store') }}" class="card"
              x-data="{
                  expected: {{ (float) $expected }},
                  notes: @js(collect(DayClosing::NOTES)->mapWithKeys(fn ($n) => [$n => (string) old('denominations.'.$n, '')])->all()),
                  coins: @js((string) old('denominations.coins', '')),
                  typed: @js((string) old('counted_amount', '')),
                  byNotes() { return Object.values(this.notes).some(v => v !== '' && v !== null) || (this.coins !== '' && this.coins !== null) },
                  total() {
                      if (! this.byNotes()) return Number(this.typed) || 0;
                      let t = Number(this.coins) || 0;
                      for (const [n, c] of Object.entries(this.notes)) t += Number(n) * (parseInt(c) || 0);
                      return Math.round(t * 100) / 100;
                  },
                  difference() { return Math.round((this.total() - this.expected) * 100) / 100 },
                  money(v) { return Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) },
              }">
            @csrf
            <input type="hidden" name="money_account_id" value="{{ $account->id }}">
            <div class="card-header"><h2 class="card-title">Count the cash in {{ $account->name }}</h2></div>
            <div class="card-body grid gap-6 md:grid-cols-2">
                <div>
                    <table class="table">
                        <thead><tr><th>Note</th><th class="w-28">How many</th><th class="text-right">Amount</th></tr></thead>
                        <tbody>
                        @foreach(DayClosing::NOTES as $n)
                            <tr>
                                <td class="font-medium">Rs. {{ number_format($n) }}</td>
                                <td><input type="number" min="0" step="1" inputmode="numeric" name="denominations[{{ $n }}]" x-model="notes[{{ $n }}]" class="input" aria-label="How many Rs. {{ $n }} notes"></td>
                                <td class="text-right" x-text="money({{ $n }} * (parseInt(notes[{{ $n }}]) || 0))"></td>
                            </tr>
                        @endforeach
                            <tr>
                                <td class="font-medium">Coins and small notes</td>
                                <td colspan="2"><input type="number" min="0" step="0.01" name="denominations[coins]" x-model="coins" class="input" placeholder="amount" aria-label="Coins and small notes, as an amount"></td>
                            </tr>
                        </tbody>
                    </table>
                    <div class="mt-3" x-show="! byNotes()">
                        <label class="label" for="counted_amount">…or type the total without counting note by note</label>
                        <input id="counted_amount" type="number" min="0" step="0.01" name="counted_amount" x-model="typed" class="input">
                    </div>
                </div>

                <div class="space-y-4">
                    <div class="rounded-md border border-slate-200 p-4">
                        <div class="flex justify-between text-sm text-slate-600"><span>The book says</span><span>{{ Money::withSymbol($expected) }}</span></div>
                        <div class="mt-1 flex justify-between text-sm text-slate-600"><span>You counted</span><span class="font-semibold text-slate-900" x-text="money(total())"></span></div>
                        <div class="mt-2 flex justify-between border-t border-slate-200 pt-2 text-base font-semibold"
                             :class="difference() === 0 ? 'text-emerald-700' : (difference() < 0 ? 'text-red-700' : 'text-amber-700')">
                            <span x-text="difference() === 0 ? 'Exact' : (difference() < 0 ? 'Short' : 'Over')"></span>
                            <span x-text="money(Math.abs(difference()))"></span>
                        </div>
                    </div>
                    <div>
                        <label class="label" for="notes">What explains the difference <span class="text-red-500" x-show="difference() !== 0">*</span></label>
                        <textarea id="notes" name="notes" rows="3" maxlength="255" class="input" :required="difference() !== 0">{{ old('notes') }}</textarea>
                        <p class="help">Needed when the count is short or over. The difference is recorded with your name so the book agrees with the drawer again.</p>
                    </div>
                </div>
            </div>
            <div class="flex justify-end gap-2 border-t border-slate-100 px-5 py-3">
                <a href="{{ route('cashbook.index') }}" class="btn btn-secondary">Cancel</a>
                <button class="btn btn-primary" data-loading-text="Closing…">Close the day</button>
            </div>
        </form>
    </div>
</x-layouts.app>
