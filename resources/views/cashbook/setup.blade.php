@php use App\Support\Money; use App\Models\MoneyAccount; @endphp
<x-layouts.app title="Cash & bank accounts">
    <div class="space-y-6">
        <div class="card overflow-x-auto">
            <div class="card-header"><h2 class="card-title">Accounts</h2></div>
            <table class="table">
                <thead><tr><th>Name</th><th>Kind</th><th>Account number</th><th>Note</th><th class="text-right">Holds now</th><th></th></tr></thead>
                <tbody>
                @foreach($accounts as $a)
                    <tr class="{{ $a->is_active ? '' : 'text-slate-400' }}">
                        <td colspan="4">
                            <form id="account-{{ $a->id }}" method="POST" action="{{ route('cashbook.accounts.update', $a) }}" class="grid gap-2 sm:grid-cols-4">
                                @csrf @method('PUT')
                                <input name="name" value="{{ $a->name }}" maxlength="60" required class="input" aria-label="Name">
                                <div class="flex items-center text-sm">{{ $a->kindLabel() }}@if($a->is_system) <span class="badge badge-gray ml-2">Main drawer</span>@endif</div>
                                <input name="account_number" value="{{ $a->account_number }}" maxlength="60" class="input" aria-label="Account number" placeholder="—">
                                <input name="notes" value="{{ $a->notes }}" maxlength="255" class="input" aria-label="Note" placeholder="—">
                            </form>
                        </td>
                        <td class="text-right font-medium"><a href="{{ route('cashbook.accounts.show', $a) }}">{{ Money::format($balances[$a->id] ?? '0') }}</a></td>
                        <td class="whitespace-nowrap text-right">
                            <button form="account-{{ $a->id }}" class="btn btn-secondary btn-sm">Save</button>
                            @unless($a->is_system)
                                <form method="POST" action="{{ route('cashbook.accounts.toggle', $a) }}" class="inline">@csrf<button class="btn btn-secondary btn-sm">{{ $a->is_active ? 'Switch off' : 'Switch on' }}</button></form>
                            @endunless
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <form method="POST" action="{{ route('cashbook.accounts.store') }}" class="grid gap-3 border-t border-slate-100 px-5 py-4 sm:grid-cols-5">
                @csrf
                <x-form.input name="name" label="New account" placeholder="e.g. HBL current account" maxlength="60" required />
                <x-form.select name="kind" label="Kind" :options="MoneyAccount::KINDS" value="bank" />
                <x-form.input name="account_number" label="Account number" maxlength="60" />
                <x-form.input name="notes" label="Note" maxlength="255" />
                <div class="flex items-end"><button class="btn btn-primary">Add the account</button></div>
            </form>
        </div>

        <div class="card overflow-x-auto">
            <div class="card-header"><h2 class="card-title">Where each payment method pays into</h2></div>
            <p class="px-5 pt-3 text-sm text-slate-600">Money taken or paid with a method lands in its account. A change applies <strong>from now on</strong>: what was already taken stays in the account it landed in (only money taken while a method had no account at all is put into the new one). Customer credit is not money and has no account.</p>
            <table class="table">
                <thead><tr><th>Payment method</th><th>Kind</th><th>Pays into</th><th></th></tr></thead>
                <tbody>
                @foreach($methods as $m)
                    <tr class="{{ $m->is_active ? '' : 'text-slate-400' }}">
                        <td class="font-medium">{{ $m->name }}@unless($m->is_active) <span class="badge badge-gray">Off</span>@endunless</td>
                        <td>{{ \App\Models\PaymentMethod::KINDS[$m->kind] ?? $m->kind }}</td>
                        <td>
                            <form id="method-{{ $m->id }}" method="POST" action="{{ route('cashbook.methods.link', $m) }}" >
                                @csrf @method('PUT')
                                <select name="money_account_id" class="input w-64" aria-label="Account for {{ $m->name }}">
                                    <option value="">— no account (not in any balance) —</option>
                                    @foreach($accounts as $a)
                                        @if($a->is_active || $m->money_account_id === $a->id)<option value="{{ $a->id }}" @selected($m->money_account_id === $a->id)>{{ $a->name }}</option>@endif
                                    @endforeach
                                </select>
                            </form>
                        </td>
                        <td class="text-right"><button form="method-{{ $m->id }}" class="btn btn-secondary btn-sm">Save</button></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        @php($drawers = $accounts->where('kind', 'cash')->where('is_active', true))
        <div class="card overflow-x-auto">
            <div class="card-header"><h2 class="card-title">One drawer per counter</h2></div>
            <p class="px-5 pt-3 text-sm text-slate-600">Everybody's cash goes to the main drawer unless you give a person a drawer of their own. Add a cash account above for each counter (for example “Counter 2 drawer”), then choose it here. From then on the cash that person takes at the till, refunds or pays out lands in their drawer, and each drawer is counted and closed on its own. A change applies from now on.</p>
            <table class="table">
                <thead><tr><th>Person</th><th>Role</th><th>Their cash goes to</th><th></th></tr></thead>
                <tbody>
                @foreach($people as $p)
                    <tr>
                        <td class="font-medium">{{ $p->name }}</td>
                        <td>{{ $p->roleName() }}</td>
                        <td>
                            <form id="person-{{ $p->id }}" method="POST" action="{{ route('cashbook.people.drawer', $p) }}">
                                @csrf @method('PUT')
                                <select name="money_account_id" class="input w-64" aria-label="Drawer of {{ $p->name }}">
                                    <option value="">The main drawer</option>
                                    @foreach($drawers as $a)
                                        @unless($a->is_system)<option value="{{ $a->id }}" @selected($p->money_account_id === $a->id)>{{ $a->name }}</option>@endunless
                                    @endforeach
                                </select>
                            </form>
                        </td>
                        <td class="text-right"><button form="person-{{ $p->id }}" class="btn btn-secondary btn-sm">Save</button></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div class="card card-body">
            <h2 class="card-title">Opening balances</h2>
            <p class="mt-1 text-sm text-slate-600">On the day you start using this book, enter what each account really holds: count the drawer, and read the bank and wallet balances. Once per account.</p>
            <a href="{{ route('cashbook.entries.create', ['type' => 'opening_balance']) }}" class="btn btn-secondary mt-3">Enter an opening balance</a>
        </div>

        <a href="{{ route('cashbook.index') }}" class="inline-block text-sm">← Cash &amp; bank</a>
    </div>
</x-layouts.app>
