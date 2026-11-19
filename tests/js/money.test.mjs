// Run: node --test tests/js
// Verifies the offline POS maths produces exactly the same integers as the PHP TaxCalculator tests.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { allocate, calculate, divRound, format, parse } from '../../resources/js/offline/money.js';

test('rounding and allocation', () => {
    assert.equal(divRound(5, 3), 2);
    assert.equal(divRound(-5, 3), -2);
    assert.deepEqual(allocate(100, [1, 1, 1]).reduce((a, b) => a + b), 100);
    assert.deepEqual(allocate(1001, [333, 333, 334]).reduce((a, b) => a + b), 1001);
});

test('VAT inclusive 15% matches server (50000 → tax 6522)', () => {
    const r = calculate([{ quantity: 1, unit_price: 50000, discount: 0, tax_rate_bp: 1500 }], 0, true);
    assert.equal(r.total, 50000);
    assert.equal(r.tax_total, 6522);
});

test('VAT exclusive 15% matches server (50000 → 57500)', () => {
    const r = calculate([{ quantity: 1, unit_price: 50000, discount: 0, tax_rate_bp: 1500 }], 0, false);
    assert.equal(r.total, 57500);
    assert.equal(r.tax_total, 7500);
});

test('document discount spread across lines sums exactly', () => {
    const r = calculate([
        { quantity: 3, unit_price: 30000, discount: 0, tax_rate_bp: 0 },
        { quantity: 1, unit_price: 45000, discount: 0, tax_rate_bp: 0 },
    ], 8000, true);
    assert.equal(r.discount_total, 8000);
    assert.equal(r.total, 135000 - 8000);
});

test('parse and format without floats', () => {
    assert.equal(parse('1,250.50', 2), 125050);
    assert.equal(parse('١٩٫٩٩', 2), 1999);
    assert.equal(parse('12.345', 2), null);
    assert.equal(format(125050, 2), '1,250.50');
    assert.equal(format(5, 2), '0.05');
});
