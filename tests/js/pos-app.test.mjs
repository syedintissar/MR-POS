// Run with:  node --test "tests/js/*.test.mjs"
import test from 'node:test';
import assert from 'node:assert/strict';
import { posApp, uuid } from '../../resources/js/pos.js';

const methods = [
    { id: 1, name: 'Cash', kind: 'cash', ref: false },
    { id: 2, name: 'Card', kind: 'card', ref: false },
    { id: 3, name: 'Easypaisa', kind: 'wallet', ref: true },
];

const cfg = (over = {}) => ({
    currency: 'Rs.', decimals: 2, taxEnabled: false, taxInclusive: false, taxLabel: 'GST', roundTo: 0, maxDiscountPercent: '5.00', allowNegative: false,
    defaultFormat: 'thermal', autoPrint: true, can: { changePrice: false, discount: true, discountUnlimited: false, hold: true, complete: true },
    methods,
    urls: { search: '/pos/products/search', quick: '/pos/products/quick', sale: '/pos/sales', approval: '/pos/approvals', held: '/pos/held', sales: '/sales' },
    ...over,
});

const product = (id, over = {}) => ({ id, sku: `SKU-${id}`, barcode: null, name: `Product ${id}`, unit: 'pcs', allows_decimal: false, is_service: false, stock: '10', price: '150.00', wholesale_price: null, tax_rate: '0', warranty_months: null, ...over });

/** A fake server: records every request and answers from a queue (or a default). */
function fakeNet(answers = []) {
    const calls = [];
    const fetch = async (url, init = {}) => {
        const body = init.body ? JSON.parse(init.body) : undefined;
        calls.push({ url, method: init.method, body });
        const a = answers.length ? answers.shift() : { status: 200, data: [] };
        const answer = typeof a === 'function' ? a({ url, method: init.method, body }) : a;

        return { ok: answer.status >= 200 && answer.status < 300, status: answer.status, json: async () => answer.data };
    };

    return { calls, fetch };
}

const memory = () => { const m = new Map(); return { getItem: (k) => m.get(k) ?? null, setItem: (k, v) => m.set(k, v), removeItem: (k) => m.delete(k), m }; };

function till(over = {}, net = fakeNet(), storage = memory()) {
    const app = posApp(cfg(over), { fetch: net.fetch, csrf: 'token', storage });
    app.loadQuick = async () => {};   // not part of these tests
    return Object.assign(app, { _net: net, _storage: storage });
}

test('a checkout key is a version 4 UUID, with or without the secure-context crypto', () => {
    const re = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
    assert.match(uuid(), re);
    assert.match(uuid(null), re, 'the fallback used on plain-http LAN pages');
    assert.notEqual(uuid(), uuid());
});

test('adding a product twice raises the quantity; whole-unit products never take fractions', () => {
    const t = till();
    t.add(product(1));
    t.add(product(1));
    assert.equal(t.lines.length, 1);
    assert.equal(t.lines[0].qty, '2');

    t.dec(t.lines[0]); t.dec(t.lines[0]); t.dec(t.lines[0]);
    assert.equal(t.lines[0].qty, '1', 'a quantity never drops below one whole unit');

    t.add(product(2, { unit: 'g', allows_decimal: true }));
    t.lines[1].qty = '2.5';
    t.inc(t.lines[1]);
    assert.equal(t.lines[1].qty, '3.5');
    t.lines[1].qty = '0.2'; t.dec(t.lines[1]);
    assert.equal(t.lines[1].qty, '0.001');
});

test('totals follow quantity, a typed price, discounts and the invoice discount', () => {
    const t = till();
    t.add(product(1)); t.lines[0].qty = '2';          // 300
    t.add(product(2, { price: '200.00' }));           // 200
    assert.equal(t.total, '500.00');

    t.lines[1].priceEdited = '180';
    assert.equal(t.total, '480.00');

    t.lines[0].disc = '10'; t.lines[0].discMode = 'pct';   // 30 off
    assert.equal(t.total, '450.00');

    t.invMode = 'pct'; t.invValue = '10';                  // 10% of 450
    assert.equal(t.calc.invoiceDiscount, '45.00');
    assert.equal(t.total, '405.00');
    assert.equal(t.money(t.total), '405.00');
});

