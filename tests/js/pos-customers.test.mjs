// Run with:  node --test "tests/js/*.test.mjs"
// The customer features of the till (Phase 6): choosing a customer, credit sales, customer credit, quick add.
import test from 'node:test';
import assert from 'node:assert/strict';
import { posApp } from '../../resources/js/pos.js';

const methods = [
    { id: 1, name: 'Cash', kind: 'cash', ref: false },
    { id: 2, name: 'Card', kind: 'card', ref: false },
    { id: 3, name: 'Easypaisa', kind: 'wallet', ref: true },
];

const can = { changePrice: false, discount: true, discountUnlimited: false, hold: true, complete: true, credit: true, overrideLimit: false, customers: true, addCustomer: true, seeBalance: true };

const cfg = (over = {}) => ({
    currency: 'Rs.', decimals: 2, taxEnabled: false, taxInclusive: false, taxLabel: 'GST', roundTo: 0, maxDiscountPercent: '5.00', allowNegative: false,
    defaultFormat: 'thermal', autoPrint: true, can, methods, creditMethodId: 7, customer: null,
    urls: { search: '/pos/products/search', quick: '/pos/products/quick', sale: '/pos/sales', approval: '/pos/approvals', held: '/pos/held', sales: '/sales', customerSearch: '/pos/customers/search', customerStore: '/pos/customers' },
    ...over,
});

const product = (id, over = {}) => ({ id, sku: `SKU-${id}`, barcode: null, name: `Product ${id}`, unit: 'pcs', allows_decimal: false, is_service: false, stock: '10', price: '150.00', wholesale_price: null, tax_rate: '0', warranty_months: null, ...over });

const person = (over = {}) => ({ id: 5, code: 'CUS-000005', name: 'Ali Traders', company: null, phone: '0300-1111111', tier: 'retail', allow_credit: true, credit_limit: '1000.00', balance: '0.00', advance: '0.00', ...over });

function fakeNet(answers = []) {
    const calls = [];
    const fetch = async (url, init = {}) => {
        const body = init.body ? JSON.parse(init.body) : undefined;
        calls.push({ url, method: init.method, body });
        const a = answers.length ? answers.shift() : { status: 200, data: [] };

        return { ok: a.status >= 200 && a.status < 300, status: a.status, json: async () => a.data };
    };

    return { calls, fetch };
}

const memory = () => { const m = new Map(); return { getItem: (k) => m.get(k) ?? null, setItem: (k, v) => m.set(k, v), removeItem: (k) => m.delete(k), m }; };

function till(net = fakeNet(), over = {}, storage = memory()) {
    const app = posApp(cfg(over), { fetch: net.fetch, csrf: 'token', storage });
    app.loadQuick = async () => {};
    return Object.assign(app, { _net: net, _storage: storage });
}

test('the walk-in customer must pay in full; a customer without credit terms too', () => {
    const t = till();
    t.add(product(1)); t.lines[0].qty = '2';   // 300
    t.openPayment();
    t.pay.rows[0].amount = '100';
    assert.match(t.payProblem, /walk-in customer pays in full/);
    assert.match(t.payProblem, /choose a customer/i);
    assert.equal(t.onAccount, '0.00');

    t.useCustomer(person({ allow_credit: false }));
    assert.match(t.payProblem, /not allowed credit/);
    assert.equal(t.onAccount, '0.00');
});

test('a credit customer can leave the rest on account; the till shows what they would owe', () => {
    const t = till();
    t.useCustomer(person({ balance: '200.00' }));
    t.add(product(1)); t.lines[0].qty = '2';   // 300
    t.openPayment();
    t.pay.rows[0].amount = '100';
    assert.equal(t.payProblem, '');
    assert.equal(t.onAccount, '200.00');
    assert.equal(t.balanceAfter, '400.00', '200 owed before + 300 sale - 100 paid');
    assert.equal(t.overLimit, false, 'the limit is 1000.00');
});

test('the credit limit hint compares what they would owe with the limit', () => {
    const t = till();
    t.useCustomer(person({ balance: '900.00', credit_limit: '1000.00' }));
    t.add(product(1)); t.lines[0].qty = '2';   // 300 on top of 900
    t.openPayment();
    t.pay.rows[0].amount = '100';
    assert.equal(t.balanceAfter, '1100.00');
    assert.equal(t.overLimit, true);

    t.pay.rows[0].amount = '300';   // paid in full: nothing goes on account, so the limit does not matter
    assert.equal(t.onAccount, '0.00');
    assert.equal(t.overLimit, false);

    t.useCustomer(person({ balance: '900.00', credit_limit: null }));
    t.pay.rows[0].amount = '100';
    assert.equal(t.overLimit, false, 'no limit set means unlimited');
});

test('a cashier who may not see balances still gets a working till (no figures, no limit hint)', () => {
    const t = till();
    t.useCustomer(person({ balance: null, advance: null, credit_limit: null }));
    t.add(product(1));
    t.openPayment();
    t.pay.rows[0].amount = '50';
    assert.equal(t.payProblem, '');
    assert.equal(t.balanceAfter, null);
    assert.equal(t.overLimit, false);
    assert.equal(t.availableMethods.length, methods.length, 'customer credit is not offered when the amount is unknown');
});

