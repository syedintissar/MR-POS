<x-layouts.app title="Point of sale">
    <div x-data="posApp(@js($config))" @keydown.window="onKey($event)" class="space-y-3">

        {{-- the sale that was just completed --}}
        <template x-if="done">
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-3" role="status">
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Sale completed</div>
                    <div class="text-lg font-semibold text-emerald-900"><span x-text="done.invoice_no"></span> · <span x-text="cfg.currency + ' ' + money(done.total)"></span></div>
                    <div class="text-2xl font-bold text-emerald-800" x-show="parseFloat(done.change) > 0">Change to give: <span x-text="cfg.currency + ' ' + money(done.change)"></span></div>
                    <div class="text-sm text-emerald-800" x-show="done.customer">Sold to <strong x-text="done.customer"></strong></div>
                    <div class="text-xl font-bold text-amber-700" x-show="parseFloat(done.due || 0) > 0">Left on account: <span x-text="cfg.currency + ' ' + money(done.due)"></span></div>
                    <div class="text-xs text-emerald-700" x-show="done.replayed">This sale had already been recorded; no second sale was made.</div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a :href="done.print.thermal" target="_blank" class="btn btn-secondary btn-sm" data-no-loading>Print receipt</a>
                    <a :href="done.print.a4" target="_blank" class="btn btn-secondary btn-sm" data-no-loading>Print A4 invoice</a>
                    <a :href="done.view" class="btn btn-secondary btn-sm" data-no-loading>View invoice</a>
                    <button type="button" class="btn btn-primary btn-sm" data-no-loading @click="done = null; $nextTick(() => document.getElementById('pos-search').focus())">Next customer</button>
                </div>
            </div>
        </template>

        <template x-if="restored && lines.length">
            <div class="flex items-center justify-between rounded-md border border-amber-300 bg-amber-50 px-4 py-2 text-sm text-amber-900">
                <span>The cart from before the page was closed has been brought back.</span>
                <button type="button" class="text-amber-800 underline" data-no-loading @click="restored = false">OK</button>
            </div>
        </template>

        <template x-if="warnings.length">
            <div class="rounded-md border border-amber-300 bg-amber-50 px-4 py-2 text-sm text-amber-900">
                <template x-for="w in warnings" :key="w"><div x-text="w"></div></template>
                <button type="button" class="mt-1 text-xs underline" data-no-loading @click="warnings = []">Dismiss</button>
            </div>
        </template>

        <div class="grid gap-4 lg:grid-cols-12">
            {{-- ===================================================== find products --}}
            <div class="space-y-3 lg:col-span-7">
                <div class="card card-body space-y-3">
                    <div class="relative">
                        <label for="pos-search" class="sr-only">Find a product</label>
                        <input id="pos-search" type="search" x-model="query" @input="onSearchInput()" @keydown.enter.prevent="onSearchEnter()"
                               autocomplete="off" autofocus placeholder="Scan a barcode, or type a name, SKU or model   (F2)" class="input py-3 text-base">
                        <span x-show="searching" x-cloak class="absolute right-3 top-3.5 text-xs text-slate-400">searching…</span>
                    </div>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach($categories as $c)
                            <button type="button" data-no-loading @click="pickCategory({{ $c->id }})"
                                    :class="category === {{ $c->id }} ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'"
                                    class="rounded-full px-3 py-1 text-xs font-medium">{{ $c->name }}</button>
                        @endforeach
                    </div>
                </div>

                <div class="card card-body">
                    <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500" x-text="(query.trim() || category) ? 'Results' : 'Quick picks'"></div>
                    <template x-if="(query.trim() || category) && !results.length && !searching"><p class="py-6 text-center text-sm text-slate-500">Nothing found.</p></template>
                    <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                        <template x-for="p in ((query.trim() || category) ? results : quick)" :key="p.id">
                            <button type="button" data-no-loading @click="add(p); query = ''; results = []; category = null; $nextTick(() => document.getElementById('pos-search').focus())"
                                    class="rounded-lg border border-slate-200 bg-white p-3 text-left shadow-sm transition hover:border-brand-500 hover:shadow">
                                <div class="line-clamp-2 text-sm font-medium text-slate-900" x-text="p.name"></div>
                                <div class="mt-0.5 font-mono text-[11px] text-slate-500" x-text="p.sku"></div>
                                <div class="mt-1.5 flex items-end justify-between">
                                    <span class="text-base font-semibold text-slate-900" x-text="money(p.price)"></span>
                                    <span class="text-[11px]" :class="p.is_service ? 'text-slate-400' : (parseFloat(p.stock) <= 0 ? 'font-semibold text-red-600' : 'text-slate-500')"
                                          x-text="p.is_service ? 'service' : (parseFloat(p.stock) <= 0 ? 'out of stock' : p.stock + ' ' + p.unit)"></span>
                                </div>
                            </button>
                        </template>
                    </div>
                </div>
            </div>

            {{-- ===================================================== the cart --}}
            <div class="lg:col-span-5">
                <div class="card flex flex-col lg:sticky lg:top-16">
                    <div class="card-header">
                        <h2 class="card-title">Cart <span class="font-normal text-slate-500" x-show="lines.length" x-text="'· ' + lines.length + ' line(s)'"></span></h2>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <div class="inline-flex overflow-hidden rounded-md border border-slate-300 text-xs">
                                <button type="button" data-no-loading @click="setTier('retail')" :class="tier === 'retail' ? 'bg-brand-600 text-white' : 'bg-white text-slate-700'" class="px-2 py-1">Retail</button>
                                <button type="button" data-no-loading @click="setTier('wholesale')" :class="tier === 'wholesale' ? 'bg-brand-600 text-white' : 'bg-white text-slate-700'" class="px-2 py-1">Wholesale</button>
                            </div>
                            @if($config['can']['hold'])
                                <button type="button" class="btn btn-secondary btn-sm" data-no-loading :disabled="!lines.length" @click="openHold()" title="F8">Hold</button>
                                <button type="button" class="btn btn-secondary btn-sm" data-no-loading @click="openResume()">Held carts</button>
                            @endif
                            <button type="button" class="btn btn-secondary btn-sm text-red-600" data-no-loading :disabled="!lines.length" @click="if (confirm('Clear the whole cart?')) clearCart()">Clear</button>
                        </div>
                    </div>

                    @if($config['can']['customers'])
                        <div class="flex items-center justify-between gap-2 border-b border-slate-200 bg-slate-50 px-4 py-2 text-sm" id="pos-customer-bar">
                            <div class="min-w-0">
                                <template x-if="!customer"><span class="text-slate-600">Customer: <strong class="text-slate-900">Walk-in</strong> <span class="text-xs text-slate-500">(pays in full)</span></span></template>
                                <template x-if="customer">
                                    <div class="min-w-0">
                                        <div class="truncate font-medium text-slate-900"><span x-text="customer.name"></span> <span class="rounded bg-slate-200 px-1 text-[10px] uppercase text-slate-600" x-show="customer.tier === 'wholesale'">wholesale</span></div>
                                        <div class="truncate text-xs text-slate-500">
                                            <span x-text="customer.phone || customer.code"></span>
                                            <template x-if="customer.balance && parseFloat(customer.balance) > 0"><span class="text-amber-700"> · owes <span x-text="cfg.currency + ' ' + money(customer.balance)"></span></span></template>
                                            <template x-if="customer.advance && parseFloat(customer.advance) > 0"><span class="text-blue-700"> · holds credit <span x-text="cfg.currency + ' ' + money(customer.advance)"></span></span></template>
                                            <template x-if="!customer.allow_credit"><span> · cash only</span></template>
                                        </div>
                                    </div>
                                </template>
                            </div>
                            <div class="flex shrink-0 gap-1.5">
                                <button type="button" class="btn btn-secondary btn-sm" data-no-loading @click="openCustomer()" title="F4" x-text="customer ? 'Change (F4)' : 'Choose customer (F4)'"></button>
                                <button type="button" class="btn btn-secondary btn-sm" data-no-loading x-show="customer" @click="useCustomer(null)" aria-label="Back to the walk-in customer" title="Back to the walk-in customer">✕</button>
                            </div>
                        </div>
                    @endif

                    <p x-show="error" x-cloak class="border-b border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700" x-text="error"></p>

                    <div class="max-h-[46vh] divide-y divide-slate-100 overflow-y-auto">
                        <template x-if="!lines.length"><p class="px-4 py-10 text-center text-sm text-slate-500">The cart is empty. Scan a barcode or pick a product.</p></template>
                        <template x-for="(l, i) in lines" :key="l.id">
                            <div class="px-4 py-2.5" :class="stockWarning(l) ? 'bg-red-50' : ''">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <div class="truncate text-sm font-medium text-slate-900" x-text="l.name"></div>
                                        <div class="font-mono text-[11px] text-slate-500"><span x-text="l.sku"></span><span x-show="!l.is_service"> · in stock <span x-text="l.stock"></span></span></div>
                                    </div>
                                    <div class="text-right"><div class="text-sm font-semibold" x-text="money(lineTotal(i))"></div>
                                        <button type="button" class="text-xs text-red-600" data-no-loading @click="remove(i)" aria-label="Remove">remove</button></div>
                                </div>
                                <div class="mt-1.5 grid grid-cols-12 items-center gap-1.5 text-sm">
                                    <div class="col-span-5 flex items-center">
                                        <button type="button" class="h-8 w-8 rounded-l border border-slate-300 bg-white text-lg leading-none" data-no-loading @click="dec(l)" aria-label="Less">−</button>
                                        <input type="text" inputmode="decimal" x-model="l.qty" @keydown="onQtyKey($event, l)" class="h-8 w-full min-w-0 border-y border-slate-300 px-1 text-center" aria-label="Quantity">
                                        <button type="button" class="h-8 w-8 rounded-r border border-slate-300 bg-white text-lg leading-none" data-no-loading @click="inc(l)" aria-label="More">+</button>
                                    </div>
                                    <div class="col-span-4">
                                        <input type="text" inputmode="decimal" x-model="l.priceEdited" :placeholder="money(listPrice(l))" aria-label="Price"
                                               :class="(l.priceEdited !== null && String(l.priceEdited).trim() !== '') ? 'border-amber-400 bg-amber-50' : 'border-slate-300'"
                                               class="h-8 w-full rounded border px-1.5 text-right">
                                    </div>
                                    <div class="col-span-3 flex">
                                        <input type="text" inputmode="decimal" x-model="l.disc" placeholder="disc." aria-label="Line discount" class="h-8 w-full min-w-0 rounded-l border border-slate-300 px-1 text-right">
                                        <select x-model="l.discMode" class="h-8 rounded-r border border-l-0 border-slate-300 bg-white px-0.5 text-xs" aria-label="Discount type"><option value="amt" x-text="cfg.currency"></option><option value="pct">%</option></select>
                                    </div>
                                </div>
                                <template x-if="l.warranty_months">
                                    <input type="text" x-model="l.serial" maxlength="100" placeholder="Serial number (warranty)" class="mt-1.5 h-7 w-full rounded border border-slate-300 px-1.5 text-xs">
                                </template>
                            </div>
                        </template>
                    </div>

                    <div class="space-y-2 border-t border-slate-200 px-4 py-3 text-sm">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-slate-600">Invoice discount</span>
                            <span class="flex">
                                <input type="text" inputmode="decimal" x-model="invValue" placeholder="0" aria-label="Invoice discount" class="h-8 w-24 rounded-l border border-slate-300 px-1.5 text-right">
                                <select x-model="invMode" class="h-8 rounded-r border border-l-0 border-slate-300 bg-white px-0.5 text-xs" aria-label="Discount type"><option value="amt" x-text="cfg.currency"></option><option value="pct">%</option></select>
                            </span>
                        </div>
                        <details class="text-xs text-slate-500"><summary class="cursor-pointer">Note on the invoice</summary>
                            <textarea x-model="notes" rows="2" maxlength="1000" class="mt-1 w-full rounded border border-slate-300 px-2 py-1 text-sm"></textarea>
                            <label class="mt-1 flex items-center gap-1.5"><input type="checkbox" x-model="printNotes" class="rounded border-slate-300"> print this note on the invoice</label>
                        </details>
                        <div class="flex justify-between text-slate-600" x-show="parseFloat(calc.lineDiscountTotal) + parseFloat(calc.invoiceDiscount) > 0"><span>Discounts</span><span x-text="'− ' + money((parseFloat(calc.lineDiscountTotal) + parseFloat(calc.invoiceDiscount)).toFixed(2))"></span></div>
                        <div class="flex justify-between text-slate-600" x-show="hasTax"><span x-text="cfg.taxLabel + (cfg.taxInclusive ? ' (included)' : '')"></span><span x-text="money(calc.taxTotal)"></span></div>
                        <div class="flex justify-between text-slate-600" x-show="parseFloat(calc.roundOff) !== 0"><span>Round-off</span><span x-text="money(calc.roundOff)"></span></div>
                        <div class="flex items-baseline justify-between border-t border-slate-200 pt-2">
                            <span class="text-base font-semibold text-slate-700">Total</span>
                            <span class="text-3xl font-bold text-slate-900" x-text="cfg.currency + ' ' + money(total)"></span>
                        </div>
                        <ul class="text-xs text-red-600" x-show="problems.length" x-cloak><template x-for="p in problems" :key="p"><li x-text="p"></li></template></ul>
                        @if($config['can']['complete'])
                            <button type="button" class="btn btn-primary w-full py-3 text-base" data-no-loading :disabled="!lines.length || submitting" @click="openPayment()">Pay (F9)</button>
                        @else
                            <p class="text-xs text-slate-500">You can prepare carts but not complete sales.</p>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- ===================================================== payment --}}
        <div x-show="pay.open" x-cloak class="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/50 p-4">
            <div class="w-full max-w-lg rounded-lg bg-white p-5 shadow-xl" role="dialog" aria-modal="true" aria-label="Payment">
                <div class="flex items-baseline justify-between">
                    <h3 class="text-lg font-semibold text-slate-900">Payment</h3>
                    <div class="text-2xl font-bold" x-text="cfg.currency + ' ' + money(total)"></div>
                </div>
                <div class="mt-3 space-y-2">
                    <template x-for="(row, i) in pay.rows" :key="i">
                        <div class="grid grid-cols-12 items-center gap-1.5">
                            <select x-model.number="row.method" class="input col-span-4 h-10" aria-label="Payment method"><template x-for="m in availableMethods" :key="m.id"><option :value="m.id" x-text="m.name"></option></template></select>
                            <input type="text" inputmode="decimal" x-model="row.amount" @keydown.enter.prevent="complete()" class="input col-span-4 h-10 text-right text-lg" aria-label="Amount" :id="'pay-amount-' + i">
                            <button type="button" class="btn btn-secondary btn-sm col-span-2 h-10" data-no-loading @click="fillExact(row)">Exact</button>
                            <button type="button" class="col-span-2 h-10 text-sm text-red-600" data-no-loading x-show="pay.rows.length > 1" @click="removePayRow(i)">remove</button>
                            <input type="text" x-show="method(row.method)?.ref" x-model="row.reference" placeholder="Reference / transaction no." class="input col-span-12 h-9 text-sm">
                        </div>
                    </template>
                    <button type="button" class="text-sm" data-no-loading @click="addPayRow()">+ Add another payment method</button>
                </div>

                <div class="mt-4 grid grid-cols-2 gap-2 rounded-md bg-slate-50 p-3 text-sm">
                    <div>Paid <div class="text-lg font-semibold" x-text="money(paid)"></div></div>
                    <div class="text-right"><span x-show="parseFloat(change) > 0">Change to give</span><span x-show="parseFloat(change) <= 0 && parseFloat(remaining) > 0" x-text="customer && customer.allow_credit ? 'Left on account' : 'Still to pay'"></span>
                        <div class="text-2xl font-bold" :class="parseFloat(change) > 0 ? 'text-emerald-700' : 'text-amber-700'" x-text="parseFloat(change) > 0 ? money(change) : (parseFloat(remaining) > 0 ? money(remaining) : '0.00')"></div></div>
                </div>

                <template x-if="customer">
                    <div class="mt-2 rounded-md border border-slate-200 p-3 text-sm" id="pos-pay-customer">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span>Customer: <strong x-text="customer.name"></strong></span>
                            <span class="flex flex-wrap gap-1.5">
                                <button type="button" class="btn btn-secondary btn-sm" data-no-loading x-show="customer.allow_credit" @click="payOnAccount()">Put it all on account</button>
                                <button type="button" class="btn btn-secondary btn-sm" data-no-loading x-show="creditHeld > 0n" @click="useCustomerCredit()">Use their credit</button>
                            </span>
                        </div>
                        <template x-if="parseFloat(onAccount) > 0">
                            <div class="mt-1 text-amber-700">Left on account: <strong x-text="cfg.currency + ' ' + money(onAccount)"></strong>
                                <template x-if="balanceAfter !== null"><span class="text-slate-600"> · they will then owe <strong x-text="cfg.currency + ' ' + money(balanceAfter)"></strong></span></template>
                            </div>
                        </template>
                        <template x-if="overLimit">
                            <div class="mt-1 text-red-700">This is over their credit limit of <span x-text="cfg.currency + ' ' + money(customer.credit_limit)"></span>. A manager must approve it.</div>
                        </template>
                        <template x-if="creditHeld > 0n">
                            <div class="mt-1 text-blue-700">They hold <span x-text="cfg.currency + ' ' + money(customer.advance)"></span> of credit from earlier payments.</div>
                        </template>
                    </div>
                </template>

                <p class="mt-2 text-sm text-red-600" x-show="pay.error || payProblem" x-text="pay.error || payProblem"></p>

                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" class="btn btn-secondary" data-no-loading @click="closePayment()">Back (Esc)</button>
                    <button type="button" class="btn btn-primary px-6" data-no-loading :disabled="submitting || !!payProblem" @click="complete()"><span x-text="submitting ? 'Saving…' : 'Complete sale (F10)'"></span></button>
                </div>
            </div>
        </div>

        {{-- ===================================================== choose or add the customer --}}
        @if($config['can']['customers'])
            <div x-show="cust.open" x-cloak class="fixed inset-0 z-40 flex items-start justify-center bg-slate-900/50 p-4 pt-20">
                <div class="w-full max-w-lg rounded-lg bg-white p-5 shadow-xl" role="dialog" aria-modal="true" aria-label="Customer">
                    <h3 class="text-lg font-semibold text-slate-900" x-text="cust.adding ? 'Add a customer' : 'Choose the customer'"></h3>

                    <div x-show="!cust.adding">
                        <input id="pos-customer-search" type="search" x-model="cust.query" @input="onCustomerInput()" autocomplete="off" placeholder="Name, phone, code or company"
                               class="input mt-3" x-effect="if (cust.open && !cust.adding) $nextTick(() => $el.focus())">
                        <p class="py-4 text-center text-sm text-slate-500" x-show="!cust.query.trim()">Type a name, phone number or customer code.</p>
                        <p class="py-4 text-center text-sm text-slate-500" x-show="cust.query.trim() && !cust.busy && !cust.results.length">No customer found.</p>
                        <ul class="mt-2 max-h-72 divide-y divide-slate-100 overflow-y-auto">
                            <template x-for="c in cust.results" :key="c.id">
                                <li>
                                    <button type="button" data-no-loading class="flex w-full items-center justify-between gap-2 px-2 py-2 text-left hover:bg-slate-50" @click="useCustomer(c)">
                                        <span class="min-w-0">
                                            <span class="block truncate text-sm font-medium text-slate-900" x-text="c.name"></span>
                                            <span class="block truncate text-xs text-slate-500"><span x-text="c.phone || 'no phone'"></span> · <span x-text="c.code"></span><template x-if="c.company"><span> · <span x-text="c.company"></span></span></template></span>
                                        </span>
                                        <span class="shrink-0 text-right text-xs">
                                            <template x-if="c.balance && parseFloat(c.balance) > 0"><span class="block text-amber-700">owes <span x-text="money(c.balance)"></span></span></template>
                                            <template x-if="c.advance && parseFloat(c.advance) > 0"><span class="block text-blue-700">credit <span x-text="money(c.advance)"></span></span></template>
                                            <template x-if="c.tier === 'wholesale'"><span class="block text-slate-500">wholesale</span></template>
                                            <template x-if="!c.allow_credit"><span class="block text-slate-400">cash only</span></template>
                                        </span>
                                    </button>
                                </li>
                            </template>
                        </ul>
                        <div class="mt-4 flex flex-wrap justify-between gap-2">
                            <span class="flex gap-2">
                                <button type="button" class="btn btn-secondary" data-no-loading @click="useCustomer(null)">Walk-in customer</button>
                                @if($config['can']['addCustomer'])<button type="button" class="btn btn-secondary" data-no-loading @click="startAddCustomer()">Add a new customer</button>@endif
                            </span>
                            <button type="button" class="btn btn-secondary" data-no-loading @click="closeCustomer()">Close (Esc)</button>
                        </div>
                    </div>

                    @if($config['can']['addCustomer'])
                        <form x-show="cust.adding" @submit.prevent="quickAdd()" class="mt-3 space-y-2">
                            <input type="text" x-model="cust.form.name" maxlength="150" placeholder="Name" class="input" aria-label="Customer name" x-effect="if (cust.open && cust.adding) $nextTick(() => $el.focus())">
                            <input type="text" x-model="cust.form.phone" maxlength="30" placeholder="Phone number" inputmode="tel" class="input" aria-label="Phone number">
                            <input type="text" x-model="cust.form.company" maxlength="150" placeholder="Company (optional)" class="input" aria-label="Company">
                            <label class="flex items-center gap-2 text-sm text-amber-800" x-show="cust.duplicate"><input type="checkbox" x-model="cust.allow" class="rounded border-slate-300"> This is a different person with the same number: save anyway</label>
                            <p class="text-sm text-red-600" x-show="cust.error" x-text="cust.error"></p>
                            <p class="text-xs text-slate-500">Credit terms and wholesale prices are set by a manager on the customer's page.</p>
                            <div class="flex justify-end gap-2">
                                <button type="button" class="btn btn-secondary" data-no-loading @click="cust.adding = false; cust.error = ''">Back</button>
                                <button type="submit" class="btn btn-primary" data-no-loading :disabled="cust.busy"><span x-text="cust.busy ? 'Saving…' : 'Add and select'"></span></button>
                            </div>
                        </form>
                    @endif
                </div>
            </div>
        @endif

        {{-- ===================================================== manager approval --}}
        <div x-show="approval.open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4">
            <form @submit.prevent="askApproval()" class="w-full max-w-md rounded-lg bg-white p-5 shadow-xl" role="dialog" aria-modal="true" aria-label="Manager approval">
                <h3 class="text-lg font-semibold text-slate-900">Manager approval needed</h3>
                <ul class="mt-2 list-disc pl-5 text-sm text-slate-700"><template x-for="r in approval.reasons" :key="r.code"><li x-text="r.message"></li></template></ul>
                <p class="mt-2 text-xs text-slate-500">A manager types their own username and password here. Nothing is saved until you press Complete again.</p>
                <div class="mt-3 grid gap-2">
                    <input type="text" x-model="approval.username" autocomplete="off" placeholder="Manager username" class="input" required>
                    <input type="password" x-model="approval.password" autocomplete="off" placeholder="Manager password" class="input" required>
                </div>
                <p class="mt-2 text-sm text-red-600" x-show="approval.error" x-text="approval.error"></p>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" class="btn btn-secondary" data-no-loading @click="cancelApproval()">Cancel</button>
                    <button type="submit" class="btn btn-primary" data-no-loading :disabled="approval.busy"><span x-text="approval.busy ? 'Checking…' : 'Approve and complete'"></span></button>
                </div>
            </form>
        </div>

        {{-- ===================================================== hold / resume --}}
        <div x-show="hold.open" x-cloak class="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/50 p-4">
            <form @submit.prevent="doHold()" class="w-full max-w-sm rounded-lg bg-white p-5 shadow-xl">
                <h3 class="text-lg font-semibold text-slate-900">Hold this cart</h3>
                <p class="mt-1 text-xs text-slate-500">Put the customer's items aside and serve someone else. No stock is reserved.</p>
                <input type="text" x-model="hold.label" maxlength="100" placeholder="Name or note (optional)" class="input mt-3" x-effect="if (hold.open) $nextTick(() => $el.focus())">
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" class="btn btn-secondary" data-no-loading @click="hold.open = false">Cancel</button>
                    <button type="submit" class="btn btn-primary" data-no-loading>Hold</button>
                </div>
            </form>
        </div>

        <div x-show="resume.open" x-cloak class="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/50 p-4">
            <div class="w-full max-w-md rounded-lg bg-white p-5 shadow-xl">
                <h3 class="text-lg font-semibold text-slate-900">Held carts</h3>
                <p class="py-6 text-center text-sm text-slate-500" x-show="!resume.busy && !resume.list.length">No carts are on hold.</p>
                <ul class="mt-2 divide-y divide-slate-100">
                    <template x-for="h in resume.list" :key="h.id">
                        <li class="flex items-center justify-between gap-2 py-2">
                            <div><div class="text-sm font-medium" x-text="h.label"></div><div class="text-xs text-slate-500"><span x-text="h.lines"></span> line(s) · <span x-text="h.at"></span><span x-show="h.total"> · <span x-text="money(h.total)"></span></span></div></div>
                            <div class="flex gap-1"><button type="button" class="btn btn-primary btn-sm" data-no-loading @click="doResume(h.id)">Resume</button>
                                <button type="button" class="btn btn-secondary btn-sm text-red-600" data-no-loading @click="if (confirm('Discard this held cart?')) discardHeld(h.id)">Discard</button></div>
                        </li>
                    </template>
                </ul>
                <div class="mt-4 text-right"><button type="button" class="btn btn-secondary" data-no-loading @click="resume.open = false">Close</button></div>
            </div>
        </div>

        {{-- the receipt prints from here, out of sight --}}
        <iframe :src="printSrc || 'about:blank'" title="Receipt" aria-hidden="true" style="position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden"></iframe>
    </div>
</x-layouts.app>