test('tax and round-off follow the shop settings', () => {
    const t = till({ taxEnabled: true, roundTo: 10 });
    t.add(product(1, { price: '1000.00', tax_rate: '17.00' }));
    t.lines[0].qty = '1';
    assert.equal(t.calc.taxTotal, '170.00');
    assert.equal(t.total, '1170.00');
    assert.equal(t.hasTax, true);

    t.add(product(2, { price: '4.00', tax_rate: '0' }));
    assert.equal(t.calc.roundOff, '-4.00', '1174 rounds to 1170');
});

test('the wholesale list switches prices and forgets typed prices', () => {
    const t = till();
    t.add(product(1, { price: '150.00', wholesale_price: '130.00' }));
    t.add(product(2, { price: '90.00' }));
    t.lines[0].priceEdited = '100';
    t.setTier('wholesale');
    assert.equal(t.lines[0].priceEdited, null);
    assert.equal(t.total, '220.00', 'wholesale 130 + normal 90 where there is no wholesale price');
    t.setTier('retail');
    assert.equal(t.total, '240.00');
});

test('stock warnings and quantity problems are caught before the server is asked', () => {
    const t = till();
    t.add(product(1, { stock: '2' })); t.lines[0].qty = '3';
    assert.ok(t.problems.some((p) => p.includes('only 2')));
    t.openPayment();
    assert.equal(t.pay.open, false, 'the payment screen does not open on a cart with a problem');

    t.lines[0].qty = '1.5';
    assert.ok(t.problems.some((p) => p.includes('whole')));

    const allowed = till({ allowNegative: true });
    allowed.add(product(1, { stock: '2' })); allowed.lines[0].qty = '3';
    assert.deepEqual(allowed.problems, []);

    const service = till();
    service.add(product(9, { is_service: true, stock: null })); service.lines[0].qty = '40';
    assert.deepEqual(service.problems, [], 'services have no stock to run out of');
});

test('the payment screen starts with exact cash and works out the change', () => {
    const t = till();
    t.add(product(1)); t.lines[0].qty = '2';
    t.openPayment();
    assert.equal(t.pay.rows[0].amount, '300.00');
    assert.equal(t.payProblem, '');

    t.pay.rows[0].amount = '500';
    assert.equal(t.change, '200.00');
    assert.equal(t.payProblem, '');

    t.pay.rows[0].amount = '250';
    assert.match(t.payProblem, /still missing/);
    assert.equal(t.remaining, '50.00');
});

test('split payments: the remainder goes to the next line, and only cash can be overpaid', () => {
    const t = till();
    t.add(product(1)); t.lines[0].qty = '2';
    t.openPayment();
    t.pay.rows[0].amount = '100';
    t.addPayRow();
    assert.equal(t.pay.rows[1].amount, '200.00');
    assert.equal(t.pay.rows[1].method, 2, 'the second line defaults to a non-cash method');
    assert.equal(t.payProblem, '');

    t.pay.rows[1].amount = '250';   // card 250 + cash 100 = 350: the 50 extra is change out of the cash, which is fine
    assert.equal(t.payProblem, '');
    assert.equal(t.change, '50.00');

    t.pay.rows[0].amount = '0'; t.pay.rows[1].amount = '350';   // the card alone paying more than the total
    assert.match(t.payProblem, /Only cash/);

    t.pay.rows[0].amount = '100';
    t.pay.rows[1].method = 3; t.pay.rows[1].amount = '200';
    assert.match(t.payProblem, /reference/);
    t.pay.rows[1].reference = 'EP-1';
    assert.equal(t.payProblem, '');

    t.pay.rows[0].amount = '';
    t.fillExact(t.pay.rows[0]);
    assert.equal(t.pay.rows[0].amount, '100.00');
});

test('a free sale needs no payment', () => {
    const t = till();
    t.add(product(1, { price: '0.00' }));
    t.openPayment();
    assert.equal(t.pay.rows[0].amount, '');
    assert.equal(t.payProblem, '');
});