test('customer credit is offered only to a customer who holds some, and cannot be overspent', () => {
    const t = till();
    t.add(product(1)); t.lines[0].qty = '2';   // 300
    assert.equal(t.availableMethods.some((m) => m.kind === 'customer_credit'), false);

    t.useCustomer(person({ advance: '120.00', allow_credit: false, balance: '-120.00' }));
    assert.equal(t.availableMethods.some((m) => m.kind === 'customer_credit'), true);

    t.openPayment();
    t.pay.rows = [
        { method: 7, amount: '120.00', reference: '' },
        { method: 1, amount: '180.00', reference: '' },
    ];
    assert.equal(t.payProblem, '');
    assert.equal(t.creditUsed, 12000n);
    assert.equal(t.balanceAfter, '0.00', '-120 held + 300 sale - 180 paid now');

    t.pay.rows[0].amount = '150.00'; t.pay.rows[1].amount = '150.00';
    assert.match(t.payProblem, /has only 120\.00 of credit/);

    const p = t.payload();
    assert.equal(p.customer_id, 5);
    assert.deepEqual(p.payments[0], { payment_method_id: 7, amount: '150.00', reference_no: null });
});

test('a wholesale customer switches the price list, and going back to the walk-in customer undoes it', () => {
    const t = till();
    t.add(product(1, { wholesale_price: '120.00' }));
    assert.equal(t.total, '150.00');

    t.useCustomer(person({ tier: 'wholesale' }));
    assert.equal(t.tier, 'wholesale');
    assert.equal(t.total, '120.00');

    t.useCustomer(null);
    assert.equal(t.customer, null);
    assert.equal(t.tier, 'retail');
    assert.equal(t.total, '150.00');

    t.setTier('wholesale');   // chosen by hand
    t.useCustomer(person());
    assert.equal(t.tier, 'retail', 'a retail customer is sold at retail prices');
});

test('the sale is sent with the customer; after it the till goes back to the walk-in customer', async () => {
    const net = fakeNet([{ status: 201, data: { invoice_no: 'INV-9', due: '200.00', customer: 'Ali Traders', print: { thermal: '/t' } } }]);
    const t = till(net);
    t.useCustomer(person());
    t.add(product(1)); t.lines[0].qty = '2';
    t.openPayment();
    t.pay.rows[0].amount = '100';
    await t.complete();

    assert.equal(net.calls[0].body.customer_id, 5);
    assert.equal(t.done.due, '200.00');
    assert.equal(t.done.customer, 'Ali Traders');
    assert.equal(t.customer, null);
});

test('searching customers: an older answer cannot replace a newer one, and an empty box searches nothing', async () => {
    let release;
    const slow = new Promise((r) => { release = r; });
    const calls = [];
    const fetch = async (url) => {
        calls.push(url);
        if (url.endsWith('q=ali')) { await slow; return { ok: true, status: 200, json: async () => [person({ id: 1, name: 'Old answer' })] }; }
        return { ok: true, status: 200, json: async () => [person({ id: 2, name: 'New answer' })] };
    };
    const t = till({ calls, fetch });
    t.openCustomer();

    t.cust.query = '  ';
    await t.searchCustomers();
    assert.equal(calls.length, 0);

    t.cust.query = 'ali';
    const first = t.searchCustomers();
    t.cust.query = 'ali t';
    await t.searchCustomers();
    release();
    await first;
    assert.deepEqual(t.cust.results.map((c) => c.name), ['New answer']);
    assert.equal(t.cust.busy, false);
});

test('quick add: a phone number that is taken needs a confirmation, then the customer is selected', async () => {
    const net = fakeNet([
        { status: 409, data: { error: 'duplicate_phone', message: 'This phone number already belongs to Ali. Tick "save anyway".', names: ['Ali'] } },
        { status: 201, data: person({ id: 8, name: 'New Person', allow_credit: false, credit_limit: null }) },
    ]);
    const t = till(net);
    t.openCustomer();
    t.cust.query = '0300-1234567';
    t.startAddCustomer();
    assert.equal(t.cust.form.phone, '0300-1234567', 'a typed phone number goes to the phone box');
    assert.equal(t.cust.form.name, '');

    await t.quickAdd();
    assert.equal(net.calls.length, 0, 'a name is required first');
    assert.match(t.cust.error, /name/);

    t.cust.form.name = 'New Person';
    await t.quickAdd();
    assert.equal(t.cust.duplicate, true);
    assert.match(t.cust.error, /save anyway/);
    assert.equal(net.calls[0].body.allow_duplicate_phone, false);
    assert.equal(t.customer, null);

    t.cust.allow = true;
    await t.quickAdd();
    assert.equal(net.calls[1].body.allow_duplicate_phone, true);
    assert.equal(t.customer.id, 8);
    assert.equal(t.cust.open, false);
});

