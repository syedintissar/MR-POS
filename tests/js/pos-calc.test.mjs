// Run with:  php tests/js/gen_parity_cases.php <file> && node --test tests/js   (the cases file path is passed in PARITY_CASES)
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { calculate, divRound, format, parse, pretty, share } from '../../resources/js/pos-calc.js';

test('parse and format round-trip decimal text exactly', () => {
    assert.equal(parse('12.5', 2), 1250n);
    assert.equal(parse('1,200.00', 2), 120000n);
    assert.equal(parse('0.005', 2), 1n, 'extra decimals round half up');
    assert.equal(parse('0.004', 2), 0n);
    assert.equal(parse('-3.10', 2), -310n);
    assert.equal(parse('', 2), 0n);
    assert.equal(parse('abc', 2), 0n);
    assert.equal(parse('1e3', 2), 0n, 'an exponent is not a number here');
    assert.equal(format(1250n, 2), '12.50');
    assert.equal(format(-5n, 2), '-0.05');
    assert.equal(format(0n, 2), '0.00');
    assert.equal(format(12345n, 3), '12.345');
});

test('divRound rounds half away from zero', () => {
    assert.equal(divRound(5n, 10n), 1n);
    assert.equal(divRound(4n, 10n), 0n);
    assert.equal(divRound(-5n, 10n), -1n);
    assert.equal(divRound(15n, 10n), 2n);
});

test('pretty groups thousands and honours the decimals setting', () => {
    assert.equal(pretty(123456789n, 2), '1,234,567.89');
    assert.equal(pretty(150n, 2), '1.50');
    assert.equal(pretty(150n, 0), '2');
    assert.equal(pretty(-250000n, 2), '-2,500.00');
});

test('a discount is shared out to the paisa', () => {
    assert.deepEqual(share(10000n, [10000n, 10000n, 10000n]), [3334n, 3333n, 3333n]);
    assert.deepEqual(share(0n, [100n, 200n]), [0n, 0n]);
});

test('the simple cases the cashier meets every day', () => {
    const r = calculate([{ qty: '2', price: '500' }, { qty: '1', price: '99.50' }]);
    assert.equal(r.grandTotal, '1099.50');
    assert.equal(r.roundOff, '0.00');

    const t = calculate([{ qty: '1', price: '1000', taxRate: '17' }]);
    assert.equal(t.grandTotal, '1170.00');
    assert.equal(t.taxTotal, '170.00');

    const inc = calculate([{ qty: '1', price: '1170', taxRate: '17' }], { inclusive: true });
    assert.equal(inc.lines[0].net, '1000.00');
    assert.equal(inc.grandTotal, '1170.00');

    const rounded = calculate([{ qty: '1', price: '1234' }], { roundTo: 10 });
    assert.equal(rounded.grandTotal, '1230.00');
    assert.equal(rounded.roundOff, '-4.00');
});

test('mistakes are clamped and reported, never thrown', () => {
    const r = calculate([{ qty: '1', price: '100', discAmount: '150' }]);
    assert.equal(r.grandTotal, '0.00');
    assert.equal(r.problems.length, 1);
    assert.equal(calculate([{ qty: 'x', price: '' }]).grandTotal, '0.00');
});

const file = process.env.PARITY_CASES;
test('the browser and the server agree on every random sale', { skip: !(file && existsSync(file)) && 'set PARITY_CASES to the file written by gen_parity_cases.php' }, () => {
    const cases = JSON.parse(readFileSync(file, 'utf8'));
    assert.ok(cases.length > 1000, 'expected thousands of cases');

    for (const [n, c] of cases.entries()) {
        const lines = c.lines.map((l) => ({ qty: l.quantity, price: l.unit_price, discAmount: l.discount_amount, discPct: l.discount_percent, taxRate: l.tax_rate }));
        const got = calculate(lines, {
            invAmount: c.options.discount_amount, invPct: c.options.discount_percent, inclusive: c.options.tax_inclusive, roundTo: c.options.round_to,
        });
        const want = c.expected;

        const where = `case ${n}: ${JSON.stringify(c)}`;
        assert.equal(got.grandTotal, want.grand_total, `grand total, ${where}`);
        assert.equal(got.roundOff, want.round_off, `round-off, ${where}`);
        assert.equal(got.itemsTotal, want.items_total, `items total, ${where}`);
        assert.equal(got.lineDiscountTotal, want.line_discount_total, `line discounts, ${where}`);
        assert.equal(got.invoiceDiscount, want.invoice_discount_amount, `invoice discount, ${where}`);
        assert.equal(got.taxTotal, want.tax_total, `tax, ${where}`);
        assert.equal(got.netTotal, want.net_total, `net, ${where}`);
        got.lines.forEach((l, i) => {
            const w = want.lines[i];
            assert.deepEqual([l.gross, l.lineDiscount, l.share, l.net, l.tax, l.total], [w.gross, w.line_discount, w.invoice_discount_share, w.net_amount, w.tax_amount, w.line_total], `line ${i + 1}, ${where}`);
        });
    }
});
