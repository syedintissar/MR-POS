@php use App\Support\Money; @endphp
<x-layouts.app title="New cash & bank entry">
    <div class="mx-auto max-w-3xl">
        <form method="POST" action="{{ route('cashbook.entries.store') }}" class="card"
              x-data="{ type: @js(old('type', $type)), needsFrom() { return ['transfer', 'owner_out'].includes(this.type) }, needsTo() { return ['transfer', 'owner_in', 'opening_balance'].includes(this.type) } }">
            @csrf
            <div class="card-header"><h2 class="card-title">Money that moved without a sale, a purchase or an expense</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2"><label class="label" for="type">What happened <span class="text-red-500">*</span></label>
                    <select id="type" name="type" class="input" x-model="type" required>
                        @foreach($types as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
                    </select>
                    <p class="help" x-show="type === 'transfer'">For example the day's cash taken to the bank, or money drawn from the bank for the drawer.</p>
                    <p class="help" x-show="type === 'owner_out'" x-cloak>Money taken for yourself. It is not an expense of the shop, so profit does not change.</p>
                    <p class="help" x-show="type === 'owner_in'" x-cloak>Your own money put into the shop. It is not a sale, so profit does not change.</p>
                    <p class="help" x-show="type === 'opening_balance'" x-cloak>What the account held on the day you started using this book. Once per account.</p>
                </div>

                <div x-show="needsFrom()"><label class="label" for="from_account_id">Money leaves <span class="text-red-500">*</span></label>
                    <select id="from_account_id" name="from_account_id" class="input" :disabled="! needsFrom()">
                        <option value="">Choose…</option>
                        @foreach($accounts as $a)<option value="{{ $a->id }}" @selected((int) old('from_account_id', $account) === $a->id)>{{ $a->name }} ({{ Money::withSymbol($balances[$a->id] ?? '0') }})</option>@endforeach
                    </select></div>
                <div x-show="needsTo()"><label class="label" for="to_account_id">Money goes into <span class="text-red-500">*</span></label>
                    <select id="to_account_id" name="to_account_id" class="input" :disabled="! needsTo()">
                        <option value="">Choose…</option>
                        @foreach($accounts as $a)<option value="{{ $a->id }}" @selected((int) old('to_account_id') === $a->id)>{{ $a->name }} ({{ Money::withSymbol($balances[$a->id] ?? '0') }})</option>@endforeach
                    </select></div>

                <x-form.input name="amount" label="Amount (Rs.)" type="number" step="0.01" min="0.01" required />
                <x-form.input name="txn_date" label="Date" type="date" :value="now()->toDateString()" :max="now()->toDateString()" required />
                <x-form.input name="reference_no" label="Reference" help="Deposit slip or transaction number, if there is one" />
                <x-form.input name="description" label="Note" help="Who took it, which bank branch, why" />
            </div>
            <div class="flex justify-end gap-2 border-t border-slate-100 px-5 py-3">
                <a href="{{ route('cashbook.index') }}" class="btn btn-secondary">Cancel</a>
                <button class="btn btn-primary" data-loading-text="Saving…">Record the entry</button>
            </div>
        </form>
    </div>
</x-layouts.app>
