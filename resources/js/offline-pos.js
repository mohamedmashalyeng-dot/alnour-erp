// Offline-first POS. Works without connectivity using the IndexedDB catalog snapshot, queues sales
// encrypted at rest, and synchronizes them (idempotent by UUID) when the connection returns.
import { calculate, format, parse } from './offline/money.js';
import * as store from './offline/store.js';

const root = document.getElementById('offline-pos');
const T = JSON.parse(root.dataset.i18n);
const URLS = JSON.parse(root.dataset.urls);
const BRANCHES = JSON.parse(root.dataset.branches);
const csrf = () => document.querySelector('meta[name="csrf-token"]').content;
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

const state = {
    meta: null, products: [], customers: [], cart: new Map(), customerId: null, discount: '', payments: [],
    search: '', online: navigator.onLine, syncing: false, authProblem: null, message: null, queue: [],
};

// ------------------------------------------------------------------ server calls

async function request(url, options = {}) {
    const res = await fetch(url, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
        ...options,
    });
    if (res.status === 401 || res.status === 419) {
        state.authProblem = T.reauth;
        throw Object.assign(new Error('auth'), { auth: true });
    }
    if (res.status === 403) {
        state.authProblem = T.device_problem;
        throw Object.assign(new Error('device'), { auth: true });
    }
    if (!res.ok) {
        const body = await res.json().catch(() => ({}));
        throw Object.assign(new Error(body.message || res.statusText), { status: res.status });
    }
    return res.json();
}

async function refreshCatalog() {
    const data = await request(URLS.bootstrap);
    await store.replaceAll('products', data.products);
    await store.replaceAll('customers', data.customers);
    await store.kvSet('meta', { device: data.device, user: data.user, settings: data.settings, version: data.version, fetched_at: new Date().toISOString() });
    await loadLocal();
}

async function loadLocal() {
    state.meta = (await store.kvGet('meta')) || null;
    state.products = await store.all('products');
    state.customers = await store.all('customers');
    state.queue = await store.queue();
}

async function sync() {
    if (state.syncing || !navigator.onLine) return;
    state.syncing = true;
    render();
    try {
        const pending = (await store.queue()).filter((q) => q.status === 'pending' || q.status === 'retry');
        for (let i = 0; i < pending.length; i += 20) {
            const batch = pending.slice(i, i + 20);
            const sales = await Promise.all(batch.map((q) => store.decrypt(q.box)));
            const { results } = await request(URLS.sync, { method: 'POST', body: JSON.stringify({ sales }) });
            for (const r of results) {
                await store.update(r.uuid, { status: r.status, invoice_number: r.invoice_number || null, message: r.message || null, synced_at: new Date().toISOString() });
            }
        }
        state.authProblem = null;
        if (pending.length) await refreshCatalog(); // pull fresh stock after posting sales
    } catch (e) {
        if (!e.auth) {
            // Network / server error: keep for automatic retry; validation failures come back per-sale.
            for (const q of (await store.queue()).filter((x) => x.status === 'pending' || x.status === 'retry')) {
                await store.update(q.uuid, { status: 'retry', attempts: (q.attempts || 0) + 1, message: e.message });
            }
        }
    } finally {
        state.syncing = false;
        state.queue = await store.queue();
        render();
    }
}

// ------------------------------------------------------------------ cart

const decimals = () => state.meta?.settings.currency.decimals ?? 2;
const money = (m) => format(m, decimals(), state.meta?.settings.currency.symbol ?? '');

function totals() {
    const lines = [...state.cart.values()].map((l) => ({
        product_id: l.product.id, quantity: l.qty, unit_price: l.product.price,
        discount: Math.max(0, Math.min(l.qty * l.product.price, parse(l.discount, decimals()) ?? 0)), tax_rate_bp: l.product.tax_bp,
    }));
    if (!lines.length) return { lines: [], subtotal: 0, discount_total: 0, tax_total: 0, total: 0 };
    const gross = lines.reduce((s, l) => s + l.quantity * l.unit_price - l.discount, 0);
    const doc = Math.max(0, Math.min(gross, parse(state.discount, decimals()) ?? 0));
    return calculate(lines, doc, !!state.meta.settings.prices_include_tax);
}