test('quick add is not offered without the permission, and a typed name goes to the name box', () => {
    const t = till(fakeNet(), { can: { ...can, addCustomer: false } });
    t.openCustomer();
    t.cust.query = 'Bilal';
    t.startAddCustomer();
    assert.equal(t.cust.adding, false);

    const u = till();
    u.openCustomer();
    u.cust.query = 'Bilal';
    u.startAddCustomer();
    assert.equal(u.cust.adding, true);
    assert.equal(u.cust.form.name, 'Bilal');
});

test('the customer is remembered with the cart, refreshed after a refresh, and held and resumed with it', async () => {
    const storage = memory();
    const a = till(fakeNet(), {}, storage);
    a.useCustomer(person({ balance: '50.00' }));
    a.add(product(1));
    assert.equal(JSON.parse(storage.getItem('mrpos.cart')).customer.id, 5);

    const net = fakeNet([{ status: 200, data: [person({ balance: '75.00' })] }]);
    const b = till(net, {}, storage);
    b.restore();
    assert.equal(b.customer.balance, '50.00');
    await b.refreshCustomer();
    assert.equal(b.customer.balance, '75.00', 'the balance on screen is brought up to date');
    assert.match(net.calls[0].url, /q=CUS-000005/);

    assert.equal(b.holdSnapshot().customer_id, 5);

    const resumeNet = fakeNet([
        { status: 200, data: { id: 3, label: 'x', cart: { lines: [{ id: 1, qty: '1', price: '150.00' }], tier: 'retail', customer_id: 5 }, products: [{ ...product(1), available: true }], customer: person({ id: 5, name: 'Ali Traders' }) } },
        { status: 200, data: {} },
    ]);
    const c = till(resumeNet);
    await c.doResume(3);
    assert.equal(c.customer.name, 'Ali Traders');

    const gone = fakeNet([
        { status: 200, data: { id: 4, label: 'y', cart: { lines: [{ id: 1, qty: '1', price: '150.00' }], tier: 'retail', customer_id: 5 }, products: [{ ...product(1), available: true }], customer: null } },
        { status: 200, data: {} },
    ]);
    const d = till(gone);
    await d.doResume(4);
    assert.equal(d.customer, null);
    assert.ok(d.warnings.some((w) => /no longer available/.test(w)));
});

test('a customer chosen on their own page is preselected, and sets the price list', () => {
    const t = till(fakeNet(), { customer: person({ tier: 'wholesale' }) });
    t.init();
    assert.equal(t.customer.id, 5);
    assert.equal(t.tier, 'wholesale');
});

test('F4 opens the customer box, Escape closes it, and it cannot open over the payment screen', () => {
    const t = till();
    t.add(product(1));
    const key = (k) => ({ key: k, preventDefault() {} });
    t.onKey(key('F4'));
    assert.equal(t.cust.open, true);
    t.onKey(key('Escape'));
    assert.equal(t.cust.open, false);

    t.openPayment();
    t.onKey(key('F4'));
    assert.equal(t.cust.open, false);

    const noCustomers = till(fakeNet(), { can: { ...can, customers: false } });
    noCustomers.onKey(key('F4'));
    assert.equal(noCustomers.cust.open, false, 'a user who may not see customers never gets the box');
});

test('"put it all on account" empties the payment, and only for customers with credit terms', () => {
    const t = till();
    t.add(product(1)); t.lines[0].qty = '2';
    t.useCustomer(person({ allow_credit: false }));
    t.openPayment();
    t.payOnAccount();
    assert.equal(t.pay.rows[0].amount, '300.00', 'a customer without credit terms keeps the exact-cash default');

    t.useCustomer(person());
    t.payOnAccount();
    assert.equal(t.pay.rows.length, 1);
    assert.equal(t.pay.rows[0].amount, '');
    assert.equal(t.payProblem, '');
    assert.equal(t.onAccount, '300.00');
    assert.deepEqual(t.payload().payments, [], 'nothing is paid now: the whole sale goes on the account');
});

test('"use their credit" pays with the held credit first and the rest in cash', () => {
    const t = till();
    t.add(product(1)); t.lines[0].qty = '2';   // 300
    t.useCustomer(person({ advance: '100.00', balance: '-100.00' }));
    t.openPayment();
    t.useCustomerCredit();
    assert.deepEqual(t.pay.rows.map((r) => [r.method, r.amount]), [[7, '100.00'], [1, '200.00']]);
    assert.equal(t.payProblem, '');

    t.useCustomer(person({ advance: '900.00', balance: '-900.00' }));
    t.useCustomerCredit();
    assert.deepEqual(t.pay.rows.map((r) => [r.method, r.amount]), [[7, '300.00']], 'more credit than the sale: only the sale total is used');
    assert.equal(t.payProblem, '');

    t.useCustomer(person());
    t.useCustomerCredit();
    assert.equal(t.pay.rows.length, 1, 'no credit held: nothing happens');
});
