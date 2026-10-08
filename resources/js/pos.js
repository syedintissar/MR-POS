import { calculate, format, parse, pretty } from './pos-calc.js';

/**
 * The till (Alpine component). It keeps the cart, shows totals, and sends the cart to the server, which prices and checks
 * everything again. Nothing here is trusted by the server: the prices, discounts and totals typed or shown are hints.
 *
 * The component is a plain object, so it can be exercised without a browser (tests/js/pos-app.test.mjs).
 */

/** A v4 UUID. crypto.randomUUID() only exists on https or localhost, but the tills open the site over plain http on the LAN. */
export function uuid(cryptoLike = globalThis.crypto) {
    const bytes = new Uint8Array(16);
    if (cryptoLike && cryptoLike.getRandomValues) {
        cryptoLike.getRandomValues(bytes);
    } else {
        for (let i = 0; i < 16; i++) bytes[i] = Math.floor(Math.random() * 256);
    }
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

const STORAGE_KEY = 'mrpos.cart';

export function posApp(cfg, env = {}) {
    const storage = env.storage ?? (() => { try { return globalThis.localStorage; } catch { return null; } })();
    const doFetch = env.fetch ?? ((...a) => globalThis.fetch(...a));
    const csrf = () => env.csrf ?? globalThis.document?.querySelector('meta[name="csrf-token"]')?.content ?? '';

    return {
        cfg,

        // ---- the cart
        lines: [],
        tier: 'retail',
        invMode: 'amt',          // 'amt' (rupees) or 'pct'
        invValue: '',
        notes: '',
        printNotes: false,
        key: uuid(),             // one per checkout attempt: the server never makes two sales from one key
        approvalToken: null,

        // ---- search
        query: '', results: [], quick: [], category: null, searching: false, timer: null, pendingEnter: false, searchSeq: 0,

        // ---- dialogs and messages
        pay: { open: false, rows: [], error: '' },
        approval: { open: false, reasons: [], username: '', password: '', error: '', busy: false, approver: null },
        hold: { open: false, label: '' },
        resume: { open: false, list: [], busy: false },
        submitting: false,
        error: '',
        warnings: [],
        done: null,              // the last completed sale
        printSrc: '',
        restored: false,

        _calcKey: '',
        _calc: null,

        // ------------------------------------------------------------------------------------------------ setup

        init() {
            this.loadQuick();
            this.restore();

            // Anything typed into the cart (a quantity box, a price, a note) is remembered, not only the buttons.
            if (this.$watch) {
                this.$watch('lines', () => this.save(), { deep: true });
                for (const field of ['tier', 'invMode', 'invValue', 'notes', 'printNotes']) this.$watch(field, () => this.save());
            }
        },

        // ------------------------------------------------------------------------------------------------ figures

        get calc() {
            const input = this.calcInput();
            const key = JSON.stringify(input);
            if (key !== this._calcKey) {
                this._calcKey = key;
                this._calc = input.lines.length
                    ? calculate(input.lines, input.options)
                    : { lines: [], itemsTotal: '0.00', lineDiscountTotal: '0.00', invoiceDiscount: '0.00', netTotal: '0.00', taxTotal: '0.00', roundOff: '0.00', grandTotal: '0.00', grandUnits: 0n, problems: [] };
            }

            return this._calc;
        },

        calcInput() {
            return {
                lines: this.lines.map((l) => ({
                    qty: l.qty, price: this.unitPrice(l), discAmount: l.discMode === 'amt' ? l.disc : '', discPct: l.discMode === 'pct' ? l.disc : '', taxRate: l.tax_rate,
                })),
                options: {
                    invAmount: this.invMode === 'amt' ? this.invValue : '', invPct: this.invMode === 'pct' ? this.invValue : '',
                    inclusive: this.cfg.taxInclusive, roundTo: this.cfg.roundTo,
                },
            };
        },

        /** The price the line is sold at: what the cashier typed, otherwise the price list for the chosen tier. */
        unitPrice(l) {
            if (l.priceEdited !== null && l.priceEdited !== undefined && String(l.priceEdited).trim() !== '') return l.priceEdited;

            return this.listPrice(l);
        },

        listPrice(l) {
            return this.tier === 'wholesale' && l.wholesale_price !== null && l.wholesale_price !== undefined ? l.wholesale_price : l.price;
        },

        lineTotal(i) {
            return this.calc.lines[i]?.total ?? '0.00';
        },

        money(text) {
            return pretty(parse(text, 2), this.cfg.decimals);
        },

        get total() {
            return this.calc.grandTotal;
        },

        get hasTax() {
            return this.cfg.taxEnabled && parse(this.calc.taxTotal, 2) > 0n;
        },

        get count() {
            return this.lines.reduce((n, l) => n + (parseFloat(l.qty) || 0), 0);
        },

        stockWarning(l) {
            if (l.is_service || this.cfg.allowNegative) return false;
            const stock = parseFloat(l.stock);

            return !isNaN(stock) && (parseFloat(l.qty) || 0) > stock;
        },

        get problems() {
            const out = [...this.calc.problems];
            this.lines.forEach((l, i) => {
                if (!(parseFloat(l.qty) > 0)) out.push(`Line ${i + 1}: enter a quantity.`);
                if (!l.allows_decimal && !Number.isInteger(parseFloat(l.qty))) out.push(`${l.name} is sold in whole ${l.unit}s.`);
                if (this.stockWarning(l)) out.push(`${l.name}: only ${l.stock} in stock.`);
            });

            return out;
        },

        // ------------------------------------------------------------------------------------------------ search

        async http(method, url, body) {
            const response = await doFetch(url, {
                method,
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: body === undefined ? undefined : JSON.stringify(body),
            });
            let data = null;
            try { data = await response.json(); } catch { data = null; }

            return { ok: response.ok, status: response.status, data };
        },

        async loadQuick() {
            try {
                const r = await this.http('GET', this.cfg.urls.quick);
                if (r.ok) this.quick = r.data;
            } catch { /* the till still works without the quick-pick grid */ }
        },

        onSearchInput() {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.search(), 150);
        },

        /** Enter in the search box: a scanner "types" the code and presses Enter. */
        onSearchEnter() {
            clearTimeout(this.timer);
            this.pendingEnter = true;
            return this.search();
        },

        async search() {
            const term = this.query.trim();
            if (term === '' && !this.category) {
                this.results = [];
                this.pendingEnter = false;

                return;
            }

            const seq = ++this.searchSeq;
            this.searching = true;
            try {
                const params = new URLSearchParams({ q: term });
                if (this.category) params.set('category', String(this.category));
                const r = await this.http('GET', `${this.cfg.urls.search}?${params}`);
                if (seq !== this.searchSeq) return;   // an older answer arriving late must not replace a newer one
                this.results = r.ok ? r.data : [];

                if (this.pendingEnter && term !== '') {
                    const lower = term.toLowerCase();
                    const exact = this.results.find((p) => (p.barcode && p.barcode === term) || p.sku.toLowerCase() === lower);
                    const only = this.results.length === 1 ? this.results[0] : null;
                    const pick = exact ?? only;
                    if (pick) {
                        this.add(pick);
                        this.query = '';
                        this.results = [];
                    }
                }
            } catch {
                if (seq === this.searchSeq) this.results = [];
            } finally {
                if (seq === this.searchSeq) {
                    this.searching = false;
                    this.pendingEnter = false;
                }
            }
        },

        pickCategory(id) {
            this.category = this.category === id ? null : id;
            this.query = '';
            this.search();
        },

        // ------------------------------------------------------------------------------------------------ the cart

        /** Adds one of the product, or +1 when it is already in the cart. */
        add(p) {
            this.done = null;
            const existing = this.lines.find((l) => l.id === p.id);
            if (existing) {
                existing.qty = this.bump(existing, 1);
            } else {
                this.lines.push({
                    id: p.id, sku: p.sku, name: p.name, unit: p.unit, allows_decimal: p.allows_decimal, is_service: p.is_service, stock: p.stock,
                    price: p.price, wholesale_price: p.wholesale_price, tax_rate: p.tax_rate, qty: '1',
                    priceEdited: null, disc: '', discMode: 'amt', serial: '', warranty_months: p.warranty_months ?? null,
                });
            }
            this.save();
        },

        bump(l, delta) {
            const step = l.allows_decimal ? 1 : 1;
            const next = (parseFloat(l.qty) || 0) + delta * step;

            return String(Math.max(l.allows_decimal ? 0.001 : 1, Math.round(next * 1000) / 1000));
        },

        inc(l) { l.qty = this.bump(l, 1); this.save(); },
        dec(l) { l.qty = this.bump(l, -1); this.save(); },
        remove(i) { this.lines.splice(i, 1); this.save(); },

        /** "+" and "-" on a quantity box change the quantity instead of typing a character. */
        onQtyKey(event, l) {
            if (event.key === '+') { event.preventDefault(); this.inc(l); }
            else if (event.key === '-') { event.preventDefault(); this.dec(l); }
        },

        clearCart() {
            this.lines = [];
            this.tier = 'retail';
            this.invMode = 'amt';
            this.invValue = '';
            this.notes = '';
            this.printNotes = false;
            this.approvalToken = null;
            this.warnings = [];
            this.error = '';
            this.key = uuid();
            this.save();
        },

        setTier(tier) {
            this.tier = tier;
            this.lines.forEach((l) => { l.priceEdited = null; });   // a different price list: the typed prices no longer apply
            this.save();
        },

        // ------------------------------------------------------------------------------------------------ keeping the cart safe

        save() {
            try {
                if (!storage) return;
                if (!this.lines.length) { storage.removeItem(STORAGE_KEY); return; }
                storage.setItem(STORAGE_KEY, JSON.stringify({ at: Date.now(), lines: this.lines, tier: this.tier, invMode: this.invMode, invValue: this.invValue, notes: this.notes, printNotes: this.printNotes, key: this.key }));
            } catch { /* private mode or full storage: the cart just is not remembered */ }
        },

        /** A cart that was open when the page was refreshed or closed by accident comes back (for up to 12 hours). */
        restore() {
            try {
                const raw = storage?.getItem(STORAGE_KEY);
                if (!raw) return;
                const s = JSON.parse(raw);
                if (!s.lines?.length || Date.now() - (s.at || 0) > 12 * 3600 * 1000) { storage.removeItem(STORAGE_KEY); return; }
                Object.assign(this, { lines: s.lines, tier: s.tier ?? 'retail', invMode: s.invMode ?? 'amt', invValue: s.invValue ?? '', notes: s.notes ?? '', printNotes: !!s.printNotes, key: s.key || uuid() });
                this.restored = true;
            } catch { /* ignore a damaged saved cart */ }
        },

        // ------------------------------------------------------------------------------------------------ payment

        get cashMethod() {
            return this.cfg.methods.find((m) => m.kind === 'cash') ?? this.cfg.methods[0];
        },

        method(id) {
            return this.cfg.methods.find((m) => m.id === Number(id));
        },

        openPayment() {
            if (!this.lines.length || this.submitting) return;
            if (this.problems.length) { this.error = this.problems[0]; return; }
            this.error = '';
            this.pay = { open: true, rows: [{ method: this.cashMethod?.id ?? null, amount: parse(this.total, 2) > 0n ? this.total : '', reference: '' }], error: '' };
        },

        closePayment() { this.pay.open = false; },

        addPayRow() {
            const left = this.remaining;
            const other = this.cfg.methods.find((m) => m.kind !== 'cash') ?? this.cashMethod;
            this.pay.rows.push({ method: other?.id ?? null, amount: parse(left, 2) > 0n ? left : '', reference: '' });
        },

        removePayRow(i) { this.pay.rows.splice(i, 1); },
        fillExact(row) {
            const others = this.pay.rows.filter((r) => r !== row).reduce((a, r) => a + parse(r.amount, 2), 0n);
            const left = parse(this.total, 2) - others;
            row.amount = left > 0n ? format(left, 2) : '';
        },

        get paid() {
            return format(this.pay.rows.reduce((a, r) => a + parse(r.amount, 2), 0n), 2);
        },

        get remaining() {
            const d = parse(this.total, 2) - parse(this.paid, 2);

            return d > 0n ? format(d, 2) : '0.00';
        },

        /** Cash handed over in excess of the total. Only cash can produce change. */
        get change() {
            const excess = parse(this.paid, 2) - parse(this.total, 2);
            if (excess <= 0n) return '0.00';
            const cash = this.pay.rows.filter((r) => this.method(r.method)?.kind === 'cash').reduce((a, r) => a + parse(r.amount, 2), 0n);

            return excess <= cash ? format(excess, 2) : '0.00';
        },

        get payProblem() {
            const total = parse(this.total, 2);
            const paid = parse(this.paid, 2);
            if (total === 0n) return this.pay.rows.some((r) => parse(r.amount, 2) > 0n) ? 'This sale is free: there is nothing to pay.' : '';
            if (paid < total) return `${pretty(total - paid, this.cfg.decimals)} is still missing. The walk-in customer pays in full.`;
            if (paid > total && this.change === '0.00') return 'Only cash can be handed over in excess; card, bank and wallet payments must be exact.';
            for (const r of this.pay.rows) {
                const m = this.method(r.method);
                if (!m) return 'Choose how each amount is paid.';
                if (m.ref && parse(r.amount, 2) > 0n && !String(r.reference).trim()) return `${m.name} needs a reference number.`;
            }

            return '';
        },

        // ------------------------------------------------------------------------------------------------ completing the sale

        payload() {
            return {
                idempotency_key: this.key,
                price_tier: this.tier,
                lines: this.lines.map((l) => {
                    const row = { product_id: l.id, quantity: String(l.qty) };
                    if (l.priceEdited !== null && l.priceEdited !== undefined && String(l.priceEdited).trim() !== '') row.unit_price = String(l.priceEdited);
                    if (String(l.disc).trim() !== '' && parse(l.disc, 2) > 0n) row[l.discMode === 'pct' ? 'discount_percent' : 'discount_amount'] = String(l.disc);
                    if (String(l.serial).trim() !== '') row.serial_number = String(l.serial).trim();
                    return row;
                }),
                ...(String(this.invValue).trim() !== '' && parse(this.invValue, 2) > 0n ? { [this.invMode === 'pct' ? 'discount_percent' : 'discount_amount']: String(this.invValue) } : {}),
                payments: this.pay.rows.filter((r) => parse(r.amount, 2) > 0n).map((r) => ({ payment_method_id: Number(r.method), amount: String(r.amount), reference_no: String(r.reference).trim() || null })),
                notes: this.notes.trim() || null,
                print_notes: this.printNotes,
                expected_total: this.total,
                approval_token: this.approvalToken,
            };
        },

        async complete() {
            if (this.submitting) return;
            if (this.payProblem) { this.pay.error = this.payProblem; return; }
            this.submitting = true;
            this.pay.error = '';
            try {
                const r = await this.http('POST', this.cfg.urls.sale, this.payload());
                await this.afterSale(r);
            } catch {
                this.pay.error = 'The server could not be reached. Nothing was charged. Check the connection and press Complete again: the same sale will not be made twice.';
            } finally {
                this.submitting = false;
            }
        },

        async afterSale(r) {
            if (r.ok) {
                this.done = r.data;
                this.pay.open = false;
                const format_ = this.cfg.defaultFormat;
                this.lines = [];
                this.clearCart();
                if (this.cfg.autoPrint) this.printSrc = r.data.print[format_];
                return;
            }

            const d = r.data || {};
            if (r.status === 409 && d.error === 'approval_required') {
                this.approval = { open: true, reasons: d.reasons || [], username: '', password: '', error: '', busy: false, approver: null };
            } else if (r.status === 409 && d.error === 'total_mismatch') {
                this.pay.error = d.message;
                await this.refreshPrices();
            } else if (r.status === 401 || r.status === 419) {
                this.pay.error = 'Your session has ended. Log in again in another tab; this cart is kept on this screen.';
            } else if (r.status === 422 && d.errors) {
                this.pay.error = Object.values(d.errors).flat()[0];
            } else {
                this.pay.error = d.message || 'The sale could not be completed.';
            }
        },

        /** After a "total changed" answer: fetch today's prices for what is in the cart. */
        async refreshPrices() {
            try {
                const r = await this.http('GET', `${this.cfg.urls.search}?ids=${this.lines.map((l) => l.id).join(',')}`);
                if (!r.ok) return;
                const warnings = [];
                for (const p of r.data) {
                    const l = this.lines.find((x) => x.id === p.id);
                    if (!l) continue;
                    if (l.price !== p.price || (l.wholesale_price ?? null) !== (p.wholesale_price ?? null)) warnings.push(`${l.name}: price is now ${this.money(p.price)}.`);
                    Object.assign(l, { price: p.price, wholesale_price: p.wholesale_price, tax_rate: p.tax_rate, stock: p.stock });
                }
                this.warnings = warnings;
                this.save();
            } catch { /* the cashier can still try again */ }
        },

        async askApproval() {
            if (this.approval.busy) return;
            this.approval.busy = true;
            this.approval.error = '';
            try {
                const r = await this.http('POST', this.cfg.urls.approval, { username: this.approval.username, password: this.approval.password });
                if (!r.ok) {
                    this.approval.error = r.data?.message || 'Approval refused.';
                    this.approval.password = '';

                    return;
                }
                this.approvalToken = r.data.token;
                this.approval = { open: false, reasons: [], username: '', password: '', error: '', busy: false, approver: r.data.approver };
                await this.complete();   // the same sale (same key) goes through again with the approval
            } catch {
                this.approval.error = 'The server could not be reached.';
            } finally {
                this.approval.busy = false;
            }
        },

        cancelApproval() {
            this.approval = { open: false, reasons: [], username: '', password: '', error: '', busy: false, approver: null };
        },

        // ------------------------------------------------------------------------------------------------ held carts

        openHold() {
            if (!this.lines.length || !this.cfg.can.hold) return;
            this.hold = { open: true, label: '' };
        },

        async doHold() {
            try {
                const cart = this.holdSnapshot();
                const r = await this.http('POST', this.cfg.urls.held, { label: this.hold.label, cart });
                if (!r.ok) { this.error = r.data?.message || 'The cart could not be held.'; this.hold.open = false; return; }
                this.hold.open = false;
                this.clearCart();
                this.warnings = [`Cart "${r.data.label}" is on hold.`];
            } catch {
                this.error = 'The server could not be reached; the cart was not held.';
            }
        },

        holdSnapshot() {
            return {
                lines: this.lines.map((l) => ({ id: l.id, qty: l.qty, price: l.price, priceEdited: l.priceEdited, disc: l.disc, discMode: l.discMode, serial: l.serial })),
                tier: this.tier, invMode: this.invMode, invValue: this.invValue, notes: this.notes, printNotes: this.printNotes,
                meta: { total: this.total },
            };
        },

        async openResume() {
            if (!this.cfg.can.hold) return;
            this.resume = { open: true, list: [], busy: true };
            try {
                const r = await this.http('GET', this.cfg.urls.held);
                this.resume.list = r.ok ? r.data : [];
            } finally {
                this.resume.busy = false;
            }
        },

        async doResume(id) {
            if (this.lines.length && !globalThis.confirm?.('Replace the cart on screen with the held one? (Hold the current cart first if you want to keep it.)')) return;
            const r = await this.http('GET', `${this.cfg.urls.held}/${id}`);
            if (!r.ok) { this.error = 'That held cart is no longer there.'; return; }

            const warnings = [];
            const lines = [];
            for (const saved of r.data.cart.lines || []) {
                const p = r.data.products.find((x) => x.id === saved.id);
                if (!p || !p.available) { warnings.push(`A product on this cart is no longer available and was left out.`); continue; }
                if (saved.price !== p.price) warnings.push(`${p.name}: price changed from ${this.money(saved.price)} to ${this.money(p.price)}; today's price is used.`);
                lines.push({
                    id: p.id, sku: p.sku, name: p.name, unit: p.unit, allows_decimal: p.allows_decimal, is_service: p.is_service, stock: p.stock,
                    price: p.price, wholesale_price: p.wholesale_price, tax_rate: p.tax_rate, qty: String(saved.qty),
                    priceEdited: saved.priceEdited ?? null, disc: saved.disc ?? '', discMode: saved.discMode ?? 'amt', serial: saved.serial ?? '', warranty_months: p.warranty_months ?? null,
                });
            }

            const c = r.data.cart;
            this.lines = lines;
            this.tier = c.tier ?? 'retail';
            this.invMode = c.invMode ?? 'amt';
            this.invValue = c.invValue ?? '';
            this.notes = c.notes ?? '';
            this.printNotes = !!c.printNotes;
            this.key = uuid();
            this.warnings = warnings;
            this.resume.open = false;
            this.save();
            await this.http('DELETE', `${this.cfg.urls.held}/${id}`);   // resumed: it is no longer "on hold"
        },

        async discardHeld(id) {
            await this.http('DELETE', `${this.cfg.urls.held}/${id}`);
            this.resume.list = this.resume.list.filter((h) => h.id !== id);
        },

        // ------------------------------------------------------------------------------------------------ keyboard

        onKey(event) {
            const k = event.key;
            if (k === 'Escape') {
                if (this.approval.open) this.cancelApproval();
                else if (this.pay.open) this.closePayment();
                else if (this.hold.open) this.hold.open = false;
                else if (this.resume.open) this.resume.open = false;
                return;
            }
            if (k === 'F2') { event.preventDefault(); globalThis.document?.getElementById('pos-search')?.focus(); }
            else if (k === 'F8') { event.preventDefault(); this.openHold(); }
            else if (k === 'F9') { event.preventDefault(); this.openPayment(); }
            else if (k === 'F10') { event.preventDefault(); if (this.pay.open) this.complete(); else this.openPayment(); }
        },
    };
}