function addProduct(p) {
    const line = state.cart.get(p.id) || { product: p, qty: 0, discount: '' };
    line.qty++;
    state.cart.set(p.id, line);
    state.search = '';
    render();
    document.getElementById('scan')?.focus();
}

function scan(code) {
    code = code.trim();
    const p = state.products.find((x) => x.barcode === code || x.sku === code || x.qr === code);
    if (p) addProduct(p);
    else flash(T.not_found.replace(':code', code), true);
}

function flash(text, error = false) {
    state.message = { text, error };
    render();
    setTimeout(() => { state.message = null; render(); }, 4000);
}

async function completeSale() {
    const t = totals();
    if (!t.lines.length) return;
    const payments = state.payments.map((p) => ({ method: p.method, amount: parse(p.amount, decimals()) ?? -1, tendered: p.method === 'cash' && p.tendered ? parse(p.tendered, decimals()) : null }));
    if (payments.some((p) => p.amount <= 0)) return flash(T.invalid_amount, true);
    const paid = payments.reduce((s, p) => s + p.amount, 0);
    if (paid > t.total) return flash(T.overpaid, true);
    if (!state.customerId && paid < t.total) return flash(T.need_customer, true);
    if (t.discount_total > 0 && !state.meta.user.can_discount) return flash(T.no_discount, true);
    if (t.discount_total * 100 > t.subtotal * state.meta.user.max_discount_percent) return flash(T.discount_limit, true);

    const sale = {
        uuid: crypto.randomUUID(), branch_id: state.meta.device.branch_id, created_at: new Date().toISOString(),
        customer_id: state.customerId, discount: Math.max(0, Math.min(t.subtotal, parse(state.discount, decimals()) ?? 0)),
        items: t.lines.map((l) => ({ product_id: l.product_id, quantity: l.quantity, unit_price: l.unit_price, discount: Math.max(0, parse([...state.cart.values()].find((c) => c.product.id === l.product_id).discount, decimals()) ?? 0) })),
        payments: payments.map((p) => (p.tendered ? p : { method: p.method, amount: p.amount })),
    };
    await store.enqueue(sale, t.total);
    printReceipt(sale, t);
    state.cart.clear();
    state.payments = [];
    state.discount = '';
    state.customerId = null;
    state.queue = await store.queue();
    flash(T.saved);
    sync();
}

function printReceipt(sale, t) {
    const el = document.getElementById('receipt');
    el.innerHTML = `<div style="text-align:center;font-weight:bold">${esc(state.meta.settings.company)}</div>
        <div style="text-align:center">${esc(state.meta.device.branch)}</div>
        <div>${esc(T.offline_receipt)} — ${esc(sale.uuid.slice(0, 8).toUpperCase())}</div>
        <div dir="ltr">${esc(new Date(sale.created_at).toLocaleString())}</div><hr>
        ${t.lines.map((l) => { const p = state.products.find((x) => x.id === l.product_id); return `<div>${esc(p?.name)}</div><div dir="ltr">${l.quantity} × ${esc(format(l.unit_price, decimals()))} = ${esc(format(l.line_total, decimals()))}</div>`; }).join('')}
        <hr><div style="font-weight:bold">${esc(T.total)}: ${esc(money(t.total))}</div>
        <div>${esc(T.receipt_note)}</div>`;
    window.print();
}

// ------------------------------------------------------------------ rendering

const statusBadge = (s) => ({ pending: ['#f59e0b', T.st_pending], retry: ['#f59e0b', T.st_pending], synced: ['#059669', T.st_synced], failed: ['#e11d48', T.st_failed], review: ['#6366f1', T.st_review] })[s] || ['#64748b', s];

