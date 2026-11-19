// IndexedDB storage for the offline POS + at-rest encryption of the sales queue.
// Never stores passwords, session cookies or device secrets (those live in HttpOnly cookies).

const DB_NAME = 'erp-offline-pos';
const VERSION = 1;

function open() {
    return new Promise((resolve, reject) => {
        const req = indexedDB.open(DB_NAME, VERSION);
        req.onupgradeneeded = () => {
            const db = req.result;
            db.createObjectStore('kv');
            db.createObjectStore('products', { keyPath: 'id' });
            db.createObjectStore('customers', { keyPath: 'id' });
            db.createObjectStore('queue', { keyPath: 'uuid' });
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
}

let dbPromise;
const db = () => (dbPromise ??= open());

function tx(store, mode, fn) {
    return db().then((d) => new Promise((resolve, reject) => {
        const t = d.transaction(store, mode);
        const s = t.objectStore(store);
        const result = fn(s);
        t.oncomplete = () => resolve(result && 'result' in result ? result.result : result);
        t.onerror = () => reject(t.error);
    }));
}

export const kvGet = (key) => tx('kv', 'readonly', (s) => s.get(key));
export const kvSet = (key, value) => tx('kv', 'readwrite', (s) => s.put(value, key));
export const all = (store) => tx(store, 'readonly', (s) => s.getAll());
export const put = (store, value) => tx(store, 'readwrite', (s) => s.put(value));

export async function replaceAll(store, rows) {
    await tx(store, 'readwrite', (s) => {
        s.clear();
        rows.forEach((r) => s.put(r));
    });
}

// ---- encryption (AES-GCM, non-extractable key kept in IndexedDB) ---------------------------

async function key() {
    let k = await kvGet('enc-key');
    if (!k) {
        k = await crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
        await kvSet('enc-key', k);
    }
    return k;
}

export async function encrypt(obj) {
    const iv = crypto.getRandomValues(new Uint8Array(12));
    const data = new TextEncoder().encode(JSON.stringify(obj));
    const ct = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, await key(), data);
    return { iv: Array.from(iv), ct: new Uint8Array(ct) };
}

export async function decrypt(box) {
    const pt = await crypto.subtle.decrypt({ name: 'AES-GCM', iv: new Uint8Array(box.iv) }, await key(), box.ct);
    return JSON.parse(new TextDecoder().decode(pt));
}

/** Queue entry: {uuid, status, created_at, total, invoice_number?, message?, box (encrypted sale)} */
export async function enqueue(sale, total) {
    await put('queue', { uuid: sale.uuid, status: 'pending', created_at: sale.created_at, total, box: await encrypt(sale), attempts: 0 });
}

export async function queue() {
    return (await all('queue')).sort((a, b) => (a.created_at < b.created_at ? 1 : -1));
}

export async function update(uuid, patch) {
    const row = await tx('queue', 'readonly', (s) => s.get(uuid));
    if (row) await put('queue', { ...row, ...patch });
}
