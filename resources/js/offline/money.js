// Integer-only money maths for the offline POS. Mirrors App\Modules\Core\Support\Money and
// App\Modules\Core\Services\TaxCalculator exactly, so offline totals equal server totals.

/** Integer division rounded half away from zero. */
export function divRound(n, d) {
    const sign = (n < 0) !== (d < 0) ? -1 : 1;
    const a = Math.abs(n);
    const b = Math.abs(d);
    return sign * Math.floor((a * 2 + b) / (b * 2));
}

/** Split total across weights (largest remainder); parts always sum to total. */
export function allocate(total, weights) {
    const sum = weights.reduce((s, w) => s + w, 0);
    if (sum === 0 || total === 0) return weights.map(() => 0);
    const out = [];
    const rem = [];
    let allocated = 0;
    weights.forEach((w, i) => {
        const share = Math.floor((total * w) / sum);
        out.push(share);
        rem.push([i, (total * w) % sum]);
        allocated += share;
    });
    rem.sort((a, b) => b[1] - a[1] || a[0] - b[0]);
    for (const [i] of rem) {
        if (allocated >= total) break;
        out[i]++;
        allocated++;
    }
    return out;
}

/**
 * @param lines [{quantity, unit_price, discount, tax_rate_bp}]
 * @returns {{lines, subtotal, discount_total, tax_total, total}}
 */
export function calculate(lines, documentDiscount, inclusive) {
    const grossBefore = lines.map((l) => Math.max(0, l.quantity * l.unit_price - l.discount));
    const sumGross = grossBefore.reduce((s, g) => s + g, 0);
    if (documentDiscount < 0 || documentDiscount > sumGross) throw new Error('Discount exceeds the amount.');
    const extra = allocate(documentDiscount, grossBefore);
    const out = { lines: [], subtotal: 0, discount_total: 0, tax_total: 0, total: 0 };
    lines.forEach((l, i) => {
        const lineSubtotal = l.quantity * l.unit_price;
        const discount = Math.min(lineSubtotal, l.discount + (extra[i] || 0));
        const gross = lineSubtotal - discount;
        let tax;
        let total;
        if (inclusive) {
            tax = l.tax_rate_bp === 0 ? 0 : divRound(gross * l.tax_rate_bp, 10000 + l.tax_rate_bp);
            total = gross;
        } else {
            tax = divRound(gross * l.tax_rate_bp, 10000);
            total = gross + tax;
        }
        out.lines.push({ ...l, discount, tax_amount: tax, line_total: total });
        out.subtotal += lineSubtotal;
        out.discount_total += discount;
        out.tax_total += tax;
        out.total += total;
    });
    return out;
}

/** "1,250.50" / "١٢٥٠٫٥" → minor units (integer). Returns null when invalid. */
export function parse(value, decimals) {
    if (value === null || value === undefined || String(value).trim() === '') return 0;
    let s = String(value).trim().replace(/[,\s٬]/g, '');
    s = s.replace(/[٠-٩]/g, (d) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d))).replace('٫', '.');
    const m = /^(-?)(\d+)(?:\.(\d+))?$/.exec(s);
    if (!m) return null;
    const frac = m[3] || '';
    if (frac.length > decimals) return null;
    const minor = parseInt(m[2] + frac.padEnd(decimals, '0'), 10);
    return m[1] === '-' ? -minor : minor;
}

export function format(minor, decimals, symbol = '') {
    const neg = minor < 0;
    let s = String(Math.abs(minor));
    if (decimals > 0) {
        s = s.padStart(decimals + 1, '0');
        s = s.slice(0, -decimals) + '.' + s.slice(-decimals);
    }
    const [int, frac] = s.split('.');
    const grouped = int.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return (neg ? '-' : '') + grouped + (frac !== undefined ? '.' + frac : '') + (symbol ? ' ' + symbol : '');
}