function render() {
    const online = navigator.onLine;
    if (!state.meta) {
        root.innerHTML = `<div class="card card-body max-w-lg space-y-3">
            <h2 class="font-semibold">${esc(T.register_title)}</h2><p class="text-sm text-slate-500">${esc(T.register_help)}</p>
            <input id="dev-name" class="form-input" placeholder="${esc(T.device_name)}" value="POS">
            <select id="dev-branch" class="form-input">${BRANCHES.map((b) => `<option value="${b.id}">${esc(b.name)}</option>`).join('')}</select>
            <button id="dev-register" class="btn-primary" ${online ? '' : 'disabled'}>${esc(T.register)}</button>
            ${online ? '' : `<p class="form-error">${esc(T.need_online)}</p>`}</div>`;
        document.getElementById('dev-register')?.addEventListener('click', async () => {
            try {
                await request(URLS.register, { method: 'POST', body: JSON.stringify({ name: document.getElementById('dev-name').value, branch_id: +document.getElementById('dev-branch').value }) });
                await refreshCatalog();
                render();
            } catch (e) { flash(e.message, true); }
        });
        return;
    }
    const t = totals();
    const q = state.search.trim().toLowerCase();
    const results = q ? state.products.filter((p) => [p.name, p.name_en, p.sku, p.barcode].some((v) => String(v || '').toLowerCase().includes(q))).slice(0, 12) : [];
    const counts = state.queue.reduce((c, x) => ({ ...c, [x.status]: (c[x.status] || 0) + 1 }), {});
    const paid = state.payments.reduce((s, p) => s + Math.max(0, parse(p.amount, decimals()) ?? 0), 0);
    const cash = state.payments.filter((p) => p.method === 'cash');
    const change = cash.reduce((s, p) => s + (parse(p.tendered, decimals()) ?? 0) - (parse(p.amount, decimals()) ?? 0), 0);

    root.innerHTML = `
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <span class="badge ${online ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'}">${esc(online ? T.online : T.offline)}</span>
        <span class="text-sm">${esc(state.meta.device.name)} — ${esc(state.meta.device.branch)} — ${esc(state.meta.user.name)}</span>
        <span class="text-xs text-slate-500" dir="ltr">${esc(T.catalog_of)} ${esc(new Date(state.meta.fetched_at).toLocaleString())}</span>
        <span class="ms-auto text-sm">${esc(T.st_pending)}: ${(counts.pending || 0) + (counts.retry || 0)} · ${esc(T.st_failed)}: ${counts.failed || 0} · ${esc(T.st_review)}: ${counts.review || 0}</span>
        <button id="sync" class="btn-secondary btn-sm" ${online && !state.syncing ? '' : 'disabled'}>${esc(state.syncing ? T.syncing : T.sync_now)}</button>
        <button id="refresh" class="btn-secondary btn-sm" ${online ? '' : 'disabled'}>${esc(T.refresh)}</button>
    </div>
    ${state.authProblem ? `<div class="mb-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-800">${esc(state.authProblem)} <a class="underline" href="${URLS.login}">${esc(T.sign_in)}</a></div>` : ''}
    ${state.message ? `<div class="mb-3 rounded-lg p-3 text-sm ${state.message.error ? 'bg-rose-50 text-rose-800' : 'bg-emerald-50 text-emerald-800'}">${esc(state.message.text)}</div>` : ''}
    <div class="grid gap-4 xl:grid-cols-5">
      <div class="space-y-4 xl:col-span-3">
        <div class="card card-body grid gap-3 sm:grid-cols-2">
          <form id="scan-form"><label class="form-label">${esc(T.scan)}</label><input id="scan" class="form-input font-mono" dir="ltr" autocomplete="off"></form>
          <div class="relative"><label class="form-label">${esc(T.search)}</label><input id="search" class="form-input" value="${esc(state.search)}" autocomplete="off">
            ${results.length ? `<div class="absolute z-20 mt-1 w-full rounded-lg border bg-white shadow-lg">${results.map((p) => `<button data-add="${p.id}" class="flex w-full justify-between px-3 py-2 text-start text-sm hover:bg-slate-50"><span>${esc(p.name)} <span class="font-mono text-xs text-slate-500">${esc(p.sku)}</span></span><span>${p.track ? `${esc(T.stock)}: ${p.stock} · ` : ''}${esc(money(p.price))}</span></button>`).join('')}</div>` : ''}
          </div>
        </div>
        <div class="card table-wrap"><table class="table"><tbody class="divide-y divide-slate-100">
          ${[...state.cart.values()].map((l) => `<tr><td>${esc(l.product.name)}<div class="font-mono text-xs text-slate-500">${esc(l.product.sku)}${l.product.track && l.qty > l.product.stock ? ` · <span class="text-amber-700">${esc(T.low_snapshot)}</span>` : ''}</div></td>
            <td>${esc(money(l.product.price))}</td>
            <td><button data-dec="${l.product.id}" class="btn-secondary btn-sm">−</button> ${l.qty} <button data-inc="${l.product.id}" class="btn-secondary btn-sm">+</button></td>
            ${state.meta.user.can_discount ? `<td><input data-disc="${l.product.id}" class="form-input w-24 py-1" dir="ltr" value="${esc(l.discount)}" placeholder="0.00"></td>` : ''}
            <td><button data-del="${l.product.id}" class="text-rose-600">✕</button></td></tr>`).join('') || `<tr><td class="py-10 text-center text-slate-400">${esc(T.empty)}</td></tr>`}
        </tbody></table></div>
      </div>
      <div class="space-y-4 xl:col-span-2">
        <div class="card card-body space-y-2 text-sm">
          <select id="customer" class="form-input"><option value="">${esc(T.walk_in)}</option>${state.customers.map((c) => `<option value="${c.id}" ${state.customerId === c.id ? 'selected' : ''}>${esc(c.name)} ${esc(c.phone || '')}</option>`).join('')}</select>
          <div class="flex justify-between"><span>${esc(T.subtotal)}</span><span>${esc(money(t.subtotal))}</span></div>
          ${state.meta.user.can_discount ? `<div class="flex items-center justify-between"><span>${esc(T.discount)}</span><input id="discount" class="form-input w-28 py-1" dir="ltr" value="${esc(state.discount)}" placeholder="0.00"></div>` : ''}
          ${state.meta.settings.tax_enabled ? `<div class="flex justify-between"><span>${esc(T.tax)}</span><span>${esc(money(t.tax_total))}</span></div>` : ''}
          <div class="flex justify-between text-xl font-bold"><span>${esc(T.total)}</span><span>${esc(money(t.total))}</span></div>
        </div>
        <div class="card card-body space-y-2">
          <div class="flex gap-2"><button data-pay="cash" class="btn-secondary btn-sm">+ ${esc(T.cash)}</button><button data-pay="card" class="btn-secondary btn-sm">+ ${esc(T.card)}</button></div>
          ${state.payments.map((p, i) => `<div class="flex items-center gap-2"><span class="w-16 text-sm">${esc(p.method === 'cash' ? T.cash : T.card)}</span>
            <input data-amt="${i}" class="form-input py-1" dir="ltr" value="${esc(p.amount)}">
            ${p.method === 'cash' ? `<input data-tend="${i}" class="form-input py-1" dir="ltr" placeholder="${esc(T.tendered)}" value="${esc(p.tendered || '')}">` : ''}
            <button data-rmpay="${i}" class="text-rose-600">✕</button></div>`).join('')}
          <div class="flex justify-between text-sm"><span>${esc(T.remaining)}</span><span>${esc(money(Math.max(0, t.total - paid)))}</span></div>
          ${change > 0 ? `<div class="flex justify-between text-lg font-bold"><span>${esc(T.change)}</span><span>${esc(money(change))}</span></div>` : ''}
          <button id="complete" class="btn-success w-full" ${state.cart.size ? '' : 'disabled'}>${esc(T.complete)}</button>
        </div>
      </div>
    </div>
    <div class="card mt-6 table-wrap"><div class="border-b px-4 py-3 font-semibold">${esc(T.queue)}</div><table class="table"><tbody class="divide-y divide-slate-100">
      ${state.queue.slice(0, 30).map((q) => { const [c, l] = statusBadge(q.status); return `<tr><td class="font-mono text-xs" dir="ltr">${esc(q.uuid.slice(0, 8))}</td><td dir="ltr" class="text-xs">${esc(new Date(q.created_at).toLocaleString())}</td><td>${esc(money(q.total))}</td>
        <td><span class="badge" style="background:${c}22;color:${c}">${esc(l)}</span></td><td class="font-mono text-xs" dir="ltr">${esc(q.invoice_number || '')}</td><td class="text-xs text-slate-500">${esc(q.message || '')}</td>
        <td>${q.status === 'failed' ? `<button data-retry="${q.uuid}" class="btn-secondary btn-sm">${esc(T.retry)}</button>` : ''}</td></tr>`; }).join('') || `<tr><td class="text-center text-slate-500">${esc(T.no_queue)}</td></tr>`}
    </tbody></table></div>`;
    bind();
}

