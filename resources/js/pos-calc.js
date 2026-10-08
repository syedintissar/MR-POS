/**
 * Sale arithmetic for the till screen: the same rules as App\Services\Sales\SaleCalculator (PHP), done with exact integers (BigInt),
 * never floating point. The screen uses it to show totals while the cashier works; the SERVER recalculates everything when the sale
 * is completed and refuses the sale if its total differs from what was shown. tests/js and tests/Feature/Sales/CalculatorParityTest
 * check that both give identical figures on thousands of random sales.
 *
 * Money is held in paisa (scale 2), quantities in thousandths (scale 3), rates and percentages in hundredths (scale 2).
 */

/** Text like "12.5" or "1,200.00" into a scaled BigInt ("12.5" at scale 2 is 1250n). Anything unreadable is 0. Extra decimals round half up. */
export function parse(value, scale) {
    const s0 = String(value ?? '').trim().replace(/,/g, '');
    if (!/^-?\d*\.?\d*$/.test(s0) || !/\d/.test(s0)) return 0n;

    const negative = s0.startsWith('-');
    const s = negative ? s0.slice(1) : s0;
    const [whole = '', fraction = ''] = s.split('.');
    let n = BigInt((whole || '0') + fraction.padEnd(scale, '0').slice(0, scale));
    if (fraction.length > scale && fraction.charCodeAt(scale) - 48 >= 5) n += 1n;

    return negative ? -n : n;
}

/** A scaled BigInt back to text with exactly `scale` decimals. */
export function format(units, scale) {
    const negative = units < 0n;
    const s = (negative ? -units : units).toString().padStart(scale + 1, '0');
    const out = scale === 0 ? s : s.slice(0, -scale) + '.' + s.slice(-scale);

    return negative && /[1-9]/.test(out) ? '-' + out : out;
}

/** Display text with thousands separators: 1234567n -> "12,345.67" */
export function pretty(units, decimals = 2) {
    const text = format(decimals === 0 ? roundTo(units, 100n) / 100n * 100n : units, 2);
    const negative = text.startsWith('-');
    const [whole, fraction] = (negative ? text.slice(1) : text).split('.');
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');

    return (negative ? '-' : '') + grouped + (decimals === 0 ? '' : '.' + fraction);
}

/** n / d rounded half away from zero (d must not be 0). */
export function divRound(n, d) {
    if (d < 0n) {
        n = -n;
        d = -d;
    }
    const negative = n < 0n;
    const abs = negative ? -n : n;
    const q = (2n * abs + d) / (2n * d);

    return negative ? -q : q;
}

const roundTo = (n, step) => divRound(n, step) * step;

/**
 * Splits `amount` over the weights in whole paisas so the parts add up to it exactly (largest remainder method; an earlier line wins a tie).
 * @param {bigint} amount
 * @param {bigint[]} weights
 * @returns {bigint[]}
 */
export function share(amount, weights) {
    const out = weights.map(() => 0n);
    const total = weights.reduce((a, b) => a + b, 0n);
    if (amount === 0n || total === 0n) return out;

    const remainders = [];
    let given = 0n;
    weights.forEach((w, i) => {
        const product = amount * w;
        out[i] = product / total;
        remainders[i] = product % total;
        given += out[i];
    });

    let left = amount - given;
    const order = weights.map((_, i) => i).sort((a, b) => (remainders[b] > remainders[a] ? 1 : remainders[b] < remainders[a] ? -1 : a - b));
    for (const i of order) {
        if (left <= 0n) break;
        if (remainders[i] > 0n) {
            out[i] += 1n;
            left -= 1n;
        }
    }

    return out;
}

/**
 * @param {Array<{qty: string, price: string, discAmount?: string, discPct?: string, taxRate?: string}>} lines
 * @param {{invAmount?: string, invPct?: string, inclusive?: boolean, roundTo?: number}} options
 * @returns an object of text amounts with two decimals, plus per-line figures and `problems` (what the server would refuse)
 */
export function calculate(lines, options = {}) {
    const inclusive = !!options.inclusive;
    const problems = [];

    const gross = [];
    const lineDiscount = [];
    const after = [];
    lines.forEach((l, i) => {
        const qty = parse(l.qty, 3);
        const price = parse(l.price, 2);
        const g = divRound(qty * price, 1000n);

        let d;
        const pct = parse(l.discPct, 2);
        if (pct > 0n) {
            if (pct > 10000n) problems.push(`Line ${i + 1}: a discount cannot be more than 100%.`);
            d = divRound(g * pct, 10000n);
        } else {
            d = parse(l.discAmount, 2);
        }
        if (d < 0n) d = 0n;
        if (d > g) {
            problems.push(`Line ${i + 1}: the discount is larger than the line.`);
            d = g;
        }

        gross.push(g);
        lineDiscount.push(d);
        after.push(g - d);
    });
    const sumAfter = after.reduce((a, b) => a + b, 0n);

    let invoiceDiscount;
    const invPct = parse(options.invPct, 2);
    if (invPct > 0n) {
        invoiceDiscount = divRound(sumAfter * invPct, 10000n);
    } else {
        invoiceDiscount = parse(options.invAmount, 2);
    }
    if (invoiceDiscount < 0n) invoiceDiscount = 0n;
    if (invoiceDiscount > sumAfter) {
        problems.push('The invoice discount is larger than the total of the lines.');
        invoiceDiscount = sumAfter;
    }

    const shares = share(invoiceDiscount, after);

    const out = [];
    let itemsTotal = 0n;
    let lineDiscountTotal = 0n;
    let netTotal = 0n;
    let taxTotal = 0n;
    let beforeRound = 0n;
    lines.forEach((l, i) => {
        const rate = parse(l.taxRate, 2);
        const paid = after[i] - shares[i];

        let net;
        let tax;
        if (inclusive && rate > 0n) {
            net = divRound(paid * 10000n, 10000n + rate);
            tax = paid - net;
        } else {
            net = paid;
            tax = divRound(net * rate, 10000n);
        }
        const total = net + tax;

        out.push({
            gross: format(gross[i], 2), lineDiscount: format(lineDiscount[i], 2), share: format(shares[i], 2),
            net: format(net, 2), tax: format(tax, 2), total: format(total, 2),
        });
        itemsTotal += gross[i];
        lineDiscountTotal += lineDiscount[i];
        netTotal += net;
        taxTotal += tax;
        beforeRound += total;
    });

    const step = BigInt(options.roundTo || 0) * 100n;
    const grand = step > 0n ? roundTo(beforeRound, step) : beforeRound;

    return {
        lines: out,
        itemsTotal: format(itemsTotal, 2),
        lineDiscountTotal: format(lineDiscountTotal, 2),
        invoiceDiscount: format(invoiceDiscount, 2),
        netTotal: format(netTotal, 2),
        taxTotal: format(taxTotal, 2),
        beforeRound: format(beforeRound, 2),
        roundOff: format(grand - beforeRound, 2),
        grandTotal: format(grand, 2),
        grandUnits: grand,
        problems,
    };
}