test('the payload sends what was bought, only typed prices, and the total the screen showed', () => {
    const t = till();
    t.add(product(1)); t.lines[0].qty = '2'; t.lines[0].serial = ' SN-1 ';
    t.add(product(2)); t.lines[1].priceEdited = '120'; t.lines[1].disc = '5'; t.lines[1].discMode = 'pct';
    t.invValue = '10';
    t.notes = ' hello '; t.printNotes = true;
    t.openPayment();
    t.pay.rows[0].amount = '1000';

    const p = t.payload();
    assert.deepEqual(p.lines[0], { product_id: 1, quantity: '2', serial_number: 'SN-1' });
    assert.deepEqual(p.lines[1], { product_id: 2, quantity: '1', unit_price: '120', discount_percent: '5' });
    assert.equal(p.discount_amount, '10');
    assert.equal(p.expected_total, t.total);
    assert.deepEqual(p.payments, [{ payment_method_id: 1, amount: '1000', reference_no: null }]);
    assert.equal(p.notes, 'hello');
    assert.equal(p.print_notes, true);
    assert.equal(p.approval_token, null);
    assert.match(p.idempotency_key, /^[0-9a-f-]{36}$/);
});

test('a completed sale clears the cart, starts a new checkout key and prints automatically', async () => {
    const net = fakeNet([{ status: 201, data: { sale_id: 5, invoice_no: 'INV-000005', total: '300.00', change: '0.00', print: { thermal: '/sales/5/print/thermal?sig', a4: '/sales/5/print/a4?sig' } } }]);
    const t = till({}, net);
    t.add(product(1)); t.lines[0].qty = '2';
    const firstKey = t.key;
    t.openPayment();
    await t.complete();

    assert.equal(net.calls[0].url, '/pos/sales');
    assert.equal(net.calls[0].body.idempotency_key, firstKey);
    assert.equal(t.done.invoice_no, 'INV-000005');
    assert.equal(t.lines.length, 0);
    assert.notEqual(t.key, firstKey);
    assert.equal(t.printSrc, '/sales/5/print/thermal?sig');
    assert.equal(t.pay.open, false);
    assert.equal(t._storage.getItem('mrpos.cart'), null, 'nothing left to restore');
});

test('a failed connection keeps the cart and the SAME checkout key, so retrying cannot sell twice', async () => {
    const net = fakeNet();
    net.fetch = async () => { throw new TypeError('network down'); };
    const t = till({}, net);
    t.add(product(1));
    const key = t.key;
    t.openPayment();
    await t.complete();

    assert.match(t.pay.error, /Nothing was charged/);
    assert.equal(t.lines.length, 1);
    assert.equal(t.key, key);
    assert.equal(t.submitting, false);
});

test('a second press while the first is still running is ignored', async () => {
    let release;
    const gate = new Promise((r) => { release = r; });
    let calls = 0;
    const t = till();
    t.add(product(1)); t.openPayment();
    t.http = async () => { calls++; await gate; return { ok: true, status: 201, data: { invoice_no: 'INV-1', print: {} } }; };

    const first = t.complete();
    const second = t.complete();
    release();
    await Promise.all([first, second]);
    assert.equal(calls, 1);
});

test('when a manager must approve, the same sale is sent again with the token', async () => {
    const net = fakeNet([
        { status: 409, data: { error: 'approval_required', message: 'Manager approval is needed', reasons: [{ code: 'discount_limit', message: 'the discount is more than 5%', permission: 'sales.discount_unlimited' }] } },
        { status: 200, data: { token: 'tok123', approver: 'Boss', seconds: 120 } },
        { status: 201, data: { sale_id: 7, invoice_no: 'INV-000007', total: '285.00', change: '0.00', print: { thermal: '/t', a4: '/a' } } },
    ]);
    const t = till({}, net);
    t.add(product(1)); t.lines[0].disc = '10'; t.lines[0].discMode = 'pct';
    const key = t.key;
    t.openPayment();
    await t.complete();

    assert.equal(t.approval.open, true);
    assert.equal(t.approval.reasons[0].code, 'discount_limit');
    assert.equal(t.done, null);
    assert.equal(t.lines.length, 1, 'the cart is untouched while waiting');

    t.approval.username = 'boss'; t.approval.password = 'secret';
    await t.askApproval();

    assert.deepEqual(net.calls[1].body, { username: 'boss', password: 'secret' });
    assert.equal(net.calls[2].url, '/pos/sales');
    assert.equal(net.calls[2].body.approval_token, 'tok123');
    assert.equal(net.calls[2].body.idempotency_key, key, 'it is the same checkout');
    assert.equal(t.done.invoice_no, 'INV-000007');
    assert.equal(t.approval.password, '');
});