function bind() {
    const $ = (id) => document.getElementById(id);
    $('scan-form')?.addEventListener('submit', (e) => { e.preventDefault(); scan($('scan').value); });
    $('search')?.addEventListener('input', (e) => { state.search = e.target.value; render(); const s = $('search'); s.focus(); s.setSelectionRange(s.value.length, s.value.length); });
    $('sync')?.addEventListener('click', sync);
    $('refresh')?.addEventListener('click', () => refreshCatalog().then(render).catch((e) => flash(e.message, true)));
    $('customer')?.addEventListener('change', (e) => { state.customerId = e.target.value ? +e.target.value : null; render(); });
    $('discount')?.addEventListener('change', (e) => { state.discount = e.target.value; render(); });
    $('complete')?.addEventListener('click', completeSale);
    root.querySelectorAll('[data-add]').forEach((b) => b.addEventListener('click', () => addProduct(state.products.find((p) => p.id === +b.dataset.add))));
    root.querySelectorAll('[data-inc]').forEach((b) => b.addEventListener('click', () => { state.cart.get(+b.dataset.inc).qty++; render(); }));
    root.querySelectorAll('[data-dec]').forEach((b) => b.addEventListener('click', () => { const l = state.cart.get(+b.dataset.dec); if (--l.qty < 1) state.cart.delete(+b.dataset.dec); render(); }));
    root.querySelectorAll('[data-del]').forEach((b) => b.addEventListener('click', () => { state.cart.delete(+b.dataset.del); render(); }));
    root.querySelectorAll('[data-disc]').forEach((i) => i.addEventListener('change', () => { state.cart.get(+i.dataset.disc).discount = i.value; render(); }));
    root.querySelectorAll('[data-pay]').forEach((b) => b.addEventListener('click', () => {
        const rest = Math.max(0, totals().total - state.payments.reduce((s, p) => s + (parse(p.amount, decimals()) ?? 0), 0));
        state.payments.push({ method: b.dataset.pay, amount: format(rest, decimals()).replace(/,/g, ''), tendered: '' });
        render();
    }));
    root.querySelectorAll('[data-amt]').forEach((i) => i.addEventListener('change', () => { state.payments[+i.dataset.amt].amount = i.value; render(); }));
    root.querySelectorAll('[data-tend]').forEach((i) => i.addEventListener('change', () => { state.payments[+i.dataset.tend].tendered = i.value; render(); }));
    root.querySelectorAll('[data-rmpay]').forEach((b) => b.addEventListener('click', () => { state.payments.splice(+b.dataset.rmpay, 1); render(); }));
    root.querySelectorAll('[data-retry]').forEach((b) => b.addEventListener('click', async () => { await store.update(b.dataset.retry, { status: 'pending' }); state.queue = await store.queue(); sync(); }));
}

// ------------------------------------------------------------------ boot

window.addEventListener('online', () => { state.online = true; render(); sync(); });
window.addEventListener('offline', () => { state.online = false; render(); });
setInterval(() => { if (navigator.onLine) sync(); }, 30000);

(async () => {
    await loadLocal();
    // Online and this browser has no valid device cookie (new user / revoked): require registration again.
    if (navigator.onLine && root.dataset.registered !== '1') {
        state.meta = null;
    }
    if (navigator.onLine && root.dataset.registered === '1') {
        try { await refreshCatalog(); } catch { /* keep cached snapshot */ }
    }
    render();
    sync();
})();