test('a refused approval keeps the dialog open and clears the password', async () => {
    const net = fakeNet([{ status: 422, data: { message: 'Approval refused: wrong username or password' } }]);
    const t = till({}, net);
    t.approval = { open: true, reasons: [], username: 'boss', password: 'wrong', error: '', busy: false, approver: null };
    await t.askApproval();

    assert.equal(t.approval.open, true);
    assert.match(t.approval.error, /refused/);
    assert.equal(t.approval.password, '');
    assert.equal(t.approvalToken, null);
});

test('when the total changed on the server, today\'s prices are fetched and the cashier is told', async () => {
    const net = fakeNet([
        { status: 409, data: { error: 'total_mismatch', message: 'The total changed from 300.00 to 320.00', server_total: '320.00' } },
        { status: 200, data: [product(1, { price: '160.00' })] },
    ]);
    const t = till({}, net);
    t.add(product(1)); t.lines[0].qty = '2';
    t.openPayment();
    await t.complete();

    assert.match(t.pay.error, /total changed/);
    assert.equal(t.lines[0].price, '160.00');
    assert.equal(t.total, '320.00');
    assert.ok(t.warnings[0].includes('160.00'));
    assert.ok(net.calls[1].url.includes('ids=1'));
});

test('stock and validation answers from the server are shown, never swallowed', async () => {
    for (const [status, data, text] of [
        [422, { error: 'insufficient_stock', message: 'Not enough stock for "Toner"' }, /Not enough stock/],
        [422, { message: 'The given data was invalid.', errors: { 'lines.0.quantity': ['Enter a quantity greater than zero.'] } }, /quantity greater/],
        [419, {}, /session/],
        [500, null, /could not be completed/],
    ]) {
        const t = till({}, fakeNet([{ status, data }]));
        t.add(product(1)); t.openPayment();
        await t.complete();
        assert.match(t.pay.error, text);
        assert.equal(t.lines.length, 1);
    }
});

test('searching: Enter adds an exact barcode or SKU match, and an older answer cannot replace a newer one', async () => {
    const net = fakeNet([{ status: 200, data: [product(7, { barcode: '6001', name: 'Scanned toner' }), product(8)] }]);
    const t = till({}, net);
    t.query = '6001';
    await t.onSearchEnter();
    assert.equal(t.lines.length, 1);
    assert.equal(t.lines[0].name, 'Scanned toner');
    assert.equal(t.query, '', 'the box is ready for the next scan');

    // two searches in flight: the first (slow) answer must be ignored
    let releaseFirst;
    const slow = new Promise((r) => { releaseFirst = r; });
    let n = 0;
    const t2 = till();
    t2.http = async () => { const mine = ++n; if (mine === 1) await slow; return { ok: true, status: 200, data: [product(mine)] }; };
    t2.query = 'a'; const first = t2.search();
    t2.query = 'ab'; await t2.search();
    releaseFirst(); await first;
    assert.equal(t2.results[0].id, 2, 'the newest search wins');
});

test('searching with several matches and no exact one does not guess', async () => {
    const t = till({}, fakeNet([{ status: 200, data: [product(1), product(2)] }]));
    t.query = 'toner';
    await t.onSearchEnter();
    assert.equal(t.lines.length, 0);
    assert.equal(t.results.length, 2);
});

test('the cart survives an accidental refresh, but not a stale one', () => {
    const storage = memory();
    const a = till({}, fakeNet(), storage);
    a.add(product(1)); a.lines[0].qty = '3';
    a.notes = 'for Ahmed';
    a.save();   // in the page, Alpine's watchers call this after every change

    const b = till({}, fakeNet(), storage);
    b.restore();
    assert.equal(b.lines[0].qty, '3');
    assert.equal(b.notes, 'for Ahmed');
    assert.equal(b.restored, true);
    assert.equal(b.key, a.key, 'the same checkout key: a sale that already went through is not repeated');

    const old = JSON.parse(storage.getItem('mrpos.cart'));
    old.at = Date.now() - 13 * 3600 * 1000;
    storage.setItem('mrpos.cart', JSON.stringify(old));
    const c = till({}, fakeNet(), storage);
    c.restore();
    assert.equal(c.lines.length, 0);
    assert.equal(storage.getItem('mrpos.cart'), null);

    storage.setItem('mrpos.cart', '{not json');
    assert.doesNotThrow(() => till({}, fakeNet(), storage).restore());
});

test('holding a cart saves it on the server and clears the till', async () => {
    const net = fakeNet([{ status: 201, data: { id: 3, label: 'Mr Ali' } }]);
    const t = till({}, net);
    t.add(product(1)); t.lines[0].qty = '2';
    t.openHold();
    t.hold.label = 'Mr Ali';
    await t.doHold();

    assert.equal(net.calls[0].url, '/pos/held');
    assert.equal(net.calls[0].body.label, 'Mr Ali');
    assert.deepEqual(net.calls[0].body.cart.lines, [{ id: 1, qty: '2', price: '150.00', priceEdited: null, disc: '', discMode: 'amt', serial: '' }]);
    assert.equal(net.calls[0].body.cart.meta.total, '300.00');
    assert.equal(t.lines.length, 0);
});

test('a hold is refused for people without the permission, and for an empty cart', () => {
    const t = till({ can: { hold: false, complete: true } });
    t.add(product(1)); t.openHold();
    assert.equal(t.hold.open, false);

    const empty = till();
    empty.openHold();
    assert.equal(empty.hold.open, false);
});

test('resuming warns about changed prices and drops products that are gone', async () => {
    const saved = { lines: [{ id: 1, qty: '2', price: '150.00', priceEdited: null, disc: '', discMode: 'amt', serial: '' }, { id: 2, qty: '1', price: '90.00', priceEdited: null, disc: '', discMode: 'amt', serial: '' }], tier: 'retail', invMode: 'amt', invValue: '', notes: 'x', printNotes: false, meta: { total: '390.00' } };
    const net = fakeNet([
        { status: 200, data: { id: 4, label: 'L', cart: saved, products: [{ ...product(1, { price: '170.00' }), available: true }, { ...product(2), available: false }] } },
        { status: 200, data: { ok: true } },
    ]);
    const t = till({}, net);
    await t.doResume(4);

    assert.equal(t.lines.length, 1);
    assert.equal(t.lines[0].price, '170.00', "today's price is used");
    assert.equal(t.total, '340.00');
    assert.ok(t.warnings.some((w) => w.includes('170.00')));
    assert.ok(t.warnings.some((w) => w.includes('no longer available')));
    assert.equal(net.calls[1].method, 'DELETE', 'a resumed cart is no longer on hold');
});

test('function keys: F9 opens payment, F10 completes, Escape closes the top dialog', async () => {
    const net = fakeNet([{ status: 201, data: { invoice_no: 'INV-1', print: { thermal: '/t' } } }]);
    const t = till({}, net);
    t.add(product(1));
    const key = (k) => ({ key: k, preventDefault() {} });

    t.onKey(key('F9'));
    assert.equal(t.pay.open, true);
    t.onKey(key('Escape'));
    assert.equal(t.pay.open, false);

    t.onKey(key('F10'));
    assert.equal(t.pay.open, true);
    t.onKey(key('F10'));
    await new Promise((r) => setTimeout(r, 0));
    assert.equal(net.calls.length, 1);
});

test('plus and minus on a quantity box change the quantity', () => {
    const t = till();
    t.add(product(1));
    let prevented = 0;
    const l = t.lines[0];
    t.onQtyKey({ key: '+', preventDefault: () => prevented++ }, l);
    assert.equal(l.qty, '2');
    t.onQtyKey({ key: '-', preventDefault: () => prevented++ }, l);
    assert.equal(l.qty, '1');
    t.onQtyKey({ key: '5', preventDefault: () => prevented++ }, l);
    assert.equal(prevented, 2, 'other keys are left alone');
});
